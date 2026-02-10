<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleSheetsService;
use App\Models\BotSettings;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleSheetsController extends Controller
{
    /**
     * Получить текущие настройки Google Sheets
     */
    public function getSettings(): JsonResponse
    {
        try {
            $credentialsPath = config('services.google.credentials_path');
            $hasCredentials = file_exists($credentialsPath);
            $enabled = config('services.google.sheets_enabled', false) && $hasCredentials;
            
            return response()->json([
                'enabled' => $enabled,
                'credentialsPath' => $hasCredentials ? $credentialsPath : null,
                'hasCredentials' => $hasCredentials,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to get Google Sheets settings', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Ошибка получения настроек',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Включить/выключить интеграцию
     */
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);
        
        try {
            $enabled = $request->input('enabled');
            
            // Проверяем наличие credentials
            $credentialsPath = config('services.google.credentials_path');
            if ($enabled && !file_exists($credentialsPath)) {
                return response()->json([
                    'message' => 'Невозможно включить: отсутствует файл ключа',
                ], 400);
            }
            
            // Обновляем .env (требуется права на запись)
            $this->updateEnvFile('GOOGLE_SHEETS_ENABLED', $enabled ? 'true' : 'false');
            
            // Очищаем кеш конфига
            \Artisan::call('config:clear');
            
            return response()->json([
                'enabled' => $enabled,
                'message' => $enabled ? 'Интеграция включена' : 'Интеграция отключена',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to toggle Google Sheets', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Ошибка изменения настроек',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Загрузить файл credentials
     */
    public function uploadCredentials(Request $request): JsonResponse
    {
        $request->validate([
            'credentials' => 'required|file|mimes:json',
        ]);
        
        try {
            $file = $request->file('credentials');
            
            // Проверяем валидность JSON
            $content = file_get_contents($file->getRealPath());
            $json = json_decode($content, true);
            
            if (!$json) {
                return response()->json([
                    'message' => 'Невалидный JSON файл',
                ], 400);
            }
            
            // Проверяем, что это правильный формат Service Account
            if (!isset($json['type']) || $json['type'] !== 'service_account') {
                return response()->json([
                    'message' => 'Это не файл Service Account. Убедитесь, что вы скачали правильный JSON-ключ.',
                ], 400);
            }
            
            if (!isset($json['client_email']) || !isset($json['private_key'])) {
                return response()->json([
                    'message' => 'Файл не содержит необходимые поля (client_email, private_key)',
                ], 400);
            }
            
            // Создаём директорию если не существует
            $storageDir = storage_path('app/google');
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }
            
            // Сохраняем файл
            $targetPath = $storageDir . '/service-account.json';
            file_put_contents($targetPath, $content);
            chmod($targetPath, 0600);
            
            Log::info('Google Sheets credentials uploaded', [
                'client_email' => $json['client_email'],
                'project_id' => $json['project_id'] ?? 'N/A',
            ]);
            
            return response()->json([
                'message' => 'Файл ключа успешно загружен',
                'path' => $targetPath,
                'client_email' => $json['client_email'],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to upload credentials', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Ошибка загрузки файла',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Тестировать подключение к Google Sheets
     */
    public function test(): JsonResponse
    {
        try {
            // Проверяем наличие credentials
            $credentialsPath = config('services.google.credentials_path');
            if (!file_exists($credentialsPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл ключа не найден. Загрузите service-account.json.',
                ]);
            }
            
            // Проверяем валидность JSON
            $json = json_decode(file_get_contents($credentialsPath), true);
            if (!$json) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл ключа повреждён (невалидный JSON)',
                ]);
            }
            
            $details = [
                'Service Account: ' . ($json['client_email'] ?? 'N/A'),
                'Проект: ' . ($json['project_id'] ?? 'N/A'),
            ];
            
            // Инициализируем сервис
            try {
                $service = new GoogleSheetsService();
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось инициализировать Google Sheets API: ' . $e->getMessage(),
                    'details' => $details,
                ]);
            }
            
            // Проверяем доступ к таблицам ботов
            $settings = BotSettings::whereNotNull('google_sheet_url')->get();
            
            if ($settings->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Ключ валиден, но ни один бот не настроен с Google Sheet URL',
                    'details' => array_merge($details, [
                        'Настройте Google Sheet URL в настройках бота',
                    ]),
                ]);
            }
            
            $tested = 0;
            $succeeded = 0;
            $failed = [];
            
            foreach ($settings as $setting) {
                if (!$setting->google_sheet_url) continue;
                
                $tested++;
                
                // Извлекаем ID таблицы
                if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $setting->google_sheet_url, $matches)) {
                    $sheetId = $matches[1];
                } else {
                    $failed[] = 'Бот #' . $setting->telegram_bot_id . ': неверный URL';
                    continue;
                }
                
                // Тестируем доступ
                if ($service->testAccess($sheetId)) {
                    $succeeded++;
                } else {
                    $failed[] = 'Бот #' . $setting->telegram_bot_id . ': нет доступа к таблице';
                }
            }
            
            if ($tested === 0) {
                return response()->json([
                    'success' => true,
                    'message' => 'Ключ валиден. Настройте Google Sheet URL в настройках бота.',
                    'details' => $details,
                ]);
            }
            
            if ($succeeded === $tested) {
                return response()->json([
                    'success' => true,
                    'message' => "Подключение успешно! Доступ к {$succeeded} таблицам подтверждён.",
                    'details' => array_merge($details, [
                        "Протестировано ботов: {$tested}",
                        "Успешно: {$succeeded}",
                    ]),
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => "Частичный успех: {$succeeded} из {$tested}",
                'details' => array_merge($details, [
                    "Протестировано ботов: {$tested}",
                    "Успешно: {$succeeded}",
                    "Ошибки:",
                ], $failed, [
                    '',
                    'Решение: откройте каждую таблицу в браузере → Share → добавьте ' . ($json['client_email'] ?? 'N/A') . ' как Редактор',
                ]),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Google Sheets test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка тестирования: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Обновить .env файл
     */
    private function updateEnvFile(string $key, string $value): void
    {
        $path = base_path('.env');
        
        if (!file_exists($path)) {
            throw new \Exception('.env file not found');
        }
        
        $content = file_get_contents($path);
        $pattern = "/^{$key}=.*/m";
        
        if (preg_match($pattern, $content)) {
            // Обновляем существующую строку
            $content = preg_replace($pattern, "{$key}={$value}", $content);
        } else {
            // Добавляем новую строку
            $content .= "\n{$key}={$value}\n";
        }
        
        file_put_contents($path, $content);
    }
}
