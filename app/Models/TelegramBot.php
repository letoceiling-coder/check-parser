<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * Дефолтное приветственное сообщение (устаревшее, использовать BotSettings)
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

    // ==========================================
    // Связи
    // ==========================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(BotSettings::class);
    }

    public function botUsers(): HasMany
    {
        return $this->hasMany(BotUser::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function adminRequests(): HasMany
    {
        return $this->hasMany(AdminRequest::class);
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(AdminActionLog::class);
    }

    // ==========================================
    // Хелперы
    // ==========================================

    /**
     * Получить или создать настройки бота
     */
    public function getOrCreateSettings(): BotSettings
    {
        return BotSettings::getOrCreate($this->id);
    }

    /**
     * Получить администраторов бота
     */
    public function getAdmins()
    {
        return $this->botUsers()->admins()->active()->get();
    }

    /**
     * Получить Telegram ID всех администраторов
     */
    public function getAdminTelegramIds(): array
    {
        return $this->botUsers()
            ->admins()
            ->active()
            ->pluck('telegram_user_id')
            ->toArray();
    }

    /**
     * Найти или создать пользователя бота
     */
    public function findOrCreateBotUser(array $telegramUser): BotUser
    {
        return BotUser::firstOrCreate(
            [
                'telegram_bot_id' => $this->id,
                'telegram_user_id' => $telegramUser['id'],
            ],
            [
                'username' => $telegramUser['username'] ?? null,
                'first_name' => $telegramUser['first_name'] ?? null,
                'last_name' => $telegramUser['last_name'] ?? null,
                'role' => 'user',
                'fsm_state' => 'IDLE',
            ]
        );
    }

    /**
     * Получить статистику номерков
     */
    public function getTicketsStats(): array
    {
        return Ticket::getStats($this->id);
    }

    /**
     * Инициализировать номерки
     */
    public function initializeTickets(?int $totalSlots = null): void
    {
        $settings = $this->getOrCreateSettings();
        $slots = $totalSlots ?? $settings->total_slots;
        Ticket::initializeForBot($this->id, $slots);
    }
}
