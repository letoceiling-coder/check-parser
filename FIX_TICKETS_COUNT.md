# Исправление подсчета выданных билетов

## Проблема
Билеты с `order_id` (забронированные, но еще не выданные) учитывались в `tickets_issued`, что приводило к неправильному отображению доступных мест.

**Пример:**
- Всего мест: 100
- Реально выдано: 1 билет (с `bot_user_id`)
- Забронировано: 42 билета (с `order_id`, но без `bot_user_id`)
- **Неправильно показывалось:** Выдано 43, Доступно 57
- **Правильно должно быть:** Выдано 1, Доступно 99

## Исправления

### 1. `app/Models/Raffle.php` - метод `updateStatistics()`
**Было:**
```php
$this->tickets_issued = $this->tickets()
    ->where(function ($q) {
        $q->whereNotNull('bot_user_id')->orWhereNotNull('order_id');
    })
    ->count();
```

**Стало:**
```php
// Учитываем только реально выданные билеты (с bot_user_id)
// Билеты с order_id но без bot_user_id - это только бронь, они не считаются выданными
$this->tickets_issued = $this->tickets()
    ->whereNotNull('bot_user_id')
    ->count();
```

### 2. `app/Models/BotSettings.php` - метод `getAvailableSlotsCount()`
**Было:**
```php
$issuedCount = Ticket::where('raffle_id', $raffle->id)
    ->where(function ($q) {
        $q->whereNotNull('bot_user_id')->orWhereNotNull('order_id');
    })
    ->count();
```

**Стало:**
```php
// Учитываем только реально выданные билеты (с bot_user_id)
// Билеты с order_id но без bot_user_id - это только бронь, они не считаются выданными
$issuedCount = Ticket::where('raffle_id', $raffle->id)
    ->whereNotNull('bot_user_id')
    ->count();
```

### 3. Обновлена команда диагностики
- Теперь правильно сравнивает кэшированные и реальные данные
- Автоматически обновляет статистику при обнаружении расхождений

## Результат

После исправления:
- ✅ `tickets_issued` считает только реально выданные билеты (с `bot_user_id`)
- ✅ Забронированные билеты (с `order_id` но без `bot_user_id`) не учитываются
- ✅ Доступные места рассчитываются правильно: `total_slots - tickets_issued`
- ✅ Телеграм-бот и веб-интерфейс показывают одинаковые данные

## Проверка

После применения исправлений выполните:
```bash
php artisan raffle:diagnose --active --fix
```

Должно показать:
- Выдано билетов: 1 (или реальное количество)
- Доступно: 99 (или правильное количество)

## Важно

Билеты с `order_id` но без `bot_user_id` - это **бронь**, которая:
- Может истечь (если `reserved_until < now()`)
- Может быть отменена
- Может быть подтверждена (тогда появится `bot_user_id`)

Только после появления `bot_user_id` билет считается **реально выданным**.
