# Установка Tesseract OCR на сервере

Tesseract - это локальное OCR решение, которое не зависит от внешних API и работает быстрее.

## Установка на Linux (Ubuntu/Debian):

```bash
# Обновить пакеты
sudo apt-get update

# Установить Tesseract
sudo apt-get install -y tesseract-ocr

# Установить русский язык для Tesseract
sudo apt-get install -y tesseract-ocr-rus

# Проверить установку
tesseract --version

# Проверить доступные языки
tesseract --list-langs
```

## Установка на CentOS/RHEL:

```bash
# Установить EPEL репозиторий (если еще не установлен)
sudo yum install -y epel-release

# Установить Tesseract
sudo yum install -y tesseract

# Установить русский язык
sudo yum install -y tesseract-langpack-rus
```

## После установки:

1. Проверьте, что Tesseract работает:
```bash
tesseract --version
# Должно показать версию, например: tesseract 4.1.1
```

2. Проверьте русский язык:
```bash
tesseract --list-langs | grep rus
# Должно показать: rus
```

3. Очистите кеш Laravel:
```bash
cd ~/project.siteaccess.ru/public_html
php artisan config:clear
php artisan cache:clear
```

## Тестирование:

После установки Tesseract, система автоматически будет использовать его для распознавания текста с чеков.

Проверьте логи:
```bash
tail -f storage/logs/laravel.log | grep -E "Tesseract|extracted text"
```

## Преимущества Tesseract:

- ✅ Работает локально (не зависит от внешних API)
- ✅ Быстрее внешних API
- ✅ Нет лимитов запросов
- ✅ Работает даже при проблемах с интернетом
- ✅ Бесплатно и открытый исходный код
