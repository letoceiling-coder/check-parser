#!/bin/bash
# Финальное исправление .htaccess на сервере

cd ~/project.siteaccess.ru/public_html

# Обновить .htaccess в корне по образцу рабочего проекта
cat > .htaccess << 'EOF'
<IfModule mod_rewrite.c>
    RewriteEngine On
    
    # Перенаправляем все запросы в директорию public/
    RewriteCond %{REQUEST_URI} !^/public/
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ /public/$1 [L]
    
    # Если запрашивается корень, перенаправляем в public/
    RewriteCond %{REQUEST_URI} ^/$
    RewriteRule ^(.*)$ /public/ [L]
</IfModule>
EOF

chmod 644 .htaccess

# Обновить код из git
git fetch origin
git reset --hard origin/main

# Убедиться, что public/.htaccess существует
if [ ! -f public/.htaccess ]; then
cat > public/.htaccess << 'EOF'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    RewriteCond %{REQUEST_FILENAME} -f
    RewriteCond %{REQUEST_URI} \.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ico|json|map)$ [NC]
    RewriteRule ^ - [L]

    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
EOF
fi

# Убедиться, что public/index.php существует
if [ ! -f public/index.php ]; then
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

chmod 644 public/.htaccess public/index.php

# Очистить кеши
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Проверить
echo "=== Проверка маршрутов ==="
php artisan route:list | grep deploy

echo -e "\n=== Проверка доступности API ==="
curl -X POST http://localhost/api/deploy -H "Authorization: Bearer bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4" -H "Accept: application/json" -v
