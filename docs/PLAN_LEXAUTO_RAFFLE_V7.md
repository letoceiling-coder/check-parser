# ПЛАН РЕАЛИЗАЦИИ: Telegram-бот для розыгрыша LEXAUTO v7.0

**Дата:** 2026-02-04  
**Задача:** Приведение текущей системы в соответствие с ТЗ v7.0 (Разделение новичков/старичков, Докупка, Бронь, Web-админка)

---

## ТЕКУЩЕЕ СОСТОЯНИЕ СИСТЕМЫ

### Что уже есть (используется):
1. ✅ **Модели:**
   - `BotUser` - пользователи бота с FSM (fio, phone, inn зашифрованы)
   - `Raffle` - розыгрыши (активные/завершенные)
   - `Ticket` - билеты с номерами (привязка к user/check/raffle)
   - `Check` - чеки с парсингом (amount, date, review_status: pending/approved/rejected)
   - `BotSettings` - настройки бота (total_slots, slot_price, qr_image, сообщения)
   - `AdminRequest` - запросы на админа
   - `AdminActionLog` - лог действий админов

2. ✅ **FSM (Finite State Machine):**
   - Состояния: IDLE, WELCOME, WAIT_FIO, WAIT_PHONE, WAIT_INN, CONFIRM_DATA, SHOW_QR, WAIT_CHECK, PENDING_REVIEW, APPROVED, REJECTED, TEST_MODE
   - Переходы между состояниями есть

3. ✅ **Функционал:**
   - Регистрация пользователя (ФИО + телефон + ИНН)
   - Показ QR-кода для оплаты
   - Прием PDF-чеков
   - Парсинг суммы и даты из чеков (pdftotext + OCR)
   - Проверка дубликатов чеков (по хешу, operation_id, unique_key)
   - Админская панель (одобрение/отклонение чеков, редактирование сумм)
   - Выдача билетов (sequential/random)
   - Постоянное меню бота (🏠 Главная, ℹ️ О розыгрыше, 🎫 Мои номерки, 💬 Поддержка)
   - Завершение розыгрыша с выбором победителя

### Что НЕ соответствует ТЗ v7.0:

#### 1. **ОТСУТСТВУЕТ: Система Orders (Заказов) с бронированием**
   - Текущая логика: Юзер присылает чек → парсинг → создается Check → админ одобряет → выдаются tickets
   - Нужно по ТЗ: Юзер выбирает количество → создается Order (RESERVED на 30 мин) → затем присылает чек → проверка → одобрение → Order становится SOLD

#### 2. **ОТСУТСТВУЕТ: Выбор количества билетов пользователем**
   - Текущая логика: Количество рассчитывается автоматически `floor(amount / slot_price)`
   - Нужно по ТЗ: Юзер сам вводит число N → система проверяет наличие мест → бронирует → показывает сумму к оплате

#### 3. **ОТСУТСТВУЕТ: Бронирование на 30 минут**
   - Текущая логика: Нет таймера, нет автоматической очистки
   - Нужно по ТЗ: При создании Order ставится `reserved_until = now() + 30 минут`, Cron Job освобождает места

#### 4. **ОТСУТСТВУЕТ: Разделение приветствия для новичков/старичков**
   - Текущая логика: Всем одинаковое приветствие
   - Нужно по ТЗ:
     - **Новый юзер (без fio/phone в БД):** Приветствие + кнопка [Заполнить анкету]
     - **Вернувшийся юзер (есть fio/phone, есть свободные места):** "Рад видеть снова, {Имя}! Твои номера: X, Y. Хочешь докупить?" + кнопка [Купить ещё]
     - **Sold Out с билетами:** "Места закончились. Ты в игре, номера: X, Y"
     - **Sold Out без билетов:** "Места закончились. Следи за новостями"

#### 5. **ЛОГИКА ФЛОУ не соответствует:**
   Текущий флоу:
   ```
   /start → Регистрация (ФИО+Телефон+ИНН) → Подтверждение → QR → Загрузка чека → Проверка → Одобрение → Билеты
   ```

   Нужный флоу по ТЗ:
   ```
   /start → Приветствие (новичок/старичок) → [Заполнить анкету] / [Купить ещё]
   ├─ Новичок: ФИО → Телефон → Выбор кол-ва → Бронь (Order RESERVED) → Инструкции + QR → Чек → Проверка
   └─ Старичок: Выбор кол-ва → Бронь (Order RESERVED) → Инструкции + QR → Чек → Проверка
   ```

#### 6. **ИНН не требуется по ТЗ**
   - Текущая логика: Запрашивается ИНН (STATE_WAIT_INN)
   - Нужно по ТЗ: Только ФИО + Телефон (ИНН убрать из обязательных)

#### 7. **ОТСУТСТВУЕТ: Cron Job для очистки брони**
   - Нужно: Задача каждую минуту проверяет Orders со статусом RESERVED, где `reserved_until < now()` → удаляет Order → освобождает места → уведомляет юзера "Время вышло"

#### 8. **Google Sheets интеграция отсутствует**
   - Нужно: При одобрении заказа админом → запись в Google Sheets (ID, ФИО, Телефон, Сумма, Номера, Дата)

#### 9. **Race Conditions не защищены транзакциями**
   - Текущая логика: Выдача билетов делается через `Ticket::issueTickets()` без транзакций
   - Нужно: При бронировании использовать DB::transaction() + lockForUpdate() чтобы избежать двойной продажи последнего места

#### 10. **Текст сообщений не соответствует ТЗ**
   - Нужно: Обновить все сообщения под стиль ТЗ (более живой, дружеский тон)

---

## ПЛАН РЕАЛИЗАЦИИ (ПОШАГОВО)

### **ЭТАП 1: Создание таблицы Orders**

#### 1.1. Создать миграцию `create_orders_table`
**Файл:** `database/migrations/2026_02_05_000000_create_orders_table.php`

**Структура таблицы:**
```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('telegram_bot_id')->constrained()->onDelete('cascade');
    $table->foreignId('raffle_id')->nullable()->constrained()->onDelete('set null');
    $table->foreignId('bot_user_id')->constrained('bot_users')->onDelete('cascade');
    $table->foreignId('check_id')->nullable()->constrained()->onDelete('set null');
    
    // Статус: reserved, review, sold, rejected
    $table->enum('status', ['reserved', 'review', 'sold', 'rejected'])->default('reserved');
    
    // Бронирование
    $table->timestamp('reserved_until')->nullable(); // Время истечения брони (30 мин)
    
    // Заказ
    $table->integer('quantity'); // Количество билетов
    $table->decimal('amount', 15, 2); // Сумма к оплате (quantity * slot_price)
    
    // Выданные билеты (заполняется при одобрении)
    $table->json('ticket_numbers')->nullable(); // [55, 56, 57]
    
    // Проверка
    $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
    $table->timestamp('reviewed_at')->nullable();
    
    // Примечания
    $table->text('reject_reason')->nullable();
    $table->text('admin_notes')->nullable();
    
    $table->timestamps();
    
    // Индексы
    $table->index(['telegram_bot_id', 'status']);
    $table->index('reserved_until');
    $table->index('bot_user_id');
});
```

