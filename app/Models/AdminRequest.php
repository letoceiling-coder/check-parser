<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminRequest extends Model
{
    protected $fillable = [
        'telegram_bot_id',
        'bot_user_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'admin_comment',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ==========================================
    // Статусы
    // ==========================================

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Одобрить запрос
     */
    public function approve(?int $reviewerId = null, ?string $comment = null): self
    {
        $this->status = 'approved';
        $this->reviewed_by = $reviewerId;
        $this->reviewed_at = now();
        $this->admin_comment = $comment;
        $this->save();

        // Назначить роль админа пользователю
        $this->botUser->makeAdmin();

        return $this;
    }

    /**
     * Отклонить запрос
     */
    public function reject(?int $reviewerId = null, ?string $comment = null): self
    {
        $this->status = 'rejected';
        $this->reviewed_by = $reviewerId;
        $this->reviewed_at = now();
        $this->admin_comment = $comment;
        $this->save();

        return $this;
    }

    // ==========================================
    // Скопы
    // ==========================================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    // ==========================================
    // Статические методы
    // ==========================================

    /**
     * Проверить, есть ли у пользователя активный запрос
     */
    public static function hasPendingRequest(int $botUserId): bool
    {
        return self::where('bot_user_id', $botUserId)
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * Создать запрос на роль админа
     */
    public static function createRequest(BotUser $botUser): self
    {
        return self::create([
            'telegram_bot_id' => $botUser->telegram_bot_id,
            'bot_user_id' => $botUser->id,
            'status' => 'pending',
        ]);
    }
}
