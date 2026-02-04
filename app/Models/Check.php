<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Check extends Model
{
    protected $fillable = [
        'telegram_bot_id',
        'bot_user_id',
        'chat_id',
        'username',
        'first_name',
        'file_path',
        'file_type',
        'file_size',
        'amount',
        'currency',
        'check_date',
        'ocr_method',
        'raw_text',
        'text_length',
        'readable_ratio',
        'status',
        'amount_found',
        'date_found',
        'corrected_amount',
        'corrected_date',
        'admin_notes',
        // Новые поля для розыгрыша
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'tickets_count',
        'admin_edited_amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'corrected_amount' => 'decimal:2',
        'admin_edited_amount' => 'decimal:2',
        'check_date' => 'datetime',
        'corrected_date' => 'datetime',
        'reviewed_at' => 'datetime',
        'amount_found' => 'boolean',
        'date_found' => 'boolean',
        'readable_ratio' => 'float',
        'tickets_count' => 'integer',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    // ==========================================
    // Атрибуты
    // ==========================================

    /**
     * Получить итоговую сумму (с учетом коррекции админом)
     */
    public function getFinalAmountAttribute(): ?float
    {
        return $this->admin_edited_amount ?? $this->corrected_amount ?? $this->amount;
    }

    /**
     * Получить итоговую дату (с учетом коррекции)
     */
    public function getFinalDateAttribute()
    {
        return $this->corrected_date ?? $this->check_date;
    }

    /**
     * Проверить, был ли чек скорректирован
     */
    public function getWasCorrectedAttribute(): bool
    {
        return $this->corrected_amount !== null || $this->corrected_date !== null || $this->admin_edited_amount !== null;
    }

    /**
     * URL для просмотра файла
     */
    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }
        return url('storage/' . $this->file_path);
    }

    // ==========================================
    // Статусы проверки
    // ==========================================

    public function isPendingReview(): bool
    {
        return $this->review_status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->review_status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->review_status === 'rejected';
    }

    /**
     * Одобрить чек и выдать номерки
     */
    public function approve(
        int $ticketsCount,
        ?int $reviewerId = null,
        ?float $editedAmount = null
    ): self {
        $this->review_status = 'approved';
        $this->reviewed_by = $reviewerId;
        $this->reviewed_at = now();
        $this->tickets_count = $ticketsCount;
        
        if ($editedAmount !== null) {
            $this->admin_edited_amount = $editedAmount;
        }
        
        $this->save();
        return $this;
    }

    /**
     * Отклонить чек
     */
    public function reject(?int $reviewerId = null, ?string $reason = null): self
    {
        $this->review_status = 'rejected';
        $this->reviewed_by = $reviewerId;
        $this->reviewed_at = now();
        
        if ($reason) {
            $this->admin_notes = ($this->admin_notes ? $this->admin_notes . "\n" : '') . "Причина отклонения: " . $reason;
        }
        
        $this->save();
        return $this;
    }

    /**
     * Обновить сумму (редактирование админом)
     */
    public function editAmount(float $newAmount): self
    {
        $this->admin_edited_amount = $newAmount;
        $this->save();
        return $this;
    }

    // ==========================================
    // Скопы
    // ==========================================

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePartial($query)
    {
        return $query->where('status', 'partial');
    }

    public function scopePendingReview($query)
    {
        return $query->where('review_status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('review_status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('review_status', 'rejected');
    }

    public function scopeForBot($query, int $telegramBotId)
    {
        return $query->where('telegram_bot_id', $telegramBotId);
    }

    // ==========================================
    // Хелперы
    // ==========================================

    /**
     * Рассчитать количество номерков по настройкам бота
     */
    public function calculateTicketsCount(): int
    {
        $settings = BotSettings::where('telegram_bot_id', $this->telegram_bot_id)->first();
        if (!$settings || $settings->slot_price <= 0) {
            return 0;
        }
        
        $amount = $this->final_amount ?? 0;
        return (int) floor($amount / $settings->slot_price);
    }

    /**
     * Получить номера выданных билетов
     */
    public function getTicketNumbers(): array
    {
        return $this->tickets()->pluck('number')->sort()->values()->toArray();
    }
}
