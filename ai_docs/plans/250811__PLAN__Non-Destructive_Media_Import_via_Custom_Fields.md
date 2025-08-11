# Implementation Plan: Non-Destructive Media Import via Custom Fields

**Objective:** Modify the plugin to tag all imported media with a custom field. Update the deletion logic to only remove tagged media, thus preserving manually uploaded images.

**Target Version:** 8.2.0

## Phase 1: Database Schema Setup (Custom Fields Migration)

**Goal:** Create the necessary custom field set and a specific boolean field to tag media entities.

1.  **Create a New Migration File.**
    *   **Action:** Create a new file in the `src/Migration/` directory.
    *   **File Name:** `Migration[TIMESTAMP]AddCustomFieldsForTagging.php`. Replace `[TIMESTAMP]` with the current Unix timestamp.
    *   **Content:** Populate the file with the following code. This migration creates a "Topdata Connector" custom field set and adds a boolean field `topdata_connector_is_imported_media` which is assigned to the `media` entity.

    ```php
    <?php declare(strict_types=1);

    namespace Topdata\TopdataConnectorSW6\Migration;

    use Doctrine\DBAL\Connection;
    use Shopware\Core\Framework\Migration\MigrationStep;
    use Shopware\Core\Framework\Uuid\Uuid;

    class Migration1765000000AddCustomFieldsForTagging extends MigrationStep
    {
        // NOTE: Replace 1765000000 with the actual timestamp in the filename.
        public function getCreationTimestamp(): int
        {
            return 1765000000;
        }

        public function update(Connection $connection): void
        {
            $this->createCustomFieldSet($connection);
            $this->createCustomFields($connection);
            $this->createCustomFieldRelations($connection);
        }

        public function updateDestructive(Connection $connection): void
        {
            // No destructive changes needed
        }

        private function createCustomFieldSet(Connection $connection): void
        {
            $setId = Uuid::fromHexToBytes('a1b2c3d4e5f640a1b2c3d4e5f6a1b2c3'); // Fixed UUID for consistency
            $config = json_encode([
                'label' => [
                    'en-GB' => 'Topdata Connector',
                    'de-DE' => 'Topdata Connector',
                ],
            ]);

            $sql = <<<SQL
            INSERT IGNORE INTO `custom_field_set` (`id`, `name`, `config`, `active`, `created_at`)
            VALUES (:id, 'topdata_connector_set', :config, 1, NOW());
    SQL;
            $connection->executeStatement($sql, [
                'id' => $setId,
                'config' => $config,
            ]);
        }

        private function createCustomFields(Connection $connection): void
        {
            $setId = Uuid::fromHexToBytes('a1b2c3d4e5f640a1b2c3d4e5f6a1b2c3');
            $fieldId = Uuid::fromHexToBytes('f1e2d3c4b5a640f1e2d3c4b5a6f1e2d3'); // Fixed UUID for consistency
            $config = json_encode([
                'label' => [
                    'en-GB' => 'Is Topdata Imported Media',
                    'de-DE' => 'Ist von Topdata importiertes Medium',
                ],
                'helpText' => [
                    'en-GB' => 'Flag to identify media imported by the Topdata Connector.',
                    'de-DE' => 'Kennzeichen für Medien, die vom Topdata Connector importiert wurden.',
                ],
                'componentName' => 'sw-switch-field',
                'customFieldType' => 'switch',
                'customFieldPosition' => 1,
            ]);

            $sql = <<<SQL
            INSERT IGNORE INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
            VALUES (:id, 'topdata_connector_is_imported_media', 'bool', :config, 1, :set_id, NOW());
    SQL;
            $connection->executeStatement($sql, [
                'id' => $fieldId,
                'config' => $config,
                'set_id' => $setId,
            ]);
        }

        private function createCustomFieldRelations(Connection $connection): void
        {
            $setId = Uuid::fromHexToBytes('a1b2c3d4e5f640a1b2c3d4e5f6a1b2c3');

            $relationId = Uuid::randomBytes();
            $sql = <<<SQL
            INSERT IGNORE INTO `custom_field_set_relation` (`id`, `set_id`, `entity_name`, `created_at`)
            VALUES (:id, :set_id, 'media', NOW());
    SQL;
            $connection->executeStatement($sql, [
                'id' => $relationId,
                'set_id' => $setId,
            ]);
        }
    }
    ```

