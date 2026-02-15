# Чеклист перед деплоем

## Проверки (выполнены локально)

- **PHP синтаксис**: контроллеры и модели без ошибок.
- **Маршруты**: `POST api/bot/{id}/raffle-settings/orders/{orderId}/cancel-reservation` зарегистрирован.
- **Миграции**: `php artisan migrate --pretend` проходит, новые миграции отображаются:
  - `2026_02_15_000000_create_slot_notify_subscriptions_table`
  - `2026_02_15_100000_add_reservation_messages_to_bot_settings`
- **Линтер**: изменённые файлы без замечаний.

## На сервере после выката

```bash
cd /var/www/auto.siteaccess.ru
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
```

## Затронутые файлы (для отката при необходимости)

**Backend:**
- `app/Http/Controllers/Api/RaffleSettingsController.php` — cancelReservation, getMessage
- `app/Http/Controllers/Api/RaffleWebhookController.php` — подписка при «нет мест»
- `app/Models/BotSettings.php` — msg_reservation_cancelled, msg_slots_available
- `app/Models/SlotNotifySubscription.php` — новый
- `routes/api.php` — маршрут cancel-reservation
- `database/migrations/2026_02_15_000000_create_slot_notify_subscriptions_table.php`
- `database/migrations/2026_02_15_100000_add_reservation_messages_to_bot_settings.php`

**Frontend:**
- `frontend/src/components/RaffleSettings.js` — кнопка «Отменить бронь», handleCancelReservation

## Рекомендация

Перед деплоем выполнить локально `npm run build` в папке `frontend` и убедиться, что сборка завершается без ошибок.
