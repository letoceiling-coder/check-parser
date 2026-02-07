<?php

namespace App\Services;


/**
 * Парсер чеков с контекстной логикой.
 * 3. Дата: все кандидаты → фильтр по контексту → приоритеты.
 * 4. Сумма: только в строках с ключевыми словами → исключения → приоритеты.
 * 5. Банк (опционально).
 * 6. Confidence score.
 */
class ReceiptParser
{
    private const YEAR_MIN = 2018;

    private string $text;
    private string $textLower;
    private int $textLength;
    private ?int $dateCandidatesCount = null;
    private ?bool $amountFoundByKeyword = null;

    /** Ключевые слова для поиска сумм (порядок приоритета) */
    private const AMOUNT_KEYWORDS = ['итого', 'сумма', 'всего', 'оплачено', 'списано', 'к оплате', 'исполнено', 'сумма перевода'];

    /** Слова рядом с которыми число НЕ считается суммой */
    private const BAD_AMOUNT_CONTEXT = [
        'комиссия', 'счет', 'счёт', 'телефон', 'идентификатор',
        '****', 'инн', 'бик', 'кпп', 'остаток', 'авторизац',
    ];

    /** Паттерны дат (DD.MM.YYYY, DD/MM/YYYY, DD-MM-YYYY, 2-значный год) */
    private const DATE_PATTERNS = [
        '/(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?/u',
        '/(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{4})\s+(\d{1,2}):(\d{2})/u',
        '/(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{4})/u',
        '/(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{2})(?:\s+(\d{1,2}):(\d{2}))?/u', // DD.MM.YY
        '/(\d{4})[\.\/\-](\d{2})[\.\/\-](\d{2})\s+(\d{2}):(\d{2})/u', // YYYY-MM-DD
    ];

    /** Контекстные слова для валидности даты */
    private const DATE_CONTEXT_KEYWORDS = ['дата', 'операции', 'операци', 'время', 'чек', 'операция'];

    /** Regex числа (с пробелами в тысячах, дробная часть) */
    private const AMOUNT_REGEX = '(\d{1,3}(?:[\s\x{00A0}]\d{3})*(?:[.,]\d{2})?|\d{1,3}(?:\.\d{3})+(?:,\d{2})?|\d+(?:[.,]\d{2})?)';

    public function __construct(string $text)
    {
        $preprocessor = new ReceiptTextPreprocessor();
        $this->text = $preprocessor->preprocessForNumbers($text);
        $this->textLower = mb_strtolower($this->text, 'UTF-8');
        $this->textLength = mb_strlen($this->text);
    }

    public function parse(): array
    {
        $date = $this->extractDate();
        $amount = $this->extractAmount();
        $bankCode = $this->extractBank();
        $confidence = $this->calculateConfidence($date, $amount);

        $result = [
            'date' => $date,
            'amount' => $amount,
            'sum' => $amount,
            'currency' => 'RUB',
            'bank_code' => $bankCode,
            'parsing_confidence' => $confidence,
            'raw_text' => mb_substr($this->text, 0, 500),
        ];
        return array_filter($result, static fn($v) => $v !== null);
    }

    /**
     * 3. Определение даты: все кандидаты → контекстная фильтрация → приоритеты.
     */
    private function extractDate(): ?string
    {
        $candidates = $this->findDateCandidates();
        $this->dateCandidatesCount = count($candidates);

        if (empty($candidates)) {
            return $this->tryRussianMonthDate();
        }

        // Фильтр: валидна если в первых 20% ИЛИ рядом контекст ИЛИ есть время
        $valid = array_filter($candidates, fn($c) => $this->isDateValidByContext($c));

        if (empty($valid)) {
            $valid = $candidates;
        }

        // Приоритет: 1) выше в тексте, 2) с временем, 3) рядом "дата"
        usort($valid, function ($a, $b) {
            $pos = $a['pos'] <=> $b['pos'];
            if ($pos !== 0) return $pos;
            $hasTimeA = isset($a['time']) && $a['time'] !== '' ? 1 : 0;
            $hasTimeB = isset($b['time']) && $b['time'] !== '' ? 1 : 0;
            if ($hasTimeB !== $hasTimeA) return $hasTimeB - $hasTimeA;
            return ($b['has_data_kw'] ? 1 : 0) - ($a['has_data_kw'] ? 1 : 0);
        });

        $best = $valid[0];
        return $best['normalized'] . ($best['time'] ?? '');
    }

