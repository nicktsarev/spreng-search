<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema for search benchmark: customers, products, orders, order_items, product_reviews';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS=0');

        // Customers table
        $this->addSql('
            CREATE TABLE IF NOT EXISTS customers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                phone VARCHAR(20),
                address TEXT,
                city VARCHAR(100),
                country VARCHAR(100),
                postal_code VARCHAR(20),
                notes TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FULLTEXT INDEX ft_customer_name (first_name, last_name),
                FULLTEXT INDEX ft_customer_search (first_name, last_name, email, address, notes),
                INDEX idx_city (city),
                INDEX idx_country (country),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Products table
        $this->addSql('
            CREATE TABLE IF NOT EXISTS products (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sku VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                long_description TEXT,
                category VARCHAR(100) NOT NULL,
                brand VARCHAR(100),
                price DECIMAL(10, 2) NOT NULL,
                stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
                attributes JSON,
                tags JSON,
                specifications JSON,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FULLTEXT INDEX ft_product_name (name),
                FULLTEXT INDEX ft_product_desc (description, long_description),
                FULLTEXT INDEX ft_product_full (name, description, long_description, category, brand),
                INDEX idx_category (category),
                INDEX idx_brand (brand),
                INDEX idx_price (price),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Add virtual columns for JSON indexing
        $this->addSql('
            ALTER TABLE products
            ADD COLUMN attr_color VARCHAR(50) AS (JSON_UNQUOTE(JSON_EXTRACT(attributes, "$.color"))) VIRTUAL,
            ADD COLUMN attr_size VARCHAR(50) AS (JSON_UNQUOTE(JSON_EXTRACT(attributes, "$.size"))) VIRTUAL,
            ADD COLUMN attr_material VARCHAR(100) AS (JSON_UNQUOTE(JSON_EXTRACT(attributes, "$.material"))) VIRTUAL,
            ADD INDEX idx_attr_color (attr_color),
            ADD INDEX idx_attr_size (attr_size),
            ADD INDEX idx_attr_material (attr_material)
        ');

        // Orders table
        $this->addSql('
            CREATE TABLE IF NOT EXISTS orders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                order_number VARCHAR(50) NOT NULL UNIQUE,
                status ENUM("pending", "processing", "shipped", "delivered", "cancelled", "refunded") NOT NULL DEFAULT "pending",
                total_amount DECIMAL(10, 2) NOT NULL,
                shipping_address TEXT NOT NULL,
                billing_address TEXT NOT NULL,
                payment_method VARCHAR(50),
                shipping_method VARCHAR(50),
                tracking_number VARCHAR(100),
                notes TEXT,
                metadata JSON,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                shipped_at DATETIME,
                delivered_at DATETIME,
                
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
                FULLTEXT INDEX ft_order_notes (notes),
                FULLTEXT INDEX ft_order_address (shipping_address, billing_address),
                INDEX idx_customer (customer_id),
                INDEX idx_status (status),
                INDEX idx_order_number (order_number),
                INDEX idx_created (created_at),
                INDEX idx_total (total_amount)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Order items table
        $this->addSql('
            CREATE TABLE IF NOT EXISTS order_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                unit_price DECIMAL(10, 2) NOT NULL,
                total_price DECIMAL(10, 2) NOT NULL,
                product_snapshot JSON,
                discount_amount DECIMAL(10, 2) DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
                INDEX idx_order (order_id),
                INDEX idx_product (product_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Product reviews table
        $this->addSql('
            CREATE TABLE IF NOT EXISTS product_reviews (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                customer_id INT UNSIGNED NOT NULL,
                rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
                title VARCHAR(255),
                review_text TEXT NOT NULL,
                helpful_count INT UNSIGNED DEFAULT 0,
                verified_purchase BOOLEAN DEFAULT FALSE,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                FULLTEXT INDEX ft_review_title (title),
                FULLTEXT INDEX ft_review_text (review_text),
                FULLTEXT INDEX ft_review_full (title, review_text),
                INDEX idx_product (product_id),
                INDEX idx_customer (customer_id),
                INDEX idx_rating (rating),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS=0');
        $this->addSql('DROP TABLE IF EXISTS product_reviews');
        $this->addSql('DROP TABLE IF EXISTS order_items');
        $this->addSql('DROP TABLE IF EXISTS orders');
        $this->addSql('DROP TABLE IF EXISTS products');
        $this->addSql('DROP TABLE IF EXISTS customers');
        $this->addSql('SET FOREIGN_KEY_CHECKS=1');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
