# Исправление дубликата DEPLOY_TOKEN

## Проблема:
В `.env` файле есть дубликат `DEPLOY_TOKEN` (две одинаковые строки).

## Решение:

Выполните на сервере:

```bash
cd ~/project.siteaccess.ru/public_html

# Удалить все строки с DEPLOY_TOKEN
sed -i '/DEPLOY_TOKEN=/d' .env

# Добавить одну строку
echo "DEPLOY_TOKEN=bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4" >> .env

# Проверить
grep DEPLOY_TOKEN .env

# Должна быть только одна строка

# Очистить кеш
php artisan config:clear
php artisan cache:clear
```

После этого `artisan deploy` должен работать корректно.
