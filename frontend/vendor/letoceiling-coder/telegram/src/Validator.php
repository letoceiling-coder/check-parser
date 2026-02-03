<?php

namespace LetoceilingCoder\Telegram;

use LetoceilingCoder\Telegram\Exceptions\TelegramValidationException;

/**
 * Валидатор для проверки данных перед отправкой в Telegram API
 */
class Validator
{
    /**
     * Валидировать текст сообщения
     */
    public static function validateMessageText(string $text): void
    {
        if (empty($text)) {
            throw new TelegramValidationException('Message text cannot be empty');
        }

        $length = mb_strlen($text);
        if ($length > Limits::MESSAGE_TEXT_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Message text is too long ({$length} characters). Maximum: " . Limits::MESSAGE_TEXT_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать подпись к медиа
     */
    public static function validateCaption(?string $caption): void
    {
        if ($caption === null) {
            return;
        }

        $length = mb_strlen($caption);
        if ($length > Limits::CAPTION_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Caption is too long ({$length} characters). Maximum: " . Limits::CAPTION_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать callback data
     */
    public static function validateCallbackData(string $data): void
    {
        $length = strlen($data); // callback_data измеряется в байтах, не символах
        if ($length > Limits::CALLBACK_DATA_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Callback data is too long ({$length} bytes). Maximum: " . Limits::CALLBACK_DATA_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать текст кнопки
     */
    public static function validateButtonText(string $text): void
    {
        if (empty($text)) {
            throw new TelegramValidationException('Button text cannot be empty');
        }

        $length = mb_strlen($text);
        if ($length > Limits::BUTTON_TEXT_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Button text is too long ({$length} characters). Maximum: " . Limits::BUTTON_TEXT_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать URL
     */
    public static function validateUrl(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new TelegramValidationException("Invalid URL: {$url}");
        }

        $length = strlen($url);
        if ($length > Limits::BUTTON_URL_MAX_LENGTH) {
            throw new TelegramValidationException(
                "URL is too long ({$length} characters). Maximum: " . Limits::BUTTON_URL_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать chat_id
     */
    public static function validateChatId(int|string $chatId): void
    {
        if (is_string($chatId)) {
            // Username должен начинаться с @
            if (!str_starts_with($chatId, '@')) {
                throw new TelegramValidationException(
                    "Channel username must start with @: {$chatId}"
                );
            }
            
            // Username должен содержать только допустимые символы
            if (!preg_match('/^@[a-zA-Z0-9_]{5,32}$/', $chatId)) {
                throw new TelegramValidationException(
                    "Invalid channel username format: {$chatId}"
                );
            }
        } elseif (is_int($chatId)) {
            // ID не может быть 0
            if ($chatId === 0) {
                throw new TelegramValidationException('Chat ID cannot be 0');
            }
        } else {
            throw new TelegramValidationException('Chat ID must be integer or string');
        }
    }

    /**
     * Валидировать название чата
     */
    public static function validateChatTitle(string $title): void
    {
        if (empty($title)) {
            throw new TelegramValidationException('Chat title cannot be empty');
        }

        $length = mb_strlen($title);
        if ($length > Limits::CHAT_TITLE_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Chat title is too long ({$length} characters). Maximum: " . Limits::CHAT_TITLE_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать описание чата
     */
    public static function validateChatDescription(string $description): void
    {
        $length = mb_strlen($description);
        if ($length > Limits::CHAT_DESCRIPTION_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Chat description is too long ({$length} characters). Maximum: " . Limits::CHAT_DESCRIPTION_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать вопрос опроса
     */
    public static function validatePollQuestion(string $question): void
    {
        if (empty($question)) {
            throw new TelegramValidationException('Poll question cannot be empty');
        }

        $length = mb_strlen($question);
        if ($length > Limits::POLL_QUESTION_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Poll question is too long ({$length} characters). Maximum: " . Limits::POLL_QUESTION_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать варианты ответа опроса
     */
    public static function validatePollOptions(array $options): void
    {
        $count = count($options);
        
        if ($count < Limits::POLL_OPTIONS_MIN_COUNT) {
            throw new TelegramValidationException(
                "Poll must have at least " . Limits::POLL_OPTIONS_MIN_COUNT . " options"
            );
        }

        if ($count > Limits::POLL_OPTIONS_MAX_COUNT) {
            throw new TelegramValidationException(
                "Poll cannot have more than " . Limits::POLL_OPTIONS_MAX_COUNT . " options. Got: {$count}"
            );
        }

        foreach ($options as $index => $option) {
            $length = mb_strlen($option);
            if ($length > Limits::POLL_OPTION_MAX_LENGTH) {
                throw new TelegramValidationException(
                    "Poll option #{$index} is too long ({$length} characters). Maximum: " . Limits::POLL_OPTION_MAX_LENGTH
                );
            }
        }
    }

    /**
     * Валидировать media group
     */
    public static function validateMediaGroup(array $media): void
    {
        $count = count($media);
        
        if ($count < Limits::MEDIA_GROUP_MIN_COUNT) {
            throw new TelegramValidationException(
                "Media group must have at least " . Limits::MEDIA_GROUP_MIN_COUNT . " items"
            );
        }

        if ($count > Limits::MEDIA_GROUP_MAX_COUNT) {
            throw new TelegramValidationException(
                "Media group cannot have more than " . Limits::MEDIA_GROUP_MAX_COUNT . " items. Got: {$count}"
            );
        }
    }

    /**
     * Валидировать кастомный титул администратора
     */
    public static function validateCustomTitle(string $title): void
    {
        $length = mb_strlen($title);
        if ($length > Limits::ADMIN_CUSTOM_TITLE_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Custom title is too long ({$length} characters). Maximum: " . Limits::ADMIN_CUSTOM_TITLE_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать название товара (invoice)
     */
    public static function validateInvoiceTitle(string $title): void
    {
        if (empty($title)) {
            throw new TelegramValidationException('Invoice title cannot be empty');
        }

        $length = mb_strlen($title);
        if ($length > Limits::INVOICE_TITLE_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Invoice title is too long ({$length} characters). Maximum: " . Limits::INVOICE_TITLE_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать описание товара (invoice)
     */
    public static function validateInvoiceDescription(string $description): void
    {
        if (empty($description)) {
            throw new TelegramValidationException('Invoice description cannot be empty');
        }

        $length = mb_strlen($description);
        if ($length > Limits::INVOICE_DESCRIPTION_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Invoice description is too long ({$length} characters). Maximum: " . Limits::INVOICE_DESCRIPTION_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать payload товара (invoice)
     */
    public static function validateInvoicePayload(string $payload): void
    {
        if (empty($payload)) {
            throw new TelegramValidationException('Invoice payload cannot be empty');
        }

        $length = strlen($payload);
        if ($length > Limits::INVOICE_PAYLOAD_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Invoice payload is too long ({$length} bytes). Maximum: " . Limits::INVOICE_PAYLOAD_MAX_LENGTH
            );
        }
    }

    /**
     * Валидировать prices товара (invoice)
     */
    public static function validateInvoicePrices(array $prices): void
    {
        if (empty($prices)) {
            throw new TelegramValidationException('Invoice must have at least one price');
        }

        $count = count($prices);
        if ($count > Limits::INVOICE_PRICES_MAX_COUNT) {
            throw new TelegramValidationException(
                "Invoice cannot have more than " . Limits::INVOICE_PRICES_MAX_COUNT . " prices. Got: {$count}"
            );
        }

        foreach ($prices as $index => $price) {
            if (!isset($price['label']) || !isset($price['amount'])) {
                throw new TelegramValidationException(
                    "Price #{$index} must have 'label' and 'amount' fields"
                );
            }

            if (!is_int($price['amount']) || $price['amount'] <= 0) {
                throw new TelegramValidationException(
                    "Price #{$index} amount must be a positive integer"
                );
            }
        }
    }

    /**
     * Валидировать deep link параметр
     */
    public static function validateDeepLinkParam(string $param): void
    {
        $length = strlen($param);
        
        if ($length < Limits::DEEP_LINK_PARAM_MIN_LENGTH) {
            throw new TelegramValidationException(
                "Deep link parameter is too short. Minimum: " . Limits::DEEP_LINK_PARAM_MIN_LENGTH
            );
        }

        if ($length > Limits::DEEP_LINK_PARAM_MAX_LENGTH) {
            throw new TelegramValidationException(
                "Deep link parameter is too long ({$length} characters). Maximum: " . Limits::DEEP_LINK_PARAM_MAX_LENGTH
            );
        }

        // Разрешены только A-Z, a-z, 0-9, _ и -
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $param)) {
            throw new TelegramValidationException(
                "Deep link parameter can only contain letters, numbers, underscore and hyphen"
            );
        }
    }

    /**
     * Валидировать parse_mode
     */
    public static function validateParseMode(?string $parseMode): void
    {
        if ($parseMode === null) {
            return;
        }

        $allowedModes = ['Markdown', 'MarkdownV2', 'HTML'];
        if (!in_array($parseMode, $allowedModes)) {
            throw new TelegramValidationException(
                "Invalid parse_mode: {$parseMode}. Allowed: " . implode(', ', $allowedModes)
            );
        }
    }

    /**
     * Валидировать user_id
     */
    public static function validateUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new TelegramValidationException(
                "User ID must be a positive integer. Got: {$userId}"
            );
        }
    }

    /**
     * Валидировать message_id
     */
    public static function validateMessageId(int $messageId): void
    {
        if ($messageId <= 0) {
            throw new TelegramValidationException(
                "Message ID must be a positive integer. Got: {$messageId}"
            );
        }
    }

    /**
     * Валидировать limit для getUpdates
     */
    public static function validateUpdatesLimit(?int $limit): void
    {
        if ($limit === null) {
            return;
        }

        if ($limit < 1 || $limit > Limits::UPDATES_LIMIT_MAX) {
            throw new TelegramValidationException(
                "Updates limit must be between 1 and " . Limits::UPDATES_LIMIT_MAX . ". Got: {$limit}"
            );
        }
    }

    /**
     * Валидировать timeout для getUpdates
     */
    public static function validateLongPollingTimeout(?int $timeout): void
    {
        if ($timeout === null) {
            return;
        }

        if ($timeout < 0 || $timeout > Limits::LONG_POLLING_TIMEOUT_MAX) {
            throw new TelegramValidationException(
                "Timeout must be between 0 and " . Limits::LONG_POLLING_TIMEOUT_MAX . ". Got: {$timeout}"
            );
        }
    }

    /**
     * Валидировать координаты (широта)
     */
    public static function validateLatitude(float $latitude): void
    {
        if ($latitude < -90 || $latitude > 90) {
            throw new TelegramValidationException(
                "Latitude must be between -90 and 90. Got: {$latitude}"
            );
        }
    }

    /**
     * Валидировать координаты (долгота)
     */
    public static function validateLongitude(float $longitude): void
    {
        if ($longitude < -180 || $longitude > 180) {
            throw new TelegramValidationException(
                "Longitude must be between -180 and 180. Got: {$longitude}"
            );
        }
    }

    /**
     * Автоматически обрезать текст до допустимой длины
     */
    public static function truncateText(string $text, int $maxLength, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $suffixLength = mb_strlen($suffix);
        return mb_substr($text, 0, $maxLength - $suffixLength) . $suffix;
    }

    /**
     * Разбить длинный текст на несколько сообщений
     */
    public static function splitLongText(string $text, int $maxLength = null): array
    {
        $maxLength = $maxLength ?? Limits::MESSAGE_TEXT_MAX_LENGTH;
        
        if (mb_strlen($text) <= $maxLength) {
            return [$text];
        }

        $messages = [];
        $parts = explode("\n", $text);
        $currentMessage = '';

        foreach ($parts as $part) {
            $partLength = mb_strlen($part) + 1; // +1 для \n
            
            if (mb_strlen($currentMessage) + $partLength <= $maxLength) {
                $currentMessage .= ($currentMessage ? "\n" : '') . $part;
            } else {
                if ($currentMessage) {
                    $messages[] = $currentMessage;
                }
                
                // Если одна строка длиннее maxLength, разбиваем ее
                if (mb_strlen($part) > $maxLength) {
                    $chunks = mb_str_split($part, $maxLength);
                    foreach ($chunks as $chunk) {
                        $messages[] = $chunk;
                    }
                    $currentMessage = '';
                } else {
                    $currentMessage = $part;
                }
            }
        }

        if ($currentMessage) {
            $messages[] = $currentMessage;
        }

        return $messages;
    }
}

