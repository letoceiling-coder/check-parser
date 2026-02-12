<?php

namespace App\Models;

use App\Services\ActiveRaffle\RaffleScope;
use App\Services\ActiveRaffleResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Raffle extends Model
{
    // Статусы розыгрыша
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    /** Приостановлен (был активным, сменили на другой) */
    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'telegram_bot_id',
        'name',
        'status',
        'total_slots',
        'slot_price',
        'slots_mode',
        'raffle_info',
        'prize_description',
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

    public function scopePaused($query)
    {
        return $query->where('status', self::STATUS_PAUSED);
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
     * Получить список участников с номерками (в т.ч. по брони — билеты с order_id).
     */
    public function getParticipants(): Collection
    {
        $raffleId = $this->id;

        // ID пользователей: владельцы билетов (bot_user_id) или владельцы заказов (order.bot_user_id)
        $userIds = Ticket::where('raffle_id', $raffleId)
            ->where(function ($q) {
                $q->whereNotNull('bot_user_id')->orWhereNotNull('order_id');
            })
            ->with('order')
            ->get()
            ->map(function (Ticket $t) {
                if ($t->bot_user_id) {
                    return $t->bot_user_id;
                }
                return $t->relationLoaded('order') ? $t->order?->bot_user_id : (Order::find($t->order_id)?->bot_user_id);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($userIds)) {
            return collect();
        }

        $users = BotUser::whereIn('id', $userIds)->get()->keyBy('id');

        // Для каждого пользователя — его номерки в этом розыгрыше (по билетам или по заказам)
        $orderIdsByUser = Order::where('raffle_id', $raffleId)
            ->whereIn('bot_user_id', $userIds)
            ->get()
            ->groupBy('bot_user_id')
            ->map(fn ($orders) => $orders->pluck('id')->all())
            ->toArray();

        $allOrderIds = collect($orderIdsByUser)->flatten()->all();

        $ticketsByUser = Ticket::where('raffle_id', $raffleId)
            ->where(function ($q) use ($userIds, $allOrderIds) {
                $q->whereIn('bot_user_id', $userIds);
                if (!empty($allOrderIds)) {
                    $q->orWhereIn('order_id', $allOrderIds);
                }
            })
            ->orderBy('number')
            ->get()
            ->groupBy(function (Ticket $t) use ($orderIdsByUser) {
                if ($t->bot_user_id) {
                    return (int) $t->bot_user_id;
                }
                foreach ($orderIdsByUser as $uid => $oids) {
                    $oids = is_array($oids) ? $oids : [$oids];
                    if (in_array((int) $t->order_id, array_map('intval', $oids), true)) {
                        return (int) $uid;
                    }
                }
                return 0;
            });

        foreach ($users as $user) {
            $tickets = $ticketsByUser->get((int) $user->id, collect());
            $user->setRelation('tickets', $tickets);
        }

        return $users->values();
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

        // Учитываем только реально выданные билеты (с bot_user_id)
        // Билеты с order_id но без bot_user_id - это только бронь, они не считаются выданными
        $this->tickets_issued = $this->tickets()
            ->whereNotNull('bot_user_id')
            ->count();
        
        // Используем итоговую сумму с учетом коррекции админом
        $this->total_revenue = $this->checks()
            ->where('review_status', 'approved')
            ->get()
            ->sum(function ($check) {
                return $check->admin_edited_amount ?? $check->corrected_amount ?? $check->amount ?? 0;
            });
        
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

        // Делаем активным только этот розыгрыш — остальные переводим в «приостановлен»
        self::where('telegram_bot_id', $botId)
            ->where('id', '!=', $raffle->id)
            ->where('status', self::STATUS_ACTIVE)
            ->update(['status' => self::STATUS_PAUSED]);
        
        // Обновляем current_raffle_id в настройках
        if ($settings) {
            $settings->current_raffle_id = $raffle->id;
            $settings->save();
        }
        
        return $raffle;
    }

    /**
     * Единственный способ получить активный розыгрыш для бота (через ActiveRaffleResolver).
     */
    public static function resolveActiveForBot(int $botId): ?self
    {
        return app(ActiveRaffleResolver::class)->getActive(RaffleScope::forBot($botId));
    }

    /**
     * Активный розыгрыш для бота или исключение (через ActiveRaffleResolver).
     *
     * @throws \App\Exceptions\NoActiveRaffleException
     */
    public static function requireActiveForBot(int $botId): self
    {
        return app(ActiveRaffleResolver::class)->requireActive(RaffleScope::forBot($botId));
    }

    /**
     * @deprecated Используйте resolveActiveForBot() или ActiveRaffleResolver
     */
    public static function getCurrentForBot(int $botId): ?self
    {
        return self::resolveActiveForBot($botId);
    }

    /**
     * @deprecated Не создавайте розыгрыш автоматически. Используйте resolveActiveForBot() и при отсутствии показывайте сообщение или createForBot() только из админки.
     */
    public static function getOrCreateForBot(int $botId): self
    {
        $raffle = self::resolveActiveForBot($botId);
        if ($raffle) {
            return $raffle;
        }
        return self::createForBot($botId);
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