**Связи:**
- Order → BotUser (один заказ принадлежит одному юзеру)
- Order → Check (один заказ может иметь один чек, nullable пока чек не загружен)
- Order → Raffle (привязка к розыгрышу)
- Tickets → Order (через промежуточную таблицу или order_id в tickets)

**ВАЖНО:** Нужно добавить `order_id` в таблицу `tickets`:
```php
// В миграции alter tickets
$table->foreignId('order_id')->nullable()->after('check_id')->constrained()->onDelete('set null');
```

#### 1.2. Создать модель Order
**Файл:** `app/Models/Order.php`

**Методы:**
- `isReserved()`, `isReview()`, `isSold()`, `isRejected()`
- `isExpired()` - проверка `reserved_until < now()`
- `extendReservation(int $minutes)` - продлить бронь
- `cancelReservation()` - отменить бронь, освободить места
- `moveToReview()` - перевести в статус review (когда чек загружен)
- `approve(array $ticketNumbers, ?int $reviewerId)` - одобрить (status=sold, сохранить номера)
- `reject(?int $reviewerId, ?string $reason)` - отклонить
- Связи: `botUser()`, `check()`, `raffle()`, `tickets()`, `reviewer()`

#### 1.3. Обновить модель Ticket
**Добавить:**
- `order_id` в fillable
- Связь `order(): BelongsTo`
- Метод `reserveForOrder(Order $order)` - временная привязка к заказу (пока не одобрен, bot_user_id = null, order_id != null)

---

### **ЭТАП 2: Изменение FSM (добавление новых состояний)**

#### 2.1. Добавить новые константы состояний в `BotUser` и `BotFSM`
**Файл:** `app/Models/BotUser.php`, `app/Services/Telegram/FSM/BotFSM.php`

**Новые состояния:**
```php
// После регистрации/возврата старичка
public const STATE_ASK_QUANTITY = 'ASK_QUANTITY';          // Запрос количества билетов
public const STATE_CONFIRM_ORDER = 'CONFIRM_ORDER';        // Подтверждение заказа (количество + сумма)
public const STATE_ORDER_RESERVED = 'ORDER_RESERVED';      // Заказ забронирован, показаны инструкции + QR
public const STATE_WAIT_CHECK_FOR_ORDER = 'WAIT_CHECK_FOR_ORDER'; // Ожидание чека для конкретного заказа
public const STATE_ORDER_REVIEW = 'ORDER_REVIEW';          // Заказ на проверке (чек загружен)
public const STATE_ORDER_SOLD = 'ORDER_SOLD';              // Заказ одобрен (билеты выданы)
public const STATE_ORDER_REJECTED = 'ORDER_REJECTED';      // Заказ отклонен
public const STATE_ORDER_EXPIRED = 'ORDER_EXPIRED';        // Бронь истекла
```

#### 2.2. Обновить методы FSM
**Файл:** `app/Services/Telegram/FSM/BotFSM.php`

**Добавить клавиатуры:**
- `getAskQuantityKeyboard()` - кнопки: [1], [2], [5], [10], [Ввести число], [❌ Отмена]
- `getConfirmOrderKeyboard()` - кнопки: [✅ Оплатить], [❌ Отмена]
- `getOrderReservedKeyboard()` - кнопки: [🔄 Продлить бронь], [🏠 В начало]
- `getWaitCheckForOrderKeyboard()` - кнопки: [❌ Отменить заказ], [🏠 В начало]
- `getOrderExpiredKeyboard()` - кнопки: [🔄 Попробовать снова], [🏠 В начало]

**Добавить методы:**
- `getAvailableQuantityOptions()` - вернуть массив доступных вариантов с учетом свободных мест
- `canReserve(int $quantity): bool` - проверка, можно ли забронировать N мест
- `calculateOrderAmount(int $quantity): float` - расчет суммы

---

### **ЭТАП 3: Обновление логики /start (разделение новички/старички)**

#### 3.1. Переписать метод `handleRaffleStart()`
**Файл:** `app/Http/Controllers/Api/TelegramWebhookController.php`

**Логика:**
```php
private function handleRaffleStart(TelegramBot $bot, BotUser $botUser, int $chatId, BotSettings $settings): void
{
    // Удаляем предыдущее inline сообщение
    if ($botUser->last_bot_message_id) {
        $this->deleteMessage($bot, $chatId, $botUser->last_bot_message_id);
    }
    
    // Получаем текущий розыгрыш
    $raffle = Raffle::getCurrentForBot($bot->id);
    if (!$raffle) {
        $raffle = Raffle::createForBot($bot->id);
    }
    
    // Проверка свободных мест
    $availableSlots = $settings->getAvailableSlotsCount();
    $userTickets = $botUser->getTicketNumbers();
    $hasTickets = count($userTickets) > 0;
    
    // === СЦЕНАРИЙ В: Мест НЕТ (Sold Out) ===
    if ($availableSlots <= 0) {
        if ($hasTickets) {
            // С билетами
            $message = "⛔️ Места закончились!\nТы уже в игре, твои номера: " . implode(', ', $userTickets) . ". Следи за розыгрышем!";
        } else {
            // Без билетов
            $message = "⛔️ К сожалению, все места уже заняты.\nЕсли кто-то не оплатит бронь, место освободится. Следи за новостями.";
        }
        $this->sendMessage($bot, $chatId, $message, true);
        $botUser->update(['fsm_state' => BotUser::STATE_IDLE, 'last_bot_message_id' => null]);
        return;
    }
    
    // === СЦЕНАРИЙ А: Новый пользователь (нет ФИО/телефона) ===
    if (!$botUser->hasAllPersonalData()) {
        $message = "Привет! Рад, что ты решил поучаствовать в нашей движухе! 🤝\n\n";
        $message .= "Для начала давай познакомимся, чтобы я мог записать тебя в список участников.\n\n";
        $message .= "Нажми кнопку ниже, чтобы начать регистрацию 👇";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📝 Заполнить анкету', 'callback_data' => 'start_registration']],
            ]
        ];
        
        $messageId = $this->sendMessageWithKeyboard($bot, $chatId, $message, $keyboard);
        $botUser->update([
            'fsm_state' => BotUser::STATE_WELCOME,
            'last_bot_message_id' => $messageId
        ]);
        return;
    }
    
    // === СЦЕНАРИЙ Б: Вернувшийся пользователь (есть ФИО/телефон) ===
    $firstName = $botUser->first_name ?? 'друг';
    $message = "Рад видеть тебя снова, {$firstName}! 🤝\n\n";
    $message .= "Хочешь увеличить шансы и докупить ещё наклеек?\n\n";
    
    if ($hasTickets) {
        $message .= "Твои текущие номера: " . implode(', ', $userTickets) . "\n\n";
    }
    
    $message .= "Нажми кнопку, чтобы оформить новую заявку 👇";
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🎯 Купить ещё', 'callback_data' => 'buy_more']],
        ]
    ];
    
    if (!$hasTickets) {
        $keyboard['inline_keyboard'][0] = [['text' => '🎯 Купить билеты', 'callback_data' => 'buy_tickets']];
    }
    
    $messageId = $this->sendMessageWithKeyboard($bot, $chatId, $message, $keyboard);
    $botUser->update([
        'fsm_state' => BotUser::STATE_WELCOME,
        'last_bot_message_id' => $messageId
    ]);
}
```

