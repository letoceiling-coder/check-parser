# Telegram API для Laravel

Полноценная библиотека для работы с Telegram Bot API, Mini App и каналами.

## Структура

```
app/Telegram/
├── TelegramClient.php      # Базовый клиент для запросов к API
├── Bot.php                 # Работа с Bot API (сообщения, медиа, игры)
├── Channel.php             # Работа с каналами и группами
├── MiniApp.php             # Работа с Mini App (WebApp)
├── Callback.php            # Работа с callback query
├── Keyboard.php            # Создание клавиатур
├── Telegram.php            # Фасад для удобного доступа
├── Validator.php           # ✅ Валидация данных
├── RateLimiter.php         # ⚡ Контроль частоты запросов
├── Limits.php              # 📊 Константы лимитов API
├── Exceptions/             # Исключения
│   ├── TelegramException.php
│   └── TelegramValidationException.php
├── Types/                  # Типы данных Telegram
│   ├── User.php
│   ├── Chat.php
│   ├── Message.php
│   └── ChatMember.php
├── README.md               # 📚 Основная документация
├── EXAMPLES.md             # 💡 Примеры использования
└── LIMITS.md               # 📏 Лимиты и валидация
```

## Установка

### 1. Настройка конфигурации

Добавьте токен бота в `config/services.php`:

```php
'telegram' => [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
],
```

В `.env`:

```env
TELEGRAM_BOT_TOKEN=your_bot_token_here
```

## Использование

### Bot API - Отправка сообщений

```php
use App\Telegram\Bot;

$bot = new Bot();

// Отправить текстовое сообщение
$bot->sendMessage(
    chatId: 123456789,
    text: 'Привет! 👋',
    params: [
        'parse_mode' => 'HTML',
        'disable_notification' => true,
    ]
);

// Отправить фото
$bot->sendPhoto(
    chatId: 123456789,
    photo: 'https://example.com/photo.jpg',
    params: ['caption' => 'Красивое фото!']
);

// Отправить документ
$bot->sendDocument(
    chatId: 123456789,
    document: 'https://example.com/file.pdf'
);

// Отправить голосование
$bot->sendPoll(
    chatId: 123456789,
    question: 'Какой фреймворк лучше?',
    options: ['Laravel', 'Symfony', 'Yii2'],
    params: ['is_anonymous' => false]
);
```

### Работа с клавиатурами

```php
use App\Telegram\Keyboard;

// Inline клавиатура
$keyboard = Keyboard::inline()
    ->row([])
    ->url('Открыть сайт', 'https://example.com')
    ->callback('Нажми меня', 'button_clicked')
    ->row([])
    ->webApp('Открыть Mini App', 'https://t.me/your_bot/app')
    ->get();

$bot->sendMessage(
    chatId: 123456789,
    text: 'Выберите действие:',
    params: ['reply_markup' => json_encode($keyboard)]
);

// Reply клавиатура
$keyboard = Keyboard::reply()
    ->row([])
    ->button('Кнопка 1')
    ->button('Кнопка 2')
    ->row([])
    ->requestContact('Отправить контакт')
    ->requestLocation('Отправить локацию')
    ->get();

// Быстрое создание
$keyboard = Keyboard::makeReply(['Кнопка 1', 'Кнопка 2', 'Кнопка 3'], columns: 2);
```

### Channel - Работа с каналами

```php
use App\Telegram\Channel;

$channel = new Channel();

// Получить информацию о канале
$info = $channel->getChat('@channel_username');

// Проверить подписку пользователя
$isMember = $channel->isMember('@channel_username', 123456789);

// Получить администраторов
$admins = $channel->getChatAdministrators('@channel_username');

// Забанить пользователя
$channel->banChatMember('@channel_username', 123456789);

// Разбанить пользователя
$channel->unbanChatMember('@channel_username', 123456789);

// Закрепить сообщение
$channel->pinChatMessage('@channel_username', messageId: 123);

// Экспортировать ссылку-приглашение
$inviteLink = $channel->exportChatInviteLink('@channel_username');
```

### MiniApp - Работа с Mini App

