# Установка Composer в bin/composer на сервере

## Выполните на сервере:

```bash
cd ~/project.siteaccess.ru/public_html

# 1. Создать директорию bin если её нет
mkdir -p bin

# 2. Скачать и установить composer в bin/composer
cd bin
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=. --filename=composer
php -r "unlink('composer-setup.php');"

# 3. Проверить установку
php bin/composer --version

# 4. Вернуться в корень проекта
cd ..

# 5. Обновить код из git
git fetch origin
git reset --hard origin/main

# 6. Очистить кеши
php artisan config:clear
php artisan cache:clear

# 7. Проверить, что bin/composer работает
php bin/composer --version
```

После этого `artisan deploy` будет использовать `bin/composer` вместо глобального, что избежит проблем с правами доступа.
