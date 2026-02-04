<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Broadcast extends Model
{
    protected $fillable = [
        'telegram_bot_id',
        'user_id',
        'type',
        'message_text',
        'file_path',
        'recipients_type',
        'recipients_count',
        'success_count',
        'failed_count',
        'failed_telegram_ids',
    ];

    protected $casts = [
        'recipients_count' => 'integer',
        'success_count' => 'integer',
        'failed_count' => 'integer',
        'failed_telegram_ids' => 'array',
    ];

    public const TYPE_TEXT = 'text';
    public const TYPE_PHOTO = 'photo';
    public const TYPE_VIDEO = 'video';
    public const TYPE_PHOTO_TEXT = 'photo_text';
    public const TYPE_VIDEO_TEXT = 'video_text';

    public const RECIPIENTS_ALL = 'all';
    public const RECIPIENTS_SELECTED = 'selected';

    public function telegramBot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_TEXT => 'Текст',
            self::TYPE_PHOTO => 'Фото',
            self::TYPE_VIDEO => 'Видео',
            self::TYPE_PHOTO_TEXT => 'Фото + текст',
            self::TYPE_VIDEO_TEXT => 'Видео + текст',
            default => $this->type,
        };
    }
}
