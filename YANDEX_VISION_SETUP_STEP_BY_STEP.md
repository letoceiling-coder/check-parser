# Пошаговая настройка Yandex Vision API

## Шаг 1: Создание сервисного аккаунта

1. В левом меню Yandex Cloud Console найдите и нажмите **"Сервисные аккаунты"** (Service Accounts)
   - Или перейдите по прямой ссылке: `https://console.cloud.yandex.ru/folders/b1g6d2bijseuj7sagqub/iam/service-accounts`

2. Нажмите кнопку **"+ Создать сервисный аккаунт"** (Create service account)

3. Заполните форму:
   - **Имя:** `vision-api-service` (или любое другое понятное имя)
   - **Описание:** `Сервисный аккаунт для Yandex Vision API`
   - Нажмите **"Создать"**

4. Запомните **ID сервисного аккаунта** (он понадобится на следующем шаге)

## Шаг 2: Назначение роли сервисному аккаунту

1. Откройте созданный сервисный аккаунт (кликните на его имя)

2. Перейдите на вкладку **"Права доступа"** (Access Rights)

3. Нажмите **"Назначить роли"** (Assign roles)

4. В выпадающем списке выберите роль: **`ai.vision.user`**
   - Если этой роли нет в списке, введите `ai.vision.user` вручную

5. В поле **"Область применения"** выберите: **"Каталог"** → выберите ваш каталог `b1g6d2bijseuj7sagqub`

6. Нажмите **"Сохранить"**

## Шаг 3: Создание API ключа

1. В том же сервисном аккаунте перейдите на вкладку **"Ключи"** (Keys)

2. Нажмите **"+ Создать ключ"** (Create key)

3. Выберите **"API ключ"** (API key)

4. Нажмите **"Создать"**

5. **ВАЖНО:** Скопируйте созданный API ключ сразу! Он показывается только один раз.
   - Ключ будет выглядеть примерно так: `AQVNxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`

6. Сохраните ключ в безопасном месте

## Шаг 4: Обновление .env на сервере

Выполните на сервере следующие команды:

```bash
cd ~/project.siteaccess.ru/public_html

# 1. Удалить старый IAM токен (если есть)
sed -i '/YANDEX_VISION_API_KEY=/d' .env

# 2. Добавить новый API ключ (ЗАМЕНИТЕ <ваш_api_ключ> на скопированный ключ)
echo "YANDEX_VISION_API_KEY=<ваш_api_ключ>" >> .env

# 3. Проверить, что Folder ID указан (должен быть уже)
grep YANDEX_FOLDER_ID .env
# Если его нет, добавьте:
# echo "YANDEX_FOLDER_ID=b1g6d2bijseuj7sagqub" >> .env

# 4. Проверить результат
grep YANDEX_VISION_API_KEY .env
grep YANDEX_FOLDER_ID .env

# 5. Очистить кеш Laravel
php artisan config:clear
php artisan cache:clear
```

## Шаг 5: Проверка работы

1. Отправьте тестовый чек боту в Telegram

2. Проверьте логи на сервере:
```bash
tail -f storage/logs/laravel.log | grep -E "Yandex Vision|extracted text"
```

3. Должно появиться:
```
[INFO] Yandex Vision extracted text
```

Вместо ошибки:
```
[ERROR] Yandex Vision API: Permission denied (403)
```

## Альтернативный способ: Назначение роли через IAM

Если у вас нет доступа к интерфейсу, можно назначить роль через CLI:

```bash
# Установите Yandex Cloud CLI (yc), если еще не установлен
# Затем выполните:

yc iam service-account add-access-binding vision-api-service \
  --role ai.vision.user \
  --subject serviceAccount:<service-account-id> \
  --folder-id b1g6d2bijseuj7sagqub
```

## Важные замечания:

- ✅ API ключ действителен до тех пор, пока вы его не удалите
- ✅ IAM токен действителен только 12 часов (не рекомендуется для production)
- ✅ Роль `ai.vision.user` дает доступ только к Vision API
- ✅ Роль `editor` дает полный доступ ко всем ресурсам (используйте с осторожностью)
