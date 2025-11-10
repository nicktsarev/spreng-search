-- Ensure database exists
CREATE DATABASE IF NOT EXISTS searchdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON searchdb.* TO 'searchuser'@'%';
FLUSH PRIVILEGES;