---

### **ЭТАП 4: Реализация флоу регистрации для новичков**

#### 4.1. Обработка callback 'start_registration'
**Файл:** `app/Http/Controllers/Api/TelegramWebhookController.php` метод `handleCallbackQuery()`

**Действие:**
- Удалить inline кнопку
- Отправить сообщение: "📝 Напиши своё ФИО полностью (например: Иванов Иван Иванович):"
- Перевести в состояние `STATE_WAIT_FIO`

#### 4.2. Обработка STATE_WAIT_FIO
**Уже есть, но нужно обновить:**
- Сохранить ФИО
- Перейти в `STATE_WAIT_PHONE`
- Отправить: "📱 Напиши свой номер телефона для связи:"

#### 4.3. Обработка STATE_WAIT_PHONE
**Уже есть, но нужно обновить:**
- Сохранить телефон
- **Убрать переход в STATE_WAIT_INN** (ИНН не нужен)
- Сразу перейти в `STATE_ASK_QUANTITY`
- Отправить сообщение с выбором количества (см. ЭТАП 5)

---

### **ЭТАП 5: Реализация выбора количества билетов**

#### 5.1. Обработка callback 'buy_more' / 'buy_tickets'
**Действие:**
- Удалить inline кнопку
- Перейти в состояние `STATE_ASK_QUANTITY`
- Отправить сообщение с запросом количества

#### 5.2. Обработка STATE_ASK_QUANTITY
**Новый обработчик в `handleRaffleFSM()`:**

```php
case BotUser::STATE_ASK_QUANTITY:
    if ($text) {
        $quantity = (int) $text;
        
        // Валидация
        if ($quantity <= 0) {
            $this->sendMessage($bot, $chatId, "⚠️ Количество должно быть больше 0. Попробуйте снова:");
            return;
        }
        
        $availableSlots = $settings->getAvailableSlotsCount();
        if ($quantity > $availableSlots) {
            $this->sendMessage(
                $bot, 
                $chatId, 
                "⚠️ Вы хотите {$quantity}, но осталось всего {$availableSlots}.\n\nВведите другое число:"
            );
            return;
        }
        
        // Расчет суммы
        $amount = $quantity * $settings->slot_price;
        
        // Сохраняем данные в FSM
        $botUser->setData([
            'order_quantity' => $quantity,
            'order_amount' => $amount
        ]);
        
        // Переход в подтверждение
        $botUser->setState(BotUser::STATE_CONFIRM_ORDER);
        
        // Сообщение с подтверждением
        $message = "✅ Заявка сформирована!\n\n";
        $message .= "📦 Количество: {$quantity} шт.\n";
        $message .= "💰 К оплате: " . number_format($amount, 0, '', ' ') . " руб.\n\n";
        $message .= "Подтверждаете заказ?";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ Подтвердить', 'callback_data' => 'confirm_order']],
                [['text' => '❌ Отменить', 'callback_data' => 'cancel_order']],
            ]
        ];
        
        $this->sendMessageWithKeyboard($bot, $chatId, $message, $keyboard);
    }
    break;
```

#### 5.3. Можно добавить inline кнопки с быстрым выбором
**В сообщении STATE_ASK_QUANTITY:**
```php
$keyboard = [
    'inline_keyboard' => [
        [
            ['text' => '1 шт.', 'callback_data' => 'quantity:1'],
            ['text' => '2 шт.', 'callback_data' => 'quantity:2'],
            ['text' => '5 шт.', 'callback_data' => 'quantity:5'],
        ],
        [['text' => '✏️ Ввести число', 'callback_data' => 'quantity_custom']],
        [['text' => '❌ Отмена', 'callback_data' => 'cancel']],
    ]
];
```

---

### **ЭТАП 6: Бронирование заказа (Order RESERVED)**

#### 6.1. Обработка callback 'confirm_order'
**Новый метод в `TelegramWebhookController`:**

```php
private function handleConfirmOrder(TelegramBot $bot, BotUser $botUser, int $chatId, BotSettings $settings): void
{
    $quantity = $botUser->getFsmDataValue('order_quantity');
    $amount = $botUser->getFsmDataValue('order_amount');
    
    // === КРИТИЧНО: Транзакция + блокировка для защиты от race conditions ===
    try {
        DB::transaction(function () use ($bot, $botUser, $chatId, $settings, $quantity, $amount) {
            // Получаем текущий розыгрыш с блокировкой
            $raffle = Raffle::where('telegram_bot_id', $bot->id)
                ->where('status', Raffle::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            
            if (!$raffle) {
                throw new \Exception('Активный розыгрыш не найден');
            }
            
            // Повторная проверка свободных мест (могли забрать пока юзер думал)
            $availableSlots = $raffle->total_slots - $raffle->tickets_issued;
            
            if ($availableSlots < $quantity) {
                throw new \Exception("Недостаточно свободных мест. Осталось: {$availableSlots}");
            }
            
            // Резервируем билеты (пока без привязки к юзеру, но с order_id)
            $tickets = Ticket::where('raffle_id', $raffle->id)
                ->whereNull('bot_user_id')
                ->whereNull('order_id')
                ->orderBy('number', 'asc') // или inRandomOrder() в зависимости от настроек
                ->limit($quantity)
                ->lockForUpdate()
                ->get();
            
            if ($tickets->count() < $quantity) {
                throw new \Exception("Не удалось зарезервировать билеты");
            }
            
            // Создаем заказ
            $order = Order::create([
                'telegram_bot_id' => $bot->id,
                'raffle_id' => $raffle->id,
                'bot_user_id' => $botUser->id,
                'status' => 'reserved',
                'reserved_until' => now()->addMinutes(30),
                'quantity' => $quantity,
                'amount' => $amount,
            ]);
            
            // Привязываем билеты к заказу (временно, без bot_user_id)
            foreach ($tickets as $ticket) {
                $ticket->update(['order_id' => $order->id]);
            }
            
            // Обновляем статистику розыгрыша
            $raffle->increment('tickets_issued', $quantity);
            
            // Сохраняем order_id в FSM
            $botUser->setData(['current_order_id' => $order->id]);
            $botUser->setState(BotUser::STATE_ORDER_RESERVED);
            
            // Отправляем инструкции по оплате
            $this->sendOrderInstructions($bot, $botUser, $chatId, $settings, $order);
        });
        
    } catch (\Exception $e) {
        Log::error('Order reservation failed', [
            'user_id' => $botUser->id,
            'error' => $e->getMessage()
        ]);
        
        $this->sendMessage($bot, $chatId, "⚠️ " . $e->getMessage() . "\n\nПопробуйте снова позже.");
        $botUser->resetState();
    }
}
```

