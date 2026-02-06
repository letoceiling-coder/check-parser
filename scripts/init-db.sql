CREATE DATABASE IF NOT EXISTS dsc23ytp_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'dsc23ytp'@'localhost' IDENTIFIED BY 'dsc23ytp_auto_2025';
GRANT ALL ON dsc23ytp_check.* TO 'dsc23ytp'@'localhost';
FLUSH PRIVILEGES;
