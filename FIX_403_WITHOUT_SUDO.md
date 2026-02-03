# Исправление 403 Forbidden без sudo

## Шаг 1: Определите, куда указывает DocumentRoot

Создайте тестовые файлы:

```bash
# В корне public_html
echo "<?php echo 'ROOT: ' . __DIR__; ?>" > test_root.php

# В public
echo "<?php echo 'PUBLIC: ' . __DIR__; ?>" > public/test_public.php
```

Откройте в браузере:
- `https://project.siteaccess.ru/test_root.php` - если открывается, DocumentRoot = public_html
- `https://project.siteaccess.ru/test_public.php` - если открывается, DocumentRoot = public_html/public
- `https://project.siteaccess.ru/public/test_public.php` - всегда должно работать

## Шаг 2: Создайте правильный .htaccess в корне

Если DocumentRoot = public_html, создайте/обновите `.htaccess`:

```bash
cat > .htaccess << 'EOF'
<IfModule mod_rewrite.c>
    RewriteEngine On
    
    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    
    # Если запрос к статическим файлам - отдать из public
    RewriteCond %{REQUEST_URI} ^/(static|favicon\.ico|robots\.txt|logo.*\.png|manifest\.json|asset-manifest\.json|index\.html)
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^(.*)$ public/$1 [L]
    
    # Все остальные запросы перенаправить в public/index.php
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ public/index.php [L]
</IfModule>
EOF
```

## Шаг 3: Создайте index.php в корне (как запасной вариант)

```bash
cat > index.php << 'EOF'
<?php
/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * This file redirects all requests to the public directory
 */

$publicPath = __DIR__ . '/public';

// If the request is for a file that exists in public, serve it
if (file_exists($publicPath . $_SERVER['REQUEST_URI'])) {
    require $publicPath . $_SERVER['REQUEST_URI'];
    exit;
}

// Otherwise, load Laravel
require $publicPath . '/index.php';
EOF
```

## Шаг 4: Проверьте права на .htaccess

```bash
chmod 644 .htaccess
chmod 644 index.php
chmod 644 public/.htaccess
```

## Шаг 5: Проверьте, что mod_rewrite работает

Создайте тестовый файл:
```bash
echo "Rewrite test" > .htaccess_test
```

Если Apache читает .htaccess, это должно работать.

## Шаг 6: Очистите кеши Laravel

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

## Шаг 7: Проверьте в браузере

1. `https://project.siteaccess.ru/` - должно открыться
2. `https://project.siteaccess.ru/test_root.php` - покажет путь
3. `https://project.siteaccess.ru/public/test_public.php` - должно работать

## Если ничего не помогает:

Обратитесь к администратору сервера с просьбой:
1. Проверить конфигурацию виртуального хоста для project.siteaccess.ru
2. Убедиться, что DocumentRoot указывает на `/home/dsc23ytp/project.siteaccess.ru/public_html/public`
3. Проверить, что `AllowOverride All` включен для этой директории
4. Проверить логи Apache для диагностики
