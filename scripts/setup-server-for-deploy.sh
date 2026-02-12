#!/bin/bash
# Однократная настройка сервера для работы webhook-деплоя (POST /api/deploy).
# Запускать от root на сервере: bash scripts/setup-server-for-deploy.sh
# После этого деплой от php artisan deploy будет выполняться под пользователем www-data.

set -e

PROJECT_ROOT="${1:-/var/www/auto.siteaccess.ru}"
WEB_USER="${2:-www-data}"

if [[ $EUID -ne 0 ]]; then
  echo "Run as root: sudo bash scripts/setup-server-for-deploy.sh"
  exit 1
fi

if [[ ! -d "$PROJECT_ROOT" ]]; then
  echo "Project directory not found: $PROJECT_ROOT"
  exit 1
fi

echo "=== Setting up deploy for $PROJECT_ROOT (web user: $WEB_USER) ==="

# 1. Git: разрешить репозиторий для пользователя веб-сервера (уберет "dubious ownership")
sudo -u "$WEB_USER" git config --global --add safe.directory "$PROJECT_ROOT"
echo "Git safe.directory added for $WEB_USER"

# 2. Владелец проекта — пользователь веб-сервера (чтобы PHP-FPM мог писать в public/ и выполнять git)
chown -R "$WEB_USER:$WEB_USER" "$PROJECT_ROOT"
echo "Ownership set to $WEB_USER:$WEB_USER"

# 3. Права на storage и cache
chmod -R 775 "$PROJECT_ROOT/storage" "$PROJECT_ROOT/bootstrap/cache" 2>/dev/null || true
echo "Storage and cache permissions set"

echo "=== Done. Deploy webhook can now run as $WEB_USER. ==="
echo "For manual updates on server run: sudo -u $WEB_USER bash update-on-server.sh"
