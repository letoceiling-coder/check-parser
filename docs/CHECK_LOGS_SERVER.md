# Просмотр логов на сервере (ошибка при сохранении чека)

Сообщение **«Чек принят, но при сохранении произошла ошибка»** пишется в боте при исключении в обработчике загрузки чека к заказу (`handleCheckForOrder`). В лог пишется запись с текстом `handleCheckForOrder failed`.

## Команды на сервере (SSH)

Подключитесь к серверу и выполните (путь к проекту: `/var/www/auto.siteaccess.ru`):

```bash
# Последние 300 строк лога (обычно здесь видна последняя ошибка)
tail -n 300 /var/www/auto.siteaccess.ru/storage/logs/laravel.log

# Или только строки, связанные с ошибкой обработки чека по заказу
grep -A 15 "handleCheckForOrder failed" /var/www/auto.siteaccess.ru/storage/logs/laravel.log | tail -50

# Поиск любых ошибок за сегодня
grep -E '"level":"error"|handleCheckForOrder failed|Error processing' /var/www/auto.siteaccess.ru/storage/logs/laravel.log | tail -20
```

В выводе будет:
- `order_id` — ID заказа
- `error` — текст исключения (например, нарушение уникальности, недоступность БД, ошибка при отправке в Telegram)
- `trace` — стек вызовов

## Типичные причины

1. **Уникальность (unique)** — дубликат `file_hash`, `operation_id` или `unique_key` при создании `Check`.
2. **Order не в статусе reserved** — бронь истекла между проверкой и сохранением (должен ловиться отдельно как `ORDER_NOT_RESERVED`).
3. **moveToReview() / save()** — ограничения или триггеры в БД при смене статуса заказа.
4. **notifyAdminsAboutNewOrder** — ошибка при отправке сообщения в Telegram (сеть, неверный chat_id, лимиты API).
5. **Диск/файловая система** — нехватка места или права на запись в `storage/`.

После того как скопируете с сервера фрагмент лога с `handleCheckForOrder failed` и полным `trace`, по нему можно точно указать причину и исправить код или настройки.
