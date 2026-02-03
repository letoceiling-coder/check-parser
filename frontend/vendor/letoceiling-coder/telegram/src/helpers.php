<?php

use LetoceilingCoder\Telegram\Bot;
use LetoceilingCoder\Telegram\Channel;
use LetoceilingCoder\Telegram\MiniApp;
use LetoceilingCoder\Telegram\Callback;
use LetoceilingCoder\Telegram\Keyboard;

if (!function_exists('telegram')) {
    /**
     * Получить экземпляр Telegram (Bot)
     */
    function telegram(): Bot
    {
        return app('telegram.bot');
    }
}

if (!function_exists('telegram_channel')) {
    /**
     * Получить экземпляр Telegram Channel
     */
    function telegram_channel(): Channel
    {
        return app('telegram.channel');
    }
}

if (!function_exists('telegram_miniapp')) {
    /**
     * Получить экземпляр Telegram MiniApp
     */
    function telegram_miniapp(): MiniApp
    {
        return app('telegram.miniapp');
    }
}

if (!function_exists('telegram_callback')) {
    /**
     * Получить экземпляр Telegram Callback
     */
    function telegram_callback(): Callback
    {
        return app('telegram.callback');
    }
}

if (!function_exists('telegram_send')) {
    /**
     * Быстрая отправка сообщения
     * 
     * @param int|string $chatId
     * @param string $text
     * @param array $params
     * @return array
     */
    function telegram_send(int|string $chatId, string $text, array $params = []): array
    {
        return telegram()->sendMessage($chatId, $text, $params);
    }
}

if (!function_exists('telegram_inline_keyboard')) {
    /**
     * Создать inline клавиатуру
     */
    function telegram_inline_keyboard(): Keyboard
    {
        return Keyboard::inline();
    }
}

if (!function_exists('telegram_reply_keyboard')) {
    /**
     * Создать reply клавиатуру
     */
    function telegram_reply_keyboard(): Keyboard
    {
        return Keyboard::reply();
    }
}

if (!function_exists('telegram_check_subscription')) {
    /**
     * Проверить подписку на канал
     * 
     * @param int|string $chatId
     * @param int $userId
     * @return bool
     */
    function telegram_check_subscription(int|string $chatId, int $userId): bool
    {
        return telegram_channel()->isMember($chatId, $userId);
    }
}

if (!function_exists('telegram_validate_miniapp')) {
    /**
     * Валидировать Mini App initData
     * 
     * @param string $initData
     * @return bool
     */
    function telegram_validate_miniapp(string $initData): bool
    {
        return telegram_miniapp()->validateInitData($initData);
    }
}

if (!function_exists('telegram_get_user')) {
    /**
     * Получить пользователя из Mini App initData
     * 
     * @param string $initData
     * @return array|null
     */
    function telegram_get_user(string $initData): ?array
    {
        return telegram_miniapp()->getUser($initData);
    }
}

if (!function_exists('telegram_is_admin')) {
    /**
     * Проверить является ли пользователь администратором
     * 
     * @param int $userId
     * @return bool
     */
    function telegram_is_admin(int $userId): bool
    {
        $adminIds = config('telegram.admin_ids', []);
        return in_array($userId, $adminIds);
    }
}

if (!function_exists('telegram_format_html')) {
    /**
     * Экранировать HTML для Telegram
     * 
     * @param string $text
     * @return string
     */
    function telegram_format_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('telegram_format_markdown')) {
    /**
     * Экранировать Markdown для Telegram (MarkdownV2)
     * 
     * @param string $text
     * @return string
     */
    function telegram_format_markdown(string $text): string
    {
        $specialChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        
        foreach ($specialChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        
        return $text;
    }
}

if (!function_exists('telegram_user_link')) {
    /**
     * Создать ссылку на пользователя
     * 
     * @param int $userId
     * @param string $name
     * @param string $format 'html' or 'markdown'
     * @return string
     */
    function telegram_user_link(int $userId, string $name, string $format = 'html'): string
    {
        if ($format === 'html') {
            return "<a href=\"tg://user?id={$userId}\">{$name}</a>";
        }
        
        return "[{$name}](tg://user?id={$userId})";
    }
}

if (!function_exists('telegram_deep_link')) {
    /**
     * Создать deep link для бота
     * 
     * @param string $param
     * @return string
     */
    function telegram_deep_link(string $param = ''): string
    {
        $botUsername = config('telegram.bot_username');
        
        if (!$botUsername) {
            throw new \Exception('Bot username not configured');
        }
        
        $url = "https://t.me/{$botUsername}";
        
        if ($param) {
            $url .= "?start={$param}";
        }
        
        return $url;
    }
}

