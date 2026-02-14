<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Ticket extends Model
{
    protected $fillable = [
        'telegram_bot_id',
        'raffle_id',
        'number',
        'bot_user_id',
        'check_id',
        'order_id',
        'issued_at',
    ];

    protected $casts = [
        'number' => 'integer',
        'issued_at' => 'datetime',
    ];

    // ==========================================
    // Связи
    // ==========================================

    public function telegramBot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class);
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class);
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // ==========================================
    // Статусы
    // ==========================================

    public function isAvailable(): bool
    {
        return $this->bot_user_id === null;
    }

    public function isIssued(): bool
    {
        return $this->bot_user_id !== null;
    }

    // ==========================================
    // Скопы
    // ==========================================

    public function scopeAvailable($query)
    {
        return $query->whereNull('bot_user_id');
    }

    public function scopeIssued($query)
    {
        return $query->whereNotNull('bot_user_id');
    }

    public function scopeForBot($query, int $telegramBotId)
    {
        return $query->where('telegram_bot_id', $telegramBotId);
    }

    // ==========================================
    // Методы выдачи
    // ==========================================

    /**
     * Выдать номерок пользователю
     */
    public function issueTo(BotUser $botUser, ?Check $check = null, ?int $raffleId = null): self
    {
        $this->bot_user_id = $botUser->id;
        $this->check_id = $check?->id;
        $this->raffle_id = $raffleId ?? $check?->raffle_id;
        $this->issued_at = now();
        $this->save();
        return $this;
    }

    /**
     * Вернуть номерок в пул (отменить выдачу)
     * @param bool $keepRaffle - сохранить привязку к розыгрышу (для истории)
     */
    public function revoke(bool $keepRaffle = false): self
    {
        $this->bot_user_id = null;
        $this->check_id = null;
        $this->issued_at = null;
        if (!$keepRaffle) {
            $this->raffle_id = null;
        }
        $this->save();
        return $this;
    }

    // ==========================================
    // Статические методы
    // ==========================================

    /**
     * Инициализировать номерки для бота (или розыгрыша)
     */
    public static function initializeForBot(int $telegramBotId, int $totalSlots, ?int $raffleId = null): void
    {
        $query = self::where('telegram_bot_id', $telegramBotId);
        
        // Если указан розыгрыш, проверяем номерки для этого розыгрыша
        if ($raffleId) {
            $query->where('raffle_id', $raffleId);
        }
        
        $existingNumbers = $query->pluck('number')->toArray();

        $toCreate = [];
        for ($i = 1; $i <= $totalSlots; $i++) {
            if (!in_array($i, $existingNumbers)) {
                $toCreate[] = [
                    'telegram_bot_id' => $telegramBotId,
                    'raffle_id' => $raffleId,
                    'number' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($toCreate)) {
            self::insert($toCreate);
        }
    }

    /**
     * Выдать номерки пользователю (последовательно или случайно)
     * 
     * @return Collection<int, Ticket> Выданные номерки
     */
    public static function issueTickets(
        int $telegramBotId,
        BotUser $botUser,
        int $count,
        ?Check $check = null,
        string $mode = 'sequential',
        ?int $raffleId = null
    ): Collection {
        $query = self::where('telegram_bot_id', $telegramBotId)
            ->whereNull('bot_user_id')
            ->limit($count);

        // Если указан розыгрыш, выдаём номерки из этого розыгрыша
        if ($raffleId) {
            $query->where('raffle_id', $raffleId);
        }

        if ($mode === 'sequential') {
            $query->orderBy('number', 'asc');
        } else {
            $query->inRandomOrder();
        }

        $tickets = $query->get();

        foreach ($tickets as $ticket) {
            $ticket->issueTo($botUser, $check, $raffleId);
        }

        return $tickets;
    }

    /**
     * Сбросить все номерки для розыгрыша (для нового розыгрыша)
     */
    public static function resetForRaffle(int $raffleId): int
    {
        return self::where('raffle_id', $raffleId)
            ->update([
                'bot_user_id' => null,
                'check_id' => null,
                'issued_at' => null,
            ]);
    }

    /**
     * Подготовить номерки для нового розыгрыша
     */
    public static function prepareForNewRaffle(int $telegramBotId, int $newRaffleId, int $totalSlots): void
    {
        // Обновляем существующие номерки - привязываем к новому розыгрышу и сбрасываем владельцев
        self::where('telegram_bot_id', $telegramBotId)
            ->whereNull('raffle_id')
            ->orWhere(function ($query) use ($telegramBotId) {
                $query->where('telegram_bot_id', $telegramBotId);
            })
            ->update([
                'raffle_id' => $newRaffleId,
                'bot_user_id' => null,
                'check_id' => null,
                'issued_at' => null,
            ]);
        
        // Инициализируем недостающие номерки
        self::initializeForBot($telegramBotId, $totalSlots, $newRaffleId);
    }

    /**
     * Получить количество свободных номерков
     */
    public static function getAvailableCount(int $telegramBotId): int
    {
        return self::where('telegram_bot_id', $telegramBotId)
            ->whereNull('bot_user_id')
            ->count();
    }

    /**
     * Получить количество выданных номерков (опционально по розыгрышу).
     * Только активный розыгрыш: при вызове из getStats всегда передавать raffleId.
     */
    public static function getIssuedCount(int $telegramBotId, ?int $raffleId = null): int
    {
        $query = self::where('telegram_bot_id', $telegramBotId)->whereNotNull('bot_user_id');
        if ($raffleId !== null) {
            $query->where('raffle_id', $raffleId);
        }
        return $query->count();
    }

    /**
     * Уменьшить количество номерков до newTotal: удалить незанятые с number > newTotal.
     * Вызывается при сохранении настроек, когда пользователь уменьшил «Количество мест».
     */
    public static function reduceToTotal(int $telegramBotId, int $newTotal, ?int $raffleId = null): int
    {
        $query = self::where('telegram_bot_id', $telegramBotId)
            ->where('number', '>', $newTotal)
            ->whereNull('bot_user_id');

        if ($raffleId !== null) {
            $query->where('raffle_id', $raffleId);
        } else {
            $query->whereNull('raffle_id');
        }

        return $query->delete();
    }

    /**
     * Статистика по номеркам только по указанному розыгрышу (для активного — передавать raffleId).
     * Инвариант: total = issued + reserved + review + available.
     * - issued: выдано (bot_user_id не null)
     * - reserved: в брони (order_id + заказ в статусе reserved)
     * - review: на проверке (order_id + заказ в статусе review)
     * - available: свободно для брони (bot_user_id и order_id null)
     */
    public static function getStats(int $telegramBotId, ?int $raffleId = null): array
    {
        $base = self::where('telegram_bot_id', $telegramBotId)
            ->when($raffleId !== null, fn ($q) => $q->where('raffle_id', $raffleId));

        $total = (clone $base)->count();
        $issued = (clone $base)->whereNotNull('bot_user_id')->count();
        $available = (clone $base)
            ->whereNull('bot_user_id')
            ->whereNull('order_id')
            ->count();
        $reserved = (clone $base)
            ->whereNotNull('order_id')
            ->whereHas('order', fn ($q) => $q->where('status', Order::STATUS_RESERVED))
            ->count();
        $review = (clone $base)
            ->whereNotNull('order_id')
            ->whereHas('order', fn ($q) => $q->where('status', Order::STATUS_REVIEW))
            ->count();

        return [
            'total' => $total,
            'issued' => $issued,
            'reserved' => $reserved,
            'review' => $review,
            'available' => $available,
            'percentage_issued' => $total > 0 ? round(($issued / $total) * 100, 1) : 0,
        ];
    }
}