#### 6.2. Создать метод sendOrderInstructions()
```php
private function sendOrderInstructions(TelegramBot $bot, BotUser $botUser, int $chatId, BotSettings $settings, Order $order): void
{
    $message = "✅ Заявка сформирована! Бронь на 30 минут.\n\n";
    $message .= "📦 Количество: {$order->quantity} шт.\n";
    $message .= "💰 К оплате: " . number_format($order->amount, 0, '', ' ') . " руб.\n\n";
    $message .= "👇 Реквизиты для оплаты:\n";
    
    // Отправка QR-кода
    if ($settings->qr_image_path) {
        $this->sendPhoto($bot, $chatId, $settings->getQrImageFullPath(), $message);
    } else {
        $this->sendMessage($bot, $chatId, $message);
    }
    
    // Инструкции (текст клиента из ТЗ)
    $instructions = "⚠️ ВНИМАНИЕ! ОЧЕНЬ ВАЖНО:\n\n";
    $instructions .= "1️⃣ Оплачивайте сумму СТРОГО ОДНИМ ПЛАТЕЖОМ. Не разбивайте оплату на части!\n";
    $instructions .= "2️⃣ В назначении платежа укажите: «Оплата наклейки».\n";
    $instructions .= "3️⃣ Мы принимаем чек только в формате PDF (выгрузка из банка).\n\n";
    $instructions .= "📄 Пришли мне чек в формате PDF-ФАЙЛА в ответ на это сообщение!\n\n";
    $instructions .= "⏰ Время брони: до " . $order->reserved_until->format('H:i d.m.Y');
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '❌ Отменить заказ', 'callback_data' => 'cancel_order:' . $order->id]],
        ]
    ];
    
    $this->sendMessageWithKeyboard($bot, $chatId, $instructions, $keyboard);
    
    // Переводим в ожидание чека
    $botUser->setState(BotUser::STATE_WAIT_CHECK_FOR_ORDER);
}
```

---

### **ЭТАП 7: Прием чека для заказа**

#### 7.1. Обновить обработку документов в handleRaffleFSM()
**При получении PDF в состоянии STATE_WAIT_CHECK_FOR_ORDER:**

```php
case BotUser::STATE_WAIT_CHECK_FOR_ORDER:
    if ($document && $this->isPdfDocument($document)) {
        $orderId = $botUser->getFsmDataValue('current_order_id');
        
        if (!$orderId) {
            $this->sendMessage($bot, $chatId, "⚠️ Заказ не найден. Начните заново с /start");
            return;
        }
        
        $order = Order::find($orderId);
        
        if (!$order || $order->bot_user_id != $botUser->id) {
            $this->sendMessage($bot, $chatId, "⚠️ Заказ не найден. Начните заново с /start");
            return;
        }
        
        // Проверка, не истекла ли бронь
        if ($order->isExpired()) {
            $order->cancelReservation(); // Освобождает билеты
            $botUser->resetState();
            
            $message = "⏰ Время брони истекло!\n\n";
            $message .= "Заказ отменён. Места освобождены.\n\n";
            $message .= "Вы можете оформить новую заявку, нажав /start";
            
            $this->sendMessage($bot, $chatId, $message);
            return;
        }
        
        // Валидация PDF
        if (!$this->isPdfDocument($document)) {
            $this->sendMessage($bot, $chatId, "⚠️ Принимаются только PDF-файлы. Загрузите чек в формате PDF.");
            return;
        }
        
        // Скачивание и обработка чека
        $filePath = $this->downloadFile($bot, $document['file_id'], 'checks');
        
        if (!$filePath) {
            $this->sendMessage($bot, $chatId, "⚠️ Ошибка загрузки файла. Попробуйте ещё раз.");
            return;
        }
        
        // Парсинг чека
        $checkData = $this->processCheckForOrder($bot, $botUser, $filePath, $order, $settings);
        
        // Создаем Check
        $check = Check::create([
            'telegram_bot_id' => $bot->id,
            'raffle_id' => $order->raffle_id,
            'bot_user_id' => $botUser->id,
            'chat_id' => $chatId,
            'username' => $botUser->username,
            'first_name' => $botUser->first_name,
            'file_path' => $filePath,
            'file_type' => 'pdf',
            'file_size' => $document['file_size'] ?? 0,
            'file_hash' => Check::calculateFileHash(storage_path('app/' . $filePath)),
            'amount' => $checkData['amount'],
            'check_date' => $checkData['date'],
            'ocr_method' => $checkData['ocr_method'],
            'raw_text' => $checkData['raw_text'],
            'status' => $checkData['status'],
            'review_status' => 'pending',
        ]);
        
        // Привязываем чек к заказу
        $order->check_id = $check->id;
        $order->status = 'review';
        $order->reserved_until = null; // Останавливаем таймер брони
        $order->save();
        
        // Переводим юзера в состояние ожидания проверки
        $botUser->setState(BotUser::STATE_ORDER_REVIEW);
        
        // Уведомление юзеру
        $message = "📄 Чек получен! ✅\n\n";
        $message .= "Статус: На проверке у администратора.\n\n";
        $message .= "Мы уведомим вас о результате проверки.";
        
        $this->sendMessage($bot, $chatId, $message);
        
        // Уведомление админам
        $this->notifyAdminsAboutNewOrder($bot, $order, $check);
    } else {
        $this->sendMessage($bot, $chatId, "⚠️ Принимаются только PDF-файлы. Загрузите чек в формате PDF.");
    }
    break;
```

---

### **ЭТАП 8: Панель администратора для Orders**

#### 8.1. Обновить уведомления админам
**Метод `notifyAdminsAboutNewOrder()`:**

