<?php

namespace App\Services\Receipt;

/**
 * DTO результата AI-извлечения данных из чека.
 */
final class AIReceiptExtractorResult
{
    public function __construct(
        public readonly ?float $amount,
        public readonly ?string $date,
        public readonly string $currency,
        public readonly float $confidence,
        public readonly string $source = 'ai',
    ) {
    }

    public static function empty(): self
    {
        return new self(null, null, 'RUB', 0.0);
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'date' => $this->date,
            'sum' => $this->amount,
            'currency' => $this->currency,
            'parsing_confidence' => $this->confidence,
            'source' => $this->source,
        ];
    }

    public function isValid(): bool
    {
        return $this->confidence >= 0.85
            && ($this->amount !== null || $this->date !== null);
    }
}
