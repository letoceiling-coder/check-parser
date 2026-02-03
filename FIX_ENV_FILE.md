# Исправление .env файла на сервере

## Проблема:
```
The environment file is invalid!
Failed to parse dotenv file. Encountered an invalid name at [cd ~/project.siteaccess.ru/public_html].
```

Это означает, что в `.env` файл попала команда `cd ~/project.siteaccess.ru/public_html`.

## Решение:

Выполните на сервере:

```bash
cd ~/project.siteaccess.ru/public_html

# 1. Проверьте .env файл на наличие лишних строк
cat .env | grep -n "cd\|~\|project.siteaccess"

# 2. Если найдены проблемные строки, удалите их
# Откройте файл для редактирования
nano .env

# Или используйте sed для удаления строк с "cd"
sed -i '/^cd /d' .env
sed -i '/^~/d' .env

# 3. Проверьте, что файл валидный
php artisan config:clear

# 4. Если ошибка сохраняется, проверьте формат .env
# Каждая строка должна быть в формате: KEY=value
# Без пробелов вокруг знака =
# Без кавычек (если не требуется)

# 5. Проверьте последние строки файла
tail -20 .env
```

## Проверка валидности .env:

```bash
# Попробуйте загрузить конфигурацию
php artisan tinker --execute="echo env('APP_NAME');"

# Если работает, значит .env валиден
```
