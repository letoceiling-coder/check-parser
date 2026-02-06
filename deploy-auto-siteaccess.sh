#!/bin/bash
# Развёртывание auto.siteaccess.ru на сервере 89.169.39.244
# Запуск: скопировать на сервер и выполнить как root (или с sudo)

set -e

DOMAIN="auto.siteaccess.ru"
# Путь к проекту - укажите существующий каталог проекта (как для project.siteaccess.ru)
# Если проект уже в /home/d/dsc23ytp/project.siteaccess.ru/public_html - используем его
PROJECT_ROOT="/home/d/dsc23ytp/project.siteaccess.ru/public_html"
# Или если разворачиваем с нуля в новый каталог:
# PROJECT_ROOT="/var/www/auto.siteaccess.ru"

echo "=== 1. Проверка каталога проекта ==="
if [ ! -d "$PROJECT_ROOT" ]; then
  echo "Создаём каталог $PROJECT_ROOT"
  mkdir -p "$PROJECT_ROOT"
  chown -R www-data:www-data "$PROJECT_ROOT" 2>/dev/null || true
fi
cd "$PROJECT_ROOT"

echo "=== 2. Обновление кода из git (если проект из репозитория) ==="
if [ -d .git ]; then
  git pull origin main || true
else
  echo "Не найден .git — пропускаем git pull. Разверните код вручную."
fi

echo "=== 3. Резервная копия .env ==="
[ -f .env ] && cp .env .env.bak.$(date +%Y%m%d%H%M) || true

echo "=== 4. Импорт базы данных ==="
echo "Убедитесь, что файл dsc23ytp_check.sql загружен в $PROJECT_ROOT или /root/"
SQL_FILE="$PROJECT_ROOT/dsc23ytp_check.sql"
[ -f /root/dsc23ytp_check.sql ] && SQL_FILE="/root/dsc23ytp_check.sql"

if [ -f "$SQL_FILE" ]; then
  # Подставьте свои данные БД из .env
  DB_NAME=$(grep DB_DATABASE .env 2>/dev/null | cut -d= -f2 | tr -d ' ')
  DB_USER=$(grep DB_USERNAME .env 2>/dev/null | cut -d= -f2 | tr -d ' ')
  DB_PASS=$(grep DB_PASSWORD .env 2>/dev/null | cut -d= -f2 | tr -d ' "')
  if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
    echo "Импорт в базу $DB_NAME..."
    mysql -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" < "$SQL_FILE" && echo "Импорт БД выполнен."
  else
    echo "Не удалось прочитать .env. Импортируйте БД вручную: mysql -u USER -p DATABASE < dsc23ytp_check.sql"
  fi
else
  echo "Файл $SQL_FILE не найден. Распакуйте dsc23ytp_check.sql.zip и загрузите dsc23ytp_check.sql на сервер, затем повторите импорт."
fi

echo "=== 5. Миграции Laravel ==="
php artisan migrate --force

echo "=== 6. Composer (без dev) ==="
composer install --no-dev --optimize-autoloader 2>/dev/null || true

echo "=== 7. Сборка фронтенда ==="
if [ -d frontend ]; then
  cd frontend
  npm ci --legacy-peer-deps 2>/dev/null || npm install --legacy-peer-deps
  npm run build --legacy-peer-deps
  cd ..
fi

echo "=== 8. Права и кеш Laravel ==="
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || chown -R nginx:nginx storage bootstrap/cache 2>/dev/null || true
php artisan config:clear
php artisan cache:clear
php artisan route:clear

echo "=== 9. Nginx: добавление домена $DOMAIN ==="
NGINX_CONF="/etc/nginx/sites-available/auto.siteaccess.ru"
PHP_SOCK="php8.2-fpm.sock"
[ -S /var/run/php/php8.1-fpm.sock ] && PHP_SOCK="php8.1-fpm.sock"
cat > "$NGINX_CONF" << NGINXEOF
server {
    listen 80;
    server_name auto.siteaccess.ru;
    root $PROJECT_ROOT/public;
    index index.php;
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location ~ \\.php\$ {
        fastcgi_pass unix:/var/run/php/$PHP_SOCK;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ /\\.(?!well-known).* { deny all; }
}
NGINXEOF
ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/ 2>/dev/null || true
nginx -t && systemctl reload nginx

echo "=== 10. Сертификат SSL для $DOMAIN ==="
if command -v certbot &>/dev/null; then
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --email admin@siteaccess.ru || certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos
else
  echo "Установите certbot: apt install certbot python3-certbot-nginx -y"
  echo "Затем: certbot --nginx -d $DOMAIN"
fi

echo "=== Готово. Проверьте https://$DOMAIN ==="
