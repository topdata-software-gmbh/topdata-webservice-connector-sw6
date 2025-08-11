<?php

declare(strict_types=1);

namespace Topdata\TopdataWebserviceConnectorSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1765000000AddCustomFieldsForTagging extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1765000000;
    }

    public function update(Connection $connection): void
    {
        $this->createCustomFieldSet($connection);
        $this->createCustomField($connection);
        $this->createFieldRelations($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }

    private function createCustomFieldSet(Connection $connection): void
    {
        $setId = Uuid::fromHexToBytes('a1b2c3d4e5f640a1b2c3d4e5f6a1b2c3');
        $config = json_encode([
            'label' => [
                'en-GB' => 'Media Import Tagging',
                'de-DE' => 'Medienimport-Kennzeichnung'
            ]
        ]);

        $connection->executeStatement("
            INSERT INTO `custom_field_set` 
                (`id`, `name`, `config`, `active`, `global`, `position`, `created_at`) 
            VALUES 
                (?, 'topdata_media_import_tagging', ?, 1, 1, 0, NOW())
        ", [$setId, $config]);
    }

    private function createCustomField(Connection $connection): void
    {
        $fieldId = Uuid::fromHexToBytes('f1e2d3c4b5a640f1e2d3c4b5a6f1e2d3');
        $setId = Uuid::fromHexToBytes('a1b2c3d4e5f640a1b2c3d4e5f6a1b2c3');
        $config = json_encode([
            'label'               => [
                'en-GB' => 'Imported Media',
                'de-DE' => 'Importierte Medien'
            ],
            'componentName'       => 'sw-field',
            'customFieldType'     => 'checkbox',
            'customFieldPosition' => 1
        ]);

        $connection->executeStatement("
            INSERT INTO `custom_field` 
                (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`) 
            VALUES 
                (?, 'topdata_imported_media', 'checkbox', ?, 1, ?, NOW())
        ", [$fieldId, $config, $setId]);
    }

    private function createFieldRelations(Connection $connection): void
    {
        $setId = Uuid::fromHexToBytes('a1b2c3d4e5f640a1b2c3d4e5f6a1b2c3');
        $relationId = Uuid::randomBytes();

        $connection->executeStatement("
            INSERT INTO `custom_field_set_relation` 
                (`id`, `set_id`, `entity_name`, `created_at`) 
            VALUES 
                (?, ?, 'media', NOW())
        ", [$relationId, $setId]);
    }
}