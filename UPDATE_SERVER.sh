#!/bin/bash
# Команды для обновления на сервере

echo "=== Обновление кода из git ==="
cd ~/project.siteaccess.ru/public_html

# 1. Сохранить .env (чтобы не потерять настройки)
cp .env .env.backup

# 2. Получить последние изменения
git fetch origin

# 3. Сбросить все локальные изменения и обновиться
git reset --hard origin/main

# 4. Очистить неотслеживаемые файлы (кроме важных)
git clean -fd -e .env -e storage -e vendor -e node_modules -e frontend/node_modules

# 5. Восстановить .env если был изменен
if [ -f .env.backup ]; then
    mv .env.backup .env
fi

# 6. Убедиться, что public/.htaccess существует
if [ ! -f public/.htaccess ]; then
    echo "Создаю public/.htaccess..."
    cat > public/.htaccess << 'EOF'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Handle X-XSRF-Token Header
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # Serve static files directly (CSS, JS, images, fonts)
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteCond %{REQUEST_URI} \.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ico|json|map)$ [NC]
    RewriteRule ^ - [L]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
EOF
fi

# 7. Убедиться, что public/index.php существует
if [ ! -f public/index.php ]; then
    echo "Создаю public/index.php..."
    cat > public/index.php << 'EOF'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
EOF
fi

# 8. Установить правильные права
chmod 644 public/.htaccess
chmod 644 public/index.php
chmod -R 775 storage bootstrap/cache

# 9. Установить/обновить composer зависимости
if [ -f bin/composer ]; then
    php bin/composer install --no-interaction --no-dev --optimize-autoloader
else
    composer install --no-interaction --no-dev --optimize-autoloader
fi

# 10. Запустить миграции
php artisan migrate --force

# 11. Очистить все кеши
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear

# 12. Оптимизировать
php artisan optimize

echo "=== Обновление завершено ==="
echo "Проверьте маршруты:"
php artisan route:list | grep deploy
