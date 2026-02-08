<?php

namespace App\Services\Receipt;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Извлечение суммы и даты из текста чека через LLM (fallback при низкой уверенности OCR).
 * Вызывается только при необходимости, результат валидируется.
 */
class AIReceiptExtractor
{
    private const SYSTEM_PROMPT = 'Ты — специализированный финансовый парсер банковских чеков Российской Федерации. Твоя задача — точно и осторожно извлекать итоговую оплаченную сумму и дату операции из текста чека. Ты НЕ ассистент и НЕ объясняешь решения. Ты возвращаешь ТОЛЬКО валидный JSON, строго по заданной схеме. Любой текст вне JSON запрещён.';

    private const USER_PROMPT_TEMPLATE = <<<'TEXT'
Проанализируй текст банковского чека ниже и извлеки итоговую оплаченную сумму и дату операции.

ПРАВИЛА:
1. Итоговая сумма — это фактически списанная сумма, а не комиссия, бонусы или предварительный расчёт.
2. Если в чеке несколько сумм, приоритет имеют поля: «Итого», «Списано», «Оплачено», «Сумма операции».
3. Если присутствует НДС — он НЕ является итоговой суммой.
4. Дата операции — это дата проведения платежа, а не дата формирования чека.
5. Время операции игнорируй.
6. Если данные противоречивы или неочевидны — верни null.
7. Оцени уверенность извлечения (confidence) от 0 до 1.

ТЕКСТ ЧЕКА:
<<<
{{CHECK_TEXT}}
>>>

ФОРМАТ ОТВЕТА (СТРОГО):
{
  "amount": number | null,
  "date": "YYYY-MM-DD" | null,
  "currency": "RUB",
  "confidence": number
}
TEXT;

    private const MAX_TEXT_LENGTH = 6000;
    private const LOG_TEXT_LENGTH = 2000;

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly int $timeout,
        private readonly array $validation,
    ) {
    }

    public static function fromConfig(): self
    {
        $config = config('receipt_ai', []);
        return new self(
            apiUrl: $config['api_url'] ?? '',
            apiKey: $config['api_key'] ?? '',
            model: $config['model'] ?? 'gpt-4o-mini',
            maxTokens: $config['max_tokens'] ?? 256,
            timeout: $config['timeout'] ?? 15,
            validation: $config['validation'] ?? [],
        );
    }

    /**
     * Извлечь сумму и дату из текста чека через LLM.
     *
     * @param  array{bank_hint?: string, previous_amount?: float, previous_date?: string}  $context
     */
    public function extract(string $rawText, array $context = []): AIReceiptExtractorResult
    {
        $text = mb_substr(trim($rawText), 0, self::MAX_TEXT_LENGTH, 'UTF-8');
        if (mb_strlen($text, 'UTF-8') < 50) {
            Log::debug('AIReceiptExtractor: text too short', ['length' => mb_strlen($text, 'UTF-8')]);
            return AIReceiptExtractorResult::empty();
        }

        $userContent = str_replace('{{CHECK_TEXT}}', $text, self::USER_PROMPT_TEMPLATE);

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userContent],
            ],
            'temperature' => 0.1,
        ];

        $logPreview = mb_substr($text, 0, self::LOG_TEXT_LENGTH, 'UTF-8');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post($this->apiUrl, $payload);

            if (!$response->successful()) {
                Log::warning('AIReceiptExtractor: API error', [
                    'status' => $response->status(),
                    'body_preview' => mb_substr((string) $response->body(), 0, 200),
                ]);
                return AIReceiptExtractorResult::empty();
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? '';
            $parsed = $this->parseJsonResponse($content);

            if ($parsed === null) {
                Log::warning('AIReceiptExtractor: invalid or empty JSON', [
                    'raw_preview' => mb_substr($content, 0, 300, 'UTF-8'),
                ]);
                return AIReceiptExtractorResult::empty();
            }

            $result = $this->buildAndValidateResult($parsed);

            Log::info('AIReceiptExtractor: result', [
                'amount' => $result->amount,
                'date' => $result->date,
                'confidence' => $result->confidence,
                'valid' => $result->isValid(),
                'text_length' => mb_strlen($text, 'UTF-8'),
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('AIReceiptExtractor: exception', [
                'message' => $e->getMessage(),
                'text_preview_length' => mb_strlen($logPreview, 'UTF-8'),
            ]);
            return AIReceiptExtractorResult::empty();
        }
    }

    private function parseJsonResponse(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content);
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    private function buildAndValidateResult(array $parsed): AIReceiptExtractorResult
    {
        $amount = isset($parsed['amount']) && $parsed['amount'] !== null
            ? round((float) $parsed['amount'], 2)
            : null;
        $date = isset($parsed['date']) && is_string($parsed['date']) && $parsed['date'] !== ''
            ? $this->normalizeDate($parsed['date'])
            : null;
        $confidence = isset($parsed['confidence'])
            ? min(1.0, max(0.0, (float) $parsed['confidence']))
            : 0.0;
        $currency = isset($parsed['currency']) ? (string) $parsed['currency'] : 'RUB';

        $amountValid = $this->validateAmount($amount);
        $dateValid = $this->validateDate($date);

        if ($amount !== null && !$amountValid) {
            $amount = null;
        }
        if ($date !== null && !$dateValid) {
            $date = null;
        }

        return new AIReceiptExtractorResult(
            amount: $amount,
            date: $date,
            currency: $currency,
            confidence: $confidence,
            source: 'ai',
        );
    }

    private function validateAmount(?float $amount): bool
    {
        if ($amount === null) {
            return true;
        }
        $min = $this->validation['amount_min'] ?? 0;
        $max = $this->validation['amount_max'] ?? 10_000_000;
        return $amount > $min && $amount <= $max;
    }

    private function validateDate(?string $date): bool
    {
        if ($date === null || $date === '') {
            return true;
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return false;
        }
        $now = time();
        if ($ts > $now) {
            return false;
        }
        $yearsAgo = $this->validation['date_max_years_ago'] ?? 2;
        if ($ts < strtotime("-{$yearsAgo} years")) {
            return false;
        }
        return true;
    }

    private function normalizeDate(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        return date('Y-m-d', $ts);
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl !== '' && $this->apiKey !== '';
    }
}
