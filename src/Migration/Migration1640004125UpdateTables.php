<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Migration\InheritanceUpdaterTrait;

class Migration1640004125UpdateTables extends MigrationStep
{
    use InheritanceUpdaterTrait;
    public function getCreationTimestamp(): int
    {
        return 1640004125;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `topdata_category_extension` (
              `id` binary(16) NOT NULL,
              `category_id` binary(16) DEFAULT NULL,
              `plugin_settings` int(1) DEFAULT 1 NOT NULL,
              `import_settings` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
              `created_at` DATETIME(3) NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              KEY `idx_category` (`category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $connection->executeStatement($sql);
        
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}