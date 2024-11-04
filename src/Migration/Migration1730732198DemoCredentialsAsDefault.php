<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
#[Package('core')]
class Migration1730732198DemoCredentialsAsDefault extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730732198;
    }

    public function update(Connection $connection): void
    {
        // Check if any of the configs exist
        $existingCount = $connection->fetchOne(
            'SELECT COUNT(*) FROM system_config 
                WHERE configuration_key IN (
                    :username,
                    :apiKey,
                    :apiSalt
                )',
            [
                'username' => 'TopdataConnectorSW6.config.apiUsername',
                'apiKey'   => 'TopdataConnectorSW6.config.apiKey',
                'apiSalt'  => 'TopdataConnectorSW6.config.apiSalt'
            ]
        );

        // Only proceed if none exist
        if ($existingCount == 0) {
            $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

            $configs = [
                [
                    'configuration_key'   => 'TopdataConnectorSW6.config.apiUsername',
                    'configuration_value' => json_encode(['_value' => '6']),
                    'created_at'          => $now
                ],
                [
                    'configuration_key'   => 'TopdataConnectorSW6.config.apiKey',
                    'configuration_value' => json_encode(['_value' => 'nTI9kbsniVWT13Ns']),
                    'created_at'          => $now
                ],
                [
                    'configuration_key'   => 'TopdataConnectorSW6.config.apiSalt',
                    'configuration_value' => json_encode(['_value' => 'oateouq974fpby5t6ldf8glzo85mr9t6aebozrox']),
                    'created_at'          => $now
                ]
            ];

            foreach ($configs as $config) {
                $connection->insert('system_config', $config);
            }
        }
    }

}
