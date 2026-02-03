<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Check extends Model
{
    protected $fillable = [
        'telegram_bot_id',
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
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'corrected_amount' => 'decimal:2',
        'check_date' => 'datetime',
        'corrected_date' => 'datetime',
        'amount_found' => 'boolean',
        'date_found' => 'boolean',
        'readable_ratio' => 'float',
    ];

    /**
     * Связь с ботом
     */
    public function telegramBot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class);
    }

    /**
     * Получить итоговую сумму (с учетом коррекции)
     */
    public function getFinalAmountAttribute(): ?float
    {
        return $this->corrected_amount ?? $this->amount;
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
        return $this->corrected_amount !== null || $this->corrected_date !== null;
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

    /**
     * Scope для успешных чеков
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope для неудачных чеков
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope для частично распознанных
     */
    public function scopePartial($query)
    {
        return $query->where('status', 'partial');
    }
}
