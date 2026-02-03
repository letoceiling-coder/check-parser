# Примеры использования Telegram API

## Быстрый старт с фасадом

```php
use App\Telegram\Telegram;

// Отправить сообщение
Telegram::send(123456789, 'Привет!');

// Проверить подписку
$isSubscribed = Telegram::checkSubscription('@channel', 123456789);

// Валидировать Mini App
$isValid = Telegram::validateMiniApp($initData);

// Получить пользователя из Mini App
$user = Telegram::getMiniAppUser($initData);
```

## Примеры в контроллерах

### 1. Контроллер проверки подписки

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Telegram\Telegram;
use Illuminate\Http\Request;

class ChannelSubscriptionController extends Controller
{
    public function check(Request $request)
    {
        $userId = $request->input('user_id');
        $channels = config('telegram.required_channels', []);
        
        $notSubscribed = [];
        
        foreach ($channels as $channel) {
            if (!Telegram::checkSubscription($channel, $userId)) {
                $notSubscribed[] = $channel;
            }
        }
        
        return response()->json([
            'subscribed' => empty($notSubscribed),
            'missing_channels' => $notSubscribed,
        ]);
    }
}
```

### 2. Mini App аутентификация

```php
<?php

namespace App\Http\Middleware;

use App\Telegram\Telegram;
use Closure;
use Illuminate\Http\Request;

class TelegramMiniAppAuth
{
    public function handle(Request $request, Closure $next)
    {
        $initData = $request->header('X-Telegram-Init-Data');
        
        if (!$initData) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        if (!Telegram::validateMiniApp($initData)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }
        
        $user = Telegram::getMiniAppUser($initData);
        $request->merge(['telegram_user' => $user]);
        
        return $next($request);
    }
}
```

### 3. Отправка уведомлений с клавиатурой

```php
<?php

namespace App\Services;

use App\Telegram\Telegram;

class TelegramNotificationService
{
    public function notifyNewTicket($user)
    {
        $keyboard = Telegram::inlineKeyboard()
            ->row([])
            ->webApp('🎰 Крутить рулетку', config('app.mini_app_url'))
            ->url('📱 Поделиться', 'https://t.me/share/url?url=' . config('app.url'))
            ->get();
        
        Telegram::send(
            chatId: $user->telegram_id,
            text: "🎫 <b>Новый билет!</b>\n\nУ вас восстановился билет для вращения рулетки!",
            params: [
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard),
            ]
        );
    }
    
    public function notifyWin($user, $amount)
    {
        $keyboard = Telegram::inlineKeyboard()
            ->row([])
            ->webApp('🎉 Забрать приз', config('app.mini_app_url'))
            ->get();
        
        Telegram::send(
            chatId: $user->telegram_id,
            text: "🎉 <b>Поздравляем!</b>\n\nВы выиграли {$amount}₽!",
            params: [
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard),
            ]
        );
    }
}
```

### 4. Webhook обработчик

```php
<?php

namespace App\Http\Controllers;

use App\Telegram\Telegram;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $update = $request->all();
        
        // Обработка обычного сообщения
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
        
        // Обработка callback query
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
        
        return response()->json(['ok' => true]);
    }
    
    protected function handleMessage(array $message)
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        
        if ($text === '/start') {
            $keyboard = Telegram::inlineKeyboard()
                ->row([])
                ->webApp('🎰 Открыть рулетку', config('app.mini_app_url'))
                ->get();
            
            Telegram::send(
                chatId: $chatId,
                text: "👋 Привет! Добро пожаловать в WOW Рулетку!",
                params: ['reply_markup' => json_encode($keyboard)]
            );
        }
    }
    
    protected function handleCallback(array $callback)
    {
        $queryId = $callback['id'];
        $data = $callback['data'] ?? '';
        
        if ($data === 'help') {
            Telegram::callback()->answerWithAlert(
                $queryId,
                'Справка: нажмите на кнопку "Открыть рулетку" чтобы начать игру!'
            );
        } else {
            Telegram::callback()->acknowledge($queryId);
        }
    }
}
```

### 5. Проверка админа канала

```php
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Telegram\Telegram;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function checkAdmin(Request $request)
    {
        $userId = $request->input('user_id');
        $channelId = config('telegram.admin_channel');
        
        $isAdmin = Telegram::channel()->isAdmin($channelId, $userId);
        
        if (!$isAdmin) {
            return response()->json(['error' => 'Access denied'], 403);
        }
        
        return response()->json(['admin' => true]);
    }
}
```

### 6. Массовая рассылка

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Telegram\Telegram;
use Illuminate\Console\Command;

class SendBroadcast extends Command
{
    protected $signature = 'telegram:broadcast {message}';
    protected $description = 'Отправить сообщение всем пользователям';

    public function handle()
    {
        $message = $this->argument('message');
        
        $users = User::whereNotNull('telegram_id')->get();
        $sent = 0;
        $failed = 0;
        
        $keyboard = Telegram::inlineKeyboard()
            ->row([])
            ->webApp('Открыть приложение', config('app.mini_app_url'))
            ->get();
        
        foreach ($users as $user) {
            try {
                Telegram::send(
                    chatId: $user->telegram_id,
                    text: $message,
                    params: ['reply_markup' => json_encode($keyboard)]
                );
                $sent++;
                $this->info("✓ Отправлено: {$user->telegram_id}");
                
                // Задержка чтобы не превысить лимиты API
                usleep(100000); // 0.1 секунда
                
            } catch (\Exception $e) {
                $failed++;
                $this->error("✗ Ошибка: {$user->telegram_id} - {$e->getMessage()}");
            }
        }
        
        $this->info("\nГотово! Отправлено: {$sent}, Ошибок: {$failed}");
    }
}
```

