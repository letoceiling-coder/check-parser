<?php

namespace LetoceilingCoder\Telegram;

use LetoceilingCoder\Telegram\TelegramClient;

/**
 * Класс для работы с Callback Query
 */
class Callback extends TelegramClient
{
    /**
     * Ответить на callback query
     */
    public function answer(
        string $callbackQueryId,
        ?string $text = null,
        bool $showAlert = false,
        ?string $url = null,
        ?int $cacheTime = null
    ): array {
        $params = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text !== null) {
            $params['text'] = $text;
        }

        if ($showAlert) {
            $params['show_alert'] = true;
        }

        if ($url !== null) {
            $params['url'] = $url;
        }

        if ($cacheTime !== null) {
            $params['cache_time'] = $cacheTime;
        }

        return $this->request('answerCallbackQuery', $params);
    }

    /**
     * Ответить с уведомлением
     */
    public function answerWithNotification(
        string $callbackQueryId,
        string $text,
        ?int $cacheTime = null
    ): array {
        return $this->answer($callbackQueryId, $text, false, null, $cacheTime);
    }

    /**
     * Ответить с alert
     */
    public function answerWithAlert(
        string $callbackQueryId,
        string $text,
        ?int $cacheTime = null
    ): array {
        return $this->answer($callbackQueryId, $text, true, null, $cacheTime);
    }

    /**
     * Ответить с URL (открыть ссылку)
     */
    public function answerWithUrl(
        string $callbackQueryId,
        string $url,
        ?int $cacheTime = null
    ): array {
        return $this->answer($callbackQueryId, null, false, $url, $cacheTime);
    }

    /**
     * Просто подтвердить callback (без текста)
     */
    public function acknowledge(string $callbackQueryId): array
    {
        return $this->answer($callbackQueryId);
    }
}

