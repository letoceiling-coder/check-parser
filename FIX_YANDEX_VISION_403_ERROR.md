# Исправление ошибки 403 для Yandex Vision API

## Проблема:
В логах появляется ошибка:
```
[ERROR] Yandex Vision API: Permission denied (403)
"Permission to [resource-manager.folder b1g6d2bijseuj7sagqub, ...] denied"
```

Это означает, что сервисный аккаунт не имеет прав на использование Vision API в каталоге.

## Решение: Назначить дополнительные роли сервисному аккаунту

### Вариант 1: Через веб-интерфейс Yandex Cloud Console (рекомендуется)

#### Шаг 1: Откройте Yandex Cloud Console
1. Перейдите на https://console.cloud.yandex.ru/
2. Выберите каталог `b1g6d2bijseuj7sagqub` (или ваш каталог)

#### Шаг 2: Откройте сервисный аккаунт
1. В левом меню найдите **"Сервисные аккаунты"** (Service Accounts)
2. Найдите и откройте сервисный аккаунт `vision-api-service`

#### Шаг 3: Добавьте роли для каталога
1. Перейдите на вкладку **"Права доступа"** (Access Rights)
2. Нажмите кнопку **"Назначить роли"** (Assign roles)
3. В выпадающем списке выберите роль: **`viewer`** (или `resource-manager.folder.viewer`)
   - Роль `viewer` дает права на просмотр ресурсов в каталоге, что необходимо для Vision API
4. В поле **"Область применения"** выберите: **"Каталог"** → выберите `b1g6d2bijseuj7sagqub`
5. Нажмите **"Сохранить"**

#### Шаг 4: Проверьте наличие роли `ai.vision.user`
1. Убедитесь, что роль **`ai.vision.user`** уже назначена (она должна быть, если вы создавали аккаунт по инструкции)
2. Если её нет, добавьте её также:
   - Нажмите **"Назначить роли"**
   - Выберите роль: **`ai.vision.user`**
   - Область: **"Каталог"** → `b1g6d2bijseuj7sagqub`
   - Сохраните

#### Шаг 5: Проверка
После назначения ролей должно быть:
- ✅ `ai.vision.user` - для использования Vision API
- ✅ `viewer` - для доступа к ресурсам каталога

### Вариант 2: Через Yandex Cloud CLI (yc)

Если у вас установлен Yandex Cloud CLI:

```bash
# 1. Установите yc (если еще не установлен)
# Для Linux:
curl -sSL https://storage.yandexcloud.net/yandexcloud-yc/install.sh | bash

# 2. Инициализируйте yc
yc init

# 3. Назначьте роль viewer для сервисного аккаунта
yc resource-manager folder add-access-binding b1g6d2bijseuj7sagqub \
  --role viewer \
  --subject serviceAccount:aje5rn9am5c7t3fcgjdd

# 4. Убедитесь, что роль ai.vision.user назначена
yc resource-manager folder add-access-binding b1g6d2bijseuj7sagqub \
  --role ai.vision.user \
  --subject serviceAccount:aje5rn9am5c7t3fcgjdd

# 5. Проверьте назначенные роли
yc resource-manager folder list-access-bindings b1g6d2bijseuj7sagqub
```

**Примечание:** Замените `aje5rn9am5c7t3fcgjdd` на ID вашего сервисного аккаунта (можно найти в консоли).

## Проверка работы

После назначения ролей:

1. **Подождите 1-2 минуты** (права могут применяться с небольшой задержкой)

2. **Отправьте тестовый чек боту** в Telegram

3. **Проверьте логи на сервере:**
```bash
tail -f storage/logs/laravel.log | grep -E "Yandex Vision|extracted text"
```

Должно появиться:
```
[INFO] Yandex Vision extracted text
[INFO] Text extracted using extractTextWithYandexVision
```

Вместо ошибки:
```
[ERROR] Yandex Vision API: Permission denied (403)
```

## Необходимые роли для работы Vision API:

1. **`ai.vision.user`** - основная роль для использования Vision API
2. **`viewer`** (или `resource-manager.folder.viewer`) - для доступа к ресурсам каталога

## Если ошибка 403 сохраняется:

1. **Проверьте, что роли назначены именно для каталога** (не для облака или организации)
2. **Убедитесь, что используется правильный Folder ID** в `.env`:
   ```bash
   grep YANDEX_FOLDER_ID .env
   ```
   Должно быть: `YANDEX_FOLDER_ID=b1g6d2bijseuj7sagqub`

3. **Проверьте, что API ключ правильный:**
   ```bash
   grep YANDEX_VISION_API_KEY .env
   ```
   Должен начинаться с `AQVN...`

4. **Очистите кеш Laravel:**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

5. **Подождите еще 2-3 минуты** - иногда права применяются с задержкой

## Альтернативное решение (если не получается настроить права):

Если по каким-то причинам не удается настроить права для Yandex Vision API, система будет использовать OCR.space как fallback. Однако качество распознавания может быть ниже, особенно для PDF документов.

Для улучшения качества OCR.space можно:
- Установить Tesseract OCR на сервере (см. `INSTALL_TESSERACT.md`)
- Настроить Google Vision API (если есть доступ)