    private function findDateCandidates(): array
    {
        $candidates = [];
        $seen = [];
        $currentYear = (int) date('Y');

        $patterns = [
            ['/(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?/u', 1, 2, 3, 4, 5, 6],
            ['/(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{4})\s+(\d{1,2}):(\d{2})/u', 1, 2, 3, 4, 5, null],
            ['/(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{4})/u', 1, 2, 3, null, null, null],
            ['/(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{2})(?:\s+(\d{1,2}):(\d{2}))?/u', 1, 2, 3, 4, 5, null],
            ['/(\d{4})[\.\/\-](\d{2})[\.\/\-](\d{2})\s+(\d{2}):(\d{2})/u', 3, 2, 1, 4, 5, null],
        ];

        foreach ($patterns as [$pattern, $gDay, $gMonth, $gYear, $gH, $gM, $gS]) {
            if (preg_match_all($pattern, $this->text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($matches as $m) {
                    $d = (int) $m[$gDay][0];
                    $month = (int) $m[$gMonth][0];
                    $y = (int) $m[$gYear][0];
                    if (strlen((string) $y) === 2) $y = 2000 + $y;

                    if ($d < 1 || $d > 31 || $month < 1 || $month > 12 || $y < self::YEAR_MIN || $y > $currentYear) continue;

                    $key = "{$d}-{$month}-{$y}";
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;

                    $normalized = sprintf('%04d-%02d-%02d', $y, $month, $d);
                    $pos = $m[0][1];
                    $snippet = mb_substr($this->textLower, max(0, $pos - 80), 160);
                    $time = null;
                    if ($gH !== null && isset($m[$gH], $m[$gM])) {
                        $time = sprintf(' %02d:%02d', (int) $m[$gH][0], (int) $m[$gM][0]);
                        if ($gS !== null && isset($m[$gS])) {
                            $time .= ':' . str_pad((int) $m[$gS][0], 2, '0', STR_PAD_LEFT);
                        }
                    }

                    $candidates[] = [
                        'normalized' => $normalized,
                        'pos' => $pos,
                        'time' => $time,
                        'snippet' => $snippet,
                        'has_data_kw' => $this->hasAnyKeyword($snippet, self::DATE_CONTEXT_KEYWORDS),
                        'has_time' => preg_match('/\d{1,2}:\d{2}/', $snippet),
                        'in_first_20pct' => $pos < $this->textLength * 0.2,
                    ];
                }
            }
        }

        return $candidates;
    }

    private function isDateValidByContext(array $c): bool
    {
        return $c['in_first_20pct']
            || $c['has_data_kw']
            || $c['has_time'];
    }

    private function tryRussianMonthDate(): ?string
    {
        $months = [
            'января' => '01', 'февраля' => '02', 'марта' => '03', 'апреля' => '04',
            'мая' => '05', 'июня' => '06', 'июля' => '07', 'августа' => '08',
            'сентября' => '09', 'октября' => '10', 'ноября' => '11', 'декабря' => '12',
        ];
        $pat = implode('|', array_keys($months));
        if (preg_match('/(\d{1,2})\s+(' . $pat . ')\s+(\d{4})(?:\s+(?:в\s+)?(\d{1,2}):(\d{2}))?/ui', $this->text, $m)) {
            $y = (int) $m[3];
            if ($y >= self::YEAR_MIN && $y <= (int) date('Y')) {
                $date = sprintf('%04d-%02d-%02d', $y, $months[mb_strtolower($m[2], 'UTF-8')], str_pad($m[1], 2, '0', STR_PAD_LEFT));
                if (isset($m[4], $m[5])) {
                    $date .= sprintf(' %02d:%02d', (int) $m[4], (int) $m[5]);
                }
                return $date;
            }
        }
        return null;
    }

    /**
     * 4. Определение суммы: только в строках с ключевыми словами, с исключениями.
     */
    private function extractAmount(): ?float
    {
        $foundByKeyword = [];
        $keywordPriority = array_flip(self::AMOUNT_KEYWORDS);
        $this->amountFoundByKeyword = false;

        // Ищем пары "ключевое_слово" + число в пределах 60 символов (в т.ч. через перенос)
        $textOneLine = preg_replace('/\s+/u', ' ', $this->text);
        foreach (self::AMOUNT_KEYWORDS as $kw) {
            $pattern = '/' . preg_quote($kw, '/') . '[^\d]{0,60}' . self::AMOUNT_REGEX . '\s*[₽РрPpруб\.]?/ui';
            if (preg_match_all($pattern, $textOneLine, $m)) {
                foreach ($m[1] as $numStr) {
                    $context = mb_strtolower($m[0], 'UTF-8');
                    if ($this->hasAnyKeyword($context, self::BAD_AMOUNT_CONTEXT)) continue;
                    if (str_contains($context, '****')) continue;

                    $val = $this->normalizeAndValidateAmount($numStr);
                    if ($val !== null) {
                        $this->amountFoundByKeyword = true;
                        $foundByKeyword[] = [
                            'amount' => $val,
                            'keyword' => $kw,
                            'priority' => $keywordPriority[$kw] ?? 99,
                        ];
                    }
                }
            }
        }

        // Дополнительно: по строкам (для случаев когда ключ и число на одной строке)
        $lines = preg_split('/\r\n|\r|\n/', $this->text);
        foreach ($lines as $line) {
            $lineLower = mb_strtolower($line, 'UTF-8');
            $usedKeyword = null;
            foreach (self::AMOUNT_KEYWORDS as $kw) {
                if (str_contains($lineLower, $kw)) {
                    $usedKeyword = $kw;
                    break;
                }
            }
            if ($usedKeyword === null) continue;
            if ($this->hasAnyKeyword($lineLower, self::BAD_AMOUNT_CONTEXT)) continue;
            if (str_contains($lineLower, '****')) continue;

            $pattern = '/' . self::AMOUNT_REGEX . '\s*[₽РрPpруб\.]?/ui';
            if (preg_match_all($pattern, $line, $m)) {
                foreach ($m[1] as $numStr) {
                    $val = $this->normalizeAndValidateAmount($numStr);
                    if ($val !== null) {
                        $this->amountFoundByKeyword = true;
                        $foundByKeyword[] = [
                            'amount' => $val,
                            'keyword' => $usedKeyword,
                            'priority' => $keywordPriority[$usedKeyword] ?? 99,
                        ];
                    }
                }
            }
        }

        if (empty($foundByKeyword)) {
            return null;
        }

        // Приоритет: итого > сумма > всего > оплачено/списано > самая большая
        usort($foundByKeyword, function ($a, $b) {
            $p = $a['priority'] <=> $b['priority'];
            if ($p !== 0) return $p;
            return $b['amount'] <=> $a['amount'];
        });

        return $foundByKeyword[0]['amount'];
    }

    private function normalizeAndValidateAmount(string $numStr): ?float
    {
        $s = preg_replace('/[\s\x{00A0}]+/u', '', $numStr);
        $s = str_replace(',', '.', $s);
        $s = preg_replace('/\.(?=.*\.)/', '', $s);
        if (!is_numeric($s)) return null;

        $val = (float) $s;
        if ($val <= 0) return null;
        if (strlen($s) > 10) return null;

        if ($val < 1 || $val > 10000000) return null;

        return $val;
    }

    /**
     * 5. Определение банка.
     */
    private function extractBank(): ?string
    {
        if (str_contains($this->textLower, 'сбербанк') || str_contains($this->textLower, 'сбер ')) {
            return 'sber';
        }
        if (str_contains($this->textLower, 'тинькофф') || str_contains($this->textLower, 'т-банк')) {
            return 'tinkoff';
        }
        if (str_contains($this->textLower, 'альфа') || str_contains($this->textLower, 'alfabank')) {
            return 'alfabank';
        }
        return null;
    }

    /**
     * 6. Confidence score.
     * +0.4 дата по контексту, +0.4 сумма по ключевому слову, +0.2 единственная дата и сумма.
     * Если несколько кандидатов → confidence < 0.7.
     */
    private function calculateConfidence(?string $date, ?float $amount): float
    {
        $score = 0.0;

        if ($date !== null) {
            $score += 0.4; // дата найдена по контексту
        }
        if ($amount !== null && $this->amountFoundByKeyword) {
            $score += 0.4; // сумма по ключевому слову
        } elseif ($amount !== null) {
            $score += 0.2;
        }
        if ($date !== null && $amount !== null && ($this->dateCandidatesCount ?? 0) <= 1) {
            $score += 0.2; // единственная дата и сумма
        }

        $confidence = min(1.0, round($score, 2));
        if (($this->dateCandidatesCount ?? 0) > 1 && $confidence >= 0.7) {
            $confidence = 0.65; // несколько кандидатов дат
        }
        return $confidence;
    }

    private function hasAnyKeyword(string $text, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) return true;
        }
        return false;
    }
}
