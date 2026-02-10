# Исправление 403 и работы API на auto.siteaccess.ru

## Причина

- **403** — из‑за смены `index.html` на `admin.html` в public не осталось главной страницы.
- **API 404** — все запросы уходили в React, а не в Laravel.

## Что сделано в коде

1. **Откат**: снова собирается `index.html` (без переименования в admin.html).
2. **Nginx**: в `deploy/nginx-auto.siteaccess.ru.conf` добавлено:
   - запросы к `/api` обрабатывает Laravel (`index.php`);
   - запросы к `/` и остальным URL — статика и SPA (`index.html`).

## Что сделать на сервере

Подключиться и выполнить:

```bash
ssh root@89.169.39.244
cd /var/www/auto.siteaccess.ru
git pull
```

### 1. Восстановить index.html и index.php в public

Если в `public/` нет `index.html` (остался только `admin.html`):

```bash
# переименовать обратно (если есть admin.html)
mv public/admin.html public/index.html

# убедиться, что есть Laravel index.php
cp frontend/laravel-public/index.php public/index.php
cp frontend/laravel-public/.htaccess public/.htaccess
```

Если делаете полную пересборку фронта:

```bash
cd frontend
npm run build
# postbuild скопирует index.php и .htaccess; index.html будет создан билдом
cd ..
```

### 2. Применить конфиг nginx

```bash
cp /var/www/auto.siteaccess.ru/deploy/nginx-auto.siteaccess.ru.conf /etc/nginx/sites-available/auto.siteaccess.ru
# или отредактировать существующий конфиг по пути из nginx -T

nginx -t
systemctl reload nginx
```

### 3. Проверка

- https://auto.siteaccess.ru/ — открывается админка (React).
- POST https://auto.siteaccess.ru/api/login — отвечает Laravel (JSON).