```php
private function notifyAdminsAboutNewOrder(TelegramBot $bot, Order $order, Check $check): void
{
    $admins = BotUser::where('telegram_bot_id', $bot->id)
        ->where('role', 'admin')
        ->where('is_blocked', false)
        ->get();
    
    foreach ($admins as $admin) {
        $message = "🔔 Новая заявка на проверку!\n\n";
        $message .= "👤 Пользователь: " . $order->botUser->getDisplayName() . "\n";
        $message .= "📱 Телефон: " . ($order->botUser->phone ?? '—') . "\n";
        $message .= "📦 Количество: {$order->quantity} шт.\n";
        $message .= "💰 Сумма заказа: " . number_format($order->amount, 0, '', ' ') . " руб.\n\n";
        $message .= "📄 Чек:\n";
        $message .= "   • Сумма: " . ($check->amount ? number_format($check->amount, 2) : '—') . " руб.\n";
        $message .= "   • Дата: " . ($check->check_date ? $check->check_date->format('d.m.Y H:i') : '—') . "\n";
        $message .= "   • Статус парсинга: " . $check->status . "\n";
        
        // Отправка чека
        $this->sendDocument($bot, $admin->telegram_user_id, storage_path('app/' . $check->file_path), $message);
        
        // Кнопки управления
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Одобрить', 'callback_data' => 'order_approve:' . $order->id],
                    ['text' => '❌ Отклонить', 'callback_data' => 'order_reject:' . $order->id],
                ],
                [
                    ['text' => '✏️ Редактировать', 'callback_data' => 'order_edit:' . $order->id],
                ],
            ]
        ];
        
        $this->sendMessageWithKeyboard($bot, $admin->telegram_user_id, "Действия:", $keyboard);
    }
}
```

#### 8.2. Обработка callback 'order_approve'
**В `handleCallbackQuery()`:**

```php
if (str_starts_with($callbackData, 'order_approve:')) {
    $orderId = (int) str_replace('order_approve:', '', $callbackData);
    $this->handleOrderApprove($bot, $botUser, $chatId, $callbackQueryId, $orderId);
    return;
}
```

**Новый метод `handleOrderApprove()`:**

```php
private function handleOrderApprove(
    TelegramBot $bot, 
    BotUser $adminUser, 
    int $chatId, 
    string $callbackQueryId, 
    int $orderId
): void {
    if (!$adminUser->isAdmin()) {
        $this->answerCallbackQuery($bot, $callbackQueryId, "⚠️ Только для администраторов");
        return;
    }
    
    $order = Order::with(['botUser', 'check', 'tickets'])->find($orderId);
    
    if (!$order) {
        $this->answerCallbackQuery($bot, $callbackQueryId, "⚠️ Заказ не найден");
        return;
    }
    
    if ($order->status !== 'review') {
        $this->answerCallbackQuery($bot, $callbackQueryId, "⚠️ Заказ уже обработан");
        return;
    }
    
    try {
        DB::transaction(function () use ($order, $adminUser) {
            // Получаем забронированные билеты для этого заказа
            $tickets = Ticket::where('order_id', $order->id)
                ->whereNull('bot_user_id')
                ->lockForUpdate()
                ->get();
            
            if ($tickets->count() !== $order->quantity) {
                throw new \Exception("Несоответствие количества билетов");
            }
            
            // Выдаем билеты юзеру
            $ticketNumbers = [];
            foreach ($tickets as $ticket) {
                $ticket->bot_user_id = $order->bot_user_id;
                $ticket->issued_at = now();
                $ticket->save();
                $ticketNumbers[] = $ticket->number;
            }
            
            // Обновляем заказ
            $order->status = 'sold';
            $order->ticket_numbers = $ticketNumbers;
            $order->reviewed_by = $adminUser->id; // ID админа из таблицы users
            $order->reviewed_at = now();
            $order->save();
            
            // Обновляем чек
            if ($order->check) {
                $order->check->review_status = 'approved';
                $order->check->reviewed_by = $adminUser->id;
                $order->check->reviewed_at = now();
                $order->check->tickets_count = $order->quantity;
                $order->check->save();
            }
            
            // Обновляем статистику розыгрыша
            $raffle = $order->raffle;
            if ($raffle) {
                $raffle->updateStatistics();
            }
            
            // Логируем действие
            AdminActionLog::create([
                'telegram_bot_id' => $order->telegram_bot_id,
                'admin_bot_user_id' => $adminUser->id,
                'action' => 'order_approved',
                'target_type' => 'Order',
                'target_id' => $order->id,
                'details' => json_encode([
                    'order_id' => $order->id,
                    'user_id' => $order->bot_user_id,
                    'quantity' => $order->quantity,
                    'amount' => $order->amount,
                    'ticket_numbers' => $ticketNumbers,
                ]),
            ]);
        });
        
        // Уведомление юзеру
        $message = "✅ Платёж подтверждён! 🎉\n\n";
        $message .= "🎫 Ваши номерки: " . implode(', ', $order->ticket_numbers) . "\n\n";
        $message .= "Удачи в розыгрыше! 🍀";
        
        $this->sendMessage($bot, $order->botUser->telegram_user_id, $message);
        
        // Обновляем состояние юзера
        $order->botUser->setState(BotUser::STATE_ORDER_SOLD);
        
        // Записываем в Google Sheets (ЭТАП 10)
        $this->writeToGoogleSheets($order);
        
        // Ответ админу
        $this->answerCallbackQuery($bot, $callbackQueryId, "✅ Заказ одобрен");
        $this->editMessageText($bot, $chatId, $messageId, "✅ Заказ #{$order->id} одобрен");
        
    } catch (\Exception $e) {
        Log::error('Order approve failed', [
            'order_id' => $orderId,
            'error' => $e->getMessage()
        ]);
        
        $this->answerCallbackQuery($bot, $callbackQueryId, "⚠️ Ошибка: " . $e->getMessage());
    }
}
```

#### 8.3. Обработка callback 'order_reject'
**Аналогично, но:**
- Статус order → 'rejected'
- Освобождаем билеты: `Ticket::where('order_id', $orderId)->update(['order_id' => null])`
- Уменьшаем `raffle->tickets_issued` на `order->quantity`
- Уведомляем юзера: "❌ Чек не принят. Проверьте оплату и оформите заявку заново."

#### 8.4. Обработка callback 'order_edit'
**Для случаев, когда сумма не совпадает:**
- Админ вводит реальную сумму
- Пересчитывается количество билетов
- Если билетов больше чем забронировано → резервируем дополнительные
- Если меньше → освобождаем лишние
- Затем одобряем

---

### **ЭТАП 9: Cron Job для очистки просроченных броней**

