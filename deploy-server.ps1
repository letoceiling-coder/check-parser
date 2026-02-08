# Инструкция: обновление на сервере 89.169.39.244
# Выполните на сервере (SSH root@89.169.39.244):

<#
cd /home/d/dsc23ytp/project.siteaccess.ru/public_html
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
cd frontend && npm install --legacy-peer-deps && npm run build --legacy-peer-deps && cd ..
php artisan config:clear
php artisan cache:clear
#>

# Для теста PDF (нужен pdftotext):
# php artisan checks:analyze-pdfs /path/to/cheki

Write-Host "Выполните на сервере (ssh root@89.169.39.244):" -ForegroundColor Cyan
Write-Host @"

cd /home/d/dsc23ytp/project.siteaccess.ru/public_html
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
cd frontend && npm install --legacy-peer-deps && npm run build --legacy-peer-deps && cd ..
php artisan config:clear
php artisan cache:clear

# Тест PDF (если pdftotext установлен):
# php artisan checks:analyze-pdfs /путь/к/папке/с/чеками

"@
