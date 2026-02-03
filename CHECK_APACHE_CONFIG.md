# Проверка конфигурации Apache

## Проблема: 403 Forbidden

Файлы существуют и имеют правильные права. Проблема скорее всего в конфигурации Apache.

## Команды для проверки на сервере:

### 1. Проверьте конфигурацию виртуального хоста
```bash
# Попробуйте найти конфигурацию
sudo grep -r "project.siteaccess.ru" /etc/apache2/sites-enabled/
sudo grep -r "project.siteaccess.ru" /etc/httpd/conf.d/
sudo grep -r "project.siteaccess.ru" /usr/local/apache2/conf/

# Или проверьте все виртуальные хосты
sudo apache2ctl -S
# или
sudo httpd -S
```

### 2. Проверьте DocumentRoot
```bash
# Найдите, куда указывает DocumentRoot для вашего домена
sudo grep -A 10 "project.siteaccess.ru" /etc/apache2/sites-enabled/*.conf
# или
sudo grep -A 10 "project.siteaccess.ru" /etc/httpd/conf.d/*.conf
```

### 3. Проверьте логи с sudo
```bash
sudo tail -50 /var/log/apache2/error.log
# или
sudo tail -50 /var/log/httpd/error_log
# или
sudo tail -50 /usr/local/apache2/logs/error_log
```

### 4. Проверьте, что mod_rewrite включен
```bash
sudo apache2ctl -M | grep rewrite
# или
sudo httpd -M | grep rewrite
```

### 5. Проверьте текущий DocumentRoot
```bash
# Создайте тестовый файл в корне
echo "<?php phpinfo(); ?>" > test.php

# Попробуйте открыть в браузере:
# https://project.siteaccess.ru/test.php
# Если открывается - DocumentRoot = public_html
# Если 404 - DocumentRoot = public_html/public
```

### 6. Временное решение - создайте index.php в корне
Если DocumentRoot = public_html, создайте временный index.php:
```bash
cat > index.php << 'EOF'
<?php
// Temporary redirect to public
header('Location: /public/');
exit;
EOF
```

Но лучше настроить DocumentRoot правильно!