#### 9.1. Создать команду ClearExpiredOrders
**Файл:** `app/Console/Commands/ClearExpiredOrdersCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\Raffle;
use App\Models\BotUser;
use App\Models\TelegramBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClearExpiredOrdersCommand extends Command
{
    protected $signature = 'orders:clear-expired';
    protected $description = 'Очистить просроченные брони заказов (RESERVED > 30 мин)';

    public function handle(): int
    {
        $this->info('Checking for expired orders...');
        
        // Находим просроченные брони
        $expiredOrders = Order::where('status', 'reserved')
            ->where('reserved_until', '<', now())
            ->with(['botUser', 'raffle', 'telegramBot'])
            ->get();
        
        if ($expiredOrders->isEmpty()) {
            $this->info('No expired orders found.');
            return 0;
        }
        
        $this->info("Found {$expiredOrders->count()} expired orders.");
        
        foreach ($expiredOrders as $order) {
            try {
                DB::transaction(function () use ($order) {
                    // Освобождаем билеты
                    $releasedCount = Ticket::where('order_id', $order->id)
                        ->update([
                            'order_id' => null,
                            'bot_user_id' => null,
                            'issued_at' => null,
                        ]);
                    
                    // Обновляем статистику розыгрыша
                    if ($order->raffle) {
                        $order->raffle->decrement('tickets_issued', $releasedCount);
                    }
                    
                    // Удаляем заказ или помечаем как expired
                    $order->status = 'expired';
                    $order->save();
                    // Или: $order->delete();
                    
                    Log::info("Order #{$order->id} expired and cleared", [
                        'user_id' => $order->bot_user_id,
                        'released_tickets' => $releasedCount,
                    ]);
                    
                    // Уведомляем пользователя
                    if ($order->botUser && $order->telegramBot) {
                        $this->notifyUser($order);
                    }
                    
                    // Сбрасываем FSM пользователя
                    if ($order->botUser) {
                        $order->botUser->resetState();
                    }
                });
                
                $this->info("✓ Order #{$order->id} cleared");
                
            } catch (\Exception $e) {
                $this->error("✗ Failed to clear order #{$order->id}: " . $e->getMessage());
                Log::error('Clear expired order failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        $this->info('Done.');
        return 0;
    }
    
    private function notifyUser(Order $order): void
    {
        $bot = $order->telegramBot;
        $user = $order->botUser;
        
        $message = "⏰ Время брони истекло!\n\n";
        $message .= "Ваш заказ на {$order->quantity} шт. отменён.\n";
        $message .= "Места освобождены и доступны для других участников.\n\n";
        $message .= "Вы можете оформить новую заявку, нажав /start";
        
        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                'chat_id' => $user->telegram_user_id,
                'text' => $message,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to notify user about expired order', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

#### 9.2. Зарегистрировать команду в Scheduler
**Файл:** `app/Console/Kernel.php`

```php
protected function schedule(Schedule $schedule): void
{
    // Очистка просроченных броней каждую минуту
    $schedule->command('orders:clear-expired')->everyMinute();
}
```

#### 9.3. Настроить cron на сервере
**В crontab добавить:**
```bash
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

---

### **ЭТАП 10: Google Sheets интеграция**

#### 10.1. Установить пакет для Google Sheets
```bash
composer require revolution/laravel-google-sheets
```

#### 10.2. Настроить Service Account
1. Создать Service Account в Google Cloud Console
2. Скачать JSON ключ
3. Положить в `storage/app/google/service-account.json`
4. Добавить в `.env`:
```
GOOGLE_SERVICE_ACCOUNT_KEY_FILE=storage/app/google/service-account.json
GOOGLE_SHEET_ID=ваш_sheet_id
```

#### 10.3. Добавить поле в BotSettings
**Миграция:** добавить `google_sheet_url` в `bot_settings`

```php
$table->string('google_sheet_url')->nullable();
```

#### 10.4. Создать сервис GoogleSheetsService
**Файл:** `app/Services/GoogleSheetsService.php`

```php
<?php

namespace App\Services;

use App\Models\Order;
use Revolution\Google\Sheets\Facades\Sheets;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
    /**
     * Записать заказ в Google Sheets
     */
    public function writeOrder(Order $order): bool
    {
        try {
            $settings = $order->telegramBot->getOrCreateSettings();
            
            if (!$settings->google_sheet_url) {
                Log::warning('Google Sheet URL not configured', ['bot_id' => $order->telegram_bot_id]);
                return false;
            }
            
            // Извлекаем ID таблицы из URL
            $sheetId = $this->extractSheetId($settings->google_sheet_url);
            
            if (!$sheetId) {
                Log::error('Invalid Google Sheet URL', ['url' => $settings->google_sheet_url]);
                return false;
            }
            
            // Данные для записи
            $row = [
                $order->id, // ID заказа
                $order->botUser->fio ?? '—', // ФИО
                $order->botUser->phone ?? '—', // Телефон
                number_format($order->amount, 2, '.', ''), // Сумма
                implode(', ', $order->ticket_numbers ?? []), // Номера
                $order->reviewed_at ? $order->reviewed_at->format('d.m.Y H:i') : '—', // Дата одобрения
            ];
            
            // Записываем в Google Sheets
            Sheets::spreadsheet($sheetId)
                ->sheet('Sheet1') // Или название вашего листа
                ->append([$row]);
            
            Log::info('Order written to Google Sheets', [
                'order_id' => $order->id,
                'sheet_id' => $sheetId,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to write to Google Sheets', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
    
    /**
     * Извлечь ID таблицы из URL
     */
    private function extractSheetId(string $url): ?string
    {
        // URL вида: https://docs.google.com/spreadsheets/d/{ID}/edit
        if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Инициализировать заголовки таблицы
     */
    public function initializeHeaders(string $sheetId): void
    {
        $headers = [
            ['ID заказа', 'ФИО', 'Телефон', 'Сумма', 'Номера', 'Дата']
        ];
        
        Sheets::spreadsheet($sheetId)
            ->sheet('Sheet1')
            ->update($headers);
    }
}
```

#### 10.5. Вызов в handleOrderApprove()
**В методе `handleOrderApprove()` после успешного одобрения:**

```php
// Записываем в Google Sheets
$googleSheetsService = new GoogleSheetsService();
$googleSheetsService->writeOrder($order);
```

---

### **ЭТАП 11: Обновление текстов сообщений**

#### 11.1. Обновить дефолтные сообщения в BotSettings
**Файл:** `app/Models/BotSettings.php` константа `DEFAULTS`

**Заменить все сообщения на тексты из ТЗ:**

