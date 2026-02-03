<?php

namespace LetoceilingCoder\Telegram;

use LetoceilingCoder\Telegram\TelegramClient;

/**
 * Класс для работы с Telegram Bot API
 * Документация: https://core.telegram.org/bots/api
 */
class Bot extends TelegramClient
{
    // ==========================================
    // Getting updates
    // ==========================================

    /**
     * Получить обновления (updates)
     */
    public function getUpdates(array $params = []): array
    {
        return $this->request('getUpdates', $params);
    }

    /**
     * Установить webhook
     */
    public function setWebhook(string $url, array $params = []): array
    {
        return $this->request('setWebhook', array_merge(['url' => $url], $params));
    }

    /**
     * Удалить webhook
     */
    public function deleteWebhook(bool $dropPendingUpdates = false): array
    {
        return $this->request('deleteWebhook', [
            'drop_pending_updates' => $dropPendingUpdates,
        ]);
    }

    /**
     * Получить информацию о webhook
     */
    public function getWebhookInfo(): array
    {
        return $this->request('getWebhookInfo');
    }

    // ==========================================
    // Sending messages
    // ==========================================

    /**
     * Отправить текстовое сообщение
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        array $params = []
    ): array {
        // Валидация
        Validator::validateChatId($chatId);
        Validator::validateMessageText($text);
        
        if (isset($params['parse_mode'])) {
            Validator::validateParseMode($params['parse_mode']);
        }
        
        return $this->request('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $params));
    }

    /**
     * Переслать сообщение
     */
    public function forwardMessage(
        int|string $chatId,
        int|string $fromChatId,
        int $messageId,
        array $params = []
    ): array {
        return $this->request('forwardMessage', array_merge([
            'chat_id' => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
        ], $params));
    }

    /**
     * Скопировать сообщение
     */
    public function copyMessage(
        int|string $chatId,
        int|string $fromChatId,
        int $messageId,
        array $params = []
    ): array {
        return $this->request('copyMessage', array_merge([
            'chat_id' => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
        ], $params));
    }

    /**
     * Отправить фото
     */
    public function sendPhoto(
        int|string $chatId,
        string $photo,
        array $params = []
    ): array {
        Validator::validateChatId($chatId);
        
        if (isset($params['caption'])) {
            Validator::validateCaption($params['caption']);
        }
        
        return $this->request('sendPhoto', array_merge([
            'chat_id' => $chatId,
            'photo' => $photo,
        ], $params));
    }

    /**
     * Отправить аудио
     */
    public function sendAudio(
        int|string $chatId,
        string $audio,
        array $params = []
    ): array {
        return $this->request('sendAudio', array_merge([
            'chat_id' => $chatId,
            'audio' => $audio,
        ], $params));
    }

    /**
     * Отправить документ
     */
    public function sendDocument(
        int|string $chatId,
        string $document,
        array $params = []
    ): array {
        return $this->request('sendDocument', array_merge([
            'chat_id' => $chatId,
            'document' => $document,
        ], $params));
    }

    /**
     * Отправить видео
     */
    public function sendVideo(
        int|string $chatId,
        string $video,
        array $params = []
    ): array {
        return $this->request('sendVideo', array_merge([
            'chat_id' => $chatId,
            'video' => $video,
        ], $params));
    }

    /**
     * Отправить анимацию (GIF)
     */
    public function sendAnimation(
        int|string $chatId,
        string $animation,
        array $params = []
    ): array {
        return $this->request('sendAnimation', array_merge([
            'chat_id' => $chatId,
            'animation' => $animation,
        ], $params));
    }

    /**
     * Отправить голосовое сообщение
     */
    public function sendVoice(
        int|string $chatId,
        string $voice,
        array $params = []
    ): array {
        return $this->request('sendVoice', array_merge([
            'chat_id' => $chatId,
            'voice' => $voice,
        ], $params));
    }

    /**
     * Отправить видео заметку
     */
    public function sendVideoNote(
        int|string $chatId,
        string $videoNote,
        array $params = []
    ): array {
        return $this->request('sendVideoNote', array_merge([
            'chat_id' => $chatId,
            'video_note' => $videoNote,
        ], $params));
    }

