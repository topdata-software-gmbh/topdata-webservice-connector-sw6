<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1752853578TopdataReports extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1752853578;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement("

                DROP TABLE IF EXISTS `topdata_report`;
                CREATE TABLE `topdata_report` (
                  `id` binary(16) NOT NULL,
                  `job_status` varchar(255) NOT NULL,
                  `command_line` longtext NOT NULL,
                  `started_at` datetime(3) NOT NULL,
                  `finished_at` datetime(3) DEFAULT NULL,
                  `report_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '{}' CHECK (json_valid(`report_data`)),
                  `created_at` datetime(3) NOT NULL,
                  `updated_at` datetime(3) DEFAULT NULL,
                  `job_type` varchar(255) NOT NULL DEFAULT 'UNKNOWN',
                  `pid` int(11) DEFAULT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        ");

    }
}



