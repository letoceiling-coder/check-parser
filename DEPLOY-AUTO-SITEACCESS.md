# Развёртывание auto.siteaccess.ru на сервере 89.169.39.244

DNS для **auto.siteaccess.ru** должен указывать на **89.169.39.244**.

---

## 1. Подключение к серверу

```bash
ssh root@89.169.39.244
```

---

## 2. Подготовка дампа БД на вашем ПК

Файл дампа у вас: `C:\Users\dsc-2\Downloads\dsc23ytp_check.sql.zip`

1. Распакуйте архив и получите файл **dsc23ytp_check.sql**.
2. Загрузите его на сервер одной из команд (выполнять **на вашем ПК** в PowerShell):

```powershell
scp C:\Users\dsc-2\Downloads\dsc23ytp_check.sql root@89.169.39.244:/root/
```

(Если после распаковки файл лежит в другой папке — укажите правильный путь.)

---

## 3. На сервере: один каталог для двух доменов

Сайт уже развёрнут как **project.siteaccess.ru** в каталоге:
`/home/d/dsc23ytp/project.siteaccess.ru/public_html`

Чтобы открывать тот же проект по **auto.siteaccess.ru**, достаточно добавить второй виртуальный хост и сертификат. Код и БД общие.

---

## 4. Команды на сервере (по шагам)

Выполняйте под **root** после `ssh root@89.169.39.244`.

### 4.1 Каталог проекта

```bash
cd /home/d/dsc23ytp/project.siteaccess.ru/public_html
```

### 4.2 Импорт базы данных

Подставьте имя базы, пользователя и пароль из вашего `.env` (или из дампа).

```bash
# Пример: база dsc23ytp_check, пользователь dsc23ytp
mysql -u dsc23ytp -p dsc23ytp_check < /root/dsc23ytp_check.sql
```

(Пароль запросится. Если дамп создаёт базу сам — при необходимости создайте базу: `mysql -e "CREATE DATABASE IF NOT EXISTS dsc23ytp_check;"`.)

### 4.3 Проверка .env

Убедитесь, что в `.env` указаны правильные `APP_URL`, `DB_*` и т.д. Для второго домена можно оставить один `APP_URL` (например, `https://project.siteaccess.ru`) или позже настроить определение домена в коде.

```bash
nano .env
# Проверьте: APP_URL, DB_DATABASE, DB_USERNAME, DB_PASSWORD
```

### 4.4 Обновление кода и зависимости

```bash
cd /home/d/dsc23ytp/project.siteaccess.ru/public_html
git pull origin main
composer install --no-dev --optimize-autoloader
```

### 4.5 Миграции

```bash
php artisan migrate --force
```

### 4.6 Сборка фронтенда

```bash
cd frontend
npm install --legacy-peer-deps
npm run build --legacy-peer-deps
cd ..
```

### 4.7 Права и кеш

```bash
chown -R www-data:www-data storage bootstrap/cache
# или, если веб-сервер под пользователем nginx:
# chown -R nginx:nginx storage bootstrap/cache

php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

---

## 5. Nginx: виртуальный хост для auto.siteaccess.ru

Создайте конфиг (путь к корню замените при необходимости):

```bash
cat > /etc/nginx/sites-available/auto.siteaccess.ru << 'EOF'
server {
    listen 80;
    server_name auto.siteaccess.ru;
    root /home/d/dsc23ytp/project.siteaccess.ru/public_html/public;
    index index.php;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
EOF
```

Проверьте, какой сокет PHP-FPM у вас (8.1 или 8.2):

```bash
ls /var/run/php/
```

При необходимости отредактируйте строку `fastcgi_pass` в конфиге (например, `php8.1-fpm.sock`).

Включите сайт и проверьте конфиг:

```bash
ln -s /etc/nginx/sites-available/auto.siteaccess.ru /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

---

## 6. Сертификат SSL (Let's Encrypt)

```bash
# Если certbot ещё не установлен:
apt update && apt install certbot python3-certbot-nginx -y

# Выпуск сертификата для auto.siteaccess.ru
certbot --nginx -d auto.siteaccess.ru --non-interactive --agree-tos -m admin@siteaccess.ru
```

Укажите свой email вместо `admin@siteaccess.ru`, если нужно.

---

## 7. Проверка

- Откройте в браузере: **https://auto.siteaccess.ru**
- Проверьте, что открывается тот же проект, что и на project.siteaccess.ru.

---

## Переменные для деплоя (локальная машина)

На ПК в `.env` для команды `php artisan deploy` должны быть указаны:

- **DEPLOY_URL=https://auto.siteaccess.ru** — адрес сайта, на который уходит запрос на обновление (не project.siteaccess.ru).
- **DEPLOY_TOKEN** — тот же секретный токен, что прописан в `.env` на сервере.

Иначе деплой будет вызывать не тот хост.

---

## poppler-utils (сервер)

Для улучшенного парсера чеков (метод «Улучшенный» в настройках бота) используется извлечение текста из PDF без OCR (команда `pdftotext`). На сервере должен быть установлен пакет **poppler-utils**:

```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install -y poppler-utils
```

Проверка: `pdftotext -v`. Если команда есть — текстовые PDF будут обрабатываться без OCR.

---

## Краткая последовательность (копировать целиком)

После загрузки `dsc23ytp_check.sql` на сервер в `/root/`:

```bash
cd /home/d/dsc23ytp/project.siteaccess.ru/public_html
mysql -u dsc23ytp -p dsc23ytp_check < /root/dsc23ytp_check.sql
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
cd frontend && npm install --legacy-peer-deps && npm run build --legacy-peer-deps && cd ..
chown -R www-data:www-data storage bootstrap/cache
php artisan config:clear && php artisan cache:clear
```

Далее добавьте конфиг Nginx для **auto.siteaccess.ru** и выполните **certbot** (шаги 5 и 6 выше).

---

## Альтернатива: готовый скрипт

В репозитории есть скрипт **deploy-auto-siteaccess.sh**. Скопируйте его на сервер и выполните:

```bash
chmod +x deploy-auto-siteaccess.sh
./deploy-auto-siteaccess.sh
```

Перед запуском загрузите **dsc23ytp_check.sql** в каталог проекта или в `/root/` и при необходимости отредактируйте в скрипте путь к проекту и параметры БД.
