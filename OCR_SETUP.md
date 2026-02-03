# Настройка OCR для распознавания чеков

Система теперь использует OCR (Optical Character Recognition) для извлечения суммы платежа из фотографий и PDF документов чеков.

## Поддерживаемые методы OCR:

1. **Yandex Vision API** (рекомендуется для русского текста)
2. **OCR.space API** (бесплатный, есть лимиты)
3. **Tesseract OCR** (локальный, требует установки)
4. **Google Cloud Vision API** (платный)

## Настройка API ключей:

Добавьте в `.env` файл:

```env
# Yandex Vision API (рекомендуется)
YANDEX_VISION_API_KEY=your_api_key_here
YANDEX_FOLDER_ID=your_folder_id_here

# OCR.space API (бесплатный, можно использовать без ключа)
OCR_SPACE_API_KEY=helloworld

# Google Cloud Vision API (опционально)
GOOGLE_VISION_API_KEY=your_api_key_here
```

## Установка Tesseract (опционально):

### Linux (Ubuntu/Debian):
```bash
sudo apt-get update
sudo apt-get install tesseract-ocr
sudo apt-get install tesseract-ocr-rus  # Русский язык
```

### Windows:
1. Скачайте установщик с https://github.com/UB-Mannheim/tesseract/wiki
2. Установите Tesseract
3. Добавьте путь к `tesseract.exe` в PATH

### macOS:
```bash
brew install tesseract
brew install tesseract-lang  # Русский язык
```

## Как это работает:

1. Пользователь отправляет фото чека или PDF в бот
2. Система пробует OCR методы по порядку:
   - Yandex Vision API
   - OCR.space API
   - Tesseract (если установлен)
   - Google Vision API
3. Из распознанного текста извлекается сумма платежа
4. Результат отправляется пользователю

## Парсинг суммы:

Система ищет сумму по следующим паттернам:
- "Итого: 550 ₽"
- "Сумма: 550 ₽"
- "К оплате: 550 ₽"
- "Всего: 550 ₽"
- Любые числа с символом валюты (₽, руб)

## Поддержка PDF:

PDF документы автоматически конвертируются в изображения с помощью Imagick перед обработкой OCR.

## Логирование:

Все операции OCR логируются в `storage/logs/laravel.log`:
```bash
tail -f storage/logs/laravel.log | grep -E "OCR|Trying|extracted|parsing"
```
