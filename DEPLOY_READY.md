# Сервер готов к deploy! ✅

## Что сделано на сервере:
- ✅ Кеши очищены
- ✅ SANCTUM_STATEFUL_DOMAINS добавлен в .env

## ⚠️ Важно: Проверьте SANCTUM_STATEFUL_DOMAINS на сервере

Выполните на сервере:
```bash
cat .env | grep SANCTUM_STATEFUL_DOMAINS
```

Должно быть:
```
SANCTUM_STATEFUL_DOMAINS=project.siteaccess.ru
```

Если там перенос строки или ошибка, исправьте:
```bash
# Удалите неправильную строку
sed -i '/SANCTUM_STATEFUL_DOMAINS=/d' .env

# Добавьте правильно
echo "SANCTUM_STATEFUL_DOMAINS=project.siteaccess.ru" >> .env
```

## 🚀 Запуск deploy с локальной машины:

### 1. Добавить все файлы в git (на локальной машине):
```bash
git add .
```

### 2. Проверить, что все добавлено:
```bash
git status
```

### 3. Запустить deploy:
```bash
php artisan deploy
```

Команда выполнит:
1. ✅ Сборку React приложения в `public/`
2. ✅ Git add, commit, push
3. ✅ POST запрос на `https://project.siteaccess.ru/api/deploy`
4. ✅ На сервере автоматически:
   - git pull
   - composer install (если нужно)
   - миграции
   - очистка кешей
   - оптимизация

## 📝 Если возникнут проблемы:

### Проверка логов на сервере:
```bash
tail -f storage/logs/laravel.log
```

### Ручной запуск deploy на сервере (если нужно):
```bash
git pull
php bin/composer install --no-interaction --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan optimize
```
