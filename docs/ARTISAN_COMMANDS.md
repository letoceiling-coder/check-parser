# Artisan-команды проекта

Список всех консольных команд с описанием и примерами.

---

## Деплой

### `deploy`
Коммит, пуш в git и обновление на сервере (по SSH: pull, composer, migrate, сборка фронта, очистка кеша) или через webhook.

| Опция        | Описание |
|-------------|----------|
| `--no-build` | Не собирать фронт локально (при SSH сборка идёт на сервере). |
| `--no-ssh`   | Только webhook, не выполнять обновление по SSH (даже если заданы DEPLOY_SSH и DEPLOY_SSH_PATH). |

**Примеры:**
```bash
php artisan deploy
php artisan deploy --no-build
php artisan deploy --no-ssh
```

**Требуется:** при SSH — `DEPLOY_SSH`, `DEPLOY_SSH_PATH` в `.env`; при webhook — `DEPLOY_URL`, `DEPLOY_TOKEN`.

---

### `deploy:trigger`
Только вызвать деплой на сервере (без сборки и без git). Удобно для ручного запуска обновления по webhook.

**Требуется:** `DEPLOY_URL`, `DEPLOY_TOKEN` в `.env`.

```bash
php artisan deploy:trigger
```

---

## Розыгрыши и номерки

### `raffle:diagnose {raffle_id?}`
Диагностика розыгрыша и билетов: количество билетов, свободные/забронированные, сравнение кэша с фактическими данными, поиск «зависших» билетов и просроченных броней.

| Аргумент/опция | Описание |
|----------------|----------|
| `raffle_id`    | ID розыгрыша (необязательно). |
| `--fix`        | Автоматически исправить: очистить просроченные брони, освободить зависшие билеты, пересоздать недостающие билеты, пересчитать статистику. |
| `--active`     | Проверять активный розыгрыш (если не указан `raffle_id`). |

**Примеры:**
```bash
php artisan raffle:diagnose              # список и диагностика активного розыгрыша
php artisan raffle:diagnose 14            # диагностика розыгрыша #14
php artisan raffle:diagnose 14 --fix      # диагностика и автоисправление
php artisan raffle:diagnose --active --fix
```

---

### `raffle:diagnose-active`
Показать активный розыгрыш и реальные данные по нему (для сверки с админкой и ботом).

| Опция   | Описание |
|--------|----------|
| `--bot=` | ID бота; если не указан — вывод по всем активным ботам. |

```bash
php artisan raffle:diagnose-active
php artisan raffle:diagnose-active --bot=1
```

---

### `raffle:init-tickets {bot_id=1}`
Инициализировать билеты для активного розыгрыша указанного бота (создание записей в `tickets` по `total_slots`).

```bash
php artisan raffle:init-tickets
php artisan raffle:init-tickets 1
```

---

### `raffles:delete`
Удаление розыгрышей (в т.ч. тестовых). У связанных записей (tickets, orders, checks) обнуляется `raffle_id`; в `bot_settings` обнуляется `current_raffle_id` для удаляемых розыгрышей.

| Опция     | Описание |
|----------|----------|
| `--id=`  | ID розыгрышей (можно несколько: `--id=14 --id=15`). |
| `--all`  | Удалить все розыгрыши. |
| `--force`| Не спрашивать подтверждение. |

**Примеры:**
```bash
php artisan raffles:delete                    # показать список розыгрышей и подсказку
php artisan raffles:delete --all              # удалить все (с подтверждением)
php artisan raffles:delete --all --force      # удалить все без подтверждения
php artisan raffles:delete --id=14 --id=15    # удалить розыгрыши 14 и 15
```

---

## Заказы и брони

### `orders:clear-expired`
Очистка просроченных броней: заказы в статусе RESERVED с истёкшим `reserved_until` или в статусе REVIEW старше 30 минут с `created_at`. Билеты освобождаются, заказ переводится в EXPIRED, пользователь и другие участники розыгрыша уведомляются. Дополнительно освобождаются «зависшие» билеты (привязка к EXPIRED/REJECTED/SOLD или просроченная RESERVED).

**Запускается по расписанию:** каждую минуту (см. `bootstrap/app.php`).

```bash
php artisan orders:clear-expired
```

---

## Чеки

### `checks:report`
Отчёт по чекам: метод определения (parser), причины несовпадения суммы и т.п.

| Опция     | Описание |
|----------|----------|
| `--id=`  | ID конкретного чека. |
| `--parser` | Показать текущий `receipt_parser_method` на сервере. |

```bash
php artisan checks:report
php artisan checks:report --id=123
php artisan checks:report --parser
```

---

### `checks:analyze-pdfs {path}`
Извлечь текст из PDF-чеков в указанной папке, определить банк и вывести данные для настройки regex.

| Аргумент   | Описание |
|------------|----------|
| `path`     | Путь к папке с PDF (локальный или на сервере). |

| Опция      | Описание |
|------------|----------|
| `--output=` | Сохранить отчёт в файл JSON. |
| `--debug`   | Включить `original_text` в отчёт для отладки. |

```bash
php artisan checks:analyze-pdfs /path/to/pdfs
php artisan checks:analyze-pdfs ./storage/checks --output=report.json --debug
```

---

## Google Таблицы

### `sheets:test`
Проверка подключения к Google Sheets и прав доступа.

| Опция      | Описание |
|------------|----------|
| `--bot-id=` | ID конкретного бота (иначе — все боты). |

```bash
php artisan sheets:test
php artisan sheets:test --bot-id=1
```

---

### `sheets:init-headers`
Инициализировать заголовки в Google Таблицах для всех ботов или для указанного.

| Опция      | Описание |
|------------|----------|
| `--bot-id=` | ID конкретного бота. |

```bash
php artisan sheets:init-headers
php artisan sheets:init-headers --bot-id=1
```

---

## Пользователи

### `user:create`
Создать нового пользователя (для входа в админку).

| Аргумент   | Описание |
|------------|----------|
| `username` | Логин/email (необязательно). |
| `password` | Пароль (необязательно). |
| `name`     | Полное имя (необязательно). |

По умолчанию: `dsc-23@yandex.ru` / `123123123` / Джон Уик.

```bash
php artisan user:create
php artisan user:create admin@example.com secret "Админ"
```

---

## Просмотр всех команд

Список команд с краткими описаниями:
```bash
php artisan list
```

Справка по конкретной команде:
```bash
php artisan help deploy
php artisan help raffle:diagnose
```
