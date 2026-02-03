<?php

namespace LetoceilingCoder\Telegram;

use LetoceilingCoder\Telegram\Exceptions\TelegramValidationException;
use Illuminate\Support\Facades\Log;

/**
 * Класс для работы с Telegram Mini App (WebApp)
 * Документация: https://core.telegram.org/bots/webapps
 */
class MiniApp
{
    protected string $botToken;

    public function __construct(?string $botToken = null)
    {
        $this->botToken = $botToken ?? config('services.telegram.bot_token');
        
        if (!$this->botToken) {
            throw new TelegramValidationException('Telegram bot token not configured');
        }
    }

    // ==========================================
    // Validation
    // ==========================================

    /**
     * Валидировать данные initData от Telegram WebApp
     * 
     * @param string $initData - Строка initData от Telegram.WebApp.initData
     * @return bool
     */
    public function validateInitData(string $initData): bool
    {
        try {
            parse_str($initData, $data);
            
            if (!isset($data['hash'])) {
                Log::warning('Telegram initData: hash not found');
                return false;
            }

            $hash = $data['hash'];
            unset($data['hash']);

            // Сортируем данные
            ksort($data);
            
            // Формируем строку для проверки
            $dataCheckString = [];
            foreach ($data as $key => $value) {
                $dataCheckString[] = $key . '=' . $value;
            }
            $dataCheckString = implode("\n", $dataCheckString);

            // Создаем secret key
            $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
            
            // Создаем hash для проверки
            $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

            $isValid = hash_equals($calculatedHash, $hash);

            if (!$isValid) {
                Log::warning('Telegram initData: invalid hash', [
                    'expected' => $calculatedHash,
                    'received' => $hash,
                ]);
            }

            return $isValid;

        } catch (\Exception $e) {
            Log::error('Telegram initData validation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Парсить данные initData
     * 
     * @param string $initData
     * @return array
     */
    public function parseInitData(string $initData): array
    {
        parse_str($initData, $data);
        
        // Декодируем JSON поля
        if (isset($data['user'])) {
            $data['user'] = json_decode($data['user'], true);
        }
        
        if (isset($data['receiver'])) {
            $data['receiver'] = json_decode($data['receiver'], true);
        }
        
        if (isset($data['chat'])) {
            $data['chat'] = json_decode($data['chat'], true);
        }
        
        if (isset($data['chat_instance'])) {
            $data['chat_instance'] = json_decode($data['chat_instance'], true);
        }

        return $data;
    }

    /**
     * Получить ID пользователя из initData
     * 
     * @param string $initData
     * @return int|null
     */
    public function getUserId(string $initData): ?int
    {
        $data = $this->parseInitData($initData);
        return $data['user']['id'] ?? null;
    }

    /**
     * Получить данные пользователя из initData
     * 
     * @param string $initData
     * @return array|null
     */
    public function getUser(string $initData): ?array
    {
        $data = $this->parseInitData($initData);
        return $data['user'] ?? null;
    }

    /**
     * Получить query_id из initData
     * 
     * @param string $initData
     * @return string|null
     */
    public function getQueryId(string $initData): ?string
    {
        $data = $this->parseInitData($initData);
        return $data['query_id'] ?? null;
    }

    /**
     * Получить chat_type из initData
     * 
     * @param string $initData
     * @return string|null
     */
    public function getChatType(string $initData): ?string
    {
        $data = $this->parseInitData($initData);
        return $data['chat_type'] ?? null;
    }

    /**
     * Получить start_param из initData
     * 
     * @param string $initData
     * @return string|null
     */
    public function getStartParam(string $initData): ?string
    {
        $data = $this->parseInitData($initData);
        return $data['start_param'] ?? null;
    }

    /**
     * Проверить, является ли пользователь премиум
     * 
     * @param string $initData
     * @return bool
     */
    public function isPremium(string $initData): bool
    {
        $user = $this->getUser($initData);
        return $user['is_premium'] ?? false;
    }

    /**
     * Получить язык пользователя
     * 
     * @param string $initData
     * @return string
     */
    public function getLanguageCode(string $initData): string
    {
        $user = $this->getUser($initData);
        return $user['language_code'] ?? 'en';
    }

    // ==========================================
    // Utilities
    // ==========================================

    /**
     * Создать данные для кнопки WebApp
     * 
     * @param string $text - Текст кнопки
     * @param string $url - URL Mini App
     * @return array
     */
    public function createWebAppButton(string $text, string $url): array
    {
        return [
            'text' => $text,
            'web_app' => ['url' => $url],
        ];
    }

    /**
     * Создать inline клавиатуру с кнопкой WebApp
     * 
     * @param string $text - Текст кнопки
     * @param string $url - URL Mini App
     * @return array
     */
    public function createWebAppKeyboard(string $text, string $url): array
    {
        return [
            'inline_keyboard' => [
                [$this->createWebAppButton($text, $url)],
            ],
        ];
    }

    /**
     * Проверить срок действия initData (по умолчанию 24 часа)
     * 
     * @param string $initData
     * @param int $maxAge - Максимальный возраст в секундах (по умолчанию 86400 - 24 часа)
     * @return bool
     */
    public function isInitDataExpired(string $initData, int $maxAge = 86400): bool
    {
        $data = $this->parseInitData($initData);
        
        if (!isset($data['auth_date'])) {
            return true;
        }

        $authDate = (int) $data['auth_date'];
        $currentTime = time();
        
        return ($currentTime - $authDate) > $maxAge;
    }

    /**
     * Получить полную информацию из initData
     * 
     * @param string $initData
     * @return array
     */
    public function getFullData(string $initData): array
    {
        return [
            'valid' => $this->validateInitData($initData),
            'expired' => $this->isInitDataExpired($initData),
            'data' => $this->parseInitData($initData),
            'user_id' => $this->getUserId($initData),
            'user' => $this->getUser($initData),
            'query_id' => $this->getQueryId($initData),
            'chat_type' => $this->getChatType($initData),
            'start_param' => $this->getStartParam($initData),
            'is_premium' => $this->isPremium($initData),
            'language_code' => $this->getLanguageCode($initData),
        ];
    }

    /**
     * Валидировать и получить пользователя
     * Бросает исключение если данные невалидны
     * 
     * @param string $initData
     * @return array
     * @throws TelegramValidationException
     */
    public function validateAndGetUser(string $initData): array
    {
        if (!$this->validateInitData($initData)) {
            throw new TelegramValidationException('Invalid initData signature');
        }

        if ($this->isInitDataExpired($initData)) {
            throw new TelegramValidationException('InitData expired');
        }

        $user = $this->getUser($initData);
        
        if (!$user) {
            throw new TelegramValidationException('User data not found in initData');
        }

        return $user;
    }

    /**
     * Создать URL для открытия Mini App
     * 
     * @param string $botUsername - Username бота
     * @param string $appName - Short name приложения (из @BotFather)
     * @param array $params - Дополнительные параметры
     * @return string
     */
    public function createMiniAppUrl(
        string $botUsername,
        string $appName,
        array $params = []
    ): string {
        $url = "https://t.me/{$botUsername}/{$appName}";
        
        if (!empty($params)) {
            $queryString = http_build_query($params);
            $url .= '?' . $queryString;
        }
        
        return $url;
    }

    /**
     * Создать deep link для открытия бота с параметром start
     * 
     * @param string $botUsername
     * @param string $startParam
     * @return string
     */
    public function createDeepLink(string $botUsername, string $startParam): string
    {
        return "https://t.me/{$botUsername}?start={$startParam}";
    }
}

