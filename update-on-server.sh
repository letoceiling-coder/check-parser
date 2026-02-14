#!/bin/bash
# Обновление на сервере: подтянуть код, миграции, сборка фронта, очистка кеша.
# Запуск на сервере: bash update-on-server.sh (из корня проекта)

set -e

echo "=== 1. Git pull ==="
git pull origin main

echo "=== 2. Composer ==="
composer install --no-dev --optimize-autoloader

echo "=== 3. Миграции ==="
php artisan migrate --force

echo "=== 4. Фронтенд ==="
if [ -d frontend ]; then
  cd frontend
  npm ci --legacy-peer-deps 2>/dev/null || npm install --legacy-peer-deps
  export NODE_OPTIONS=--max-old-space-size=4096
  npm run build --legacy-peer-deps
  cd ..
fi

echo "=== 5. Очистка кеша ==="
php artisan config:clear
php artisan cache:clear
php artisan route:clear

echo "=== Готово ==="
