# Настройка деплоя (DEPLOY_URL и DEPLOY_TOKEN)

Чтобы `php artisan deploy` после пуша в git вызывал обновление на сервере, нужно задать в `.env` два параметра и один и тот же токен на сервере.

## 1. Локально (ПК, где запускаете `php artisan deploy`)

В файл **`.env`** в корне проекта добавьте:

```env
# Деплой на сервер после git push
DEPLOY_URL=https://auto.siteaccess.ru
DEPLOY_TOKEN=ваш_секретный_токен_деплоя
```

- **DEPLOY_URL** — адрес сайта **без** слэша в конце (`https://auto.siteaccess.ru`).
- **DEPLOY_TOKEN** — любой длинный случайный пароль (например, сгенерируйте: `openssl rand -hex 32` или придумайте строку 20+ символов). Этот же токен должен быть прописан на сервере.

## 2. На сервере (auto.siteaccess.ru)

Подключитесь по SSH и откройте `.env` в каталоге проекта:

```bash
ssh root@89.169.39.244
cd /var/www/auto.siteaccess.ru
nano .env
```

Добавьте (или измените) строку с **тем же** токеном, что и локально:

```env
DEPLOY_TOKEN=ваш_секретный_токен_деплоя
```

Сохраните файл (в nano: Ctrl+O, Enter, Ctrl+X).

На сервере **DEPLOY_URL** не нужен — он используется только в команде деплоя на вашем ПК для вызова `POST https://auto.siteaccess.ru/api/deploy`.

## 3. Проверка

1. Локально в `.env` заданы `DEPLOY_URL=https://auto.siteaccess.ru` и `DEPLOY_TOKEN=...`.
2. На сервере в `.env` задан тот же `DEPLOY_TOKEN=...`.
3. Запустите: `php artisan deploy` — сборка, коммит, пуш и запрос на сервер должны пройти без ошибки «DEPLOY_URL and DEPLOY_TOKEN must be set».

Если сервер вернёт 401 Unauthorized — токены не совпадают. Проверьте, что строка `DEPLOY_TOKEN` на сервере и на ПК совпадает символ в символ (без пробелов, кавычек только при необходимости).

## 4. Ошибка «Git reset failed: Permission denied» (500)

Если в ответе деплоя есть ошибка вида:

```text
unable to unlink old 'public/static/js/...': Permission denied
fatal: Could not reset index file to revision 'origin/main'
```

значит на сервере часть файлов (часто в `public/static/`) принадлежит другому пользователю (например root), а веб-сервер (PHP) работает от `www-data` и не может их перезаписать при `git reset --hard`.

**Однократно на сервере** выполните (под root):

```bash
ssh root@89.169.39.244
cd /var/www/auto.siteaccess.ru
sudo bash scripts/setup-server-for-deploy.sh
```

Скрипт выставит владельца всего каталога проекта на `www-data:www-data`, после чего деплой через webhook будет проходить без этой ошибки. Для ручного обновления на сервере используйте: `sudo -u www-data bash update-on-server.sh`, чтобы новые файлы тоже создавались от `www-data`.