    /**
     * Отправить группу медиа файлов
     */
    public function sendMediaGroup(
        int|string $chatId,
        array $media,
        array $params = []
    ): array {
        Validator::validateChatId($chatId);
        Validator::validateMediaGroup($media);
        
        return $this->request('sendMediaGroup', array_merge([
            'chat_id' => $chatId,
            'media' => json_encode($media),
        ], $params));
    }

    /**
     * Отправить локацию
     */
    public function sendLocation(
        int|string $chatId,
        float $latitude,
        float $longitude,
        array $params = []
    ): array {
        Validator::validateChatId($chatId);
        Validator::validateLatitude($latitude);
        Validator::validateLongitude($longitude);
        
        return $this->request('sendLocation', array_merge([
            'chat_id' => $chatId,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ], $params));
    }

    /**
     * Отправить место на карте
     */
    public function sendVenue(
        int|string $chatId,
        float $latitude,
        float $longitude,
        string $title,
        string $address,
        array $params = []
    ): array {
        return $this->request('sendVenue', array_merge([
            'chat_id' => $chatId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'title' => $title,
            'address' => $address,
        ], $params));
    }

    /**
     * Отправить контакт
     */
    public function sendContact(
        int|string $chatId,
        string $phoneNumber,
        string $firstName,
        array $params = []
    ): array {
        return $this->request('sendContact', array_merge([
            'chat_id' => $chatId,
            'phone_number' => $phoneNumber,
            'first_name' => $firstName,
        ], $params));
    }

    /**
     * Отправить опрос
     */
    public function sendPoll(
        int|string $chatId,
        string $question,
        array $options,
        array $params = []
    ): array {
        Validator::validateChatId($chatId);
        Validator::validatePollQuestion($question);
        Validator::validatePollOptions($options);
        
        return $this->request('sendPoll', array_merge([
            'chat_id' => $chatId,
            'question' => $question,
            'options' => json_encode($options),
        ], $params));
    }

    /**
     * Отправить игральный кубик
     */
    public function sendDice(
        int|string $chatId,
        array $params = []
    ): array {
        return $this->request('sendDice', array_merge([
            'chat_id' => $chatId,
        ], $params));
    }

    /**
     * Отправить действие в чате (typing, upload_photo, etc.)
     */
    public function sendChatAction(
        int|string $chatId,
        string $action
    ): array {
        return $this->request('sendChatAction', [
            'chat_id' => $chatId,
            'action' => $action,
        ]);
    }

    // ==========================================
    // Updating messages
    // ==========================================

    /**
     * Редактировать текст сообщения
     */
    public function editMessageText(
        string $text,
        array $params = []
    ): array {
        return $this->request('editMessageText', array_merge([
            'text' => $text,
        ], $params));
    }

    /**
     * Редактировать подпись к сообщению
     */
    public function editMessageCaption(array $params = []): array
    {
        return $this->request('editMessageCaption', $params);
    }

    /**
     * Редактировать медиа сообщение
     */
    public function editMessageMedia(
        array $media,
        array $params = []
    ): array {
        return $this->request('editMessageMedia', array_merge([
            'media' => json_encode($media),
        ], $params));
    }

    /**
     * Редактировать клавиатуру сообщения
     */
    public function editMessageReplyMarkup(array $params = []): array
    {
        return $this->request('editMessageReplyMarkup', $params);
    }

