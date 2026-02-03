# Исправление DEPLOY_TOKEN на сервере

## Проблема:
`Deployment request failed: 500` с ошибкой `DEPLOY_TOKEN not configured on server`

## Решение:

Выполните на сервере следующие команды:

```bash
cd ~/project.siteaccess.ru/public_html

# 1. Проверьте текущий токен (если есть)
grep DEPLOY_TOKEN .env

# 2. Удалите все старые строки с DEPLOY_TOKEN (если есть дубликаты)
sed -i '/DEPLOY_TOKEN=/d' .env

# 3. Добавьте правильный токен
echo "DEPLOY_TOKEN=bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4" >> .env

# 4. Проверьте, что токен добавлен (должна быть только одна строка)
grep DEPLOY_TOKEN .env

# 5. Очистите кеш конфигурации
php artisan config:clear
php artisan cache:clear

# 6. Проверьте, что токен читается
php artisan tinker --execute="echo env('DEPLOY_TOKEN');"
```

Должен вывестись токен: `bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4`

## После настройки:

Попробуйте снова выполнить `php artisan deploy` локально.

## Если проблема сохраняется:

1. Проверьте права доступа к `.env` файлу:
```bash
ls -la .env
chmod 644 .env
```

2. Проверьте, что файл `.env` существует и читается:
```bash
cat .env | grep DEPLOY_TOKEN
```

3. Проверьте логи на сервере:
```bash
tail -50 storage/logs/laravel.log | grep -i deploy
```
