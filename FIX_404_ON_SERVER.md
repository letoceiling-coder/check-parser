# Исправление 404 на сервере

## Проблема:
Маршрут зарегистрирован, но Apache возвращает 404. Это означает, что DocumentRoot указывает на `public_html`, а не на `public_html/public`.

## Решение:

### Вариант 1: Создать index.php в корне (если DocumentRoot = public_html)

```bash
cd ~/project.siteaccess.ru/public_html

# Создать index.php в корне, который перенаправит в public
cat > index.php << 'EOF'
<?php
/**
 * Laravel - Redirect to public directory
 */
$publicPath = __DIR__ . '/public';

// If the request is for a file that exists in public, serve it
$requestUri = $_SERVER['REQUEST_URI'];
$requestUri = str_replace('?'.($_SERVER['QUERY_STRING'] ?? ''), '', $requestUri);
$requestUri = ltrim($requestUri, '/');

if ($requestUri && file_exists($publicPath . '/' . $requestUri) && !is_dir($publicPath . '/' . $requestUri)) {
    require $publicPath . '/' . $requestUri;
    exit;
}

// Otherwise, load Laravel
require $publicPath . '/index.php';
EOF

chmod 644 index.php
```

### Вариант 2: Обновить .htaccess в корне

```bash
cd ~/project.siteaccess.ru/public_html

# Обновить .htaccess чтобы правильно обрабатывать /api/*
cat > .htaccess << 'EOF'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Если запрос к файлу в public - отдать его напрямую
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} ^/(static|favicon\.ico|robots\.txt|logo.*\.png|manifest\.json|asset-manifest\.json)
    RewriteRule ^(.*)$ public/$1 [L]

    # Все остальные запросы перенаправить в public/index.php
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ public/index.php [L]
</IfModule>
EOF

chmod 644 .htaccess
```

### Вариант 3: Восстановить public/.htaccess и public/index.php

```bash
cd ~/project.siteaccess.ru/public_html

# Восстановить public/.htaccess
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

# Восстановить public/index.php
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

chmod 644 public/.htaccess public/index.php
```

## После исправления:

```bash
# Очистить кеши
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Проверить маршруты
php artisan route:list | grep deploy

# Проверить доступность
curl -X POST http://localhost/api/deploy -H "Authorization: Bearer bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4" -H "Accept: application/json" -v
```
