# Настройка Yandex Vision API

## Полученные данные:

**ВАЖНО:** Токены были получены через скрипт `setup_yandex_vision.ps1`. Запустите скрипт снова, чтобы получить актуальные значения.

## Добавьте в .env файл:

### Локально (Windows):
Запустите скрипт `setup_yandex_vision.ps1` и скопируйте значения в `.env`:
```powershell
powershell -ExecutionPolicy Bypass -File setup_yandex_vision.ps1
```

Затем добавьте в `.env`:
```
YANDEX_VISION_API_KEY=<IAM_TOKEN_из_скрипта>
YANDEX_FOLDER_ID=<FOLDER_ID_из_скрипта>
```

### На сервере (Linux):
```bash
cd ~/project.siteaccess.ru/public_html
echo "YANDEX_VISION_API_KEY=<IAM_TOKEN>" >> .env
echo "YANDEX_FOLDER_ID=<FOLDER_ID>" >> .env

# Очистить кеш
php artisan config:clear
php artisan cache:clear
```

## ВАЖНО:

⚠️ **IAM токен действителен только 12 часов!**

Для постоянной работы рекомендуется:
1. Создать сервисный аккаунт в Yandex Cloud
2. Назначить ему роль `ai.vision.user`
3. Создать API ключ для сервисного аккаунта
4. Использовать API ключ вместо IAM токена

## Проверка работы:

После добавления в .env, отправьте чек боту и проверьте логи:
```bash
tail -f storage/logs/laravel.log | grep -E "Yandex Vision|extracted text"
```
