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
    private const AMOUNT_KEYWORDS = [
        'итого', 'сумма', 'всего', 'оплачено', 'списано', 'к оплате', 'исполнено',
        'сумма перевода', 'сумма операции', 'перевод на карту',
    ];

    /** Слова в ~25 символов ПЕРЕД числом — это число НЕ сумма (комиссия, остаток и т.д.) */
    private const BAD_AMOUNT_PREFIX = [
        'комиссия', 'комисс', 'сбор за', 'сбор:', 'остаток', 'в т.ч.', 'в т ч',
        'включая комиссию', 'в том числе', 'платеж за услуг', 'счет', 'счёт',
        'номер кошелька', 'кошелька', 'номер карты', 'карты',
    ];

    /** Число — часть номера карты, если в 15 символах после него есть **** */
    private const CARD_LOOKALIKE_SUFFIX_LEN = 15;

    /** Слова в полном контексте — отбрасываем совпадение (номера карт, ID) */
    private const BAD_AMOUNT_CONTEXT = [
        'идентификатор', 'инн', 'бик', 'кпп', 'авторизац',
    ];

    private const PREFIX_CHECK_LEN = 28;

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

        // Ищем пары "ключевое_слово" + число: извлекаем ВСЕ числа в окне 80 символов после ключа
        $textOneLine = preg_replace('/\s+/u', ' ', $this->text);
        foreach (self::AMOUNT_KEYWORDS as $kw) {
            $kwPattern = '/' . preg_quote($kw, '/') . '/ui';
            if (!preg_match_all($kwPattern, $textOneLine, $kwMatches, PREG_OFFSET_CAPTURE)) continue;

            foreach ($kwMatches[0] as [$kwMatch, $kwPos]) {
                $window = mb_substr($textOneLine, $kwPos, 120, 'UTF-8');
                $context = mb_strtolower($window, 'UTF-8');
                if ($this->hasAnyKeyword($context, self::BAD_AMOUNT_CONTEXT)) continue;
                if (in_array($kw, ['итого', 'всего']) && (str_contains($context, 'комиссия') || str_contains($context, 'в т.ч.') || str_contains($context, 'в т ч'))) continue;

                $numPattern = '/' . self::AMOUNT_REGEX . '\s*[₽РрPpруб\.р]?/ui';
                if (preg_match_all($numPattern, $window, $numM, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    foreach ($numM as $numMatch) {
                        $numStr = $numMatch[1][0];
                        $numPos = $kwPos + $numMatch[1][1];
                        $fullMatch = $numMatch[0][0];
                        if ($this->hasBadPrefix($textOneLine, $numPos, $numStr)) continue;
                        if ($this->looksLikeCardNumber($textOneLine, $numPos, $numStr)) continue;

                        $val = $this->normalizeAndValidateAmount($numStr);
                        if ($val !== null) {
                            $this->amountFoundByKeyword = true;
                            $hasCurrency = (bool) preg_match('/[₽Ррруб]\s*$/u', $fullMatch);
                            $foundByKeyword[] = [
                                'amount' => $val,
                                'keyword' => $kw,
                                'priority' => $keywordPriority[$kw] ?? 99,
                                'hasCurrency' => $hasCurrency,
                            ];
                        }
                    }
                }
            }
        }

        // По строкам (перевод на карту, сумма — когда ключ и число в одной строке)
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
            // Строка "итого X в т.ч. комиссия Y" — отбрасываем целиком, берём сумму из другой строки
            $isTotalWithCommission = in_array($usedKeyword, ['итого', 'всего'])
                && (str_contains($lineLower, 'комиссия') || str_contains($lineLower, 'в т.ч.') || str_contains($lineLower, 'в т ч'));
            if ($isTotalWithCommission) continue;

            $pattern = '/' . self::AMOUNT_REGEX . '\s*[₽РрPpруб\.р]?/ui';
            if (preg_match_all($pattern, $line, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $match) {
                    $numStr = $match[1][0];
                    $numPos = $match[1][1];
                    $fullMatch = $match[0][0];
                    if ($this->hasBadPrefix($line, $numPos, $numStr)) continue;
                    if ($this->looksLikeCardNumber($line, $numPos, $numStr)) continue;

                    $val = $this->normalizeAndValidateAmount($numStr);
                    if ($val !== null) {
                        $this->amountFoundByKeyword = true;
                        $hasCurrency = (bool) preg_match('/[₽Ррруб]\s*$/u', $fullMatch);
                        $foundByKeyword[] = [
                            'amount' => $val,
                            'keyword' => $usedKeyword,
                            'priority' => $keywordPriority[$usedKeyword] ?? 99,
                            'hasCurrency' => $hasCurrency,
                        ];
                    }
                }
            }
        }

        if (empty($foundByKeyword)) {
            return null;
        }

        // Если в документе есть комиссия — приоритет "сумма перевода"/"оплачено"/"списано" над "итого"/"всего"
        $hasCommission = str_contains($this->textLower, 'комиссия') || str_contains($this->textLower, 'комисс');
        $baseKeywords = ['сумма перевода', 'оплачено', 'списано', 'сумма операции', 'перевод на карту'];
        $totalKeywords = ['итого', 'всего'];

        usort($foundByKeyword, function ($a, $b) use ($hasCommission, $baseKeywords, $totalKeywords) {
            if ($hasCommission) {
                $aBase = in_array($a['keyword'], $baseKeywords);
                $bBase = in_array($b['keyword'], $baseKeywords);
                $aTotal = in_array($a['keyword'], $totalKeywords);
                $bTotal = in_array($b['keyword'], $totalKeywords);
                if ($aBase && $bTotal) return -1;
                if ($aTotal && $bBase) return 1;
            }
            $aCur = $a['hasCurrency'] ?? false;
            $bCur = $b['hasCurrency'] ?? false;
            if ($bCur !== $aCur) return $bCur ? 1 : -1;
            $p = $a['priority'] <=> $b['priority'];
            if ($p !== 0) return $p;
            return $b['amount'] <=> $a['amount'];
        });

        $best = $foundByKeyword[0];
        $uniqueAmounts = array_unique(array_map(fn($x) => $x['amount'], $foundByKeyword));

        // Отклонение: если 4+ разных сумм и выбранная — наименьшая (вероятно комиссия/остаток)
        if (count($uniqueAmounts) >= 4 && $best['amount'] === min($uniqueAmounts)) {
            return null;
        }

        return $best['amount'];
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
     * 5. Определение банка (bank_code для расширения логики по банкам).
     */
    private function extractBank(): ?string
    {
        $banks = config('bank_checks.banks', []);
        $order = config('bank_checks.detection_order', ['sber', 'tinkoff', 'alfabank', 'default']);

        foreach ($order as $code) {
            if ($code === 'default') continue;
            $bank = $banks[$code] ?? null;
            if (!$bank || empty($bank['detect_keywords'])) continue;

            foreach ($bank['detect_keywords'] as $kw) {
                if (str_contains($this->textLower, mb_strtolower($kw, 'UTF-8'))) {
                    return $code;
                }
            }
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

    /** Число — часть номера карты/кошелька, если после него ** или сразу идут цифры (220 в 22022037...) */
    private function looksLikeCardNumber(string $text, int $numPos, string $numStr): bool
    {
        $afterStart = $numPos + strlen($numStr);
        $suffix = mb_substr($text, $afterStart, self::CARD_LOOKALIKE_SUFFIX_LEN, 'UTF-8');
        if (str_contains($suffix, '**')) return true;
        if (strlen($numStr) <= 3 && $suffix !== '' && preg_match('/^\d/', $suffix)) {
            return true;
        }
        return false;
    }

    /** Число в плохом контексте (комиссия, остаток и т.д.) — в ~25 символах перед числом */
    private function hasBadPrefix(string $text, int $numPos, string $numStr): bool
    {
        $start = max(0, $numPos - self::PREFIX_CHECK_LEN);
        $prefix = mb_substr($text, $start, $numPos - $start, 'UTF-8');
        $prefixLower = mb_strtolower($prefix, 'UTF-8');
        return $this->hasAnyKeyword($prefixLower, self::BAD_AMOUNT_PREFIX);
    }
}
