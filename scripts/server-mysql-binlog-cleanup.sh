#!/bin/bash
# Очистка бинарных логов MySQL на сервере (освобождает ~24 ГБ и останавливает постоянный рост).
# Запуск на сервере: sudo bash server-mysql-binlog-cleanup.sh

set -e

echo "=== Текущие настройки MySQL binlog ==="
mysql -e "SHOW VARIABLES LIKE 'log_bin'; SHOW VARIABLES LIKE 'binlog_expire_logs_seconds'; SHOW VARIABLES LIKE 'expire_logs_days';" 2>/dev/null || true

echo ""
echo "=== Размер binlog до очистки ==="
du -sh /var/lib/mysql/binlog.* 2>/dev/null | tail -1 || du -sh /var/lib/mysql/ 2>/dev/null

echo ""
echo "=== Удаление старых бинарных логов (оставляем только последний) ==="
mysql -e "PURGE BINARY LOGS BEFORE NOW();" 2>/dev/null || mysql -e "RESET MASTER;" 2>/dev/null

echo ""
echo "=== Включаем автоочистку: хранить логи 3 дня (259200 секунд) ==="
# MySQL 8.0+
mysql -e "SET GLOBAL binlog_expire_logs_seconds = 259200;" 2>/dev/null || true
# Или для старых версий: SET GLOBAL expire_logs_days = 3;

echo ""
echo "=== Размер /var/lib/mysql после очистки ==="
du -sh /var/lib/mysql/

echo ""
echo "Готово. Рекомендуется добавить в /etc/mysql/mysql.conf.d/mysqld.cnf:"
echo "  binlog_expire_logs_seconds = 259200"
echo "чтобы настройка сохранялась после перезапуска MySQL."