```php
public const DEFAULTS = [
    'msg_welcome_new' => "Привет! Рад, что ты решил поучаствовать в нашей движухе! 🤝\n\nДля начала давай познакомимся, чтобы я мог записать тебя в список участников.\n\nНажми кнопку ниже, чтобы начать регистрацию 👇",
    
    'msg_welcome_returning' => "Рад видеть тебя снова, {first_name}! 🤝\n\nХочешь увеличить шансы и докупить ещё наклеек?\n\nТвои текущие номера: {ticket_numbers}\n\nНажми кнопку, чтобы оформить новую заявку 👇",
    
    'msg_sold_out_with_tickets' => "⛔️ Места закончились!\n\nТы уже в игре, твои номера: {ticket_numbers}. Следи за розыгрышем!",
    
    'msg_sold_out_no_tickets' => "⛔️ К сожалению, все места уже заняты.\n\nЕсли кто-то не оплатит бронь, место освободится. Следи за новостями.",
    
    'msg_ask_fio' => "📝 Напиши своё ФИО полностью (например: Иванов Иван Иванович):",
    
    'msg_ask_phone' => "📱 Напиши свой номер телефона для связи:",
    
    'msg_ask_quantity' => "Стоимость одной наклейки: {price} руб.\n\nВведите количество наклеек, которые хотите приобрести (цифрой):",
    
    'msg_confirm_order' => "✅ Заявка сформирована!\n\n📦 Количество: {quantity} шт.\n💰 К оплате: {amount} руб.\n\nПодтверждаете заказ?",
    
    'msg_order_reserved' => "✅ Заявка сформирована! Бронь на 30 минут.\n\n📦 Количество: {quantity} шт.\n💰 К оплате: {amount} руб.\n\n👇 Реквизиты для оплаты:",
    
    'msg_payment_instructions' => "⚠️ ВНИМАНИЕ! ОЧЕНЬ ВАЖНО:\n\n1️⃣ Оплачивайте сумму СТРОГО ОДНИМ ПЛАТЕЖОМ. Не разбивайте оплату на части!\n2️⃣ В назначении платежа укажите: «Оплата наклейки».\n3️⃣ Мы принимаем чек только в формате PDF (выгрузка из банка).\n\n📄 Пришли мне чек в формате PDF-ФАЙЛА в ответ на это сообщение!",
    
    'msg_check_received' => "📄 Чек получен! ✅\n\nСтатус: На проверке у администратора.",
    
    'msg_order_approved' => "✅ Платёж подтверждён! 🎉\n\n🎫 Ваши номерки: {ticket_numbers}\n\nУдачи в розыгрыше! 🍀",
    
    'msg_order_rejected' => "❌ Чек не принят.\n\n{reason}\n\nПроверьте оплату и оформите заявку заново.",
    
    'msg_order_expired' => "⏰ Время брони истекло!\n\nВаш заказ отменён. Места освобождены.\n\nВы можете оформить новую заявку, нажав /start",
    
    'msg_insufficient_slots' => "⚠️ Вы хотите {requested}, но осталось всего {available}.\n\nВведите другое число:",
];
```

#### 11.2. Добавить новые поля в миграцию bot_settings
```php
$table->text('msg_welcome_new')->nullable();
$table->text('msg_welcome_returning')->nullable();
$table->text('msg_sold_out_with_tickets')->nullable();
$table->text('msg_sold_out_no_tickets')->nullable();
$table->text('msg_ask_quantity')->nullable();
$table->text('msg_confirm_order')->nullable();
$table->text('msg_order_reserved')->nullable();
$table->text('msg_payment_instructions')->nullable();
$table->text('msg_order_approved')->nullable();
$table->text('msg_order_rejected')->nullable();
$table->text('msg_order_expired')->nullable();
$table->text('msg_insufficient_slots')->nullable();
```

---

### **ЭТАП 12: Web-админка для управления Orders**

#### 12.1. API Endpoints
**Файл:** `routes/api.php`

```php
// Orders
Route::get('/orders', [OrderController::class, 'index']); // Список заказов
Route::get('/orders/{id}', [OrderController::class, 'show']); // Детали заказа
Route::post('/orders/{id}/approve', [OrderController::class, 'approve']); // Одобрить
Route::post('/orders/{id}/reject', [OrderController::class, 'reject']); // Отклонить
Route::post('/orders/{id}/edit', [OrderController::class, 'edit']); // Редактировать сумму/количество
Route::get('/orders/stats', [OrderController::class, 'stats']); // Статистика
```

#### 12.2. Создать OrderController
**Файл:** `app/Http/Controllers/Api/OrderController.php`

**Методы:**
- `index()` - список заказов с фильтрами (status, raffle_id, bot_user_id)
- `show($id)` - детали заказа (with: botUser, check, tickets, raffle)
- `approve($id)` - одобрение (аналогично handleOrderApprove)
- `reject($id, Request $request)` - отклонение с причиной
- `edit($id, Request $request)` - редактирование суммы/количества
- `stats()` - статистика (всего, reserved, review, sold, rejected, expired)

#### 12.3. Frontend: Страница Orders
**Файл:** `frontend/src/pages/Orders.js`

**Функционал:**
- Таблица заказов (ID, Пользователь, Количество, Сумма, Статус, Дата создания, Дата истечения брони)
- Фильтры: по статусу, по розыгрышу, поиск по пользователю
- Карточки статистики (Reserved, Review, Sold, Rejected, Expired)
- Клик на строку → модальное окно с деталями заказа
- Кнопки: [✅ Одобрить], [❌ Отклонить], [✏️ Редактировать]
- Просмотр чека (PDF preview)
- Обновление в реальном времени (polling каждые 10 сек для статуса "review")

#### 12.4. Frontend: Компонент OrderModal
**Файл:** `frontend/src/components/OrderModal.js`

**Показывает:**
- Информация о пользователе (ФИО, телефон, username)
- Детали заказа (количество, сумма, статус, время брони)
- Чек (сумма, дата, метод парсинга, confidence)
- PDF preview
- Список зарезервированных билетов (номера)
- История действий
- Форма редактирования (если нужно изменить количество/сумму)

---

### **ЭТАП 13: Тестирование и отладка**

#### 13.1. Unit тесты
**Создать тесты для:**
- `Order::isExpired()`
- `Order::cancelReservation()`
- `Order::approve()`
- `Ticket::reserveForOrder()`
- Race conditions (запустить 2 параллельных запроса на бронирование последнего места)

#### 13.2. Feature тесты
**Сценарии:**
1. Новый пользователь: /start → регистрация → выбор количества → бронь → загрузка чека → одобрение → проверка билетов
2. Вернувшийся пользователь: /start → докупка → бронь → чек → одобрение
3. Sold Out: /start → сообщение "места закончились"
4. Истечение брони: бронь → ждем 31 минуту → cron → проверка освобождения мест
5. Отклонение чека: бронь → чек → отклонение → повторная попытка
6. Race condition: 2 юзера одновременно бронируют последнее место → только 1 успешно

#### 13.3. Ручное тестирование
- Проверить все сообщения на соответствие ТЗ
- Проверить inline кнопки
- Проверить постоянное меню
- Проверить уведомления админам
- Проверить запись в Google Sheets
- Проверить cron job

---

### **ЭТАП 14: Миграция данных (если есть старые Orders)**

#### 14.1. Скрипт миграции
**Если в БД уже есть Checks без Orders:**

