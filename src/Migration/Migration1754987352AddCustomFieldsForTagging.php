<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * This migration adds custom fields for tagging media entities, specifically to indicate if a media item has been imported.
 * It creates a custom field set and a custom field within that set, then relates the set to the 'media' entity.
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

    /**
     * Executes the update logic of the migration.
     * This method creates the custom field set, the custom field, and the field relations.
     *
     * @param Connection $connection The database connection.
     */
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

    /**
     * Creates the custom field set in the database.
     *
     * @param Connection $connection The database connection.
     */
    private function _createCustomFieldSet(Connection $connection): void
    {
        $setId = Uuid::fromHexToBytes(self::UUID_1);
        $config = json_encode([
            'label' => [
                'en-GB' => 'Topdata Connector',
                'de-DE' => 'Topdata Connector'
            ]
        ]);

        // ---- Insert or update the custom field set in the database. ----
        $connection->executeStatement("
            INSERT INTO `custom_field_set` 
                (`id`, `name`, `config`, `active`, `global`, `position`, `created_at`) 
            VALUES 
                (?, 'topdata_connector', ?, 1, 1, 0, NOW())
            ON DUPLICATE KEY UPDATE `name` = `name`
        ", [$setId, $config]);
    }

    /**
     * Creates the custom field in the database.
     *
     * @param Connection $connection The database connection.
     */
    private function _createCustomField(Connection $connection): void
    {
        $fieldId = Uuid::fromHexToBytes(self::UUID_2);
        // --- Use UUID_1 here to reference the set created above. ---
        $setId = Uuid::fromHexToBytes(self::UUID_1);
        $config = json_encode([
            'label'               => [
                'en-GB' => 'Is Imported Media',
                'de-DE' => 'Ist Importiertes Medium'
            ],
            'componentName'       => 'sw-field',
            'customFieldType'     => 'checkbox',
            'customFieldPosition' => 1
        ]);

        // ---- Insert or update the custom field in the database. ----
        $connection->executeStatement("
            INSERT INTO `custom_field` 
                (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`) 
            VALUES 
                (?, 'topdata_connector_is_imported_media', 'checkbox', ?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE `name` = `name`
        ", [$fieldId, $config, $setId]);
    }

    /**
     * Creates the relation between the custom field set and the media entity.
     *
     * @param Connection $connection The database connection.
     */
    private function _createFieldRelations(Connection $connection): void
    {
        // --- Use UUID_1 here to reference the set created above. ---
        $setId = Uuid::fromHexToBytes(self::UUID_1);
        $relationId = Uuid::randomBytes();

        // ---- Insert or update the custom field set relation in the database. ----
        $connection->executeStatement("
            INSERT INTO `custom_field_set_relation` 
                (`id`, `set_id`, `entity_name`, `created_at`) 
            VALUES 
                (?, ?, 'media', NOW())
            ON DUPLICATE KEY UPDATE `entity_name` = `entity_name`
        ", [$relationId, $setId]);
    }
}