## Phase 2: Tagging Imported Media with the Custom Field

**Goal:** Modify the service responsible for creating media entities to add the new custom field flag.

1.  **Modify `MediaHelperService` to Add Custom Field on Creation.**
    *   **Action:** Edit the file `src/Service/MediaHelperService.php`.
    *   **Target Method:** `createMediaInFolder()`
    *   **Modification:** Add the `customFields` key to the data array passed to the `mediaRepository->create()` call.

    ```php
    // In src/Service/MediaHelperService.php

    private function createMediaInFolder(): string
    {
        if (!$this->uploadFolderId) {
            $this->createUploadFolder();
        }

        $mediaId = Uuid::randomHex();
        $this->mediaRepository->create(
            [
                [
                    'id'            => $mediaId,
                    'private'       => false,
                    'mediaFolderId' => $this->uploadFolderId,
                    'customFields'  => [
                        'topdata_connector_is_imported_media' => true,
                    ],
                ],
            ],
            $this->context
        );

        return $mediaId;
    }
    ```

## Phase 3: Implementing Selective Media Deletion

**Goal:** Update the image unlinking logic to only delete media that has been tagged by our connector.

1.  **Modify `MediaHelperService` to Selectively Unlink Images.**
    *   **Action:** Edit the file `src/Service/MediaHelperService.php`.
    *   **Target Method:** `unlinkImages()`
    *   **Modification:** Replace the existing `DELETE` statement for `product_media` with a new, more specific query that joins with the `media` table and checks for our custom field.

    ```php
    // In src/Service/MediaHelperService.php

    public function unlinkImages(array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }

        // First, nullify the cover image for all affected products.
        // This is a safe operation and prevents broken cover image links.
        $ids = '0x' . implode(',0x', $productIds);
        $this->connection->executeStatement("UPDATE product SET product_media_id = NULL, product_media_version_id = NULL WHERE id IN ($ids)");
        
        // Now, selectively delete product_media entries.
        // This query joins product_media with media to check our custom field.
        $sql = '
            DELETE pm FROM product_media AS pm
            INNER JOIN media AS m ON pm.media_id = m.id
            WHERE pm.product_id IN (:productIds)
              AND JSON_EXTRACT(m.custom_fields, "$.topdata_connector_is_imported_media") = TRUE
        ';

        $this->connection->executeStatement($sql, 
            ['productIds' => array_map('hex2bin', $productIds)],
            ['productIds' => \Doctrine\DBAL\ArrayParameterType::BINARY]
        );
    }
    ```

## Phase 4: Documentation and Versioning

**Goal:** Update project files to reflect the new version and changes.

1.  **Update `composer.json`.**
    *   **Action:** Modify the file `composer.json`.
    *   **Modification:** Change the `version` property to `"8.2.0"`.

2.  **Update `CHANGELOG.md`.**
    *   **Action:** Modify the file `CHANGELOG.md`.
    *   **Modification:** Add a new release section at the top of the file.

    ```markdown
    ## [8.2.0] - YYYY-MM-DD
    ### Added
    - Custom field `topdata_connector_is_imported_media` to tag all media imported by the connector. This provides clear data ownership and prevents accidental deletion of user-uploaded content.

    ### Changed
    - The image unlinking process (`--product-info` command) is now non-destructive. It will only remove product images that were previously imported by the Topdata Connector, preserving any manually uploaded images.

    ```

3.  **Update `README.md`.**
    *   **Action:** Modify the file `README.md`.
    *   **Modification:** Add a note to a relevant section (e.g., under "Console commands for work with API" or a new "Important Notes" section) to inform users about the non-destructive image updates.

    ```markdown
    ## Advices and examples
    
    ...
    
    ### Non-Destructive Image Updates
    
    Starting from version 8.2.0, the plugin will no longer delete manually uploaded product images during an import (`--product-info` or `--all`). Only images previously imported by the Topdata Connector will be managed (added, updated, or removed). This ensures that your manually curated content is safe.
    
    ...
    ```

---
**Plan Execution Complete.** The AI agent should now have successfully implemented the feature. A final step for a human would be to run the migration and test the import process.