```php
// Artisan команда: php artisan migrate:checks-to-orders

foreach (Check::where('review_status', 'approved')->get() as $check) {
    $order = Order::create([
        'telegram_bot_id' => $check->telegram_bot_id,
        'raffle_id' => $check->raffle_id,
        'bot_user_id' => $check->bot_user_id,
        'check_id' => $check->id,
        'status' => 'sold',
        'quantity' => $check->tickets_count,
        'amount' => $check->final_amount,
        'ticket_numbers' => $check->getTicketNumbers(),
        'reviewed_by' => $check->reviewed_by,
        'reviewed_at' => $check->reviewed_at,
        'created_at' => $check->created_at,
    ]);
    
    // Обновляем tickets
    Ticket::where('check_id', $check->id)->update(['order_id' => $order->id]);
}
```

---

## ИТОГОВЫЙ CHECKLIST РЕАЛИЗАЦИИ

### База данных:
- [ ] Миграция: `create_orders_table`
- [ ] Миграция: добавить `order_id` в `tickets`
- [ ] Миграция: добавить новые поля сообщений в `bot_settings`
- [ ] Миграция: добавить `google_sheet_url` в `bot_settings`
- [ ] Модель `Order` со всеми методами

### Backend:
- [ ] Обновить `BotUser` и `BotFSM` (новые состояния)
- [ ] Переписать `handleRaffleStart()` (разделение новички/старички)
- [ ] Реализовать флоу регистрации (убрать ИНН)
- [ ] Реализовать флоу выбора количества
- [ ] Реализовать бронирование с транзакциями
- [ ] Реализовать прием чека для Order
- [ ] Обновить уведомления админам
- [ ] Реализовать одобрение/отклонение Orders
- [ ] Реализовать редактирование Orders
- [ ] Создать Cron команду `ClearExpiredOrdersCommand`
- [ ] Настроить Scheduler
- [ ] Интегрировать Google Sheets
- [ ] Обновить все тексты сообщений
- [ ] Создать `OrderController` для API

### Frontend:
- [ ] Создать страницу `Orders.js`
- [ ] Создать компонент `OrderModal.js`
- [ ] Обновить Dashboard (добавить карточку Orders)
- [ ] Добавить фильтры и поиск
- [ ] Добавить PDF preview для чеков
- [ ] Добавить обновление в реальном времени

### Тестирование:
- [ ] Unit тесты для Order
- [ ] Feature тесты сценариев
- [ ] Тест race conditions
- [ ] Ручное тестирование в Telegram
- [ ] Нагрузочное тестирование (много юзеров одновременно)

### Deployment:
- [ ] Настроить cron на сервере
- [ ] Настроить Google Service Account
- [ ] Обновить .env
- [ ] Запустить миграции
- [ ] Инициализировать заголовки Google Sheets

---

## ВАЖНЫЕ ЗАМЕЧАНИЯ

### 1. Race Conditions
**КРИТИЧНО:** При бронировании мест ОБЯЗАТЕЛЬНО использовать:
```php
DB::transaction(function() {
    $raffle = Raffle::lockForUpdate()->find($id);
    $tickets = Ticket::whereNull('bot_user_id')->lockForUpdate()->limit($quantity)->get();
    // ... создание Order и привязка tickets
});
```

### 2. Таймер брони
**ВАЖНО:** 
- Таймер работает через `reserved_until` (30 минут)
- Cron job каждую минуту проверяет истекшие брони
- При загрузке чека таймер останавливается (`reserved_until = null`, status = 'review')
- При истечении брони отправляется уведомление юзеру

### 3. Логика выдачи билетов
**ДО одобрения:**
- Билеты резервируются: `order_id = X`, `bot_user_id = null`
- Показываются админу как "зарезервированные для заказа #X"

**ПОСЛЕ одобрения:**
- Билеты выдаются: `bot_user_id = Y`, `issued_at = now()`
- Номера сохраняются в `order->ticket_numbers`

**При отклонении/истечении:**
- Билеты освобождаются: `order_id = null`, `bot_user_id = null`

### 4. Google Sheets
**Формат записи:**
```
ID заказа | ФИО | Телефон | Сумма | Номера | Дата
1 | Иванов Иван | +79991234567 | 20000 | 55, 56 | 04.02.2026 15:30
```

### 5. Текст сообщений
**Стиль:** Дружеский, живой, с эмодзи (как в ТЗ)  
**Обязательно:** Все тексты должны точно соответствовать ТЗ

### 6. FSM
**Новая схема переходов:**
```
IDLE → WELCOME (новичок/старичок)
  ├─ Новичок: WAIT_FIO → WAIT_PHONE → ASK_QUANTITY
  └─ Старичок: ASK_QUANTITY

ASK_QUANTITY → CONFIRM_ORDER → ORDER_RESERVED → WAIT_CHECK_FOR_ORDER → ORDER_REVIEW
  ├─ Одобрено: ORDER_SOLD
  ├─ Отклонено: ORDER_REJECTED → ASK_QUANTITY (повтор)
  └─ Истекло: ORDER_EXPIRED → IDLE
```

---

## ПРИОРИТИЗАЦИЯ

### 🔴 HIGH (критично для работы):
1. Создание таблицы Orders
2. Обновление FSM (новые состояния)
3. Логика бронирования с транзакциями
4. Разделение новички/старички в /start
5. Cron job для очистки броней
6. Обработка одобрения/отклонения Orders

### 🟡 MEDIUM (важно для UX):
7. Выбор количества билетов
8. Обновление текстов сообщений
9. Web-админка для Orders
10. Уведомления об истечении брони

### 🟢 LOW (дополнительно):
11. Google Sheets интеграция
12. Продление брони (кнопка)
13. История заказов в боте
14. Экспорт статистики

---

## ОЦЕНКА ТРУДОЗАТРАТ

- **ЭТАП 1-2** (БД + FSM): ~4 часа
- **ЭТАП 3-5** (Логика флоу): ~6 часов
- **ЭТАП 6-7** (Бронирование + прием чеков): ~8 часов
- **ЭТАП 8** (Админка Orders в боте): ~6 часов
- **ЭТАП 9** (Cron job): ~2 часа
- **ЭТАП 10** (Google Sheets): ~3 часа
- **ЭТАП 11** (Тексты): ~1 час
- **ЭТАП 12** (Web-админка): ~8 часов
- **ЭТАП 13-14** (Тестирование + миграция): ~6 часов

**Итого:** ~44 часа чистого времени разработки

---

## ЗАКЛЮЧЕНИЕ

Данный план покрывает ВСЕ требования ТЗ v7.0:
✅ Разделение новичков/старичков  
✅ Докупка билетов  
✅ Бронирование на 30 минут  
✅ Выбор количества юзером  
✅ Защита от race conditions  
✅ Cron job для очистки броней  
✅ Обновленные тексты сообщений  
✅ Web-админка для Orders  
✅ Google Sheets интеграция  

План составлен пошагово, с детальным описанием каждого этапа, примерами кода и важными замечаниями.
