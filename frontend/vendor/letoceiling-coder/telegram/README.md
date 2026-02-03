# Telegram Bot API для Laravel

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://www.php.net/)

Полноценная библиотека для работы с Telegram Bot API, Mini App и каналами в Laravel приложениях.

## ✨ Возможности

- 🤖 **90+ методов Bot API** - отправка сообщений, медиа, опросов, игр
- 📢 **Работа с каналами** - управление участниками, администраторами, ссылками-приглашениями
- 📱 **Mini App интеграция** - валидация initData, работа с пользователями
- ⌨️ **Конструктор клавиатур** - inline и reply клавиатуры с удобным API
- ✅ **Автоматическая валидация** - проверка всех данных перед отправкой
- ⚡ **Rate Limiting** - контроль частоты запросов
- 💳 **Telegram Stars** - поддержка платежей
- 🎮 **Игры** - отправка игр и управление рекордами
- 📊 **Типы данных** - удобные классы для User, Chat, Message

## 📦 Установка

### Через Composer

```bash
composer require letoceiling-coder/telegram
```

### Публикация конфигурации

```bash
php artisan vendor:publish --tag=telegram-config
```

### Настройка .env

```env
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_WEBHOOK_URL=https://your-domain.com/api/telegram/webhook
TELEGRAM_MINI_APP_URL=https://your-domain.com
TELEGRAM_BOT_USERNAME=your_bot_username
TELEGRAM_ADMIN_IDS=123456789,987654321
```

## 🚀 Быстрый старт

### Отправка сообщения

```php
use LetoceilingCoder\Telegram\Telegram;

// Простое сообщение
Telegram::send(123456789, 'Привет! 👋');

// С форматированием
Telegram::bot()->sendMessage(123456789, '<b>Жирный</b> текст', [
    'parse_mode' => 'HTML'
]);
```

### Создание клавиатуры

```php
use LetoceilingCoder\Telegram\Telegram;

$keyboard = Telegram::inlineKeyboard()
    ->row()
    ->callback('Кнопка 1', 'btn1')
    ->callback('Кнопка 2', 'btn2')
    ->row()
    ->url('Открыть сайт', 'https://example.com')
    ->webApp('Открыть Mini App', 'https://t.me/your_bot/app')
    ->toArray();

Telegram::bot()->sendMessage(123456789, 'Выберите действие:', [
    'reply_markup' => json_encode($keyboard)
]);
```

### Валидация Mini App

```php
use LetoceilingCoder\Telegram\MiniApp;

$miniApp = new MiniApp();
$initData = $request->header('X-Telegram-Init-Data');

if ($miniApp->validateInitData($initData)) {
    $user = $miniApp->getUser($initData);
    $userId = $user['id'];
    $username = $user['username'] ?? null;
}
```

## 📚 Основные возможности

### Отправка медиа

```php
use LetoceilingCoder\Telegram\Telegram;

// Фото
Telegram::bot()->sendPhoto(123456789, 'https://example.com/photo.jpg', [
    'caption' => 'Красивое фото!'
]);

// Документ
Telegram::bot()->sendDocument(123456789, 'https://example.com/file.pdf');

// Видео
Telegram::bot()->sendVideo(123456789, 'https://example.com/video.mp4', [
    'caption' => 'Видео'
]);

// Голосовое сообщение
Telegram::bot()->sendVoice(123456789, 'https://example.com/voice.ogg');

// Опрос
Telegram::bot()->sendPoll(123456789, 'Какой фреймворк лучше?', [
    'Laravel', 'Symfony', 'Yii2'
], ['is_anonymous' => false]);
```

### Работа с каналами

```php
use LetoceilingCoder\Telegram\Channel;

$channel = new Channel();

// Проверить подписку
$isMember = $channel->isMember('@channel_username', 123456789);

// Получить администраторов
$admins = $channel->getChatAdministrators('@channel_username');

// Забанить пользователя
$channel->banChatMember('@channel_username', 123456789);

// Создать ссылку-приглашение
$inviteLink = $channel->createChatInviteLink('@channel_username', [
    'member_limit' => 100
]);
```

### Webhook

```php
use LetoceilingCoder\Telegram\Bot;

$bot = new Bot();

// Установить webhook
$bot->setWebhook('https://your-domain.com/api/telegram/webhook', [
    'secret_token' => 'your_secret_token',
    'allowed_updates' => ['message', 'callback_query']
]);

// Получить информацию о webhook
$info = $bot->getWebhookInfo();

// Удалить webhook
$bot->deleteWebhook();
```

### Редактирование сообщений

