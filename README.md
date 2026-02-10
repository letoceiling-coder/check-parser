<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## О проекте

**LEXAUTO Raffle Bot v7.0** — система автоматизации розыгрышей с продажей билетов через Telegram.

### Основные возможности

- ✅ **Telegram Bot** с FSM (конечный автомат состояний)
- ✅ **Бронирование билетов** с таймером (30 минут)
- ✅ **Защита от race conditions** (транзакции с блокировкой)
- ✅ **Автоматическая очистка** просроченных броней (cron)
- ✅ **Разделение новички/старички** (разная логика приветствия)
- ✅ **Докупка билетов** для существующих участников
- ✅ **Парсинг чеков** (PDF → сумма, дата) через Tesseract/Yandex Vision
- ✅ **Админская панель** (React + Laravel API)
- ✅ **Google Sheets интеграция** (автоматическая запись участников)
- ✅ **Шифрование персональных данных** (ФИО, телефон)

### Архитектура

**Backend:**
- Laravel 11
- PostgreSQL
- Telegram Bot API
- Google Sheets API

**Frontend:**
- React
- Vite
- TailwindCSS

### Документация

- 📖 [LEXAUTO_RAFFLE_SYSTEM.md](docs/LEXAUTO_RAFFLE_SYSTEM.md) — полное описание системы
- 🔧 [GOOGLE_SHEETS_SETUP.md](docs/GOOGLE_SHEETS_SETUP.md) — настройка интеграции с Google Sheets
- 📝 [PLAN_LEXAUTO_RAFFLE_V7.0.md](docs/PLAN_LEXAUTO_RAFFLE_V7.0.md) — план реализации

### Быстрый старт

#### 1. Установка зависимостей

```bash
# Backend
composer install

# Frontend
cd frontend
npm install
npm run build
cd ..
```

#### 2. Настройка .env

```bash
cp .env.example .env
php artisan key:generate
```

**Базовые настройки:**
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

TELEGRAM_BOT_TOKEN=your_bot_token_here
APP_URL=https://your-domain.com
```

**Google Sheets (опционально):**
```env
GOOGLE_APPLICATION_CREDENTIALS=storage/app/google/service-account.json
GOOGLE_SHEETS_ENABLED=true
```

#### 3. Миграции

```bash
php artisan migrate
```

#### 4. Настройка Telegram Webhook

```bash
php artisan telegram:set-webhook
```

#### 5. Запуск Scheduler (для автоматической очистки броней)

Добавьте в crontab:
```bash
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

### Google Sheets интеграция

При одобрении заказа администратором данные автоматически записываются в Google Таблицу.

**Настройка:**

1. Создайте Service Account в Google Cloud Console
2. Скачайте JSON-ключ и положите в `storage/app/google/service-account.json`
3. Добавьте в `.env`: `GOOGLE_SHEETS_ENABLED=true`
4. В админке бота укажите URL таблицы
5. Дайте Service Account доступ к таблице (Share → Редактор)

**Тестирование:**
```bash
# Проверка подключения
php artisan sheets:test

# Инициализация заголовков
php artisan sheets:init-headers
```

**Подробная инструкция:** [docs/GOOGLE_SHEETS_SETUP.md](docs/GOOGLE_SHEETS_SETUP.md)

### Artisan команды

```bash
# Очистка просроченных броней (запускается автоматически каждую минуту)
php artisan orders:clear-expired

# Тестирование Google Sheets
php artisan sheets:test

# Инициализация заголовков в Google Sheets
php artisan sheets:init-headers

# Создание пользователя (админ/юзер)
php artisan user:create

# Деплой проекта
php artisan deploy
```

## Deployment

### Настройка деплоя

1. Установите переменные окружения в `.env`:
```env
DEPLOY_URL=https://auto.siteaccess.ru
DEPLOY_TOKEN=your-secret-token-here
```

2. Сгенерируйте токен безопасности:
```bash
php -r "echo bin2hex(random_bytes(32));"
```

### Локальная команда деплоя

Для деплоя проекта выполните:
```bash
php artisan deploy
```

Команда выполнит:
- Сборку assets (`npm run build`)
- Коммит изменений в git
- Отправку изменений в репозиторий
- Запрос на сервер для обновления кода

**Варианты:**
- `php artisan deploy --no-build` — без сборки фронтенда (удобно на Windows, если сборка падает).
- `php artisan deploy:trigger` — только запрос на сервер (git push уже сделан). В `.env` должны быть заданы `DEPLOY_URL` и `DEPLOY_TOKEN`.

### API эндпоинт деплоя

На сервере доступен эндпоинт `/api/deploy` для автоматического обновления:

**Запрос:**
```bash
POST /api/deploy
Authorization: Bearer {DEPLOY_TOKEN}
```

**Ответ при успехе:**
```json
{
  "success": true,
  "message": "Deployment completed successfully",
  "log": [...]
}
```

**Ответ при ошибке:**
```json
{
  "success": false,
  "errors": [...],
  "log": [...]
}
```

Эндпоинт выполняет:
- Обновление кода из git (`git pull`)
- Установку composer в `bin/composer` (если отсутствует)
- Установку зависимостей (`composer install`)
- Выполнение миграций (`php artisan migrate`)
- Очистку всех кешей
- Оптимизацию приложения

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
