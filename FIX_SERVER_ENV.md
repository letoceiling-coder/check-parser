# Исправление .env файла на сервере

## Проблемы в текущем .env:
1. ❌ APP_KEY пустой
2. ❌ APP_ENV=local (должно быть production)
3. ❌ APP_DEBUG=true (должно быть false)
4. ❌ APP_URL=http://localhost (должно быть https://project.siteaccess.ru)
5. ❌ DB_CONNECTION=sqlite (должно быть mysql)
6. ❌ DEPLOY_TOKEN пустой

## Команды для исправления на сервере:

```bash
# 1. Сгенерировать APP_KEY
php artisan key:generate

# 2. Исправить APP_ENV
sed -i 's/APP_ENV=local/APP_ENV=production/' .env

# 3. Исправить APP_DEBUG
sed -i 's/APP_DEBUG=true/APP_DEBUG=false/' .env

# 4. Исправить APP_URL
sed -i 's|APP_URL=http://localhost|APP_URL=https://project.siteaccess.ru|' .env

# 5. Исправить DB_CONNECTION (если нужно)
sed -i 's/DB_CONNECTION=sqlite/DB_CONNECTION=mysql/' .env

# 6. Добавить DEPLOY_TOKEN (скопируйте из локального .env)
# Замените YOUR_TOKEN_HERE на токен из локального .env
sed -i 's/DEPLOY_TOKEN=$/DEPLOY_TOKEN=bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4/' .env

# 7. Убедиться, что DB настройки правильные (проверьте, что они есть)
# Если нет, добавьте:
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=dsc23ytp_check
# DB_USERNAME=dsc23ytp_check
# DB_PASSWORD=JSvtK*E5r&qb

# 8. Очистить кеши после изменений
php artisan config:clear
php artisan cache:clear
```

## Или создайте правильный .env вручную:

Скопируйте правильные значения из вашего локального .env и обновите на сервере.

## Проверка после исправления:

```bash
# Проверить ключевые настройки
cat .env | grep -E "APP_KEY|APP_ENV|APP_DEBUG|APP_URL|DB_CONNECTION|DEPLOY_TOKEN|SANCTUM_STATEFUL_DOMAINS"
```

Должно быть:
```
APP_KEY=base64:... (не пустой)
APP_ENV=production
APP_DEBUG=false
APP_URL=https://project.siteaccess.ru
DB_CONNECTION=mysql
DEPLOY_TOKEN=bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4
SANCTUM_STATEFUL_DOMAINS=project.siteaccess.ru
```
