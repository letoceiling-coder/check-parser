<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Order extends Model
{
    // Статусы заказа
    public const STATUS_RESERVED = 'reserved';    // Забронирован (ожидание чека)
    public const STATUS_REVIEW = 'review';        // На проверке (чек загружен)
    public const STATUS_SOLD = 'sold';            // Продано (одобрено)
    public const STATUS_REJECTED = 'rejected';    // Отклонено
    public const STATUS_EXPIRED = 'expired';      // Бронь истекла

    protected $fillable = [
        'telegram_bot_id',
        'raffle_id',
        'bot_user_id',
        'check_id',
        'status',
        'reserved_until',
        'quantity',
        'amount',
        'ticket_numbers',
        'reviewed_by',
        'reviewed_at',
        'reject_reason',
        'admin_notes',
    ];

    protected $casts = [
        'reserved_until' => 'datetime',
        'reviewed_at' => 'datetime',
        'quantity' => 'integer',
        'amount' => 'decimal:2',
        'ticket_numbers' => 'array',
    ];

    // ==========================================
    // Связи
    // ==========================================

    public function telegramBot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class);
    }

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class);
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    // ==========================================
    // Проверки статуса
    // ==========================================

    public function isReserved(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }

    public function isReview(): bool
    {
        return $this->status === self::STATUS_REVIEW;
    }

    public function isSold(): bool
    {
        return $this->status === self::STATUS_SOLD;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }
        
        // Проверяем, истекла ли бронь
        if ($this->isReserved() && $this->reserved_until && $this->reserved_until->isPast()) {
            return true;
        }
        
        return false;
    }

    // ==========================================
    // Методы управления заказом
    // ==========================================

    /**
     * Продлить бронь
     */
    public function extendReservation(int $minutes = 30): self
    {
        if ($this->isReserved()) {
            $this->reserved_until = now()->addMinutes($minutes);
            $this->save();
            
            Log::info("Order reservation extended", [
                'order_id' => $this->id,
                'new_reserved_until' => $this->reserved_until,
            ]);
        }
        
        return $this;
    }

    /**
     * Отменить бронь и освободить места
     */
    public function cancelReservation(string $reason = 'Бронь отменена'): bool
    {
        try {
            DB::transaction(function () use ($reason) {
                // Освобождаем билеты
                $releasedCount = Ticket::where('order_id', $this->id)
                    ->update([
                        'order_id' => null,
                        'bot_user_id' => null,
                        'issued_at' => null,
                    ]);
                
                // Обновляем статистику розыгрыша
                if ($this->raffle_id) {
                    $raffle = Raffle::find($this->raffle_id);
                    if ($raffle) {
                        $raffle->decrement('tickets_issued', $releasedCount);
                    }
                }
                
                // Обновляем статус заказа
                $this->status = self::STATUS_EXPIRED;
                $this->reject_reason = $reason;
                $this->save();
                
                Log::info("Order reservation cancelled", [
                    'order_id' => $this->id,
                    'released_tickets' => $releasedCount,
                    'reason' => $reason,
                ]);
            });
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to cancel order reservation", [
                'order_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Перевести заказ в статус "На проверке" (когда чек загружен)
     */
    public function moveToReview(): self
    {
        if ($this->isReserved()) {
            $this->status = self::STATUS_REVIEW;
            $this->reserved_until = null; // Останавливаем таймер брони
            $this->save();
            
            Log::info("Order moved to review", ['order_id' => $this->id]);
        }
        
        return $this;
    }

    /**
     * Одобрить заказ и выдать билеты
     */
    public function approve(?int $reviewerId = null, ?array $customTicketNumbers = null): bool
    {
        if (!$this->isReview()) {
            Log::warning("Cannot approve order - not in review status", [
                'order_id' => $this->id,
                'status' => $this->status,
            ]);
            return false;
        }
        
        try {
            DB::transaction(function () use ($reviewerId, $customTicketNumbers) {
                // Получаем забронированные билеты для этого заказа
                $tickets = Ticket::where('order_id', $this->id)
                    ->whereNull('bot_user_id')
                    ->lockForUpdate()
                    ->get();
                
                if ($tickets->count() !== $this->quantity) {
                    throw new \Exception("Несоответствие количества билетов: ожидалось {$this->quantity}, найдено {$tickets->count()}");
                }
                
                // Выдаем билеты пользователю
                $ticketNumbers = [];
                foreach ($tickets as $ticket) {
                    $ticket->bot_user_id = $this->bot_user_id;
                    $ticket->issued_at = now();
                    $ticket->save();
                    $ticketNumbers[] = $ticket->number;
                }
                
                // Используем кастомные номера если переданы (для редактирования)
                $this->ticket_numbers = $customTicketNumbers ?? $ticketNumbers;
                $this->status = self::STATUS_SOLD;
                $this->reviewed_by = $reviewerId;
                $this->reviewed_at = now();
                $this->save();
                
                // Обновляем чек если есть
                if ($this->check_id) {
                    $check = Check::find($this->check_id);
                    if ($check) {
                        $check->review_status = 'approved';
                        $check->reviewed_by = $reviewerId;
                        $check->reviewed_at = now();
                        $check->tickets_count = $this->quantity;
                        $check->save();
                    }
                }
                
                // Обновляем статистику розыгрыша
                if ($this->raffle_id) {
                    $raffle = Raffle::find($this->raffle_id);
                    if ($raffle) {
                        $raffle->updateStatistics();
                    }
                }
                
                Log::info("Order approved", [
                    'order_id' => $this->id,
                    'user_id' => $this->bot_user_id,
                    'tickets' => $this->ticket_numbers,
                ]);
            });
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to approve order", [
                'order_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Отклонить заказ
     */
    public function reject(?int $reviewerId = null, ?string $reason = null): bool
    {
        if (!$this->isReview()) {
            Log::warning("Cannot reject order - not in review status", [
                'order_id' => $this->id,
                'status' => $this->status,
            ]);
            return false;
        }
        
        try {
            DB::transaction(function () use ($reviewerId, $reason) {
                // Освобождаем билеты
                $releasedCount = Ticket::where('order_id', $this->id)
                    ->update([
                        'order_id' => null,
                        'bot_user_id' => null,
                        'issued_at' => null,
                    ]);
                
                // Уменьшаем счетчик выданных билетов в розыгрыше
                if ($this->raffle_id) {
                    $raffle = Raffle::find($this->raffle_id);
                    if ($raffle) {
                        $raffle->decrement('tickets_issued', $releasedCount);
                    }
                }
                
                // Обновляем статус заказа
                $this->status = self::STATUS_REJECTED;
                $this->reviewed_by = $reviewerId;
                $this->reviewed_at = now();
                $this->reject_reason = $reason ?? 'Чек не принят';
                $this->save();
                
                // Обновляем чек если есть
                if ($this->check_id) {
                    $check = Check::find($this->check_id);
                    if ($check) {
                        $check->review_status = 'rejected';
                        $check->reviewed_by = $reviewerId;
                        $check->reviewed_at = now();
                        if ($reason) {
                            $check->admin_notes = ($check->admin_notes ? $check->admin_notes . "\n" : '') . "Причина отклонения: " . $reason;
                        }
                        $check->save();
                    }
                }
                
                Log::info("Order rejected", [
                    'order_id' => $this->id,
                    'reason' => $reason,
                    'released_tickets' => $releasedCount,
                ]);
            });
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to reject order", [
                'order_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Редактировать заказ (изменить количество/сумму)
     */
    public function edit(int $newQuantity, float $newAmount): bool
    {
        if (!$this->isReview()) {
            Log::warning("Cannot edit order - not in review status", [
                'order_id' => $this->id,
                'status' => $this->status,
            ]);
            return false;
        }
        
        try {
            DB::transaction(function () use ($newQuantity, $newAmount) {
                $currentQuantity = $this->quantity;
                $difference = $newQuantity - $currentQuantity;
                
                if ($difference > 0) {
                    // Нужно добавить билеты
                    $additionalTickets = Ticket::where('raffle_id', $this->raffle_id)
                        ->whereNull('bot_user_id')
                        ->whereNull('order_id')
                        ->orderBy('number', 'asc')
                        ->limit($difference)
                        ->lockForUpdate()
                        ->get();
                    
                    if ($additionalTickets->count() < $difference) {
                        throw new \Exception("Недостаточно свободных билетов для увеличения заказа");
                    }
                    
                    foreach ($additionalTickets as $ticket) {
                        $ticket->order_id = $this->id;
                        $ticket->save();
                    }
                    
                    // Увеличиваем счетчик в розыгрыше
                    if ($this->raffle_id) {
                        $raffle = Raffle::find($this->raffle_id);
                        if ($raffle) {
                            $raffle->increment('tickets_issued', $difference);
                        }
                    }
                    
                } elseif ($difference < 0) {
                    // Нужно убрать билеты
                    $removeCount = abs($difference);
                    $ticketsToRemove = Ticket::where('order_id', $this->id)
                        ->whereNull('bot_user_id')
                        ->orderBy('number', 'desc')
                        ->limit($removeCount)
                        ->get();
                    
                    foreach ($ticketsToRemove as $ticket) {
                        $ticket->order_id = null;
                        $ticket->save();
                    }
                    
                    // Уменьшаем счетчик в розыгрыше
                    if ($this->raffle_id) {
                        $raffle = Raffle::find($this->raffle_id);
                        if ($raffle) {
                            $raffle->decrement('tickets_issued', $removeCount);
                        }
                    }
                }
                
                // Обновляем заказ
                $this->quantity = $newQuantity;
                $this->amount = $newAmount;
                $this->admin_notes = ($this->admin_notes ? $this->admin_notes . "\n" : '') . 
                    "Заказ отредактирован: количество {$currentQuantity} → {$newQuantity}, сумма изменена";
                $this->save();
                
                // Обновляем чек если есть
                if ($this->check_id) {
                    $check = Check::find($this->check_id);
                    if ($check) {
                        $check->admin_edited_amount = $newAmount;
                        $check->save();
                    }
                }
                
                Log::info("Order edited", [
                    'order_id' => $this->id,
                    'old_quantity' => $currentQuantity,
                    'new_quantity' => $newQuantity,
                    'new_amount' => $newAmount,
                ]);
            });
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to edit order", [
                'order_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    // ==========================================
    // Скопы
    // ==========================================

    public function scopeReserved($query)
    {
        return $query->where('status', self::STATUS_RESERVED);
    }

    public function scopeInReview($query)
    {
        return $query->where('status', self::STATUS_REVIEW);
    }

    public function scopeSold($query)
    {
        return $query->where('status', self::STATUS_SOLD);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    public function scopeForBot($query, int $telegramBotId)
    {
        return $query->where('telegram_bot_id', $telegramBotId);
    }

    public function scopeForRaffle($query, int $raffleId)
    {
        return $query->where('raffle_id', $raffleId);
    }

    /**
     * Заказы с истекшей бронью
     */
    public function scopeExpiredReservations($query)
    {
        return $query->where('status', self::STATUS_RESERVED)
            ->where('reserved_until', '<', now());
    }

    // ==========================================
    // Статические методы
    // ==========================================

    /**
     * Создать новый заказ с бронированием билетов
     */
    public static function createWithReservation(
        int $telegramBotId,
        int $raffleId,
        int $botUserId,
        int $quantity,
        float $amount,
        int $reservationMinutes = 30
    ): ?self {
        try {
            return DB::transaction(function () use (
                $telegramBotId,
                $raffleId,
                $botUserId,
                $quantity,
                $amount,
                $reservationMinutes
            ) {
                // Блокируем розыгрыш для проверки мест
                $raffle = Raffle::where('id', $raffleId)
                    ->where('status', Raffle::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->first();
                
                if (!$raffle) {
                    throw new \Exception('Активный розыгрыш не найден');
                }
                
                // Проверяем свободные места
                $availableSlots = $raffle->total_slots - $raffle->tickets_issued;
                
                if ($availableSlots < $quantity) {
                    throw new \Exception("Недостаточно свободных мест. Осталось: {$availableSlots}");
                }
                
                // Резервируем билеты
                $tickets = Ticket::where('raffle_id', $raffleId)
                    ->whereNull('bot_user_id')
                    ->whereNull('order_id')
                    ->orderBy('number', 'asc')
                    ->limit($quantity)
                    ->lockForUpdate()
                    ->get();
                
                if ($tickets->count() < $quantity) {
                    throw new \Exception("Не удалось зарезервировать билеты");
                }
                
                // Создаем заказ
                $order = self::create([
                    'telegram_bot_id' => $telegramBotId,
                    'raffle_id' => $raffleId,
                    'bot_user_id' => $botUserId,
                    'status' => self::STATUS_RESERVED,
                    'reserved_until' => now()->addMinutes($reservationMinutes),
                    'quantity' => $quantity,
                    'amount' => $amount,
                ]);
                
                // Привязываем билеты к заказу (временно, без bot_user_id)
                foreach ($tickets as $ticket) {
                    $ticket->order_id = $order->id;
                    $ticket->save();
                }
                
                // Обновляем статистику розыгрыша
                $raffle->increment('tickets_issued', $quantity);
                
                Log::info("Order created with reservation", [
                    'order_id' => $order->id,
                    'user_id' => $botUserId,
                    'quantity' => $quantity,
                    'reserved_until' => $order->reserved_until,
                ]);
                
                return $order;
            });
            
        } catch (\Exception $e) {
            Log::error("Failed to create order with reservation", [
                'bot_id' => $telegramBotId,
                'raffle_id' => $raffleId,
                'user_id' => $botUserId,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * Получить статистику по заказам
     */
    public static function getStats(int $telegramBotId, ?int $raffleId = null): array
    {
        $query = self::where('telegram_bot_id', $telegramBotId);
        
        if ($raffleId) {
            $query->where('raffle_id', $raffleId);
        }
        
        return [
            'total' => (clone $query)->count(),
            'reserved' => (clone $query)->where('status', self::STATUS_RESERVED)->count(),
            'review' => (clone $query)->where('status', self::STATUS_REVIEW)->count(),
            'sold' => (clone $query)->where('status', self::STATUS_SOLD)->count(),
            'rejected' => (clone $query)->where('status', self::STATUS_REJECTED)->count(),
            'expired' => (clone $query)->where('status', self::STATUS_EXPIRED)->count(),
            'total_revenue' => (clone $query)->where('status', self::STATUS_SOLD)->sum('amount'),
            'pending_revenue' => (clone $query)->whereIn('status', [self::STATUS_RESERVED, self::STATUS_REVIEW])->sum('amount'),
        ];
    }
}
