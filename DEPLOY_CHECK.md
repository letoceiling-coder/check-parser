# Инструкция по проверке сервера перед deploy

## Подключение к серверу
```bash
ssh dsc23ytp@dragon
cd ~/project.siteaccess.ru/public_html
```

## 1. Проверка Git репозитория
```bash
# Проверить, что это git репозиторий
git status

# Проверить remote
git remote -v

# Должен быть настроен origin на https://github.com/letoceiling-coder/check-parser.git
# Если нет - настроить:
git remote add origin https://github.com/letoceiling-coder/check-parser.git
```

## 2. Проверка PHP и расширений
```bash
# Проверить версию PHP (должна быть >= 8.1)
php -v

# Проверить необходимые расширения
php -m | grep -E "pdo|pdo_mysql|mbstring|openssl|tokenizer|xml|ctype|json|fileinfo|curl"

# Должны быть установлены:
# - pdo
# - pdo_mysql
# - mbstring
# - openssl
# - tokenizer
# - xml
# - ctype
# - json
# - fileinfo
# - curl
```

## 3. Проверка Composer
```bash
# Проверить, установлен ли composer глобально
composer --version

# Проверить, есть ли bin/composer
ls -la bin/composer

# Если нет bin/composer - он будет установлен автоматически при первом deploy
# Но можно установить вручную:
mkdir -p bin
cd bin
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=. --filename=composer
php -r "unlink('composer-setup.php');"
cd ..
```

## 4. Проверка .env файла
```bash
# Проверить наличие .env
ls -la .env

# Проверить ключевые настройки
cat .env | grep -E "APP_KEY|DB_|DEPLOY_|SANCTUM"

# Должны быть настроены:
# - APP_KEY (если нет - запустить: php artisan key:generate)
# - DB_CONNECTION=mysql
# - DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
# - DEPLOY_URL=https://project.siteaccess.ru
# - DEPLOY_TOKEN=<токен из локального .env>
# - SANCTUM_STATEFUL_DOMAINS=project.siteaccess.ru
```

## 5. Проверка базы данных
```bash
# Проверить подключение к БД
php artisan tinker --execute="echo DB::connection()->getPdo() ? 'DB OK' : 'DB FAILED';"

# Или проверить через mysql
mysql -u [DB_USERNAME] -p[DB_PASSWORD] -h [DB_HOST] [DB_DATABASE] -e "SELECT 1;"
```

## 6. Проверка зависимостей
```bash
# Проверить, установлены ли vendor зависимости
ls -la vendor/

# Если нет - установить:
php bin/composer install --no-interaction --no-dev --optimize-autoloader
# или
composer install --no-interaction --no-dev --optimize-autoloader
```

## 7. Проверка миграций
```bash
# Проверить статус миграций
php artisan migrate:status

# Если есть непримененные миграции, можно применить:
php artisan migrate --force
```

## 8. Проверка прав доступа
```bash
# Проверить права на ключевые директории
ls -la storage/
ls -la bootstrap/cache/

# Установить правильные права (если нужно):
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
# или для вашего пользователя:
chown -R dsc23ytp:dsc23ytp storage bootstrap/cache
```

## 9. Проверка маршрута /api/deploy
```bash
# Проверить, что маршрут зарегистрирован
php artisan route:list | grep deploy

# Должен показать:
# POST api/deploy ........................................................ DeployController@deploy
```

## 10. Проверка токена deploy
```bash
# Проверить DEPLOY_TOKEN в .env
grep DEPLOY_TOKEN .env

# Токен должен совпадать с токеном в локальном .env файле
# Если не совпадает - обновить на сервере:
# nano .env
# или
# echo "DEPLOY_TOKEN=ваш_токен_здесь" >> .env
```

## 11. Проверка структуры проекта
```bash
# Проверить наличие ключевых файлов
ls -la public/index.php
ls -la bootstrap/app.php
ls -la routes/api.php
ls -la app/Http/Controllers/DeployController.php
ls -la .htaccess
ls -la public/.htaccess
```

## 12. Проверка React сборки (опционально)
```bash
# Проверить, есть ли собранные файлы React в public
ls -la public/index.html
ls -la public/static/

# Если нет - это нормально, они будут собраны локально и отправлены через git
```

## 13. Тестовая проверка deploy endpoint
```bash
# Получить токен из .env
TOKEN=$(grep DEPLOY_TOKEN .env | cut -d '=' -f2)

# Проверить доступность endpoint (должен вернуть 401 без токена или 200 с токеном)
curl -X POST https://project.siteaccess.ru/api/deploy \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -v
```

## 14. Проверка логов (если что-то не работает)
```bash
# Проверить логи Laravel
tail -f storage/logs/laravel.log

# Проверить логи веб-сервера (если есть доступ)
# tail -f /var/log/apache2/error.log
# или
# tail -f /var/log/nginx/error.log
```

## 15. Финальная проверка перед deploy
```bash
# Очистить все кеши
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Оптимизировать (опционально)
php artisan optimize
```

## Быстрая проверка всего одним скриптом
Создайте файл `check_deploy.sh`:
```bash
#!/bin/bash
echo "=== Проверка Git ==="
git status && echo "✓ Git OK" || echo "✗ Git FAILED"

echo -e "\n=== Проверка PHP ==="
php -v | head -1

echo -e "\n=== Проверка Composer ==="
[ -f bin/composer ] && php bin/composer --version || composer --version

echo -e "\n=== Проверка .env ==="
[ -f .env ] && echo "✓ .env exists" || echo "✗ .env NOT FOUND"
grep -q "DEPLOY_TOKEN" .env && echo "✓ DEPLOY_TOKEN set" || echo "✗ DEPLOY_TOKEN NOT SET"
grep -q "APP_KEY" .env && echo "✓ APP_KEY set" || echo "✗ APP_KEY NOT SET"

echo -e "\n=== Проверка БД ==="
php artisan tinker --execute="try { DB::connection()->getPdo(); echo '✓ DB OK'; } catch(Exception \$e) { echo '✗ DB FAILED'; }"

echo -e "\n=== Проверка маршрутов ==="
php artisan route:list | grep -q "deploy" && echo "✓ Deploy route exists" || echo "✗ Deploy route NOT FOUND"

echo -e "\n=== Проверка прав ==="
[ -w storage ] && echo "✓ storage writable" || echo "✗ storage NOT WRITABLE"
[ -w bootstrap/cache ] && echo "✓ bootstrap/cache writable" || echo "✗ bootstrap/cache NOT WRITABLE"

echo -e "\n=== Готово! ==="
```

Запустить:
```bash
chmod +x check_deploy.sh
./check_deploy.sh
```

## После проверки - запуск deploy с локальной машины
```bash
# На локальной машине
php artisan deploy
```

Команда выполнит:
1. Сборку React приложения в `public/`
2. Git add, commit, push
3. POST запрос на сервер для автоматического deploy
