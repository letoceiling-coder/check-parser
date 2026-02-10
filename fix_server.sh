#!/bin/bash
# Скрипт для исправления критичных проблем на сервере

cd /var/www/auto.siteaccess.ru

echo "=== 1. Проверка index.php и .htaccess ==="
if [ ! -f "public/index.php" ]; then
    echo "❌ public/index.php отсутствует - копирование из frontend/laravel-public"
    cp frontend/laravel-public/index.php public/index.php
else
    echo "✅ public/index.php существует"
fi

if [ ! -f "public/.htaccess" ]; then
    echo "❌ public/.htaccess отсутствует - копирование из frontend/laravel-public"
    cp frontend/laravel-public/.htaccess public/.htaccess
else
    echo "✅ public/.htaccess существует"
fi

echo ""
echo "=== 2. Проверка storage symlink ==="
if [ ! -L "public/storage" ]; then
    echo "❌ storage symlink отсутствует - создание"
    php artisan storage:link
else
    echo "✅ storage symlink существует"
fi

echo ""
echo "=== 3. Инициализация билетов для розыгрыша ==="
TICKET_COUNT=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Models\Ticket::where('raffle_id', 1)->count();")
echo "Текущее кол-во билетов: $TICKET_COUNT"

if [ "$TICKET_COUNT" == "0" ]; then
    echo "❌ Билеты не созданы - запуск инициализации через API"
    # Используем существующий endpoint
    TOKEN=$(grep DEPLOY_TOKEN .env | cut -d '=' -f2)
    curl -s -X POST "http://localhost/api/bot/1/raffle-settings/initialize-tickets" \
         -H "Authorization: Bearer $TOKEN" \
         -H "Content-Type: application/json" \
         > /dev/null 2>&1
    
    TICKET_COUNT_AFTER=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Models\Ticket::where('raffle_id', 1)->count();")
    echo "После инициализации: $TICKET_COUNT_AFTER билетов"
else
    echo "✅ Билеты уже созданы ($TICKET_COUNT шт)"
fi

echo ""
echo "=== 4. Проверка прав доступа ==="
chown -R www-data:www-data storage bootstrap/cache public/storage 2>/dev/null
chmod -R 775 storage bootstrap/cache 2>/dev/null
echo "✅ Права обновлены"

echo ""
echo "=== 5. Очистка кешей ==="
php artisan config:clear > /dev/null 2>&1
php artisan cache:clear > /dev/null 2>&1  
php artisan route:clear > /dev/null 2>&1
php artisan optimize > /dev/null 2>&1
echo "✅ Кеши очищены"

echo ""
echo "=== 6. Проверка роутов API ==="
php artisan route:list | grep -E "(login|api/)" | head -5
echo ""
echo "✅ Исправление завершено!"
