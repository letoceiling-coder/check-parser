<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramWebhookController extends Controller
{
    /**
     * Handle Telegram webhook
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $update = $request->all();
            Log::info('Telegram webhook received', ['update' => $update]);

            // Find bot by token (we need to identify which bot this update is for)
            // Telegram sends updates to webhook URL, we need to identify bot
            // For now, we'll get bot token from webhook URL or use first active bot
            // In production, you might want to use secret_token or bot_id in URL
            
            $bot = $this->findBotByUpdate($update);
            if (!$bot) {
                Log::warning('Bot not found for update', ['update' => $update]);
                return response()->json(['ok' => true]); // Return ok to Telegram
            }

            // Handle message
            if (isset($update['message'])) {
                $this->handleMessage($bot, $update['message']);
            }

            // Handle callback query (button clicks)
            if (isset($update['callback_query'])) {
                $this->handleCallbackQuery($bot, $update['callback_query']);
            }

            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Telegram webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['ok' => true]); // Always return ok to Telegram
        }
    }

    /**
     * Find bot by update
     * Try to identify bot by checking all active bots and matching token
     */
    private function findBotByUpdate(array $update): ?TelegramBot
    {
        // Get all active bots
        $bots = TelegramBot::where('is_active', true)->get();
        
        // If only one bot, return it
        if ($bots->count() === 1) {
            return $bots->first();
        }
        
        // If multiple bots, we need to identify by bot_id or token
        // For now, try to get bot info from message and match
        // In production, you might want to use bot_id in webhook URL path
        
        // Try to get bot info from callback_query or message
        $botId = null;
        if (isset($update['callback_query']['from']['id'])) {
            // This is not reliable, but we'll use first active bot
        }
        
        // For now, return first active bot
        // TODO: Implement proper bot identification (e.g., via webhook URL path)
        return $bots->first();
    }

    /**
     * Handle incoming message
     */
    private function handleMessage(TelegramBot $bot, array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? null;
        $photo = $message['photo'] ?? null;
        $document = $message['document'] ?? null;

        // Handle /start command
        if ($text && str_starts_with($text, '/start')) {
            $this->handleStartCommand($bot, $chatId);
            return;
        }

        // Handle photo (check image)
        if ($photo) {
            $this->handlePhoto($bot, $chatId, $photo);
            return;
        }

        // Handle document (check image file)
        if ($document && $this->isImageDocument($document)) {
            $this->handleDocument($bot, $chatId, $document);
            return;
        }

        // Handle other messages
        if ($text) {
            $this->sendMessage($bot, $chatId, 'Пожалуйста, отправьте фото чека для обработки.');
        }
    }

    /**
     * Handle /start command
     */
    private function handleStartCommand(TelegramBot $bot, int $chatId): void
    {
        $welcomeMessage = "👋 Привет! Я бот для обработки чеков.\n\n";
        $welcomeMessage .= "📸 Отправьте мне фото чека, и я извлеку из него информацию.\n\n";
        $welcomeMessage .= "Просто отправьте фото чека, и я обработаю его!";

        $this->sendMessage($bot, $chatId, $welcomeMessage);
    }

    /**
     * Handle photo
     */
    private function handlePhoto(TelegramBot $bot, int $chatId, array $photo): void
    {
        // Get the largest photo
        $largestPhoto = end($photo);
        $fileId = $largestPhoto['file_id'];

        // Send "processing" message
        $this->sendMessage($bot, $chatId, '⏳ Обрабатываю чек...');

        try {
            // Get file from Telegram
            $file = $this->getFile($bot, $fileId);
            if (!$file) {
                $this->sendMessage($bot, $chatId, '❌ Ошибка при получении файла.');
                return;
            }

            // Download file
            $filePath = $this->downloadFile($bot, $file['file_path']);
            if (!$filePath) {
                $this->sendMessage($bot, $chatId, '❌ Ошибка при загрузке файла.');
                return;
            }

            // Process check (QR code parsing)
            $checkData = $this->processCheck($filePath);

            // Send result
            if ($checkData) {
                $this->sendCheckResult($bot, $chatId, $checkData);
            } else {
                $this->sendMessage($bot, $chatId, '❌ Не удалось распознать QR код на чеке. Убедитесь, что фото четкое и QR код виден.');
            }

            // Clean up
            Storage::disk('local')->delete($filePath);
        } catch (\Exception $e) {
            Log::error('Error processing photo: ' . $e->getMessage());
            $this->sendMessage($bot, $chatId, '❌ Произошла ошибка при обработке чека.');
        }
    }

    /**
     * Handle document (image file)
     */
    private function handleDocument(TelegramBot $bot, int $chatId, array $document): void
    {
        $fileId = $document['file_id'];

        // Send "processing" message
        $this->sendMessage($bot, $chatId, '⏳ Обрабатываю чек...');

        try {
            // Get file from Telegram
            $file = $this->getFile($bot, $fileId);
            if (!$file) {
                $this->sendMessage($bot, $chatId, '❌ Ошибка при получении файла.');
                return;
            }

            // Download file
            $filePath = $this->downloadFile($bot, $file['file_path']);
            if (!$filePath) {
                $this->sendMessage($bot, $chatId, '❌ Ошибка при загрузке файла.');
                return;
            }

            // Process check (QR code parsing)
            $checkData = $this->processCheck($filePath);

            // Send result
            if ($checkData) {
                $this->sendCheckResult($bot, $chatId, $checkData);
            } else {
                $this->sendMessage($bot, $chatId, '❌ Не удалось распознать QR код на чеке. Убедитесь, что фото четкое и QR код виден.');
            }

            // Clean up
            Storage::disk('local')->delete($filePath);
        } catch (\Exception $e) {
            Log::error('Error processing document: ' . $e->getMessage());
            $this->sendMessage($bot, $chatId, '❌ Произошла ошибка при обработке чека.');
        }
    }

    /**
     * Check if document is an image
     */
    private function isImageDocument(array $document): bool
    {
        $mimeType = $document['mime_type'] ?? '';
        return str_starts_with($mimeType, 'image/');
    }

    /**
     * Get file info from Telegram
     */
    private function getFile(TelegramBot $bot, string $fileId): ?array
    {
        try {
            $response = Http::get("https://api.telegram.org/bot{$bot->token}/getFile", [
                'file_id' => $fileId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['result'] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting file: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Download file from Telegram
     */
    private function downloadFile(TelegramBot $bot, string $filePath): ?string
    {
        try {
            $url = "https://api.telegram.org/file/bot{$bot->token}/{$filePath}";
            $contents = Http::get($url)->body();

            $localPath = 'telegram/' . basename($filePath);
            Storage::disk('local')->put($localPath, $contents);

            return $localPath;
        } catch (\Exception $e) {
            Log::error('Error downloading file: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Process check - extract QR code and parse data
     */
    private function processCheck(string $filePath): ?array
    {
        try {
            $fullPath = Storage::disk('local')->path($filePath);

            // Try to extract QR code using different methods
            // Method 1: Use zxing via exec (if available)
            $qrData = $this->extractQRCodeWithZxing($fullPath);
            if ($qrData) {
                return $this->parseCheckData($qrData);
            }

            // Method 2: Use PHP QR code reader library (if available)
            $qrData = $this->extractQRCodeWithLibrary($fullPath);
            if ($qrData) {
                return $this->parseCheckData($qrData);
            }

            // Method 3: Use external API (fallback)
            $qrData = $this->extractQRCodeWithAPI($fullPath);
            if ($qrData) {
                return $this->parseCheckData($qrData);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error processing check: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using zxing (requires Java and zxing installed)
     */
    private function extractQRCodeWithZxing(string $filePath): ?string
    {
        try {
            // Check if zxing is available
            $zxingPath = exec('which zxing 2>/dev/null') ?: exec('which java 2>/dev/null');
            if (!$zxingPath) {
                return null;
            }

            // Try to decode QR code
            $command = "zxing --decode {$filePath} 2>/dev/null";
            $output = exec($command, $outputArray, $returnCode);

            if ($returnCode === 0 && !empty($output)) {
                return $output;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract QR code using PHP library (placeholder - implement if library is installed)
     */
    private function extractQRCodeWithLibrary(string $filePath): ?string
    {
        // TODO: Implement if QR code library is installed
        // Example: using simple-qrcode or other library
        return null;
    }

    /**
     * Extract QR code using external API (fallback)
     */
    private function extractQRCodeWithAPI(string $filePath): ?string
    {
        try {
            // Use free QR code API service
            // Example: https://api.qrserver.com/v1/read-qr-code/
            $url = 'https://api.qrserver.com/v1/read-qr-code/';
            
            $response = Http::attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['symbol'][0]['data'])) {
                    return $data[0]['symbol'][0]['data'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error extracting QR with API: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse check data from QR code string
     * Russian fiscal receipt format (ФНС)
     */
    private function parseCheckData(string $qrData): ?array
    {
        try {
            // Russian fiscal receipt QR code format:
            // t=YYYYMMDDTHHMM&s=SUM&fn=FN&i=FPD&fp=FP&n=OPERATION_TYPE
            
            $params = [];
            parse_str($qrData, $params);

            if (empty($params)) {
                return null;
            }

            $checkData = [
                'date' => $this->parseDate($params['t'] ?? null),
                'sum' => $params['s'] ?? null,
                'fn' => $params['fn'] ?? null, // Fiscal number
                'fpd' => $params['i'] ?? null, // Fiscal document number
                'fp' => $params['fp'] ?? null, // Fiscal sign
                'operation_type' => $params['n'] ?? null,
                'raw_data' => $qrData,
            ];

            return $checkData;
        } catch (\Exception $e) {
            Log::error('Error parsing check data: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse date from fiscal receipt format
     */
    private function parseDate(?string $dateString): ?string
    {
        if (!$dateString) {
            return null;
        }

        try {
            // Format: YYYYMMDDTHHMM
            if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})$/', $dateString, $matches)) {
                return "{$matches[1]}-{$matches[2]}-{$matches[3]} {$matches[4]}:{$matches[5]}";
            }
            return $dateString;
        } catch (\Exception $e) {
            return $dateString;
        }
    }

    /**
     * Send check result to user
     */
    private function sendCheckResult(TelegramBot $bot, int $chatId, array $checkData): void
    {
        $message = "✅ Чек успешно обработан!\n\n";
        $message .= "📅 Дата: " . ($checkData['date'] ?? 'Не указана') . "\n";
        $message .= "💰 Сумма: " . ($checkData['sum'] ? number_format($checkData['sum'] / 100, 2, '.', ' ') . ' ₽' : 'Не указана') . "\n";
        $message .= "🏪 ФН: " . ($checkData['fn'] ?? 'Не указан') . "\n";
        $message .= "📄 ФД: " . ($checkData['fpd'] ?? 'Не указан') . "\n";
        $message .= "🔐 ФП: " . ($checkData['fp'] ?? 'Не указан') . "\n";

        $this->sendMessage($bot, $chatId, $message);
    }

    /**
     * Handle callback query (button clicks)
     */
    private function handleCallbackQuery(TelegramBot $bot, array $callbackQuery): void
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $data = $callbackQuery['data'] ?? '';

        // Answer callback query
        Http::post("https://api.telegram.org/bot{$bot->token}/answerCallbackQuery", [
            'callback_query_id' => $callbackQuery['id'],
        ]);

        // Handle callback data
        // TODO: Implement callback handling if needed
    }

    /**
     * Send message to user
     */
    private function sendMessage(TelegramBot $bot, int $chatId, string $text): void
    {
        try {
            Http::post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending message: ' . $e->getMessage());
        }
    }
}
