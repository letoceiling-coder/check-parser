#!/bin/bash
cd /var/www/auto.siteaccess.ru
sed -i 's|APP_URL=.*|APP_URL=https://auto.siteaccess.ru|' .env
sed -i 's|APP_ENV=.*|APP_ENV=production|' .env
sed -i 's|APP_DEBUG=.*|APP_DEBUG=false|' .env
sed -i 's|DB_CONNECTION=.*|DB_CONNECTION=mysql|' .env
sed -i 's|^# DB_HOST=.*|DB_HOST=127.0.0.1|' .env
sed -i 's|^# DB_PORT=.*|DB_PORT=3306|' .env
sed -i 's|^# DB_DATABASE=.*|DB_DATABASE=dsc23ytp_check|' .env
sed -i 's|^# DB_USERNAME=.*|DB_USERNAME=dsc23ytp|' .env
sed -i 's|^# DB_PASSWORD=.*|DB_PASSWORD=dsc23ytp_auto_2025|' .env
sed -i 's|^DB_CONNECTION=.*|DB_CONNECTION=mysql|' .env
sed -i 's|^DB_HOST=.*|DB_HOST=127.0.0.1|' .env
sed -i 's|^DB_PORT=.*|DB_PORT=3306|' .env
sed -i 's|^DB_DATABASE=.*|DB_DATABASE=dsc23ytp_check|' .env
sed -i 's|^DB_USERNAME=.*|DB_USERNAME=dsc23ytp|' .env
sed -i 's|^DB_PASSWORD=.*|DB_PASSWORD=dsc23ytp_auto_2025|' .env
