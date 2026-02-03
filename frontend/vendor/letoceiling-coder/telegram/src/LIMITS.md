# Лимиты и ограничения Telegram Bot API

Все классы автоматически валидируют данные перед отправкой в Telegram API.

## 📝 Текстовые ограничения

### Сообщения
- **Максимальная длина текста сообщения**: 4096 символов
- **Максимальная длина подписи к медиа**: 1024 символа
- **Максимальная длина callback_data**: 64 байта
- **Максимальная длина inline query**: 256 символов

### Имена и названия
- **Имя пользователя**: до 64 символов
- **Фамилия пользователя**: до 64 символов
- **Название чата**: до 255 символов
- **Описание чата**: до 255 символов
- **Кастомный титул администратора**: до 16 символов

### Кнопки
- **Текст кнопки**: до 64 символов
- **URL в кнопке**: до 2048 символов
- **Максимум кнопок в строке**: 8
- **Максимум строк в клавиатуре**: 100

## 📊 Опросы

- **Вопрос**: до 300 символов
- **Вариант ответа**: до 100 символов
- **Количество вариантов**: от 2 до 10
- **Объяснение в quiz**: до 200 символов

## 📁 Медиа файлы

### Размеры файлов
- **Фото**: до 10 MB
- **Документ**: до 50 MB
- **Аудио**: до 50 MB
- **Видео**: до 50 MB
- **Голосовое сообщение**: до 1 MB
- **Видео заметка**: до 1 MB
- **Стикер**: до 512 KB

### Media Group
- **Минимум файлов**: 2
- **Максимум файлов**: 10

## ⚡ Rate Limits (Лимиты на частоту запросов)

### Автоматически контролируются через RateLimiter

- **30 запросов в секунду** - общий лимит к Bot API
- **1 сообщение в секунду** - для одного чата
- **20 сообщений в минуту** - для групп
- **30 сообщений в секунду** - общий лимит для всех чатов

### Использование

```php
use App\Telegram\RateLimiter;

$limiter = new RateLimiter();

// Проверить глобальный API лимит
$limiter->checkApiLimit();

// Проверить лимит для чата
$limiter->checkChatLimit(123456789);

// Проверить лимит для группы
$limiter->checkGroupLimit(-1001234567890);

// Умная задержка (для массовых рассылок)
foreach ($users as $user) {
    $limiter->throttle($user->telegram_id);
    Telegram::send($user->telegram_id, 'Сообщение');
}
```

## ✅ Автоматическая валидация

Все данные автоматически проверяются перед отправкой:

```php
use App\Telegram\Telegram;
use App\Telegram\Exceptions\TelegramValidationException;

try {
    // Если текст длиннее 4096 символов - выбросит исключение
    Telegram::send(123456789, str_repeat('A', 5000));
} catch (TelegramValidationException $e) {
    echo $e->getMessage();
    // "Message text is too long (5000 characters). Maximum: 4096"
}
```

## 🛠️ Вспомогательные методы Validator

### Обрезка текста

```php
use App\Telegram\Validator;

// Автоматически обрезать до допустимой длины
$text = str_repeat('A', 5000);
$truncated = Validator::truncateText($text, 4096);
// Результат: 4093 символа + "..."
```

### Разбиение длинного текста

```php
// Разбить на несколько сообщений
$longText = file_get_contents('long_article.txt');
$messages = Validator::splitLongText($longText);

foreach ($messages as $message) {
    Telegram::send(123456789, $message);
}
```

## 🔍 Ручная валидация

Можно валидировать данные вручную перед отправкой:

```php
use App\Telegram\Validator;

// Валидировать текст сообщения
Validator::validateMessageText($text);

// Валидировать chat_id
Validator::validateChatId('@channel_name');
Validator::validateChatId(123456789);

// Валидировать URL
Validator::validateUrl('https://example.com');

// Валидировать координаты
Validator::validateLatitude(55.7558);
Validator::validateLongitude(37.6173);

// Валидировать опрос
Validator::validatePollQuestion('Какой язык лучше?');
Validator::validatePollOptions(['PHP', 'JavaScript', 'Python']);

// Валидировать callback data
Validator::validateCallbackData('button_clicked');
```

## 📋 Примеры валидации

### Валидация сообщения

```php
use App\Telegram\Telegram;
use App\Telegram\Validator;

$text = "Очень длинный текст...";

// Проверка
if (mb_strlen($text) > 4096) {
    // Обрезать
    $text = Validator::truncateText($text, 4096);
    // Или разбить на части
    // $messages = Validator::splitLongText($text);
}

Telegram::send(123456789, $text);
```

### Валидация chat_id

