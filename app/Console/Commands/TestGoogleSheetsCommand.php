<?php

namespace App\Console\Commands;

use App\Models\BotSettings;
use App\Services\GoogleSheetsService;
use Illuminate\Console\Command;

class TestGoogleSheetsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sheets:test {--bot-id= : ID конкретного бота}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Проверить подключение к Google Sheets и права доступа';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧪 Тестирование подключения к Google Sheets...');
        $this->newLine();
        
        try {
            // Проверка наличия файла credentials
            $credentialsPath = config('services.google.credentials_path');
            
            $this->line("📄 Файл credentials: {$credentialsPath}");
            
            if (!file_exists($credentialsPath)) {
                $this->error("❌ Файл не найден!");
                $this->newLine();
                $this->line("Создайте Service Account в Google Cloud Console и положите JSON-ключ в:");
                $this->line("  {$credentialsPath}");
                $this->newLine();
                $this->line("Подробная инструкция: docs/GOOGLE_SHEETS_SETUP.md");
                return 1;
            }
            
            $this->info("✅ Файл существует");
            
            // Проверка валидности JSON
            $json = json_decode(file_get_contents($credentialsPath), true);
            
            if (!$json) {
                $this->error("❌ Невалидный JSON");
                return 1;
            }
            
            $this->info("✅ JSON валиден");
            $this->line("   Service Account: " . ($json['client_email'] ?? 'N/A'));
            $this->newLine();
            
            // Инициализация сервиса
            $this->line("🔌 Инициализация GoogleSheetsService...");
            $service = new GoogleSheetsService();
            $this->info("✅ Сервис инициализирован");
            $this->newLine();
            
            // Получаем настройки ботов
            $query = BotSettings::whereNotNull('google_sheet_url');
            
            if ($botId = $this->option('bot-id')) {
                $query->where('telegram_bot_id', $botId);
            }
            
            $settings = $query->with('telegramBot')->get();
            
            if ($settings->isEmpty()) {
                $this->warn('⚠️  Не найдено ботов с настроенным google_sheet_url');
                $this->newLine();
                $this->line("Настройте Google Sheet URL в админке бота:");
                $this->line("  https://auto.siteaccess.ru/bot-settings");
                return 1;
            }
            
            $this->info("Найдено ботов для тестирования: {$settings->count()}");
            $this->newLine();
            
            $success = 0;
            $failed = 0;
            
            foreach ($settings as $setting) {
                $botName = $setting->telegramBot->name ?? "Bot #{$setting->telegram_bot_id}";
                $this->line("🤖 {$botName}");
                $this->line("   URL: {$setting->google_sheet_url}");
                
                // Извлекаем ID
                if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $setting->google_sheet_url, $matches)) {
                    $sheetId = $matches[1];
                    $this->line("   ID: {$sheetId}");
                } else {
                    $this->error("   ❌ Неверный формат URL");
                    $failed++;
                    $this->newLine();
                    continue;
                }
                
                // Тест доступа
                $this->line("   🔐 Проверка доступа...");
                
                if ($service->testAccess($sheetId)) {
                    $this->info("   ✅ Доступ есть");
                    
                    // Пробуем прочитать заголовки
                    try {
                        $records = $service->getAllRecords($sheetId);
                        $this->line("   📊 Записей в таблице: " . count($records));
                        
                    } catch (\Exception $e) {
                        $this->warn("   ⚠️  Не удалось прочитать данные: " . $e->getMessage());
                    }
                    
                    $success++;
                } else {
                    $this->error("   ❌ Нет доступа");
                    $this->line("   💡 Решение:");
                    $this->line("      1. Откройте таблицу в браузере");
                    $this->line("      2. Нажмите 'Настройки доступа' (Share)");
                    $this->line("      3. Добавьте email Service Account как 'Редактор':");
                    $this->line("         " . ($json['client_email'] ?? 'N/A'));
                    
                    $failed++;
                }
                
                $this->newLine();
            }
            
            // Итоги
            $this->info('📊 Итоги:');
            $this->line("  ✅ Успешно: {$success}");
            
            if ($failed > 0) {
                $this->line("  ❌ Ошибки: {$failed}");
            }
            
            $this->newLine();
            
            if ($success > 0) {
                $this->info('✨ Всё работает! Можно использовать.');
                $this->line('   При одобрении заказа данные будут автоматически записываться в таблицу.');
                return 0;
            } else {
                $this->error('⚠️  Есть проблемы. Исправьте их и запустите команду снова.');
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Критическая ошибка: ' . $e->getMessage());
            $this->newLine();
            
            if (str_contains($e->getMessage(), 'not found')) {
                $this->line('💡 Установите пакет:');
                $this->line('   composer require google/apiclient:"^2.15"');
            }
            
            return 1;
        }
    }
}
