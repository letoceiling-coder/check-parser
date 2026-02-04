<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Raffle extends Model
{
    // Статусы розыгрыша
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'telegram_bot_id',
        'name',
        'status',
        'total_slots',
        'slot_price',
        'slots_mode',
        'winner_ticket_id',
        'winner_bot_user_id',
        'winner_ticket_number',
        'total_participants',
        'tickets_issued',
        'total_revenue',
        'checks_count',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'total_slots' => 'integer',
        'slot_price' => 'decimal:2',
        'total_participants' => 'integer',
        'tickets_issued' => 'integer',
        'total_revenue' => 'decimal:2',
        'checks_count' => 'integer',
        'winner_ticket_number' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ==========================================
    // Связи
    // ==========================================

    public function telegramBot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class);
    }

    public function winnerTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'winner_ticket_id');
    }

    public function winnerUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'winner_bot_user_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    // ==========================================
    // Скопы
    // ==========================================

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeForBot($query, int $botId)
    {
        return $query->where('telegram_bot_id', $botId);
    }

    // ==========================================
    // Методы
    // ==========================================

    /**
     * Проверить, активен ли розыгрыш
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Проверить, завершён ли розыгрыш
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Получить список участников с номерками
     */
    public function getParticipants()
    {
        return BotUser::whereHas('tickets', function ($query) {
            $query->where('raffle_id', $this->id);
        })
        ->with(['tickets' => function ($query) {
            $query->where('raffle_id', $this->id)->orderBy('number');
        }])
        ->get();
    }

    /**
     * Получить все выданные номерки
     */
    public function getIssuedTickets()
    {
        return $this->tickets()->whereNotNull('bot_user_id')->orderBy('number')->get();
    }

    /**
     * Обновить статистику розыгрыша
     */
    public function updateStatistics(): self
    {
        $this->total_participants = BotUser::whereHas('tickets', function ($query) {
            $query->where('raffle_id', $this->id);
        })->count();

        $this->tickets_issued = $this->tickets()->whereNotNull('bot_user_id')->count();
        
        $this->total_revenue = $this->checks()
            ->where('review_status', 'approved')
            ->sum('amount');
        
        $this->checks_count = $this->checks()->count();
        
        $this->save();
        
        return $this;
    }

    /**
     * Завершить розыгрыш с выбором победителя
     */
    public function complete(int $winnerTicketId, ?string $notes = null): self
    {
        $ticket = Ticket::findOrFail($winnerTicketId);
        
        $this->status = self::STATUS_COMPLETED;
        $this->winner_ticket_id = $ticket->id;
        $this->winner_bot_user_id = $ticket->bot_user_id;
        $this->winner_ticket_number = $ticket->number;
        $this->completed_at = now();
        
        if ($notes) {
            $this->notes = $notes;
        }
        
        // Обновляем статистику перед завершением
        $this->updateStatistics();
        
        return $this;
    }

    /**
     * Отменить розыгрыш
     */
    public function cancel(?string $reason = null): self
    {
        $this->status = self::STATUS_CANCELLED;
        $this->completed_at = now();
        
        if ($reason) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Причина отмены: " . $reason;
        }
        
        $this->save();
        
        return $this;
    }

    // ==========================================
    // Статические методы
    // ==========================================

    /**
     * Создать новый розыгрыш для бота
     */
    public static function createForBot(int $botId, ?string $name = null): self
    {
        $settings = BotSettings::where('telegram_bot_id', $botId)->first();
        
        // Генерируем имя если не указано
        if (!$name) {
            $count = self::where('telegram_bot_id', $botId)->count() + 1;
            $name = "Розыгрыш #{$count}";
        }
        
        $raffle = self::create([
            'telegram_bot_id' => $botId,
            'name' => $name,
            'status' => self::STATUS_ACTIVE,
            'total_slots' => $settings->total_slots ?? 500,
            'slot_price' => $settings->slot_price ?? 10000,
            'slots_mode' => $settings->slots_mode ?? 'sequential',
            'started_at' => now(),
        ]);
        
        // Обновляем current_raffle_id в настройках
        if ($settings) {
            $settings->current_raffle_id = $raffle->id;
            $settings->save();
        }
        
        return $raffle;
    }

    /**
     * Получить текущий активный розыгрыш для бота
     */
    public static function getCurrentForBot(int $botId): ?self
    {
        return self::where('telegram_bot_id', $botId)
            ->where('status', self::STATUS_ACTIVE)
            ->latest()
            ->first();
    }

    /**
     * Получить или создать активный розыгрыш для бота
     */
    public static function getOrCreateForBot(int $botId): self
    {
        $raffle = self::getCurrentForBot($botId);
        
        if (!$raffle) {
            $raffle = self::createForBot($botId);
        }
        
        return $raffle;
    }

    /**
     * Получить историю розыгрышей для бота
     */
    public static function getHistoryForBot(int $botId, int $limit = 20)
    {
        return self::where('telegram_bot_id', $botId)
            ->with(['winnerUser', 'winnerTicket'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
