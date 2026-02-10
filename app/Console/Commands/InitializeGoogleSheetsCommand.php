<?php

namespace App\Console\Commands;

use App\Models\BotSettings;
use App\Services\GoogleSheetsService;
use Illuminate\Console\Command;

class InitializeGoogleSheetsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sheets:init-headers {--bot-id= : ID конкретного бота}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Инициализировать заголовки в Google Таблицах для всех ботов (или конкретного)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔧 Инициализация Google Sheets...');
        $this->newLine();
        
        try {
            $service = new GoogleSheetsService();
            
            // Получаем настройки ботов
            $query = BotSettings::whereNotNull('google_sheet_url');
            
            if ($botId = $this->option('bot-id')) {
                $query->where('telegram_bot_id', $botId);
            }
            
            $settings = $query->with('telegramBot')->get();
            
            if ($settings->isEmpty()) {
                $this->warn('⚠️  Не найдено ботов с настроенным google_sheet_url');
                return 1;
            }
            
            $this->info("Найдено ботов: {$settings->count()}");
            $this->newLine();
            
            $success = 0;
            $failed = 0;
            $skipped = 0;
            
            foreach ($settings as $setting) {
                $botName = $setting->telegramBot->name ?? "Bot #{$setting->telegram_bot_id}";
                $this->line("Обработка: {$botName}");
                
                if (!$setting->google_sheet_url) {
                    $this->warn("  └─ ⚠️  Google Sheet URL не настроен");
                    $skipped++;
                    continue;
                }
                
                // Извлекаем ID таблицы
                if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $setting->google_sheet_url, $matches)) {
                    $sheetId = $matches[1];
                } else {
                    $this->error("  └─ ❌ Неверный формат URL: {$setting->google_sheet_url}");
                    $failed++;
                    continue;
                }
                
                // Проверяем доступ
                if (!$service->testAccess($sheetId)) {
                    $this->error("  └─ ❌ Нет доступа к таблице. Проверьте права Service Account.");
                    $failed++;
                    continue;
                }
                
                // Инициализируем заголовки
                if ($service->initializeHeaders($sheetId)) {
                    $this->info("  └─ ✅ Заголовки инициализированы");
                    $this->line("     📊 {$setting->google_sheet_url}");
                    $success++;
                } else {
                    $this->error("  └─ ❌ Ошибка инициализации");
                    $failed++;
                }
                
                $this->newLine();
            }
            
            // Итоги
            $this->newLine();
            $this->info('📊 Итоги:');
            $this->line("  ✅ Успешно: {$success}");
            
            if ($failed > 0) {
                $this->line("  ❌ Ошибки: {$failed}");
            }
            
            if ($skipped > 0) {
                $this->line("  ⚠️  Пропущено: {$skipped}");
            }
            
            return $failed > 0 ? 1 : 0;
            
        } catch (\Exception $e) {
            $this->error('❌ Критическая ошибка: ' . $e->getMessage());
            $this->newLine();
            $this->line('Проверьте:');
            $this->line('  1. Файл service-account.json существует и валиден');
            $this->line('  2. GOOGLE_APPLICATION_CREDENTIALS в .env настроен');
            $this->line('  3. Пакет google/apiclient установлен');
            return 1;
        }
    }
}
