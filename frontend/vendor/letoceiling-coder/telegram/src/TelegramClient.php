<?php

namespace LetoceilingCoder\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LetoceilingCoder\Telegram\Exceptions\TelegramException;

/**
 * Базовый класс для работы с Telegram API
 */
class TelegramClient
{
    protected string $token;
    protected string $baseUrl = 'https://api.telegram.org/bot';

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? config('telegram.bot_token');
        
        if (!$this->token) {
            throw new TelegramException('Telegram bot token not configured');
        }
    }

    /**
     * Выполнить запрос к Telegram API
     */
    protected function request(string $method, array $parameters = []): array
    {
        $url = $this->baseUrl . $this->token . '/' . $method;

        try {
            $response = Http::timeout(30)->post($url, $parameters);

            $result = $response->json();

            if (!$result['ok']) {
                $errorMessage = $result['description'] ?? 'Unknown error';
                Log::error('Telegram API error', [
                    'method' => $method,
                    'error' => $errorMessage,
                    'parameters' => $parameters,
                ]);
                throw new TelegramException($errorMessage);
            }

            return $result['result'] ?? [];

        } catch (\Exception $e) {
            Log::error('Telegram API request failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            throw new TelegramException('Failed to execute Telegram API request: ' . $e->getMessage());
        }
    }

    /**
     * Выполнить запрос с файлом
     */
    protected function requestWithFile(string $method, array $parameters = [], array $files = []): array
    {
        $url = $this->baseUrl . $this->token . '/' . $method;

        try {
            $request = Http::timeout(30)->asMultipart();

            foreach ($files as $key => $file) {
                $request->attach($key, $file['content'], $file['filename'] ?? null);
            }

            $response = $request->post($url, $parameters);
            $result = $response->json();

            if (!$result['ok']) {
                $errorMessage = $result['description'] ?? 'Unknown error';
                throw new TelegramException($errorMessage);
            }

            return $result['result'] ?? [];

        } catch (\Exception $e) {
            throw new TelegramException('Failed to execute Telegram API request with file: ' . $e->getMessage());
        }
    }

    /**
     * Получить информацию о боте
     */
    public function getMe(): array
    {
        return $this->request('getMe');
    }

    /**
     * Логирование для отладки
     */
    protected function log(string $message, array $context = []): void
    {
        Log::info('[Telegram] ' . $message, $context);
    }
}

