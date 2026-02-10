# Установка Google Sheets интеграции

## Шаг 1: Установить пакет (локально или на сервере)

```bash
composer require google/apiclient:"^2.15"
```

⚠️ **Внимание:** Пакет большой (~50MB для google/apiclient-services), установка может занять 2-5 минут.

## Шаг 2: Проверить установку

```bash
composer show | grep google
```

**Должны увидеть:**
```
google/apiclient               v2.15.0
google/apiclient-services      v0.432.0
google/auth                    v1.50.0
```

## Шаг 3: Настроить .env

Добавьте строки в `.env`:

```env
GOOGLE_APPLICATION_CREDENTIALS=storage/app/google/service-account.json
GOOGLE_SHEETS_ENABLED=true
```

Затем:
```bash
php artisan config:cache
```

## Шаг 4: Положить Service Account ключ

```bash
mkdir -p storage/app/google
# Скопировать service-account.json в эту папку
chmod 600 storage/app/google/service-account.json
```

## Шаг 5: Тестирование

```bash
# Проверка подключения
php artisan sheets:test

# Если всё ок — инициализация заголовков
php artisan sheets:init-headers
```

## Готово!

При одобрении заказов данные будут автоматически записываться в Google Таблицу. ✅

---

## Полная инструкция

- **Создание Service Account:** `docs/GOOGLE_SHEETS_SETUP.md`
- **Описание системы:** `docs/LEXAUTO_RAFFLE_SYSTEM.md`
