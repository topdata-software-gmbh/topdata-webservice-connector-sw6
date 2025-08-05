<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
#[Package('core')]
class Migration1754373899TopdataDeviceToCustomer extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1754373899;
    }

    public function update(Connection $connection): void
    {
        $query = <<<'SQL'

-- It's highly recommended to wrap these operations in a transaction
START TRANSACTION;

-- Step 1: Rename the existing table to create a backup.
ALTER TABLE `topdata_device_to_customer` RENAME TO `topdata_device_to_customer_old`;

-- Step 2: Create the new table with the correct, complete schema.
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

-- Step 3: Copy data from the old table to the new one using compatible functions.
INSERT INTO `topdata_device_to_customer`
(
    `id`,
    `device_id`,
    `customer_id`,
    -- `customer_extra_id` is omitted, so it will get its default value (NULL)
    `extra_info`,
    `is_dealer_managed`,
    `created_at`,
    `updated_at`
)
SELECT
    -- This is the compatible way to generate a BINARY(16) UUID
    UNHEX(REPLACE(UUID(), '-', '')),
    `device_id`,
    `customer_id`,
    `extra_info`,
    `is_dealer_managed`,
    `created_at`,
    `updated_at`
FROM `topdata_device_to_customer_old`;

-- we keep the old table for reference
-- DROP TABLE `topdata_device_to_customer_old`;


-- If everything above executed without error, commit the changes.
COMMIT;

SQL;

        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Add destructive update if necessary
        //-- we keep the old table for reference
        //-- DROP TABLE `topdata_device_to_customer_old`;

    }
}
