# Исправление .htaccess в корне

## Проблема:
DocumentRoot указывает на `public_html`, а не на `public_html/public`. Нужно настроить `.htaccess` в корне так, чтобы все запросы перенаправлялись в `public/index.php`.

## Решение:

```bash
cd ~/project.siteaccess.ru/public_html

# Обновить .htaccess в корне
cat > .htaccess << 'EOF'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Если запрос к существующему файлу в public - отдать его
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{DOCUMENT_ROOT}/public%{REQUEST_URI} -f
    RewriteRule ^(.*)$ public/$1 [L]

    # Все остальные запросы (включая /api/*) перенаправить в public/index.php
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ public/index.php [L]
</IfModule>
EOF

chmod 644 .htaccess

# Проверить
curl -X POST http://localhost/api/deploy -H "Authorization: Bearer bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4" -H "Accept: application/json" -v
```
