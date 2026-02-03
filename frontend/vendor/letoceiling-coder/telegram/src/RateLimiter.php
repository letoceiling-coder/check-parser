<?php

namespace LetoceilingCoder\Telegram;

use Illuminate\Support\Facades\Cache;
use LetoceilingCoder\Telegram\Exceptions\TelegramException;

/**
 * Rate Limiter для контроля частоты запросов к Telegram API
 * 
 * Лимиты Telegram:
 * - 30 запросов в секунду к Bot API
 * - 1 сообщение в секунду в один чат
 * - 20 сообщений в минуту в группу
 */
class RateLimiter
{
    protected string $prefix = 'telegram_rate_limit:';
    
    /**
     * Проверить можно ли отправить запрос
     */
    public function canMakeRequest(string $key, int $maxRequests, int $seconds): bool
    {
        $cacheKey = $this->prefix . $key;
        $attempts = Cache::get($cacheKey, 0);
        
        return $attempts < $maxRequests;
    }

    /**
     * Зарегистрировать запрос
     */
    public function hit(string $key, int $seconds = 1): void
    {
        $cacheKey = $this->prefix . $key;
        
        if (Cache::has($cacheKey)) {
            Cache::increment($cacheKey);
        } else {
            Cache::put($cacheKey, 1, $seconds);
        }
    }

    /**
     * Проверить лимит для общих API запросов (30 req/sec)
     */
    public function checkApiLimit(): void
    {
        $key = 'api_global';
        
        if (!$this->canMakeRequest($key, Limits::API_REQUESTS_PER_SECOND, 1)) {
            throw new TelegramException('API rate limit exceeded. Max 30 requests per second.');
        }
        
        $this->hit($key, 1);
    }

    /**
     * Проверить лимит для отправки в конкретный чат (1 msg/sec)
     */
    public function checkChatLimit(int|string $chatId): void
    {
        $key = 'chat:' . $chatId;
        
        if (!$this->canMakeRequest($key, Limits::MESSAGES_PER_SECOND_PER_CHAT, 1)) {
            throw new TelegramException("Rate limit exceeded for chat {$chatId}. Max 1 message per second.");
        }
        
        $this->hit($key, 1);
    }

    /**
     * Проверить лимит для отправки в группу (20 msg/min)
     */
    public function checkGroupLimit(int|string $chatId): void
    {
        $key = 'group:' . $chatId;
        
        if (!$this->canMakeRequest($key, Limits::MESSAGES_PER_MINUTE_PER_GROUP, 60)) {
            throw new TelegramException("Rate limit exceeded for group {$chatId}. Max 20 messages per minute.");
        }
        
        $this->hit($key, 60);
    }

    /**
     * Получить количество оставшихся запросов
     */
    public function remaining(string $key, int $maxRequests): int
    {
        $cacheKey = $this->prefix . $key;
        $attempts = Cache::get($cacheKey, 0);
        
        return max(0, $maxRequests - $attempts);
    }

    /**
     * Сбросить лимит (для тестирования)
     */
    public function reset(string $key): void
    {
        $cacheKey = $this->prefix . $key;
        Cache::forget($cacheKey);
    }

    /**
     * Сбросить все лимиты
     */
    public function resetAll(): void
    {
        // В продакшене это не рекомендуется, только для разработки/тестов
        Cache::flush();
    }

    /**
     * Умная задержка перед отправкой (для массовых рассылок)
     * 
     * @param int|string $chatId
     * @param bool $isGroup
     */
    public function throttle(int|string $chatId, bool $isGroup = false): void
    {
        if ($isGroup) {
            // Для групп - 20 сообщений в минуту = одно сообщение каждые 3 секунды
            $key = 'group:' . $chatId;
            $attempts = Cache::get($this->prefix . $key, 0);
            
            if ($attempts >= Limits::MESSAGES_PER_MINUTE_PER_GROUP) {
                // Ждем пока лимит не сбросится
                sleep(3);
            }
        } else {
            // Для обычных чатов - 1 сообщение в секунду
            $key = 'chat:' . $chatId;
            $attempts = Cache::get($this->prefix . $key, 0);
            
            if ($attempts >= Limits::MESSAGES_PER_SECOND_PER_CHAT) {
                usleep(100000); // 0.1 секунда
            }
        }
    }

    /**
     * Получить информацию о лимитах
     */
    public function getInfo(): array
    {
        return [
            'api_requests_per_second' => Limits::API_REQUESTS_PER_SECOND,
            'messages_per_second_per_chat' => Limits::MESSAGES_PER_SECOND_PER_CHAT,
            'messages_per_minute_per_group' => Limits::MESSAGES_PER_MINUTE_PER_GROUP,
            'total_messages_per_second' => Limits::MESSAGES_PER_SECOND_TOTAL,
        ];
    }
}

