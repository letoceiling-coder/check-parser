<?php

namespace LetoceilingCoder\Telegram;

/**
 * Фасад для удобного доступа ко всем Telegram классам
 */
class Telegram
{
    /**
     * Получить экземпляр Bot
     */
    public static function bot(?string $token = null): Bot
    {
        return new Bot($token);
    }

    /**
     * Получить экземпляр Channel
     */
    public static function channel(?string $token = null): Channel
    {
        return new Channel($token);
    }

    /**
     * Получить экземпляр MiniApp
     */
    public static function miniApp(?string $token = null): MiniApp
    {
        return new MiniApp($token);
    }

    /**
     * Получить экземпляр Callback
     */
    public static function callback(?string $token = null): Callback
    {
        return new Callback($token);
    }

    /**
     * Создать inline клавиатуру
     */
    public static function inlineKeyboard(): Keyboard
    {
        return Keyboard::inline();
    }

    /**
     * Создать reply клавиатуру
     */
    public static function replyKeyboard(): Keyboard
    {
        return Keyboard::reply();
    }

    /**
     * Быстрая отправка сообщения
     */
    public static function send(int|string $chatId, string $text, array $params = []): array
    {
        return static::bot()->sendMessage($chatId, $text, $params);
    }

    /**
     * Проверить подписку на канал
     */
    public static function checkSubscription(int|string $chatId, int $userId): bool
    {
        return static::channel()->isMember($chatId, $userId);
    }

    /**
     * Валидировать Mini App initData
     */
    public static function validateMiniApp(string $initData): bool
    {
        return static::miniApp()->validateInitData($initData);
    }

    /**
     * Получить пользователя из Mini App initData
     */
    public static function getMiniAppUser(string $initData): ?array
    {
        return static::miniApp()->getUser($initData);
    }
}

