#!/bin/bash

echo "=========================================="
echo "Проверка готовности сервера к deploy"
echo "=========================================="
echo ""

# Цвета для вывода
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

ERRORS=0

# 1. Проверка Git
echo "1. Проверка Git репозитория..."
if git status &>/dev/null; then
    echo -e "${GREEN}✓ Git репозиторий настроен${NC}"
    REMOTE=$(git remote get-url origin 2>/dev/null)
    if [ -n "$REMOTE" ]; then
        echo "  Remote: $REMOTE"
    fi
else
    echo -e "${RED}✗ Git репозиторий не настроен${NC}"
    ((ERRORS++))
fi
echo ""

# 2. Проверка PHP
echo "2. Проверка PHP..."
PHP_VERSION=$(php -v 2>/dev/null | head -1)
if [ -n "$PHP_VERSION" ]; then
    echo -e "${GREEN}✓ PHP установлен${NC}"
    echo "  $PHP_VERSION"
    
    # Проверка расширений
    REQUIRED_EXTENSIONS=("pdo" "pdo_mysql" "mbstring" "openssl" "tokenizer" "xml" "ctype" "json" "fileinfo" "curl")
    MISSING_EXTENSIONS=()
    
    for ext in "${REQUIRED_EXTENSIONS[@]}"; do
        if ! php -m | grep -q "^$ext$"; then
            MISSING_EXTENSIONS+=("$ext")
        fi
    done
    
    if [ ${#MISSING_EXTENSIONS[@]} -eq 0 ]; then
        echo -e "${GREEN}✓ Все необходимые расширения установлены${NC}"
    else
        echo -e "${RED}✗ Отсутствуют расширения: ${MISSING_EXTENSIONS[*]}${NC}"
        ((ERRORS++))
    fi
else
    echo -e "${RED}✗ PHP не установлен${NC}"
    ((ERRORS++))
fi
echo ""

# 3. Проверка Composer
echo "3. Проверка Composer..."
if [ -f "bin/composer" ]; then
    COMPOSER_VERSION=$(php bin/composer --version 2>/dev/null)
    echo -e "${GREEN}✓ Composer найден в bin/composer${NC}"
    echo "  $COMPOSER_VERSION"
elif command -v composer &>/dev/null; then
    COMPOSER_VERSION=$(composer --version 2>/dev/null)
    echo -e "${YELLOW}⚠ Composer найден глобально (будет использован)${NC}"
    echo "  $COMPOSER_VERSION"
    echo -e "${YELLOW}  Примечание: при первом deploy будет установлен в bin/composer${NC}"
else
    echo -e "${YELLOW}⚠ Composer не найден (будет установлен автоматически при deploy)${NC}"
fi
echo ""

# 4. Проверка .env
echo "4. Проверка .env файла..."
if [ -f ".env" ]; then
    echo -e "${GREEN}✓ .env файл существует${NC}"
    
    # Проверка ключевых переменных
    if grep -q "APP_KEY=" .env && ! grep -q "APP_KEY=$" .env; then
        echo -e "${GREEN}✓ APP_KEY настроен${NC}"
    else
        echo -e "${RED}✗ APP_KEY не настроен (запустите: php artisan key:generate)${NC}"
        ((ERRORS++))
    fi
    
    if grep -q "DEPLOY_TOKEN=" .env && ! grep -q "DEPLOY_TOKEN=$" .env; then
        echo -e "${GREEN}✓ DEPLOY_TOKEN настроен${NC}"
    else
        echo -e "${RED}✗ DEPLOY_TOKEN не настроен${NC}"
        ((ERRORS++))
    fi
    
    if grep -q "DB_CONNECTION=mysql" .env; then
        echo -e "${GREEN}✓ База данных настроена${NC}"
    else
        echo -e "${YELLOW}⚠ Проверьте настройки базы данных${NC}"
    fi
else
    echo -e "${RED}✗ .env файл не найден${NC}"
    echo "  Скопируйте .env.example в .env и настройте"
    ((ERRORS++))
fi
echo ""

# 5. Проверка базы данных
echo "5. Проверка подключения к базе данных..."
if php artisan tinker --execute="try { DB::connection()->getPdo(); echo 'OK'; } catch(Exception \$e) { echo 'FAILED'; }" 2>/dev/null | grep -q "OK"; then
    echo -e "${GREEN}✓ Подключение к БД успешно${NC}"
else
    echo -e "${RED}✗ Не удалось подключиться к БД${NC}"
    echo "  Проверьте настройки в .env"
    ((ERRORS++))
fi
echo ""

# 6. Проверка зависимостей
echo "6. Проверка Composer зависимостей..."
if [ -d "vendor" ] && [ -f "vendor/autoload.php" ]; then
    echo -e "${GREEN}✓ Зависимости установлены${NC}"
else
    echo -e "${YELLOW}⚠ Зависимости не установлены${NC}"
    echo "  Будет выполнено: composer install при deploy"
fi
echo ""

# 7. Проверка миграций
echo "7. Проверка миграций..."
if php artisan migrate:status &>/dev/null; then
    echo -e "${GREEN}✓ Миграции доступны${NC}"
else
    echo -e "${YELLOW}⚠ Не удалось проверить статус миграций${NC}"
fi
echo ""

# 8. Проверка прав доступа
echo "8. Проверка прав доступа..."
if [ -w "storage" ]; then
    echo -e "${GREEN}✓ storage/ доступен для записи${NC}"
else
    echo -e "${RED}✗ storage/ недоступен для записи${NC}"
    echo "  Выполните: chmod -R 775 storage"
    ((ERRORS++))
fi

if [ -w "bootstrap/cache" ]; then
    echo -e "${GREEN}✓ bootstrap/cache/ доступен для записи${NC}"
else
    echo -e "${RED}✗ bootstrap/cache/ недоступен для записи${NC}"
    echo "  Выполните: chmod -R 775 bootstrap/cache"
    ((ERRORS++))
fi
echo ""

# 9. Проверка маршрута deploy
echo "9. Проверка маршрута /api/deploy..."
if php artisan route:list 2>/dev/null | grep -q "deploy"; then
    echo -e "${GREEN}✓ Маршрут /api/deploy зарегистрирован${NC}"
else
    echo -e "${RED}✗ Маршрут /api/deploy не найден${NC}"
    ((ERRORS++))
fi
echo ""

# 10. Проверка структуры проекта
echo "10. Проверка структуры проекта..."
REQUIRED_FILES=("public/index.php" "bootstrap/app.php" "routes/api.php" "app/Http/Controllers/DeployController.php")
MISSING_FILES=()

for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        MISSING_FILES+=("$file")
    fi
done

if [ ${#MISSING_FILES[@]} -eq 0 ]; then
    echo -e "${GREEN}✓ Все необходимые файлы присутствуют${NC}"
else
    echo -e "${RED}✗ Отсутствуют файлы: ${MISSING_FILES[*]}${NC}"
    ((ERRORS++))
fi
echo ""

# Итоговая проверка
echo "=========================================="
if [ $ERRORS -eq 0 ]; then
    echo -e "${GREEN}✓ Все проверки пройдены! Сервер готов к deploy.${NC}"
    echo ""
    echo "Для запуска deploy с локальной машины выполните:"
    echo "  php artisan deploy"
    exit 0
else
    echo -e "${RED}✗ Найдено ошибок: $ERRORS${NC}"
    echo ""
    echo "Исправьте ошибки перед запуском deploy"
    exit 1
fi
