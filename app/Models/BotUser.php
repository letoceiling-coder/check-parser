<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class BotUser extends Model
{
    protected $fillable = [
        'telegram_bot_id',
        'telegram_user_id',
        'username',
        'first_name',
        'last_name',
        'role',
        'fio_encrypted',
        'phone_encrypted',
        'inn_encrypted',
        'fsm_state',
        'fsm_data',
        'last_bot_message_id',
        'is_blocked',
        'notify_on_slots_available',
    ];

    protected $casts = [
        'telegram_user_id' => 'integer',
        'fsm_data' => 'array',
        'last_bot_message_id' => 'integer',
        'is_blocked' => 'boolean',
        'notify_on_slots_available' => 'boolean',
    ];

    protected $hidden = [
        'fio_encrypted',
        'phone_encrypted',
        'inn_encrypted',
    ];

    public const ROLE_USER = 'user';
    public const ROLE_ADMIN = 'admin';

    public const STATE_IDLE = 'IDLE';
    public const STATE_WELCOME = 'WELCOME';
    public const STATE_WAIT_FIO = 'WAIT_FIO';
    public const STATE_WAIT_PHONE = 'WAIT_PHONE';
    public const STATE_WAIT_INN = 'WAIT_INN';
    public const STATE_CONFIRM_DATA = 'CONFIRM_DATA';
    public const STATE_SHOW_QR = 'SHOW_QR';
    public const STATE_WAIT_CHECK = 'WAIT_CHECK';
    public const STATE_PENDING_REVIEW = 'PENDING_REVIEW';
    public const STATE_APPROVED = 'APPROVED';
    public const STATE_REJECTED = 'REJECTED';
    public const STATE_TEST_MODE = 'TEST_MODE';
    
    // Новые состояния для системы Orders
    public const STATE_ASK_QUANTITY = 'ASK_QUANTITY';                    // Запрос количества билетов
    public const STATE_CONFIRM_ORDER = 'CONFIRM_ORDER';                  // Подтверждение заказа
    public const STATE_ORDER_RESERVED = 'ORDER_RESERVED';                // Заказ забронирован
    public const STATE_WAIT_CHECK_FOR_ORDER = 'WAIT_CHECK_FOR_ORDER';  // Ожидание чека для заказа
    public const STATE_ORDER_REVIEW = 'ORDER_REVIEW';                    // Заказ на проверке
    public const STATE_ORDER_SOLD = 'ORDER_SOLD';                        // Заказ одобрен
    public const STATE_ORDER_REJECTED = 'ORDER_REJECTED';                // Заказ отклонен
    public const STATE_ORDER_EXPIRED = 'ORDER_EXPIRED';                  // Бронь истекла

    // ==========================================
    // Шифрование/расшифровка персональных данных
    // ==========================================

    public function setFioAttribute(?string $value): void
    {
        $this->attributes['fio_encrypted'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getFioAttribute(): ?string
    {
        return $this->fio_encrypted ? Crypt::decryptString($this->fio_encrypted) : null;
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone_encrypted'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->phone_encrypted ? Crypt::decryptString($this->phone_encrypted) : null;
    }

    public function setInnAttribute(?string $value): void
    {
        $this->attributes['inn_encrypted'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getInnAttribute(): ?string
    {
        return $this->inn_encrypted ? Crypt::decryptString($this->inn_encrypted) : null;
    }

    // ==========================================
    // FSM методы
    // ==========================================

    /**
     * Установить состояние FSM
     */
    public function setState(string $state, array $data = []): self
    {
        $this->fsm_state = $state;
        if (!empty($data)) {
            $this->fsm_data = array_merge($this->fsm_data ?? [], $data);
        }
        $this->save();
        return $this;
    }

    /**
     * Получить данные FSM
     */
    public function getFsmDataValue(string $key, $default = null)
    {
        return $this->fsm_data[$key] ?? $default;
    }

    /**
     * Очистить данные FSM
     */
    public function clearFsmData(): self
    {
        $this->fsm_data = null;
        $this->save();
        return $this;
    }

    /**
     * Сбросить состояние в начало
     */
    public function resetState(): self
    {
        $this->fsm_state = 'IDLE';
        $this->fsm_data = null;
        $this->save();
        return $this;
    }

    // ==========================================
    // Проверки ролей
    // ==========================================

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    public function makeAdmin(): self
    {
        $this->role = 'admin';
        $this->save();
        return $this;
    }

    // ==========================================
    // Связи
    // ==========================================

    public function telegramBot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class);
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

    // ==========================================
    // Скопы
    // ==========================================

    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }

    public function scopeActive($query)
    {
        return $query->where('is_blocked', false);
    }

    public function scopeWaitingForSlots($query)
    {
        return $query->where('notify_on_slots_available', true);
    }

    // ==========================================
    // Хелперы
    // ==========================================

    /**
     * Получить количество номерков пользователя
     */
    public function getTicketsCount(): int
    {
        return $this->tickets()->count();
    }

    /**
     * Получить номера билетов
     */
    public function getTicketNumbers(): array
    {
        return $this->tickets()->pluck('number')->sort()->values()->toArray();
    }

    /**
     * Проверить, заполнены ли все данные
     */
    public function hasAllPersonalData(): bool
    {
        return $this->fio_encrypted && $this->phone_encrypted;
    }

    /**
     * Получить отображаемое имя
     */
    public function getDisplayName(): string
    {
        if ($this->first_name) {
            return $this->first_name . ($this->last_name ? ' ' . $this->last_name : '');
        }
        return $this->username ? '@' . $this->username : 'User #' . $this->telegram_user_id;
    }
}