### 7. Создание инвойса для Stars

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Telegram\Telegram;
use Illuminate\Http\Request;

class StarsPaymentController extends Controller
{
    public function createInvoice(Request $request)
    {
        $userId = $request->input('user_id');
        $amount = 50; // 50 Stars
        
        $keyboard = Telegram::inlineKeyboard()
            ->row([])
            ->button('⭐ Оплатить 50 звёзд', [
                'pay' => true,
            ])
            ->get();
        
        $invoice = Telegram::bot()->sendInvoice(
            chatId: $userId,
            title: '20 билетов для рулетки',
            description: 'Получите 20 дополнительных вращений рулетки',
            payload: 'tickets_20',
            providerToken: '', // Пусто для Stars
            currency: 'XTR', // XTR = Telegram Stars
            prices: [
                ['label' => '20 билетов', 'amount' => $amount],
            ],
            params: [
                'reply_markup' => json_encode($keyboard),
            ]
        );
        
        return response()->json($invoice);
    }
    
    public function handlePreCheckout(Request $request)
    {
        $preCheckoutQuery = $request->all();
        $queryId = $preCheckoutQuery['id'];
        
        // Проверить можно ли принять платеж
        $ok = true; // или проверьте инвентарь и т.д.
        
        if ($ok) {
            Telegram::bot()->answerPreCheckoutQuery($queryId, true);
        } else {
            Telegram::bot()->answerPreCheckoutQuery(
                $queryId,
                false,
                'К сожалению, этот товар недоступен'
            );
        }
        
        return response()->json(['ok' => true]);
    }
}
```

### 8. Работа с опросами

```php
<?php

namespace App\Services;

use App\Telegram\Telegram;

class PollService
{
    public function createPoll($chatId, $question, array $options)
    {
        return Telegram::bot()->sendPoll(
            chatId: $chatId,
            question: $question,
            options: $options,
            params: [
                'is_anonymous' => false,
                'allows_multiple_answers' => false,
            ]
        );
    }
    
    public function createQuiz($chatId, $question, array $options, int $correctOptionId)
    {
        return Telegram::bot()->sendPoll(
            chatId: $chatId,
            question: $question,
            options: $options,
            params: [
                'type' => 'quiz',
                'correct_option_id' => $correctOptionId,
                'explanation' => 'Правильный ответ!',
            ]
        );
    }
}
```

### 9. Динамическая клавиатура

```php
<?php

namespace App\Services;

use App\Telegram\Telegram;

class KeyboardService
{
    public function getMainMenu()
    {
        return Telegram::inlineKeyboard()
            ->row([])
            ->callback('🎰 Рулетка', 'menu_wheel')
            ->callback('👥 Друзья', 'menu_friends')
            ->row([])
            ->callback('🏆 Рейтинг', 'menu_leaderboard')
            ->callback('ℹ️ Помощь', 'menu_help')
            ->get();
    }
    
    public function getSubscriptionKeyboard(array $channels)
    {
        $keyboard = Telegram::inlineKeyboard();
        
        foreach ($channels as $channel) {
            $keyboard->row([])
                ->url("📢 {$channel['name']}", $channel['url']);
        }
        
        $keyboard->row([])
            ->callback('✅ Проверить подписку', 'check_subscription');
        
        return $keyboard->get();
    }
}
```

## Использование типов данных

```php
use App\Telegram\Types\User;
use App\Telegram\Types\Chat;
use App\Telegram\Types\Message;
use App\Telegram\Types\ChatMember;

// Преобразование из массива
$userData = ['id' => 123, 'is_bot' => false, 'first_name' => 'John'];
$user = User::fromArray($userData);

echo $user->firstName; // John
echo $user->id; // 123

// Обратно в массив
$array = $user->toArray();

// Работа с Chat
$chat = Chat::fromArray($chatData);
if ($chat->isPrivate()) {
    // Приватный чат
} elseif ($chat->isGroup()) {
    // Группа
} elseif ($chat->isChannel()) {
    // Канал
}

// Работа с ChatMember
$member = ChatMember::fromArray($memberData);
if ($member->isAdmin()) {
    // Пользователь - администратор
}
```

## Обработка ошибок

```php
use App\Telegram\Exceptions\TelegramException;
use App\Telegram\Exceptions\TelegramValidationException;

// Обработка ошибок API
try {
    Telegram::send(123456789, 'Hello');
} catch (TelegramException $e) {
    Log::error('Telegram API error: ' . $e->getMessage());
    // Обработать ошибку
}

// Обработка ошибок валидации
try {
    $user = Telegram::miniApp()->validateAndGetUser($initData);
} catch (TelegramValidationException $e) {
    return response()->json(['error' => 'Unauthorized'], 401);
}
```

## Настройка в config/services.php

```php
return [
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'required_channels' => [
            '@channel1',
            '@channel2',
        ],
        'admin_channel' => '@admin_channel',
    ],
];
```

## Настройка webhook

```php
use App\Telegram\Telegram;

// Установить webhook
Telegram::bot()->setWebhook('https://yourdomain.com/api/telegram/webhook', [
    'allowed_updates' => ['message', 'callback_query', 'pre_checkout_query'],
]);

// Удалить webhook
Telegram::bot()->deleteWebhook(dropPendingUpdates: true);

// Получить информацию о webhook
$info = Telegram::bot()->getWebhookInfo();
```

