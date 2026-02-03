# Финальное исправление на сервере

## Выполните на сервере:

```bash
cd ~/project.siteaccess.ru/public_html

# 1. Обновить .htaccess в корне с обработкой Authorization
cat > .htaccess << 'EOF'
<IfModule mod_rewrite.c>
    RewriteEngine On
    
    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    
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

# 2. Обновить код из git
git fetch origin
git reset --hard origin/main

# 3. Проверить DEPLOY_URL в .env
grep DEPLOY_URL .env
# Должно быть: DEPLOY_URL=https://project.siteaccess.ru

# 4. Проверить доступность через HTTPS
curl -X POST https://project.siteaccess.ru/api/deploy -H "Authorization: Bearer bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4" -H "Accept: application/json" -v

# 5. Если HTTPS не работает, проверить через HTTP
curl -X POST http://project.siteaccess.ru/api/deploy -H "Authorization: Bearer bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4" -H "Accept: application/json" -v
```