```php
use App\Telegram\MiniApp;

$miniApp = new MiniApp();

// Валидировать initData
$initData = $request->header('X-Telegram-Init-Data');

if ($miniApp->validateInitData($initData)) {
    // Данные валидны
    $user = $miniApp->getUser($initData);
    $userId = $user['id'];
    $username = $user['username'];
    $isPremium = $miniApp->isPremium($initData);
}

// Получить полную информацию
$data = $miniApp->getFullData($initData);

// Валидировать и получить пользователя (с исключением)
try {
    $user = $miniApp->validateAndGetUser($initData);
} catch (TelegramValidationException $e) {
    return response()->json(['error' => 'Unauthorized'], 401);
}

// Создать URL для Mini App
$url = $miniApp->createMiniAppUrl('your_bot', 'app_name', ['param' => 'value']);

// Создать deep link
$link = $miniApp->createDeepLink('your_bot', 'start_param');
```

### Callback Query

```php
use App\Telegram\Callback;

$callback = new Callback();

// Простое подтверждение
$callback->acknowledge($callbackQueryId);

// С уведомлением
$callback->answerWithNotification($callbackQueryId, 'Кнопка нажата!');

// С alert
$callback->answerWithAlert($callbackQueryId, 'Внимание! Важное сообщение');

// С URL
$callback->answerWithUrl($callbackQueryId, 'https://example.com');
```

### Редактирование сообщений

```php
$bot->editMessageText(
    text: 'Обновленный текст',
    params: [
        'chat_id' => 123456789,
        'message_id' => 456,
    ]
);

$bot->deleteMessage(123456789, messageId: 456);
```

### Payments (Telegram Stars)

```php
// Получить транзакции Stars
$transactions = $bot->getStarTransactions();

// Вернуть платеж
$bot->refundStarPayment(
    userId: 123456789,
    telegramPaymentChargeId: 'charge_id'
);
```

### Работа с типами данных

```php
use App\Telegram\Types\User;
use App\Telegram\Types\Chat;
use App\Telegram\Types\Message;

// Преобразовать массив в объект
$user = User::fromArray($userData);
echo $user->firstName;
echo $user->username;

// Обратно в массив
$array = $user->toArray();

// Работа с Chat
$chat = Chat::fromArray($chatData);
if ($chat->isPrivate()) {
    // Приватный чат
}
if ($chat->isGroup()) {
    // Группа
}
```

## Примеры использования в контроллерах

### Проверка подписки на канал

```php
namespace App\Http\Controllers;

use App\Telegram\Channel;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function check(Request $request)
    {
        $channel = new Channel();
        $userId = $request->input('user_id');
        
        $requiredChannels = ['@channel1', '@channel2'];
        $notSubscribed = [];
        
        foreach ($requiredChannels as $channelUsername) {
            if (!$channel->isMember($channelUsername, $userId)) {
                $notSubscribed[] = $channelUsername;
            }
        }
        
        if (empty($notSubscribed)) {
            return response()->json(['subscribed' => true]);
        }
        
        return response()->json([
            'subscribed' => false,
            'channels' => $notSubscribed,
        ]);
    }
}
```

### Аутентификация через Mini App

```php
namespace App\Http\Controllers\Api;

use App\Telegram\MiniApp;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $miniApp = new MiniApp();
        $initData = $request->header('X-Telegram-Init-Data');
        
        try {
            $user = $miniApp->validateAndGetUser($initData);
            
            // Создать или найти пользователя в БД
            $dbUser = User::firstOrCreate(
                ['telegram_id' => $user['id']],
                [
                    'username' => $user['username'] ?? null,
                    'first_name' => $user['first_name'],
                    'language_code' => $user['language_code'] ?? 'en',
                ]
            );
            
            return response()->json([
                'success' => true,
                'user' => $dbUser,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);
        }
    }
}
```

### Отправка уведомлений

```php
namespace App\Services;

use App\Telegram\Bot;
use App\Telegram\Keyboard;

class NotificationService
{
    protected Bot $bot;
    
    public function __construct()
    {
        $this->bot = new Bot();
    }
    
    public function sendNewTicketNotification(int $userId): void
    {
        $keyboard = Keyboard::inline()
            ->row([])
            ->webApp('Открыть рулетку', config('app.mini_app_url'))
            ->get();
        
        $this->bot->sendMessage(
            chatId: $userId,
            text: "🎫 У вас восстановился билет!\nЗаходите и крутите рулетку!",
            params: ['reply_markup' => json_encode($keyboard)]
        );
    }
}
```

## Методы Bot API

### Получение обновлений
- `getUpdates()` - получить обновления
- `setWebhook()` - установить webhook
- `deleteWebhook()` - удалить webhook
- `getWebhookInfo()` - информация о webhook

