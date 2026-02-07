<?php

namespace App\Console\Commands;

use App\Services\ReceiptParser;
use App\Services\ReceiptTextPreprocessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class AnalyzePdfsCommand extends Command
{
    protected $signature = 'checks:analyze-pdfs 
                            {path : Путь к папке с PDF (локальный или на сервере)} 
                            {--output= : Сохранить отчёт в файл JSON}
                            {--debug : Включить original_text в отчёт для отладки}';

    protected $description = 'Извлечь текст из PDF чеков, определить банк и показать для настройки regex';

    public function handle(): int
    {
        $path = $this->argument('path');
        $outputPath = $this->option('output');
        $debug = $this->option('debug');

        if (!is_dir($path)) {
            $this->error("Папка не найдена: {$path}");
            return self::FAILURE;
        }

        $pdfs = glob(rtrim($path, '/\\') . '/*.pdf');
        if (empty($pdfs)) {
            $this->warn('PDF файлы не найдены в папке.');
            return self::SUCCESS;
        }

        $banks = Config::get('bank_checks.banks', []);
        $detectionOrder = Config::get('bank_checks.detection_order', []);
        $filenameHints = Config::get('bank_checks.filename_hints', []);

        $results = [];
        $this->info('Анализ ' . count($pdfs) . ' PDF файлов...');
        $this->newLine();

        foreach ($pdfs as $pdfPath) {
            $fileName = basename($pdfPath);
            $text = $this->extractTextFromPdf($pdfPath);

            if ($text === null) {
                $this->warn("  [{$fileName}] Не удалось извлечь текст (pdftotext нужен на сервере)");
                $results[] = [
                    'file' => $fileName,
                    'bank' => null,
                    'text_preview' => null,
                    'error' => 'pdftotext not available or failed',
                ];
                continue;
            }

            // Предобработка: регистр, пробелы, OCR-ошибки
            $preprocessor = new ReceiptTextPreprocessor();
            $textProcessed = $preprocessor->preprocess($text);

            $bank = $this->detectBank($textProcessed, $fileName, $banks, $detectionOrder, $filenameHints);

            $parser = new ReceiptParser($text);
            $parsed = $parser->parse();

            $resultItem = [
                'file' => $fileName,
                'bank' => $bank,
                'amount' => $parsed['amount'] ?? null,
                'date' => $parsed['date'] ?? null,
                'confidence' => $parsed['parsing_confidence'] ?? null,
                'text_preview' => mb_substr($textProcessed, 0, 800),
                'text_length' => mb_strlen($textProcessed),
            ];
            if ($debug) {
                $resultItem['original_text'] = $text;
            }
            $results[] = $resultItem;

            $this->line("  [{$fileName}]");
            $this->line("    Банк: " . ($banks[$bank]['name'] ?? $bank));
            $this->line("    Сумма: " . ($parsed['amount'] ? number_format($parsed['amount'], 2) . ' ₽' : '—'));
            $this->line("    Дата: " . ($parsed['date'] ?? '—'));
            $this->line("    Confidence: " . ($parsed['parsing_confidence'] ?? '—'));
            $this->line("    Текст: " . mb_strlen($textProcessed) . " символов");
            $this->line("    Превью: " . mb_substr(preg_replace('/\s+/', ' ', $textProcessed), 0, 150) . '...');
            $this->newLine();
        }

        if ($outputPath) {
            file_put_contents($outputPath, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->info("Отчёт сохранён в: {$outputPath}");
        }

        return self::SUCCESS;
    }

    private function extractTextFromPdf(string $pdfPath): ?string
    {
        if (!file_exists($pdfPath) || !is_readable($pdfPath)) {
            return null;
        }
        $fullPath = realpath($pdfPath);
        $escaped = escapeshellarg($fullPath);
        $command = "pdftotext -layout -enc UTF-8 {$escaped} - 2>" . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $output = @shell_exec($command);
            $text = $output ? trim($output) : '';
            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function detectBank(string $textLower, string $fileName, array $banks, array $order, array $filenameHints): string
    {
        $fileNameLower = mb_strtolower($fileName, 'UTF-8');

        // 1. Сначала проверяем имя файла — приоритет над текстом (ozonbank_document_*, Документ по операции_*)
        foreach ($order as $bankId) {
            if ($bankId === 'default') {
                continue;
            }
            $hints = $filenameHints[$bankId] ?? [];
            foreach ($hints as $hint) {
                if (str_contains($fileNameLower, mb_strtolower($hint, 'UTF-8'))) {
                    return $bankId;
                }
            }
        }

        // 2. По ключевым словам в тексте
        foreach ($order as $bankId) {
            if ($bankId === 'default') {
                continue;
            }
            $config = $banks[$bankId] ?? [];
            $keywords = $config['detect_keywords'] ?? [];
            foreach ($keywords as $kw) {
                if (str_contains($textLower, mb_strtolower($kw, 'UTF-8'))) {
                    return $bankId;
                }
            }
        }

        return 'default';
    }
}
