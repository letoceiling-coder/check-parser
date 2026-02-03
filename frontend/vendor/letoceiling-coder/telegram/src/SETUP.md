# Установка и настройка Telegram для Laravel

## 🚀 Быстрая установка

### 1. Регистрация Service Provider

Добавьте `TelegramServiceProvider` в `config/app.php`:

```php
'providers' => [
    // ...
    App\Providers\TelegramServiceProvider::class,
],
```

Или для Laravel 11+ автоматически загрузится через `bootstrap/providers.php`.

### 2. Публикация конфигурации

```bash
php artisan vendor:publish --tag=telegram-config
```

Это создаст файл `config/telegram.php`.

### 3. Настройка .env

Добавьте в `.env`:

```env
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_BOT_USERNAME=your_bot_username
TELEGRAM_WEBHOOK_URL="${APP_URL}/api/telegram/webhook"
TELEGRAM_MINI_APP_URL="${APP_URL}"

# Опционально
TELEGRAM_ADMIN_IDS=123456789,987654321
TELEGRAM_WEBHOOK_SECRET=your_secret_token
```

### 4. Загрузка helper функций

В `composer.json` добавьте:

```json
"autoload": {
    "files": [
        "app/Telegram/helpers.php"
    ]
}
```

Затем:

```bash
composer dump-autoload
```

### 5. Регистрация Middleware

В `bootstrap/app.php` (Laravel 11) или `app/Http/Kernel.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'telegram.auth' => \App\Http\Middleware\TelegramAuth::class,
        'telegram.webhook' => \App\Http\Middleware\TelegramWebhook::class,
        'telegram.admin' => \App\Http\Middleware\TelegramAdmin::class,
    ]);
})
```

### 6. Настройка webhook

```bash
# Установить webhook
php artisan telegram:set-webhook

# Проверить статус
php artisan telegram:webhook-info

# Удалить webhook
php artisan telegram:delete-webhook
```

## 📚 Использование

### Helper функции

Самый простой способ использования:

```php
// Отправить сообщение
telegram_send(123456789, 'Привет!');

// Проверить подписку
$isSubscribed = telegram_check_subscription('@channel', 123456789);

// Валидировать Mini App
$isValid = telegram_validate_miniapp($initData);
$user = telegram_get_user($initData);

// Создать клавиатуру
$keyboard = telegram_inline_keyboard()
    ->url('Сайт', 'https://example.com')
    ->callback('Кнопка', 'data')
    ->get();

// Deep link
$link = telegram_deep_link('referral_123');
```

### Через Dependency Injection

```php
use App\Telegram\Bot;
use App\Telegram\Channel;
use App\Telegram\MiniApp;

class MyController extends Controller
{
    public function __construct(
        protected Bot $bot,
        protected Channel $channel,
        protected MiniApp $miniApp
    ) {}
    
    public function sendMessage()
    {
        $this->bot->sendMessage(123456789, 'Сообщение');
    }
}
```

### Через фасад

```php
use App\Telegram\Telegram;

Telegram::send(123456789, 'Сообщение');
Telegram::checkSubscription('@channel', 123456789);
```

## 🔒 Middleware

### TelegramAuth - Аутентификация Mini App

```php
Route::middleware('telegram.auth')->group(function () {
    Route::post('/api/user/tickets', [TicketController::class, 'get']);
});
```

Добавляет в request:
- `telegram_user` - данные пользователя
- `telegram_user_id` - ID пользователя

```php
$userId = $request->telegram_user_id;
$user = $request->telegram_user;
```

### TelegramWebhook - Проверка webhook

```php
Route::post('/api/telegram/webhook', [WebhookController::class, 'handle'])
    ->middleware('telegram.webhook');
```

Проверяет:
- Secret token (если настроен)
- IP адрес Telegram (опционально)

### TelegramAdmin - Проверка прав администратора

```php
Route::middleware(['telegram.auth', 'telegram.admin'])->group(function () {
    Route::post('/api/admin/broadcast', [AdminController::class, 'broadcast']);
});
```

Проверяет, что пользователь в списке `telegram.admin_ids`.

## 📦 Queue Jobs

### Отправка через очередь

```php
use App\Jobs\Telegram\SendMessageJob;
use App\Jobs\Telegram\SendPhotoJob;
use App\Jobs\Telegram\SendBroadcastJob;

// Отправить сообщение
SendMessageJob::dispatch(123456789, 'Текст сообщения');

// Отправить фото
SendPhotoJob::dispatch(123456789, 'photo.jpg', [
    'caption' => 'Описание'
]);

// Массовая рассылка
SendBroadcastJob::dispatch('Текст для всех');

// Рассылка выбранным пользователям
SendBroadcastJob::dispatch('Текст', [], [1, 2, 3]);
```

