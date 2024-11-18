<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1699543200AddApiBaseUrlConfig extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1699543200;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            INSERT IGNORE INTO `system_config` (`id`, `configuration_key`, `configuration_value`, `created_at`)
            VALUES (
                   0xd5be1fba56a646659b8d6a2a1ccda7b2,
                "TopdataConnectorSW6.config.apiBaseUrl",
                \'{"_value": "https://ws.topdata.de"}\',
                NOW()
            )
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
