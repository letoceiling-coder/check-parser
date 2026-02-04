<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Ticket extends Model
{
    protected $fillable = [
        'telegram_bot_id',
        'number',
        'bot_user_id',
        'check_id',
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
    public function issueTo(BotUser $botUser, ?Check $check = null): self
    {
        $this->bot_user_id = $botUser->id;
        $this->check_id = $check?->id;
        $this->issued_at = now();
        $this->save();
        return $this;
    }

    /**
     * Вернуть номерок в пул (отменить выдачу)
     */
    public function revoke(): self
    {
        $this->bot_user_id = null;
        $this->check_id = null;
        $this->issued_at = null;
        $this->save();
        return $this;
    }

    // ==========================================
    // Статические методы
    // ==========================================

    /**
     * Инициализировать номерки для бота
     */
    public static function initializeForBot(int $telegramBotId, int $totalSlots): void
    {
        $existingNumbers = self::where('telegram_bot_id', $telegramBotId)
            ->pluck('number')
            ->toArray();

        $toCreate = [];
        for ($i = 1; $i <= $totalSlots; $i++) {
            if (!in_array($i, $existingNumbers)) {
                $toCreate[] = [
                    'telegram_bot_id' => $telegramBotId,
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
        string $mode = 'sequential'
    ): Collection {
        $query = self::where('telegram_bot_id', $telegramBotId)
            ->whereNull('bot_user_id')
            ->limit($count);

        if ($mode === 'sequential') {
            $query->orderBy('number', 'asc');
        } else {
            $query->inRandomOrder();
        }

        $tickets = $query->get();

        foreach ($tickets as $ticket) {
            $ticket->issueTo($botUser, $check);
        }

        return $tickets;
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
     * Получить количество выданных номерков
     */
    public static function getIssuedCount(int $telegramBotId): int
    {
        return self::where('telegram_bot_id', $telegramBotId)
            ->whereNotNull('bot_user_id')
            ->count();
    }

    /**
     * Получить статистику по номеркам
     */
    public static function getStats(int $telegramBotId): array
    {
        $total = self::where('telegram_bot_id', $telegramBotId)->count();
        $issued = self::getIssuedCount($telegramBotId);
        $available = $total - $issued;

        return [
            'total' => $total,
            'issued' => $issued,
            'available' => $available,
            'percentage_issued' => $total > 0 ? round(($issued / $total) * 100, 1) : 0,
        ];
    }
}
