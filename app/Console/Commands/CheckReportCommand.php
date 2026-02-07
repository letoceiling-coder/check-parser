<?php

namespace App\Console\Commands;

use App\Models\BotSettings;
use App\Models\Check;
use App\Models\TelegramBot;
use App\Services\ReceiptParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Отчёт по всем чекам: raw_text, ReceiptParser, причины несовпадения.
 */
class CheckReportCommand extends Command
{
    protected $signature = 'checks:report {--id= : ID конкретного чека} {--parser : Показать текущий receipt_parser_method на сервере}';
    protected $description = 'Отчёт по чекам: метод определения, причины несовпадения суммы';

    public function handle(): int
    {
        if ($this->option('parser')) {
            $this->showParserMethod();
            return self::SUCCESS;
        }

        $id = $this->option('id');
        $checks = $id
            ? Check::where('id', $id)->get()
            : Check::orderBy('id')->get();

        if ($checks->isEmpty()) {
            $this->warn('Чеки не найдены.');
            return self::FAILURE;
        }

        $noAmount = 0;
        foreach ($checks as $check) {
            $row = $this->analyzeCheck($check);
            if (!$check->amount && !$check->corrected_amount) $noAmount++;

            $this->line("--- Чек #{$check->id} ---");
            $this->line("  Сумма в БД: " . ($check->corrected_amount ?? $check->amount ?? 'null') . " | Дата: " . ($check->corrected_date ?? $check->check_date ?? 'null'));
            $this->line("  OCR: " . ($check->ocr_method ?? 'null') . " | Файл: " . ($check->file_path ?? 'null'));
            $this->line("  ReceiptParser (по raw_text): сумма=" . ($row['parser_amount'] ?? 'null') . ", дата=" . ($row['parser_date'] ?? 'null'));
            $this->line("  Причина: " . $row['reason']);
            $this->line("  raw_text (150 символов): " . mb_substr($check->raw_text ?? 'null', 0, 150) . '...');
            $this->newLine();
        }

        $this->info('=== ИТОГО ===');
        $this->line("Без суммы: {$noAmount} из " . $checks->count());

        return self::SUCCESS;
    }

    private function analyzeCheck(Check $check): array
    {
        $rawText = $check->raw_text ?? '';
        $row = [
            'id' => $check->id,
            'parser_amount' => null,
            'parser_date' => null,
            'reason' => '',
        ];

        if (empty($rawText) || mb_strlen($rawText, 'UTF-8') < 30) {
            $row['reason'] = 'Нет raw_text или слишком короткий — OCR не извлёк текст';
            return $row;
        }

        try {
            $parser = new ReceiptParser($rawText);
            $parsed = $parser->parse();
            $row['parser_amount'] = $parsed['amount'] ?? $parsed['sum'] ?? null;
            $row['parser_date'] = $parsed['date'] ?? null;

            $dbAmount = $check->corrected_amount ?? $check->amount;
            if ($row['parser_amount'] === null) {
                $row['reason'] = 'ReceiptParser не нашёл сумму в raw_text (нет ключевых слов, или исключения)';
            } elseif ($dbAmount && abs((float)$row['parser_amount'] - (float)$dbAmount) > 0.01) {
                $row['reason'] = "Несовпадение: БД={$dbAmount}, ReceiptParser={$row['parser_amount']}. При сохранении использовался legacy парсер — включите «Улучшенный» в настройках розыгрыша.";
            } else {
                $row['reason'] = 'OK';
            }
        } catch (\Throwable $e) {
            $row['reason'] = 'Ошибка ReceiptParser: ' . $e->getMessage();
        }

        return $row;
    }

    private function showParserMethod(): void
    {
        $this->info('=== Метод определения суммы и даты (receipt_parser_method) ===');
        $bots = TelegramBot::all();
        foreach ($bots as $bot) {
            $settings = BotSettings::where('telegram_bot_id', $bot->id)->first();
            $method = $settings->receipt_parser_method ?? 'legacy';
            $label = $method === BotSettings::PARSER_ENHANCED ? 'Улучшенный (pdftotext, ReceiptParser)' : 'Классический (legacy)';
            $this->line("Бот #{$bot->id} ({$bot->username}): <fg=" . ($method === 'enhanced' ? 'green' : 'yellow') . ">{$method}</> — {$label}");
        }
        if ($bots->isEmpty()) {
            $this->warn('Нет ботов.');
        }
    }
}