### Отправка сообщений
- `sendMessage()` - текстовое сообщение
- `forwardMessage()` - переслать сообщение
- `copyMessage()` - скопировать сообщение
- `sendPhoto()` - фото
- `sendAudio()` - аудио
- `sendDocument()` - документ
- `sendVideo()` - видео
- `sendAnimation()` - GIF
- `sendVoice()` - голосовое сообщение
- `sendVideoNote()` - видео заметка
- `sendMediaGroup()` - группа медиа
- `sendLocation()` - локация
- `sendVenue()` - место
- `sendContact()` - контакт
- `sendPoll()` - опрос
- `sendDice()` - игральный кубик
- `sendChatAction()` - действие (typing, etc.)

### Редактирование сообщений
- `editMessageText()` - редактировать текст
- `editMessageCaption()` - редактировать подпись
- `editMessageMedia()` - редактировать медиа
- `editMessageReplyMarkup()` - редактировать клавиатуру
- `stopPoll()` - остановить опрос
- `deleteMessage()` - удалить сообщение
- `deleteMessages()` - удалить несколько сообщений

### Стикеры
- `sendSticker()` - отправить стикер
- `getStickerSet()` - получить набор стикеров
- `uploadStickerFile()` - загрузить стикер

### Платежи
- `sendInvoice()` - отправить инвойс
- `createInvoiceLink()` - создать ссылку на инвойс
- `answerPreCheckoutQuery()` - ответить на pre-checkout запрос
- `answerShippingQuery()` - ответить на shipping запрос
- `getStarTransactions()` - получить транзакции Stars
- `refundStarPayment()` - вернуть платеж Stars

### Игры
- `sendGame()` - отправить игру
- `setGameScore()` - установить рекорд
- `getGameHighScores()` - получить рекорды

## Методы Channel API

### Информация
- `getChat()` - информация о чате
- `getChatMemberCount()` - количество участников
- `getChatMember()` - информация об участнике
- `getChatAdministrators()` - список администраторов

### Управление
- `setChatTitle()` - установить название
- `setChatDescription()` - установить описание
- `setChatPhoto()` - установить фото
- `deleteChatPhoto()` - удалить фото
- `pinChatMessage()` - закрепить сообщение
- `unpinChatMessage()` - открепить сообщение
- `unpinAllChatMessages()` - открепить все

### Участники
- `banChatMember()` - забанить
- `unbanChatMember()` - разбанить
- `restrictChatMember()` - ограничить права
- `promoteChatMember()` - повысить до админа
- `setChatAdministratorCustomTitle()` - установить титул админа

### Ссылки-приглашения
- `exportChatInviteLink()` - экспортировать ссылку
- `createChatInviteLink()` - создать ссылку
- `editChatInviteLink()` - редактировать ссылку
- `revokeChatInviteLink()` - отозвать ссылку
- `approveChatJoinRequest()` - одобрить запрос
- `declineChatJoinRequest()` - отклонить запрос

### Утилиты
- `isMember()` - проверить членство
- `isAdmin()` - проверить админа
- `leaveChat()` - покинуть чат

## Обработка ошибок

```php
use App\Telegram\Exceptions\TelegramException;

try {
    $bot->sendMessage(123456789, 'Hello');
} catch (TelegramException $e) {
    Log::error('Telegram API error: ' . $e->getMessage());
}
```

## ✅ Валидация и ограничения

Все данные автоматически проверяются перед отправкой:

```php
use App\Telegram\Telegram;
use App\Telegram\Exceptions\TelegramValidationException;

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
- **Название чата**: до 255 символов
- **Rate limit**: 30 запросов/сек к API, 1 сообщение/сек в чат

**Подробнее**: см. [LIMITS.md](LIMITS.md)

### Rate Limiter

```php
use App\Telegram\RateLimiter;

$limiter = new RateLimiter();

// Для массовых рассылок
foreach ($users as $user) {
    $limiter->throttle($user->telegram_id);
    Telegram::send($user->telegram_id, $message);
}
```

## Документация Telegram

- Bot API: https://core.telegram.org/bots/api
- Mini Apps: https://core.telegram.org/bots/webapps
- Payments: https://core.telegram.org/bots/payments
- **Лимиты и валидация**: [LIMITS.md](LIMITS.md)
- **Примеры использования**: [EXAMPLES.md](EXAMPLES.md)

