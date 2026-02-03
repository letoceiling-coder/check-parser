# Исправление ошибки 403 Forbidden

## Проблема:
Apache возвращает 403 Forbidden - это проблема с правами доступа или конфигурацией веб-сервера.

## Возможные причины:
1. DocumentRoot указывает не на `public/` директорию
2. Неправильные права доступа к файлам
3. Проблемы с .htaccess
4. Apache не может выполнить PHP скрипты

## Решения:

### 1. Проверьте DocumentRoot в конфигурации Apache

DocumentRoot должен указывать на `public_html/public`, а не на `public_html`.

Проверьте конфигурацию виртуального хоста:
```bash
# Обычно находится в:
/etc/apache2/sites-enabled/project.siteaccess.ru.conf
# или
/etc/httpd/conf.d/project.siteaccess.ru.conf
```

Должно быть:
```apache
<VirtualHost *:80>
    ServerName project.siteaccess.ru
    DocumentRoot /home/dsc23ytp/project.siteaccess.ru/public_html/public
    
    <Directory /home/dsc23ytp/project.siteaccess.ru/public_html/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 2. Проверьте права доступа

```bash
# Установите правильные права
cd ~/project.siteaccess.ru/public_html

# Права на директории
find . -type d -exec chmod 755 {} \;

# Права на файлы
find . -type f -exec chmod 644 {} \;

# Особые права для storage и cache
chmod -R 775 storage bootstrap/cache
chown -R dsc23ytp:newcustomers storage bootstrap/cache

# Права на public
chmod 755 public
chmod 644 public/index.php
```

### 3. Проверьте .htaccess файлы

```bash
# Проверьте наличие .htaccess в public
ls -la public/.htaccess

# Проверьте наличие .htaccess в корне (если DocumentRoot = public_html)
ls -la .htaccess
```

### 4. Проверьте, что mod_rewrite включен

```bash
# Проверьте, включен ли mod_rewrite
apache2ctl -M | grep rewrite
# или
httpd -M | grep rewrite

# Если не включен, включите:
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 5. Проверьте логи Apache

```bash
# Проверьте логи ошибок
tail -f /var/log/apache2/error.log
# или
tail -f /var/log/httpd/error_log
```

### 6. Временное решение - создайте символическую ссылку

Если DocumentRoot указывает на `public_html`, создайте ссылку:
```bash
# В директории public_html создайте index.php, который перенаправит в public
# Но лучше настроить DocumentRoot правильно
```

### 7. Проверьте структуру проекта

```bash
# Убедитесь, что public/index.php существует
ls -la public/index.php

# Проверьте содержимое
head -5 public/index.php
```

## Быстрое решение (если DocumentRoot = public_html):

Если DocumentRoot указывает на `public_html`, а не на `public_html/public`, то нужно либо:
1. Изменить DocumentRoot на `public_html/public` (рекомендуется)
2. Или создать .htaccess в корне, который перенаправит в public

Создайте/обновите `.htaccess` в корне `public_html`:
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/public/
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

Но лучше настроить DocumentRoot правильно!