    /**
     * Остановить опрос
     */
    public function stopPoll(
        int|string $chatId,
        int $messageId,
        array $params = []
    ): array {
        return $this->request('stopPoll', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ], $params));
    }

    /**
     * Удалить сообщение
     */
    public function deleteMessage(
        int|string $chatId,
        int $messageId
    ): array {
        Validator::validateChatId($chatId);
        Validator::validateMessageId($messageId);
        
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * Удалить несколько сообщений
     */
    public function deleteMessages(
        int|string $chatId,
        array $messageIds
    ): array {
        return $this->request('deleteMessages', [
            'chat_id' => $chatId,
            'message_ids' => json_encode($messageIds),
        ]);
    }

    // ==========================================
    // Stickers
    // ==========================================

    /**
     * Отправить стикер
     */
    public function sendSticker(
        int|string $chatId,
        string $sticker,
        array $params = []
    ): array {
        return $this->request('sendSticker', array_merge([
            'chat_id' => $chatId,
            'sticker' => $sticker,
        ], $params));
    }

    /**
     * Получить набор стикеров
     */
    public function getStickerSet(string $name): array
    {
        return $this->request('getStickerSet', ['name' => $name]);
    }

    /**
     * Загрузить файл стикера
     */
    public function uploadStickerFile(
        int $userId,
        string $sticker,
        string $stickerFormat
    ): array {
        return $this->request('uploadStickerFile', [
            'user_id' => $userId,
            'sticker' => $sticker,
            'sticker_format' => $stickerFormat,
        ]);
    }

    // ==========================================
    // Inline mode
    // ==========================================

    /**
     * Ответить на inline запрос
     */
    public function answerInlineQuery(
        string $inlineQueryId,
        array $results,
        array $params = []
    ): array {
        return $this->request('answerInlineQuery', array_merge([
            'inline_query_id' => $inlineQueryId,
            'results' => json_encode($results),
        ], $params));
    }

    /**
     * Ответить на Web App запрос
     */
    public function answerWebAppQuery(
        string $webAppQueryId,
        array $result
    ): array {
        return $this->request('answerWebAppQuery', [
            'web_app_query_id' => $webAppQueryId,
            'result' => json_encode($result),
        ]);
    }

    // ==========================================
    // Payments
    // ==========================================

    /**
     * Отправить инвойс
     */
    public function sendInvoice(
        int|string $chatId,
        string $title,
        string $description,
        string $payload,
        string $providerToken,
        string $currency,
        array $prices,
        array $params = []
    ): array {
        return $this->request('sendInvoice', array_merge([
            'chat_id' => $chatId,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'provider_token' => $providerToken,
            'currency' => $currency,
            'prices' => json_encode($prices),
        ], $params));
    }

    /**
     * Создать ссылку на инвойс
     */
    public function createInvoiceLink(
        string $title,
        string $description,
        string $payload,
        string $providerToken,
        string $currency,
        array $prices,
        array $params = []
    ): array {
        return $this->request('createInvoiceLink', array_merge([
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'provider_token' => $providerToken,
            'currency' => $currency,
            'prices' => json_encode($prices),
        ], $params));
    }

    /**
     * Ответить на pre-checkout запрос
     */
    public function answerPreCheckoutQuery(
        string $preCheckoutQueryId,
        bool $ok,
        ?string $errorMessage = null
    ): array {
        $params = [
            'pre_checkout_query_id' => $preCheckoutQueryId,
            'ok' => $ok,
        ];

        if ($errorMessage) {
            $params['error_message'] = $errorMessage;
        }

        return $this->request('answerPreCheckoutQuery', $params);
    }

    /**
     * Ответить на shipping запрос
     */
    public function answerShippingQuery(
        string $shippingQueryId,
        bool $ok,
        array $params = []
    ): array {
        return $this->request('answerShippingQuery', array_merge([
            'shipping_query_id' => $shippingQueryId,
            'ok' => $ok,
        ], $params));
    }

    // ==========================================
    // Telegram Stars (новая платежная система)
    // ==========================================

    /**
     * Получить транзакции Stars
     */
    public function getStarTransactions(array $params = []): array
    {
        return $this->request('getStarTransactions', $params);
    }

    /**
     * Вернуть платеж Stars
     */
    public function refundStarPayment(
        int $userId,
        string $telegramPaymentChargeId
    ): array {
        return $this->request('refundStarPayment', [
            'user_id' => $userId,
            'telegram_payment_charge_id' => $telegramPaymentChargeId,
        ]);
    }

    // ==========================================
    // Games
    // ==========================================

    /**
     * Отправить игру
     */
    public function sendGame(
        int $chatId,
        string $gameShortName,
        array $params = []
    ): array {
        return $this->request('sendGame', array_merge([
            'chat_id' => $chatId,
            'game_short_name' => $gameShortName,
        ], $params));
    }

    /**
     * Установить рекорд игры
     */
    public function setGameScore(
        int $userId,
        int $score,
        array $params = []
    ): array {
        return $this->request('setGameScore', array_merge([
            'user_id' => $userId,
            'score' => $score,
        ], $params));
    }

    /**
     * Получить рекорды игры
     */
    public function getGameHighScores(
        int $userId,
        array $params = []
    ): array {
        return $this->request('getGameHighScores', array_merge([
            'user_id' => $userId,
        ], $params));
    }
}

