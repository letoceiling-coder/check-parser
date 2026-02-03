# Исправление .env на сервере для Yandex Vision

## Проблема:
В `.env` есть дубликаты `YANDEX_VISION_API_KEY` и `YANDEX_FOLDER_ID`.

## Решение:

Выполните на сервере:

```bash
cd ~/project.siteaccess.ru/public_html

# 1. Удалить все старые строки с YANDEX_VISION_API_KEY
sed -i '/YANDEX_VISION_API_KEY=/d' .env

# 2. Удалить старые строки с YANDEX_FOLDER_ID
sed -i '/YANDEX_FOLDER_ID=/d' .env

# 3. Добавить правильные значения в конец файла
# ЗАМЕНИТЕ <your_iam_token> на ваш реальный IAM токен
# ЗАМЕНИТЕ <your_folder_id> на ваш реальный Folder ID
echo "YANDEX_VISION_API_KEY=<your_iam_token>" >> .env
echo "YANDEX_FOLDER_ID=<your_folder_id>" >> .env

# 4. Проверить, что все правильно (должно быть по одной строке каждого)
grep YANDEX_VISION_API_KEY .env
grep YANDEX_FOLDER_ID .env

# 5. Очистить кеш
php artisan config:clear
php artisan cache:clear
```

## Проверка:

```bash
# Проверить, что токен читается
php artisan tinker --execute="echo env('YANDEX_VISION_API_KEY') ? 'OK' : 'NOT SET';"
php artisan tinker --execute="echo env('YANDEX_FOLDER_ID') ? 'OK' : 'NOT SET';"
```

Оба должны вывести `OK`.
