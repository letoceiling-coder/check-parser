<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TelegramBot;
use App\Models\BotSettings;
use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы с Google Sheets API
 * Используется для записи данных одобренных заказов в таблицу
 */
class GoogleSheetsService
{
    private Client $client;
    private Sheets $service;

    public function __construct()
    {
        $this->initializeClient();
    }

    /**
     * Инициализировать Google API Client
     */
    private function initializeClient(): void
    {
        try {
            $this->client = new Client();
            
            $credentialsPath = config('services.google.credentials_path') 
                ?? storage_path('app/google/service-account.json');
            
            if (!file_exists($credentialsPath)) {
                throw new \Exception("Google credentials file not found: {$credentialsPath}");
            }
            
            $this->client->setAuthConfig($credentialsPath);
            $this->client->addScope(Sheets::SPREADSHEETS);
            
            $this->service = new Sheets($this->client);
            
        } catch (\Exception $e) {
            Log::error('Failed to initialize Google Sheets client', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Записать заказ в Google Sheets
     * 
     * @param Order $order Одобренный заказ
     * @return bool Успешность записи
     */
    public function writeOrder(Order $order): bool
    {
        try {
            // Проверка включена ли интеграция
            if (!config('services.google.sheets_enabled', false)) {
                Log::info('Google Sheets integration disabled', ['order_id' => $order->id]);
                return false;
            }
            
            // Получаем настройки бота
            $settings = BotSettings::where('telegram_bot_id', $order->telegram_bot_id)->first();
            
            if (!$settings || !$settings->google_sheet_url) {
                Log::warning('Google Sheet URL not configured', ['bot_id' => $order->telegram_bot_id]);
                return false;
            }
            
            // Извлекаем ID таблицы из URL
            $sheetId = $this->extractSheetId($settings->google_sheet_url);
            
            if (!$sheetId) {
                Log::error('Invalid Google Sheet URL', ['url' => $settings->google_sheet_url]);
                return false;
            }
            
            // Загружаем связи
            $order->load(['botUser', 'raffle']);
            
            // Формируем строку данных
            $row = [
                $order->id, // ID заказа
                $order->botUser->fio ?? '—', // ФИО (расшифрованный)
                $order->botUser->phone ?? '—', // Телефон (расшифрованный)
                number_format($order->amount, 2, '.', ''), // Сумма (20000.00)
                implode(', ', $order->ticket_numbers ?? []), // Номера (55, 56, 57)
                $order->reviewed_at ? $order->reviewed_at->format('d.m.Y H:i') : '—', // Дата одобрения
            ];
            
            // Записываем в Google Sheets
            $values = [$row];
            $body = new ValueRange(['values' => $values]);
            $params = ['valueInputOption' => 'RAW'];
            
            $result = $this->service->spreadsheets_values->append(
                $sheetId,
                'Sheet1!A:F', // Диапазон
                $body,
                $params
            );
            
            Log::info('Order written to Google Sheets', [
                'order_id' => $order->id,
                'sheet_id' => $sheetId,
                'updated_rows' => $result->getUpdates()->getUpdatedRows(),
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to write order to Google Sheets', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return false;
        }
    }

    /**
     * Записать несколько заказов (bulk)
     * 
     * @param array $orders Массив Orders
     * @return array ['success' => int, 'failed' => int]
     */
    public function writeOrders(array $orders): array
    {
        $success = 0;
        $failed = 0;
        
        foreach ($orders as $order) {
            if ($this->writeOrder($order)) {
                $success++;
            } else {
                $failed++;
            }
        }
        
        return compact('success', 'failed');
    }

    /**
     * Извлечь ID таблицы из URL
     * 
     * @param string $url URL Google Таблицы
     * @return string|null ID таблицы
     */
    private function extractSheetId(string $url): ?string
    {
        // URL вида: https://docs.google.com/spreadsheets/d/{ID}/edit
        if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return $matches[1];
        }
        
        // Если передан просто ID
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $url)) {
            return $url;
        }
        
        return null;
    }

    /**
     * Инициализировать заголовки таблицы
     * 
     * @param string $sheetId ID таблицы
     * @param string $sheetName Название листа (по умолчанию Sheet1)
     * @return bool
     */
    public function initializeHeaders(string $sheetId, string $sheetName = 'Sheet1'): bool
    {
        try {
            $headers = [
                ['ID заказа', 'ФИО', 'Телефон', 'Сумма', 'Номера', 'Дата']
            ];
            
            $body = new ValueRange(['values' => $headers]);
            $params = ['valueInputOption' => 'RAW'];
            
            $result = $this->service->spreadsheets_values->update(
                $sheetId,
                $sheetName . '!A1:F1',
                $body,
                $params
            );
            
            Log::info('Google Sheets headers initialized', [
                'sheet_id' => $sheetId,
                'sheet_name' => $sheetName,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to initialize Google Sheets headers', [
                'sheet_id' => $sheetId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Проверить доступ к таблице
     * 
     * @param string $sheetId ID таблицы
     * @return bool
     */
    public function testAccess(string $sheetId): bool
    {
        try {
            $range = 'Sheet1!A1:F1';
            $response = $this->service->spreadsheets_values->get($sheetId, $range);
            
            Log::info('Google Sheets access test successful', [
                'sheet_id' => $sheetId,
                'has_data' => !empty($response->getValues()),
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Google Sheets access test failed', [
                'sheet_id' => $sheetId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Получить все записи из таблицы
     * 
     * @param string $sheetId ID таблицы
     * @return array
     */
    public function getAllRecords(string $sheetId): array
    {
        try {
            $range = 'Sheet1!A2:F'; // Со второй строки (без заголовков)
            $response = $this->service->spreadsheets_values->get($sheetId, $range);
            
            return $response->getValues() ?? [];
            
        } catch (\Exception $e) {
            Log::error('Failed to read from Google Sheets', [
                'sheet_id' => $sheetId,
                'error' => $e->getMessage(),
            ]);
            
            return [];
        }
    }

    /**
     * Очистить все данные (кроме заголовков)
     * 
     * @param string $sheetId ID таблицы
     * @return bool
     */
    public function clearData(string $sheetId): bool
    {
        try {
            $range = 'Sheet1!A2:F'; // Всё кроме заголовков
            
            $this->service->spreadsheets_values->clear(
                $sheetId,
                $range,
                new Sheets\ClearValuesRequest()
            );
            
            Log::info('Google Sheets data cleared', ['sheet_id' => $sheetId]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to clear Google Sheets', [
                'sheet_id' => $sheetId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Инициализировать таблицы для всех ботов
     * 
     * @return array ['success' => int, 'failed' => int, 'skipped' => int]
     */
    public static function initializeAllBotSheets(): array
    {
        $success = 0;
        $failed = 0;
        $skipped = 0;
        
        $service = new self();
        
        $settings = BotSettings::whereNotNull('google_sheet_url')->get();
        
        foreach ($settings as $setting) {
            if (!$setting->google_sheet_url) {
                $skipped++;
                continue;
            }
            
            $sheetId = $service->extractSheetId($setting->google_sheet_url);
            
            if (!$sheetId) {
                Log::warning('Invalid Google Sheet URL', [
                    'bot_id' => $setting->telegram_bot_id,
                    'url' => $setting->google_sheet_url,
                ]);
                $failed++;
                continue;
            }
            
            if ($service->initializeHeaders($sheetId)) {
                $success++;
            } else {
                $failed++;
            }
        }
        
        return compact('success', 'failed', 'skipped');
    }
}
