<?php

namespace App\Services;

/**
 * Предобработка текста чека перед анализом.
 * Исправляет OCR-ошибки, нормализует пробелы, сохраняет исходник для отладки.
 */
class ReceiptTextPreprocessor
{
    /** Исходный текст (для отладки) */
    public ?string $originalText = null;

    /** Обработанный текст */
    public ?string $processedText = null;

    public function preprocess(string $text): string
    {
        $this->originalText = $text;

        $t = $text;

        // 1. Нормализация переносов строк
        $t = preg_replace('/\r\n|\r/', "\n", $t);

        // 2. Замена нестандартных пробелов на обычный
        $t = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]/u', ' ', $t);
        $t = preg_replace('/[ \t]+/', ' ', $t);

        // 3. Приведение к нижнему регистру (для детекции банка и поиска ключевых слов)
        // Сохраняем отдельную копию без lower для regex сумм/дат — числа лучше искать в оригинале
        $t = mb_strtolower($t, 'UTF-8');

        // 4. OCR: "О/o/O" → "0" рядом с цифрами (10 ооо ₽ → 10 000 ₽, 1ооо → 1000)
        $t = preg_replace_callback(
            '/(\d)(\s*)([ОоOo]+)(\s*[₽рруб\d]|$)/u',
            static function ($m) {
                $count = preg_match_all('/[ОоOo]/u', $m[3]);
                return $m[1] . $m[2] . str_repeat('0', $count) . $m[4];
            },
            $t
        );
        $t = preg_replace('/(\d)[ОоOo]/u', '${1}0', $t);
        $t = preg_replace('/[ОоOo](\d)/u', '0${1}', $t);

        // 5. OCR: "l" → "1" рядом с цифрами (10l0 → 1010)
        $t = preg_replace('/(\d)l/u', '${1}1', $t);
        $t = preg_replace('/l(\d)/u', '1${1}', $t);

        // 6. OCR: "," → "." в числах (10,50 → 10.50)
        $t = preg_replace('/(\d),(\d{2})(?:\D|$)/u', '${1}.${2}', $t);

        $this->processedText = $t;

        return $t;
    }

    /**
     * Предобработка для извлечения чисел (сумма, дата).
     * Сохраняет регистр — regex для сумм часто чувствительны к контексту.
     */
    public function preprocessForNumbers(string $text): string
    {
        $t = preg_replace('/\r\n|\r/', "\n", $text);
        $t = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]/u', ' ', $t);
        $t = preg_replace('/[ \t]+/', ' ', $t);

        // OCR: О → 0 в числовом контексте
        $t = preg_replace_callback(
            '/(\d)(\s*)([ОоOo]+)(\s*[₽рРруб\d]|$)/u',
            static function ($m) {
                $count = preg_match_all('/[ОоOo]/u', $m[3]);
                return $m[1] . $m[2] . str_repeat('0', $count) . $m[4];
            },
            $t
        );
        $t = preg_replace('/(\d)[ОоOo](?=\d)/u', '${1}0', $t);
        $t = preg_replace('/(?<=\d)[ОоOo](\d)/u', '0${1}', $t);

        $t = preg_replace('/(\d)l(?=\d)/u', '${1}1', $t);
        $t = preg_replace('/(?<=\d)l(\d)/u', '1${1}', $t);
        $t = preg_replace('/(\d),(\d{2})(?:\D|$)/u', '${1}.${2}', $t);

        return $t;
    }
}