### Отложенная отправка

```php
SendMessageJob::dispatch(123456789, 'Сообщение')
    ->delay(now()->addMinutes(5));
```

### Настройка очереди

В `config/telegram.php`:

```php
'notifications' => [
    'enabled' => true,
    'queue' => 'telegram', // Название очереди
],
```

Запустите worker:

```bash
php artisan queue:work --queue=telegram
```

## 🎯 Artisan команды

### telegram:test

Проверка подключения к Bot API:

```bash
php artisan telegram:test
```

Показывает:
- Информацию о боте
- Статус webhook
- Проверку подключения

### telegram:set-webhook

Установить webhook:

```bash
# Использовать URL из конфига
php artisan telegram:set-webhook

# Указать свой URL
php artisan telegram:set-webhook https://example.com/webhook

# Удалить старый webhook перед установкой
php artisan telegram:set-webhook --delete
```

### telegram:webhook-info

Получить информацию о webhook:

```bash
php artisan telegram:webhook-info
```

### telegram:delete-webhook

Удалить webhook:

```bash
# Обычное удаление
php artisan telegram:delete-webhook

# Удалить с очисткой pending updates
php artisan telegram:delete-webhook --drop-pending
```

## ⚙️ Конфигурация

### Основные настройки

```php
// config/telegram.php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
    'mini_app_url' => env('TELEGRAM_MINI_APP_URL'),
    
    'required_channels' => [
        '@channel1',
        '@channel2',
    ],
    
    'admin_ids' => [123456789, 987654321],
];
```

### Rate Limiting

```php
'rate_limiting' => [
    'enabled' => true,
    'cache_driver' => 'redis', // или 'file', 'database'
],
```

### Валидация

```php
'validation' => [
    'enabled' => true,
    'auto_truncate' => true, // Автоматически обрезать длинный текст
],
```

### Логирование

```php
'logging' => [
    'enabled' => true,
    'channel' => 'stack',
    'level' => 'info',
],
```

## 📱 Примеры использования

### 1. Контроллер с аутентификацией

```php
class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('telegram.auth');
    }
    
    public function getProfile(Request $request)
    {
        $telegramId = $request->telegram_user_id;
        
        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            ['name' => $request->telegram_user['first_name']]
        );
        
        return response()->json($user);
    }
}
```

### 2. Webhook обработчик

```php
class WebhookController extends Controller
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
            telegram_send($chatId, 'Добро пожаловать!');
        }
    }
    
    protected function handleCallback($callback)
    {
        $queryId = $callback['id'];
        $data = $callback['data'] ?? '';
        
        telegram_callback()->acknowledge($queryId);
        
        // Обработка callback
    }
}
```

### 3. Сервис уведомлений

```php
class NotificationService
{
    public function notifyNewTicket(User $user)
    {
        $keyboard = telegram_inline_keyboard()
            ->webApp('🎰 Крутить рулетку', config('telegram.mini_app_url'))
            ->get();
        
        SendMessageJob::dispatch(
            $user->telegram_id,
            "🎫 У вас новый билет!",
            [
                'reply_markup' => json_encode($keyboard),
                'parse_mode' => 'HTML',
            ]
        );
    }
}
```

### 4. Админ-команда для рассылки

```php
class BroadcastCommand extends Command
{
    protected $signature = 'telegram:broadcast {message}';
    
    public function handle()
    {
        $message = $this->argument('message');
        
        SendBroadcastJob::dispatch($message);
        
        $this->info('Рассылка запущена!');
    }
}
```

## 🔧 Troubleshooting

### Webhook не работает

```bash
# Проверить статус
php artisan telegram:webhook-info

# Переустановить
php artisan telegram:set-webhook --delete
```

### Rate limit ошибки

Включите Rate Limiter:

```php
use App\Telegram\RateLimiter;

$limiter = new RateLimiter();
$limiter->throttle($chatId);
```

### Проблемы с валидацией

Отключите валидацию в конфиге:

```php
'validation' => [
    'enabled' => false,
],
```

## 📖 Дополнительная документация

- [README.md](README.md) - Основная документация
- [EXAMPLES.md](EXAMPLES.md) - Примеры использования
- [LIMITS.md](LIMITS.md) - Лимиты и валидация

## ⚡ Production чеклист

- [ ] Настроен `TELEGRAM_BOT_TOKEN`
- [ ] Установлен webhook
- [ ] Настроен `TELEGRAM_WEBHOOK_SECRET`
- [ ] Настроена очередь для уведомлений
- [ ] Включен Rate Limiter
- [ ] Настроено логирование
- [ ] Проверены права middleware
- [ ] Настроены обязательные каналы
- [ ] Добавлены ID администраторов

