<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class Migration1754987352AddCustomFieldsForTagging extends MigrationStep
{
    // This is the UUID for the Custom Field SET. Use it everywhere.
    const UUID_1 = 'b69b29a950334caebb465025cff30a7f';

    // This is the UUID for the Custom FIELD itself.
    const UUID_2 = '79c2648ef4d84fa3849cdc1a4868afe6';

    public function getCreationTimestamp(): int
    {
        return 1754987352;
    }

    public function update(Connection $connection): void
    {
        $this->_createCustomFieldSet($connection);
        $this->_createCustomField($connection);
        $this->_createFieldRelations($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }

    private function _createCustomFieldSet(Connection $connection): void
    {
        $setId = Uuid::fromHexToBytes(self::UUID_1);
        $config = json_encode([
            'label' => [
                'en-GB' => 'Topdata Connector',
                'de-DE' => 'Topdata Connector'
            ]
        ]);

        $connection->executeStatement("
            INSERT INTO `custom_field_set` 
                (`id`, `name`, `config`, `active`, `global`, `position`, `created_at`) 
            VALUES 
                (?, 'topdata_connector', ?, 1, 1, 0, NOW())
            ON DUPLICATE KEY UPDATE `name` = `name`
        ", [$setId, $config]);
    }

    private function _createCustomField(Connection $connection): void
    {
        $fieldId = Uuid::fromHexToBytes(self::UUID_2);
        // --- Use UUID_1 here to reference the set created above. ---
        $setId = Uuid::fromHexToBytes(self::UUID_1);
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
                (?, 'topdata_connector_is_imported_media', 'checkbox', ?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE `name` = `name`
        ", [$fieldId, $config, $setId]);
    }

    private function _createFieldRelations(Connection $connection): void
    {
        // --- Use UUID_1 here to reference the set created above. ---
        $setId = Uuid::fromHexToBytes(self::UUID_1);
        $relationId = Uuid::randomBytes();

        $connection->executeStatement("
            INSERT INTO `custom_field_set_relation` 
                (`id`, `set_id`, `entity_name`, `created_at`) 
            VALUES 
                (?, ?, 'media', NOW())
            ON DUPLICATE KEY UPDATE `entity_name` = `entity_name`
        ", [$relationId, $setId]);
    }
}

