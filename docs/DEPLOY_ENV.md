# Настройка деплоя

Команда `php artisan deploy` делает: коммит и пуш в git, затем обновление на сервере. Обновление на сервере можно выполнять **через SSH** (рекомендуется) или через **webhook**.

---

## Вариант A: Деплой через SSH (сборка на сервере)

Если заданы **DEPLOY_SSH** и **DEPLOY_SSH_PATH**, после пуша команда подключается по SSH к серверу и выполняет: `git pull`, `composer install`, миграции, **сборку фронта** (`npm` в `frontend`), очистку кеша. Локальная сборка не запускается — всё делается на сервере.

В **`.env`** на ПК добавьте:

```env
# Деплой через SSH (сборка и обновление на сервере)
DEPLOY_SSH=root@89.169.39.244
DEPLOY_SSH_PATH=/var/www/auto.siteaccess.ru
```

- **DEPLOY_SSH** — пользователь и хост для SSH (например `root@89.169.39.244`). Должен быть настроен вход по ключу.
- **DEPLOY_SSH_PATH** — полный путь к проекту на сервере.

Запуск: `php artisan deploy`. Опции: `--no-build` (не меняет поведение при SSH, т.к. сборка на сервере); `--no-ssh` — игнорировать SSH и вызвать webhook, если заданы DEPLOY_URL и DEPLOY_TOKEN.

---

## Вариант B: Деплой через webhook

Если **DEPLOY_SSH** не задан (или указан `--no-ssh`), используется вызов `POST /api/deploy` (DEPLOY_URL и DEPLOY_TOKEN). Сборка фронта в этом случае выполняется **локально** перед пушем (если не передан `--no-build`).

### 1. Локально (ПК)

В файл **`.env`** добавьте:

```env
# Деплой через webhook после git push
DEPLOY_URL=https://auto.siteaccess.ru
DEPLOY_TOKEN=ваш_секретный_токен_деплоя
```

- **DEPLOY_URL** — адрес сайта без слэша в конце.
- **DEPLOY_TOKEN** — секретный токен; тот же должен быть в `.env` на сервере.

### 2. На сервере (auto.siteaccess.ru)

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

### 3. Проверка (webhook)

1. Локально в `.env` заданы `DEPLOY_URL=https://auto.siteaccess.ru` и `DEPLOY_TOKEN=...`.
2. На сервере в `.env` задан тот же `DEPLOY_TOKEN=...`.
3. Запустите: `php artisan deploy` — сборка, коммит, пуш и запрос на сервер должны пройти без ошибки «DEPLOY_URL and DEPLOY_TOKEN must be set».

Если сервер вернёт 401 Unauthorized — токены не совпадают. Проверьте, что строка `DEPLOY_TOKEN` на сервере и на ПК совпадает символ в символ (без пробелов, кавычек только при необходимости).

## Ошибка «Git reset failed: Permission denied» (500)

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
