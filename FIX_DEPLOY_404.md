# Исправление 404 для /api/deploy

## Проблема:
Nginx возвращает 404 для `/api/deploy`, хотя запрос доходит до Laravel (видно в логах "Deploy attempt").

## Причина:
После `git clean` удаляются `public/.htaccess` и `public/index.php`, которые необходимы для обработки запросов.

## Решение:
Обновлен `DeployController` для автоматического создания этих файлов после `git clean`.

## Выполните на сервере:

```bash
cd ~/project.siteaccess.ru/public_html

# 1. Обновить код
git fetch origin
git reset --hard origin/main

# 2. Очистить кеши
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# 3. Проверить, что public/.htaccess и public/index.php существуют
ls -la public/.htaccess public/index.php

# 4. Если их нет, они будут созданы автоматически при следующем deploy
# Или создайте их вручную:

# Создать public/.htaccess
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

# Создать public/index.php
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

# 5. Проверить доступность
curl -X POST https://project.siteaccess.ru/api/deploy \
  -H "Authorization: Bearer bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4" \
  -H "Accept: application/json" \
  -v
```
