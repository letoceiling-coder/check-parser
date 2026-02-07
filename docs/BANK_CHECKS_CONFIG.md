# Конфигурация парсинга чеков по банкам

## Структура конфига

Файл: `config/bank_checks.php`

Для каждого банка заданы:

| Поле | Описание |
|------|----------|
| `detect_keywords` | Слова в тексте чека для определения банка |
| `sum_keys` | Ключевые слова рядом с суммой (для скоринга) |
| `date_keys` | Ключевые слова рядом с датой |
| `sum_regex` | Regex для извлечения суммы (группа 1 = число) |
| `date_regex` | Regex для даты (группы: день, месяц, год, час, мин, сек) |
| `unique_regex` | Regex для уникального ID операции |

## Анализ ваших чеков

Чтобы подобрать regex под **ваши** 13 PDF:

### 1. Загрузите PDF на сервер

```powershell
scp "C:\Users\dsc-2\Documents\чеки\*.pdf" root@89.169.39.244:/var/www/auto.siteaccess.ru/storage/app/cheki-analysis/
```

(Создайте папку на сервере: `mkdir -p storage/app/cheki-analysis`)

### 2. Запустите анализ на сервере

```bash
ssh root@89.169.39.244
cd /var/www/auto.siteaccess.ru
php artisan checks:analyze-pdfs storage/app/cheki-analysis --output=storage/app/cheki-analysis/report.json
```

### 3. Просмотрите отчёт

Отчёт покажет для каждого PDF:
- Определённый банк
- Превью текста (первые 800 символов)
- Длину текста

По тексту можно уточнить regex в `config/bank_checks.php`.

### 4. Формат regex

**Сумма:**
- Группа `(1)` должна захватывать число: `12345` или `10 000,50` или `10.000`
- Пример: `/итого[^\d]{0,40}(\d{1,3}(?:[\s ]\d{3})*(?:[.,]\d{2})?)\s*[₽руб.]?/ui`

**Дата:**
- DD.MM.YYYY: группы 1=день, 2=месяц, 3=год
- С временем: 4=час, 5=мин, 6=сек
- YYYY-MM-DD: группы 1=год, 2=месяц, 3=день

**Уникальный ID:**
- Группа `(1)` — строка идентификатора операции, квитанции и т.п.

## Банки в конфиге

| ID | Банк | Ключевые слова для детекции |
|----|------|----------------------------|
| sber | Сбербанк | сбербанк, сбер, sberbank |
| tinkoff | Тинькофф | тинькофф, т-банк, tinkoff |
| alfabank | Альфа-Банк | альфа-банк, alfabank |
| ozonbank | Ozon Bank | ozon bank, ozonbank |
| vtb | ВТБ | втб, vtb |
| raiffeisen | Райффайзен | райффайзен |
| gazprombank | Газпромбанк | газпромбанк |
| default | Другой | (fallback) |

## Подсказки по имени файла

Если в тексте PDF банк не найден, проверяется имя файла (`filename_hints`):
- `Alfa-Bank_receipt_25012026.pdf` → alfabank
- `ozonbank_document_*.pdf` → ozonbank
- `Документ по операции_*.pdf` → tinkoff