```php
try {
    // Username должен начинаться с @
    Validator::validateChatId('channel'); // ❌ Ошибка
    Validator::validateChatId('@channel'); // ✅ OK
    
    // Username должен быть от 5 до 32 символов
    Validator::validateChatId('@abc'); // ❌ Ошибка (слишком короткий)
    Validator::validateChatId('@my_channel_123'); // ✅ OK
    
    // Числовой ID должен быть не 0
    Validator::validateChatId(0); // ❌ Ошибка
    Validator::validateChatId(123456789); // ✅ OK
    
} catch (TelegramValidationException $e) {
    echo $e->getMessage();
}
```

### Валидация клавиатуры

```php
use App\Telegram\Keyboard;

$keyboard = Keyboard::inline();

// Текст кнопки валидируется автоматически
$keyboard->button('Очень длинный текст кнопки...'); // Если >64 символов - ошибка

// URL валидируется автоматически
$keyboard->url('Сайт', 'invalid-url'); // ❌ Ошибка
$keyboard->url('Сайт', 'https://example.com'); // ✅ OK

// callback_data валидируется автоматически
$keyboard->callback('Кнопка', 'data'); // ✅ OK (до 64 байт)
$keyboard->callback('Кнопка', str_repeat('A', 65)); // ❌ Ошибка
```

### Валидация координат

```php
use App\Telegram\Telegram;

try {
    // Широта: от -90 до 90
    Telegram::bot()->sendLocation(123456789, 100.0, 37.0); // ❌ Ошибка
    Telegram::bot()->sendLocation(123456789, 55.7558, 37.0); // ✅ OK
    
    // Долгота: от -180 до 180
    Telegram::bot()->sendLocation(123456789, 55.0, 200.0); // ❌ Ошибка
    Telegram::bot()->sendLocation(123456789, 55.0, 37.6173); // ✅ OK
    
} catch (TelegramValidationException $e) {
    echo $e->getMessage();
}
```

### Валидация parse_mode

```php
try {
    Telegram::send(123456789, '<b>Текст</b>', [
        'parse_mode' => 'HTML' // ✅ OK
    ]);
    
    Telegram::send(123456789, '*Текст*', [
        'parse_mode' => 'Markdown' // ✅ OK
    ]);
    
    Telegram::send(123456789, 'Текст', [
        'parse_mode' => 'Invalid' // ❌ Ошибка
    ]);
    
} catch (TelegramValidationException $e) {
    echo $e->getMessage();
}
```

## 🚨 Исключения

### TelegramValidationException

Выбрасывается при невалидных данных:

```php
try {
    Telegram::send(123456789, '');
} catch (TelegramValidationException $e) {
    // "Message text cannot be empty"
}

try {
    Telegram::send(0, 'Текст');
} catch (TelegramValidationException $e) {
    // "Chat ID cannot be 0"
}

try {
    $keyboard = Keyboard::inline()->callback('OK', str_repeat('X', 100));
} catch (TelegramValidationException $e) {
    // "Callback data is too long (100 bytes). Maximum: 64"
}
```

### TelegramException

Выбрасывается при превышении rate limits:

```php
use App\Telegram\RateLimiter;
use App\Telegram\Exceptions\TelegramException;

$limiter = new RateLimiter();

try {
    // Попытка отправить больше 30 запросов в секунду
    for ($i = 0; $i < 50; $i++) {
        $limiter->checkApiLimit();
    }
} catch (TelegramException $e) {
    // "API rate limit exceeded. Max 30 requests per second."
}
```

## 💡 Рекомендации

### 1. Всегда обрабатывайте исключения

```php
use App\Telegram\Exceptions\TelegramValidationException;

try {
    Telegram::send($chatId, $message);
} catch (TelegramValidationException $e) {
    Log::error('Telegram validation error: ' . $e->getMessage());
    // Показать пользователю или обрезать сообщение
}
```

### 2. Используйте вспомогательные методы

```php
// Вместо проверки длины вручную
if (mb_strlen($text) > 4096) {
    $text = mb_substr($text, 0, 4093) . '...';
}

// Используйте
$text = Validator::truncateText($text, 4096);
```

### 3. Для массовых рассылок используйте throttle

```php
$limiter = new RateLimiter();

foreach ($users as $user) {
    $limiter->throttle($user->telegram_id, $isGroup = false);
    
    try {
        Telegram::send($user->telegram_id, $message);
    } catch (\Exception $e) {
        Log::error("Failed to send to {$user->telegram_id}: " . $e->getMessage());
    }
}
```

### 4. Проверяйте данные от пользователя

```php
// Если пользователь может вводить текст сообщения
$userInput = $request->input('message');

try {
    Validator::validateMessageText($userInput);
    Telegram::send($chatId, $userInput);
} catch (TelegramValidationException $e) {
    return response()->json([
        'error' => 'Сообщение слишком длинное. Максимум 4096 символов.'
    ], 422);
}
```

## 📚 Дополнительная информация

- [Официальная документация Telegram Bot API](https://core.telegram.org/bots/api)
- [FAQ по лимитам](https://core.telegram.org/bots/faq#my-bot-is-hitting-limits-how-do-i-avoid-this)
- [Лучшие практики](https://core.telegram.org/bots/api#making-requests)

