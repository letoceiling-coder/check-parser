# Тестирование deploy на сервере

## Выполните на сервере:

```bash
cd ~/project.siteaccess.ru/public_html

# 1. Обновить код
git fetch origin
git reset --hard origin/main

# 2. Очистить кеши
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# 3. Проверить логи в реальном времени
tail -f storage/logs/laravel.log

# 4. В другом терминале выполнить запрос
curl -X POST https://project.siteaccess.ru/api/deploy \
  -H "Authorization: Bearer bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4" \
  -H "Accept: application/json" \
  -v

# 5. Проверить последние логи
tail -100 storage/logs/laravel.log | grep -i deploy
```