```php
use LetoceilingCoder\Telegram\Telegram;

// Редактировать текст
Telegram::bot()->editMessageText('Новый текст', [
    'chat_id' => 123456789,
    'message_id' => 456
]);

// Удалить сообщение
Telegram::bot()->deleteMessage(123456789, 456);
```

## 🎯 Использование в контроллерах

### Webhook обработчик

```php
namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use LetoceilingCoder\Telegram\Telegram;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $update = $request->all();
        
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
        
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
        
        return response()->json(['ok' => true]);
    }
    
    protected function handleMessage($message)
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        
        if ($text === '/start') {
            Telegram::send($chatId, 'Добро пожаловать!');
        }
    }
    
    protected function handleCallback($callback)
    {
        $callbackId = $callback['id'];
        $data = $callback['data'];
        
        Telegram::callback()->answerWithNotification($callbackId, 'Обработано!');
    }
}
```

### Middleware для Mini App

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LetoceilingCoder\Telegram\MiniApp;
use LetoceilingCoder\Telegram\Exceptions\TelegramValidationException;

class ValidateTelegramInitData
{
    public function handle(Request $request, Closure $next)
    {
        $initData = $request->header('X-Telegram-Init-Data');
        
        if (!$initData) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        try {
            $miniApp = new MiniApp();
            $user = $miniApp->validateAndGetUser($initData);
            $request->merge(['telegram_user' => $user]);
        } catch (TelegramValidationException $e) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }
        
        return $next($request);
    }
}
```

## 📖 API Документация

### Основные классы

- **`Telegram`** - Фасад для быстрого доступа
- **`Bot`** - Работа с Bot API (90+ методов)
- **`Channel`** - Работа с каналами и группами (25+ методов)
- **`MiniApp`** - Валидация Mini App
- **`Callback`** - Обработка callback query
- **`Keyboard`** - Конструктор клавиатур

### Основные методы Bot API

#### Получение обновлений
- `getUpdates()` - получить обновления
- `setWebhook()` - установить webhook
- `deleteWebhook()` - удалить webhook
- `getWebhookInfo()` - информация о webhook

#### Отправка сообщений
- `sendMessage()` - текстовое сообщение
- `sendPhoto()` - фото
- `sendAudio()` - аудио
- `sendDocument()` - документ
- `sendVideo()` - видео
- `sendVoice()` - голосовое сообщение
- `sendPoll()` - опрос
- `sendDice()` - игральный кубик
- `sendLocation()` - локация
- `sendContact()` - контакт

#### Редактирование
- `editMessageText()` - редактировать текст
- `editMessageCaption()` - редактировать подпись
- `deleteMessage()` - удалить сообщение

#### Платежи
- `sendInvoice()` - отправить инвойс
- `getStarTransactions()` - получить транзакции Stars
- `refundStarPayment()` - вернуть платеж

Полный список методов смотрите в [src/README.md](src/README.md)

## 🔒 Валидация и лимиты

Все данные автоматически проверяются перед отправкой:

```php
use LetoceilingCoder\Telegram\Exceptions\TelegramValidationException;

try {
    // Если текст длиннее 4096 символов - выбросит исключение
    Telegram::send(123456789, str_repeat('A', 5000));
} catch (TelegramValidationException $e) {
    echo $e->getMessage();
}
```

### Основные лимиты:
- **Текст сообщения**: до 4096 символов
- **Подпись к медиа**: до 1024 символов
- **Callback data**: до 64 байт
- **Rate limit**: 30 запросов/сек к API, 1 сообщение/сек в чат

Подробнее: [src/LIMITS.md](src/LIMITS.md)

## 🛠️ Дополнительная документация

- **[src/README.md](src/README.md)** - Полная документация с примерами
- **[src/EXAMPLES.md](src/EXAMPLES.md)** - Примеры использования
- **[src/LIMITS.md](src/LIMITS.md)** - Лимиты и валидация
- **[src/FEATURES.md](src/FEATURES.md)** - Полный список возможностей
- **[src/SETUP.md](src/SETUP.md)** - Подробная инструкция по установке

## 🔗 Официальная документация Telegram

- [Bot API](https://core.telegram.org/bots/api)
- [Mini Apps](https://core.telegram.org/bots/webapps)
- [Payments](https://core.telegram.org/bots/payments)

## 📝 Лицензия

MIT License. См. [LICENSE](LICENSE) файл для деталей.

## 🤝 Поддержка

Если у вас есть вопросы или предложения, создайте [Issue](https://github.com/letoceiling-coder/telegram/issues) в репозитории.
