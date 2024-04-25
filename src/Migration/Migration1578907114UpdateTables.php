<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\InheritanceUpdaterTrait;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1578907114UpdateTables extends MigrationStep
{
    use InheritanceUpdaterTrait;

    public function getCreationTimestamp(): int
    {
        return 1578907114;
    }

    public function update(Connection $connection): void
    {
        $productFields = [];
        $temp = $connection->fetchAllAssociative('SHOW COLUMNS from `product`');
        foreach ($temp as $field) {
            if (isset($field['Field'])) {
                $productFields[$field['Field']] = $field['Field'];
            }
        }

        $customerFields = [];
        $temp = $connection->fetchAllAssociative('SHOW COLUMNS from `customer`');
        foreach ($temp as $field) {
            if (isset($field['Field'])) {
                $customerFields[$field['Field']] = $field['Field'];
            }
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_to_product` (
              `id` binary(16) NOT NULL,
              `top_data_id` int(11) NOT NULL,
              `product_id` binary(16) NOT NULL,
              `product_version_id` binary(16) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              KEY `idx_ws_id` (`top_data_id`),
              KEY `idx_product` (`product_id`, `product_version_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        if (!isset($productFields['topdata'])) {
            $this->updateInheritance($connection, 'product', 'topdata');
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_brand` (
              `id` binary(16) NOT NULL,
              `code` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
              `label` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
              `is_enabled` tinyint(1) NOT NULL,
              `sort` int(11) NOT NULL,
              `ws_id` int(11) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              KEY `idx_label` (`label`),
              KEY `td_brand_code` (`code`),
              KEY `td_brand_ws_id` (`ws_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_device` (
              `id` binary(16) NOT NULL,
              `brand_id` binary(16) DEFAULT NULL,
              `type_id` binary(16) DEFAULT NULL,
              `series_id` binary(16) DEFAULT NULL,
              `is_enabled` tinyint(1) NOT NULL,
              `has_synonyms` TINYINT(1) NOT NULL DEFAULT 0,
              `code` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
              `model` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
              `keywords` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
              `sort` int(11) NOT NULL,
              `media_id` binary(16) DEFAULT NULL,
              `ws_id` int(11) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `UNIQ_4B8AD3A77153098` (`code`),
              KEY `IDX_4B8AD3A44F5D008` (`brand_id`),
              KEY `IDX_4B8AD3AC54C8C93` (`type_id`),
              KEY `IDX_4B8AD3A5278319C` (`series_id`),
              KEY `IDX_4B8AD3AEA9FDD75` (`media_id`),
              KEY `ws_id` (`ws_id`),
              KEY `idx_model` (`model`),
              KEY `idx_keywords` (`keywords`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_device_to_synonym` (
              `device_id` binary(16) NOT NULL,
              `synonym_id` binary(16) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`device_id`, `synonym_id`)
            ) ENGINE=InnoDB;
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_device_to_product` (
              `device_id` binary(16) NOT NULL,
              `product_id` binary(16) NOT NULL,
              `product_version_id` binary(16) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`device_id`,`product_id`,`product_version_id`),
              KEY `idx_product` (`product_id`,`product_version_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        if (!isset($productFields['devices'])) {
            $this->updateInheritance($connection, 'product', 'devices');
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_product_to_alternate` (
              `product_id` binary(16) NOT NULL,
              `product_version_id` binary(16) NOT NULL,
              `alternate_product_id` binary(16) NOT NULL,
              `alternate_product_version_id` binary(16) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`product_id`, `product_version_id`, `alternate_product_id`, `alternate_product_version_id`),
              KEY `idx_alternate_id` (`alternate_product_id`, `alternate_product_version_id`)
            ) ENGINE=InnoDB;
        ');

        if (!isset($productFields['alternate_products'])) {
            $this->updateInheritance($connection, 'product', 'alternate_products');
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_product_to_similar` (
              `product_id` binary(16) NOT NULL,
              `product_version_id` binary(16) NOT NULL,
              `similar_product_id` binary(16) NOT NULL,
              `similar_product_version_id` binary(16) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`product_id`, `product_version_id`, `similar_product_id`, `similar_product_version_id`),
              KEY `idx_similar_id` (`similar_product_id`, `similar_product_version_id`)
            ) ENGINE=InnoDB;
        ');

        if (!isset($productFields['similar_products'])) {
            $this->updateInheritance($connection, 'product', 'similar_products');
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_product_to_related` (
              `product_id` binary(16) NOT NULL,
              `product_version_id` binary(16) NOT NULL,
              `related_product_id` binary(16) NOT NULL,
              `related_product_version_id` binary(16) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`product_id`, `product_version_id`, `related_product_id`, `related_product_version_id`),
              KEY `idx_related_id` (`related_product_id`, `related_product_version_id`)
            ) ENGINE=InnoDB;
        ');

        if (!isset($productFields['related_products'])) {
            $this->updateInheritance($connection, 'product', 'related_products');
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_product_to_bundled` (
              `product_id` binary(16) NOT NULL,
              `product_version_id` binary(16) NOT NULL,
              `bundled_product_id` binary(16) NOT NULL,
              `bundled_product_version_id` binary(16) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`product_id`, `product_version_id`, `bundled_product_id`, `bundled_product_version_id`),
              KEY `idx_bundled_id` (`bundled_product_id`, `bundled_product_version_id`)
            ) ENGINE=InnoDB;
        ');

        if (!isset($productFields['bundled_products'])) {
            $this->updateInheritance($connection, 'product', 'bundled_products');
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_product_to_color_variant` (
              `product_id` binary(16) NOT NULL,
              `product_version_id` binary(16) NOT NULL,
              `color_variant_product_id` binary(16) NOT NULL,
              `color_variant_product_version_id` binary(16) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`product_id`, `product_version_id`, `color_variant_product_id`, `color_variant_product_version_id`),
              KEY `idx_color_variant_id` (`color_variant_product_id`, `color_variant_product_version_id`)
            ) ENGINE=InnoDB;
        ');

        if (!isset($productFields['color_variant_products'])) {
            $this->updateInheritance($connection, 'product', 'color_variant_products');
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_product_to_capacity_variant` (
              `product_id` binary(16) NOT NULL,
              `product_version_id` binary(16) NOT NULL,
              `capacity_variant_product_id` binary(16) NOT NULL,
              `capacity_variant_product_version_id` binary(16) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`product_id`, `product_version_id`, `capacity_variant_product_id`, `capacity_variant_product_version_id`),
              KEY `idx_capacity_variant_id` (`capacity_variant_product_id`, `capacity_variant_product_version_id`)
            ) ENGINE=InnoDB;
        ');

        if (!isset($productFields['capacity_variant_products'])) {
            $this->updateInheritance($connection, 'product', 'capacity_variant_products');
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_product_to_variant` (
              `product_id` binary(16) NOT NULL,
              `product_version_id` binary(16) NOT NULL,
              `variant_product_id` binary(16) NOT NULL,
              `variant_product_version_id` binary(16) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`product_id`, `product_version_id`, `variant_product_id`, `variant_product_version_id`),
              KEY `idx_variant_id` (`variant_product_id`, `variant_product_version_id`)
            ) ENGINE=InnoDB;
        ');

        if (!isset($productFields['variant_products'])) {
            $this->updateInheritance($connection, 'product', 'variant_products');
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_device_to_customer` (
              `id` binary(16) NOT NULL,
              `device_id` binary(16) NOT NULL,
              `customer_id` binary(16) DEFAULT NULL,
              `customer_extra_id` binary(16) DEFAULT NULL,
              `extra_info` text DEFAULT NULL,
              `is_dealer_managed` tinyint(1) DEFAULT 0 NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              KEY `IDX_CUSTOMER` (`customer_id`),
              KEY `IDX_CUSTOMER_EXTRA` (`customer_extra_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        if (!isset($customerFields['devices'])) {
            $this->updateInheritance($connection, 'customer', 'devices');
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_device_type` (
              `id` binary(16) NOT NULL,
              `brand_id` binary(16) DEFAULT NULL,
              `code` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
              `is_enabled` tinyint(1) NOT NULL,
              `label` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
              `sort` int(11) NOT NULL,
              `ws_id` int(11) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              KEY `idx_brand_id` (`brand_id`),
              KEY `idx_code` (`code`),
              KEY `idx_label` (`label`),
              KEY `idx_ws_id` (`ws_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_series` (
              `id` binary(16) NOT NULL,
              `brand_id` binary(16) DEFAULT NULL,
              `code` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
              `is_enabled` tinyint(1) NOT NULL,
              `label` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
              `sort` int(11) NOT NULL,
              `ws_id` int(11) NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              KEY `idx_code` (`code`),
              KEY `idx_label` (`label`),
              KEY `idx_wsid` (`ws_id`),
              KEY `idx_brandid` (`brand_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
