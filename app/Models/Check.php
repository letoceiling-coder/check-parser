<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Check extends Model
{
    protected $fillable = [
        'telegram_bot_id',
        'raffle_id',
        'bot_user_id',
        'chat_id',
        'username',
        'first_name',
        'file_path',
        'file_type',
        'file_size',
        'file_hash',
        'operation_id',
        'unique_key',
        'is_duplicate',
        'original_check_id',
        'amount',
        'currency',
        'bank_code',
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
        'parsing_confidence',
        'needs_review',
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
        'is_duplicate' => 'boolean',
        'parsing_confidence' => 'float',
        'needs_review' => 'boolean',
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

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Связь с оригинальным чеком (если это дубликат)
     */
    public function originalCheck(): BelongsTo
    {
        return $this->belongsTo(Check::class, 'original_check_id');
    }

    /**
     * Дубликаты этого чека
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(Check::class, 'original_check_id');
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

    /**
     * Фильтр по банку (для будущего расширения: банк-специфичная логика, отчёты).
     */
    public function scopeByBank($query, string $bankCode)
    {
        return $query->where('bank_code', $bankCode);
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

    // ==========================================
    // Проверка дубликатов
    // ==========================================

    /**
     * Найти дубликат по хешу файла
     */
    public static function findDuplicateByHash(int $botId, string $fileHash): ?self
    {
        return self::where('telegram_bot_id', $botId)
            ->where('file_hash', $fileHash)
            ->where('is_duplicate', false)
            ->where('review_status', '!=', 'rejected')
            ->first();
    }

    /**
     * Найти дубликат по ID операции
     */
    public static function findDuplicateByOperationId(int $botId, string $operationId): ?self
    {
        return self::where('telegram_bot_id', $botId)
            ->where('operation_id', $operationId)
            ->where('is_duplicate', false)
            ->where('review_status', '!=', 'rejected')
            ->first();
    }

    /**
     * Найти дубликат по уникальному ключу (сумма + дата)
     */
    public static function findDuplicateByUniqueKey(int $botId, string $uniqueKey): ?self
    {
        return self::where('telegram_bot_id', $botId)
            ->where('unique_key', $uniqueKey)
            ->where('is_duplicate', false)
            ->where('review_status', '!=', 'rejected')
            ->first();
    }

    /**
     * Комплексная проверка на дубликат
     * Возвращает оригинальный чек если найден дубликат, иначе null
     */
    public static function findDuplicate(int $botId, ?string $fileHash, ?string $operationId, ?string $uniqueKey): ?self
    {
        // 1. Проверка по хешу файла (точное совпадение файла)
        if ($fileHash) {
            $duplicate = self::findDuplicateByHash($botId, $fileHash);
            if ($duplicate) {
                return $duplicate;
            }
        }

        // 2. Проверка по ID операции (самый надёжный способ)
        if ($operationId) {
            $duplicate = self::findDuplicateByOperationId($botId, $operationId);
            if ($duplicate) {
                return $duplicate;
            }
        }

        // 3. Проверка по уникальному ключу (сумма + дата + время)
        if ($uniqueKey) {
            $duplicate = self::findDuplicateByUniqueKey($botId, $uniqueKey);
            if ($duplicate) {
                return $duplicate;
            }
        }

        return null;
    }

    /**
     * Извлечь ID операции из текста чека
     */
    public static function extractOperationId(string $text): ?string
    {
        // Различные форматы ID операции
        $patterns = [
            // Сбербанк: "Идентификатор операции AG0331337307571E00000100116"
            '/идентификатор\s+операции\s*[:\s]*([A-Z0-9]{20,40})/ui',
            // Номер квитанции: "Квитанция № 1-121-187-963-592"
            '/квитанция\s*[№#]?\s*([0-9\-]{10,30})/ui',
            // Номер операции: "Номер операции: 123456789"
            '/номер\s+операции\s*[:\s]*([0-9A-Z\-]{8,40})/ui',
            // Код авторизации
            '/код\s+авториза[ц]ии\s*[:\s]*([0-9A-Z]{6,12})/ui',
            // РРН (Reference Retrieval Number)
            '/rrn\s*[:\s]*([0-9]{12})/ui',
            // Уникальный номер транзакции
            '/транзакц[ия]+\s*[:\s№#]*([0-9A-Z\-]{10,40})/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Сгенерировать уникальный ключ на основе суммы и даты
     */
    public static function generateUniqueKey(?float $amount, ?string $date): ?string
    {
        if (!$amount || !$date) {
            return null;
        }

        // Формат: сумма_дата (например: 10000_2026-02-02_16:37)
        // Используем минуты для точности, но не секунды (могут отличаться)
        $dateFormatted = date('Y-m-d_H:i', strtotime($date));
        return sprintf('%.2f_%s', $amount, $dateFormatted);
    }

    /**
     * Вычислить хеш файла
     */
    public static function calculateFileHash(string $filePath): string
    {
        return hash_file('sha256', $filePath);
    }

    /**
     * Scope для не-дубликатов
     */
    public function scopeNotDuplicate($query)
    {
        return $query->where('is_duplicate', false);
    }

    /**
     * Scope для дубликатов
     */
    public function scopeDuplicates($query)
    {
        return $query->where('is_duplicate', true);
    }
}
