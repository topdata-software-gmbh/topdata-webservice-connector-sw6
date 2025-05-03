<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 * Creates the mapping cache table for storing EAN/OEM/PCD mappings
 */
#[Package('core')]
class Migration1746267946CreateMappingCacheTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1746267946;
    }

    public function update(Connection $connection): void
    {
        echo "---- Create topdata_mapping_cache table\n";
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_mapping_cache` (
              `id` binary(16) NOT NULL,
              `mapping_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
              `top_data_id` int(11) NOT NULL,
              `product_id` binary(16) NOT NULL,
              `product_version_id` binary(16) NOT NULL,
              `created_at` DATETIME(3) NOT NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              KEY `idx_mapping_type` (`mapping_type`),
              KEY `idx_top_data_id` (`top_data_id`),
              KEY `idx_product` (`product_id`, `product_version_id`),
              KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes needed
    }
}
