<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminActionLog extends Model
{
    public $timestamps = false;

    protected $table = 'admin_actions_log';

    protected $fillable = [
        'telegram_bot_id',
        'admin_user_id',
        'admin_telegram_id',
        'action_type',
        'target_type',
        'target_id',
        'old_data',
        'new_data',
        'comment',
        'created_at',
    ];

    protected $casts = [
        'admin_telegram_id' => 'integer',
        'target_id' => 'integer',
        'old_data' => 'array',
        'new_data' => 'array',
        'created_at' => 'datetime',
    ];

    // ==========================================
    // Типы действий
    // ==========================================

    public const ACTION_CHECK_APPROVED = 'check_approved';
    public const ACTION_CHECK_REJECTED = 'check_rejected';
    public const ACTION_CHECK_EDITED = 'check_edited';
    public const ACTION_ADMIN_REQUEST_APPROVED = 'admin_request_approved';
    public const ACTION_ADMIN_REQUEST_REJECTED = 'admin_request_rejected';
    public const ACTION_TICKET_ISSUED = 'ticket_issued';
    public const ACTION_TICKET_REVOKED = 'ticket_revoked';
    public const ACTION_USER_BLOCKED = 'user_blocked';
    public const ACTION_USER_UNBLOCKED = 'user_unblocked';
    public const ACTION_SETTINGS_UPDATED = 'settings_updated';

    // ==========================================
    // Связи
    // ==========================================

    public function telegramBot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    // ==========================================
    // Статические методы для логирования
    // ==========================================

    /**
     * Записать действие администратора
     */
    public static function log(
        int $telegramBotId,
        string $actionType,
        string $targetType,
        int $targetId,
        ?int $adminUserId = null,
        ?int $adminTelegramId = null,
        ?array $oldData = null,
        ?array $newData = null,
        ?string $comment = null
    ): self {
        return self::create([
            'telegram_bot_id' => $telegramBotId,
            'admin_user_id' => $adminUserId,
            'admin_telegram_id' => $adminTelegramId,
            'action_type' => $actionType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'old_data' => $oldData,
            'new_data' => $newData,
            'comment' => $comment,
            'created_at' => now(),
        ]);
    }

    /**
     * Лог одобрения чека
     */
    public static function logCheckApproved(
        Check $check,
        ?int $adminUserId = null,
        ?int $adminTelegramId = null,
        ?string $comment = null
    ): self {
        return self::log(
            $check->telegram_bot_id,
            self::ACTION_CHECK_APPROVED,
            'check',
            $check->id,
            $adminUserId,
            $adminTelegramId,
            null,
            [
                'amount' => $check->amount,
                'tickets_count' => $check->tickets_count,
            ],
            $comment
        );
    }

    /**
     * Лог отклонения чека
     */
    public static function logCheckRejected(
        Check $check,
        ?int $adminUserId = null,
        ?int $adminTelegramId = null,
        ?string $comment = null
    ): self {
        return self::log(
            $check->telegram_bot_id,
            self::ACTION_CHECK_REJECTED,
            'check',
            $check->id,
            $adminUserId,
            $adminTelegramId,
            null,
            ['amount' => $check->amount],
            $comment
        );
    }

    /**
     * Лог редактирования чека
     */
    public static function logCheckEdited(
        Check $check,
        array $oldData,
        array $newData,
        ?int $adminUserId = null,
        ?int $adminTelegramId = null
    ): self {
        return self::log(
            $check->telegram_bot_id,
            self::ACTION_CHECK_EDITED,
            'check',
            $check->id,
            $adminUserId,
            $adminTelegramId,
            $oldData,
            $newData
        );
    }

    /**
     * Лог одобрения запроса на роль админа
     */
    public static function logAdminRequestApproved(
        AdminRequest $request,
        ?int $adminUserId = null
    ): self {
        return self::log(
            $request->telegram_bot_id,
            self::ACTION_ADMIN_REQUEST_APPROVED,
            'admin_request',
            $request->id,
            $adminUserId,
            null,
            null,
            ['bot_user_id' => $request->bot_user_id]
        );
    }

    /**
     * Лог отклонения запроса на роль админа
     */
    public static function logAdminRequestRejected(
        AdminRequest $request,
        ?int $adminUserId = null,
        ?string $comment = null
    ): self {
        return self::log(
            $request->telegram_bot_id,
            self::ACTION_ADMIN_REQUEST_REJECTED,
            'admin_request',
            $request->id,
            $adminUserId,
            null,
            null,
            ['bot_user_id' => $request->bot_user_id],
            $comment
        );
    }

    // ==========================================
    // Скопы
    // ==========================================

    public function scopeForBot($query, int $telegramBotId)
    {
        return $query->where('telegram_bot_id', $telegramBotId);
    }

    public function scopeByActionType($query, string $actionType)
    {
        return $query->where('action_type', $actionType);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
