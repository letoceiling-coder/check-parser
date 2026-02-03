<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramBot extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'webhook_url',
        'is_active',
        'welcome_message',
    ];
    
    /**
     * Дефолтное приветственное сообщение
     */
    public const DEFAULT_WELCOME_MESSAGE = "👋 Привет! Я бот для обработки чеков.\n\n📸 Отправьте мне фото чека или PDF документ, и я извлеку сумму платежа.\n\nПросто отправьте фото или PDF чека, и я обработаю его!";
    
    /**
     * Получить приветственное сообщение (или дефолтное)
     */
    public function getWelcomeMessageText(): string
    {
        return $this->welcome_message ?: self::DEFAULT_WELCOME_MESSAGE;
    }

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
