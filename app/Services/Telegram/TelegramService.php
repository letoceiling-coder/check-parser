<?php

namespace App\Services\Telegram;

use App\Models\TelegramBot;
use App\Models\BotUser;
use App\Models\BotSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Сервис для работы с Telegram Bot API
 */
class TelegramService
{
    protected TelegramBot $bot;
    protected string $apiUrl;

    public function __construct(TelegramBot $bot)
    {
        $this->bot = $bot;
        $this->apiUrl = "https://api.telegram.org/bot{$bot->token}";
    }

    // ==========================================
    // Основные методы отправки
    // ==========================================

    /**
     * Отправить сообщение
     */
    public function sendMessage(
        int $chatId,
        string $text,
        ?array $replyMarkup = null,
        string $parseMode = 'HTML'
    ): ?array {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ];

        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->request('sendMessage', $params);
    }

    /**
     * Редактировать сообщение (для FSM - один экран = одно сообщение)
     */
    public function editMessage(
        int $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup = null,
        string $parseMode = 'HTML'
    ): ?array {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ];

        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->request('editMessageText', $params);
    }

    /**
     * Отправить или обновить сообщение (универсальный метод для FSM)
     * Если есть lastMessageId - редактируем, иначе отправляем новое
     */
    public function sendOrEditMessage(
        BotUser $user,
        string $text,
        ?array $replyMarkup = null
    ): ?int {
        $chatId = $user->telegram_user_id;
        $lastMessageId = $user->last_bot_message_id;

        // Пробуем отредактировать существующее сообщение
        if ($lastMessageId) {
            $result = $this->editMessage($chatId, $lastMessageId, $text, $replyMarkup);
            if ($result && isset($result['result']['message_id'])) {
                return $result['result']['message_id'];
            }
        }

        // Если редактирование не удалось, отправляем новое
        $result = $this->sendMessage($chatId, $text, $replyMarkup);
        if ($result && isset($result['result']['message_id'])) {
            $messageId = $result['result']['message_id'];
            $user->last_bot_message_id = $messageId;
            $user->save();
            return $messageId;
        }

        return null;
    }

    /**
     * Отправить фото
     */
    public function sendPhoto(
        int $chatId,
        string $photoPath,
        ?string $caption = null,
        ?array $replyMarkup = null
    ): ?array {
        $params = [
            'chat_id' => $chatId,
        ];

        if ($caption) {
            $params['caption'] = $caption;
            $params['parse_mode'] = 'HTML';
        }

        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        // Проверяем, это URL или локальный файл
        if (filter_var($photoPath, FILTER_VALIDATE_URL)) {
            $params['photo'] = $photoPath;
            return $this->request('sendPhoto', $params);
        }

        // Локальный файл
        if (!file_exists($photoPath)) {
            Log::error("Photo file not found: {$photoPath}");
            return null;
        }

        return $this->requestWithFile('sendPhoto', $params, 'photo', $photoPath);
    }

    /**
     * Отправить документ
     */
    public function sendDocument(
        int $chatId,
        string $documentPath,
        ?string $caption = null,
        ?array $replyMarkup = null
    ): ?array {
        $params = [
            'chat_id' => $chatId,
        ];

        if ($caption) {
            $params['caption'] = $caption;
            $params['parse_mode'] = 'HTML';
        }

        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        if (!file_exists($documentPath)) {
            Log::error("Document file not found: {$documentPath}");
            return null;
        }

        return $this->requestWithFile('sendDocument', $params, 'document', $documentPath);
    }

    /**
     * Ответить на callback query
     */
    public function answerCallbackQuery(
        string $callbackQueryId,
        ?string $text = null,
        bool $showAlert = false
    ): ?array {
        $params = [
            'callback_query_id' => $callbackQueryId,
            'show_alert' => $showAlert,
        ];

        if ($text) {
            $params['text'] = $text;
        }

        return $this->request('answerCallbackQuery', $params);
    }

    /**
     * Удалить сообщение
     */
    public function deleteMessage(int $chatId, int $messageId): ?array
    {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    // ==========================================
    // Методы для работы с файлами
    // ==========================================

    /**
     * Получить информацию о файле
     */
    public function getFile(string $fileId): ?array
    {
        return $this->request('getFile', ['file_id' => $fileId]);
    }

    /**
     * Скачать файл
     */
    public function downloadFile(string $filePath, string $localPath): bool
    {
        try {
            $url = "https://api.telegram.org/file/bot{$this->bot->token}/{$filePath}";
            $response = Http::timeout(60)->get($url);

            if ($response->successful()) {
                Storage::disk('local')->put($localPath, $response->body());
                return true;
            }

            Log::error("Failed to download file", [
                'url' => $url,
                'status' => $response->status(),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error("Exception downloading file: " . $e->getMessage());
            return false;
        }
    }

    // ==========================================
    // Уведомления администраторам
    // ==========================================

    /**
     * Отправить уведомление всем администраторам
     */
    public function notifyAdmins(string $text, ?array $replyMarkup = null): void
    {
        $adminIds = $this->bot->getAdminTelegramIds();

        foreach ($adminIds as $adminId) {
            try {
                $this->sendMessage($adminId, $text, $replyMarkup);
            } catch (\Exception $e) {
                Log::error("Failed to notify admin", [
                    'admin_id' => $adminId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Отправить документ всем администраторам с кнопками
     */
    public function notifyAdminsWithDocument(
        string $documentPath,
        string $caption,
        ?array $replyMarkup = null
    ): void {
        $adminIds = $this->bot->getAdminTelegramIds();

        foreach ($adminIds as $adminId) {
            try {
                $this->sendDocument($adminId, $documentPath, $caption, $replyMarkup);
            } catch (\Exception $e) {
                Log::error("Failed to notify admin with document", [
                    'admin_id' => $adminId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ==========================================
    // Приватные методы
    // ==========================================

    /**
     * Выполнить запрос к Telegram API
     */
    protected function request(string $method, array $params = []): ?array
    {
        try {
            $response = Http::timeout(30)->post("{$this->apiUrl}/{$method}", $params);

            $data = $response->json();

            if (!$response->successful() || !($data['ok'] ?? false)) {
                Log::warning("Telegram API error", [
                    'method' => $method,
                    'params' => $params,
                    'response' => $data,
                ]);
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            Log::error("Telegram API exception", [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Выполнить запрос с файлом
     */
    protected function requestWithFile(
        string $method,
        array $params,
        string $fileField,
        string $filePath
    ): ?array {
        try {
            $response = Http::timeout(60)
                ->attach($fileField, file_get_contents($filePath), basename($filePath))
                ->post("{$this->apiUrl}/{$method}", $params);

            $data = $response->json();

            if (!$response->successful() || !($data['ok'] ?? false)) {
                Log::warning("Telegram API error with file", [
                    'method' => $method,
                    'response' => $data,
                ]);
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            Log::error("Telegram API exception with file", [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
