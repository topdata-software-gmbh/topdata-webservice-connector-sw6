<?php

namespace Topdata\TopdataConnectorSW6\Service\Shopware;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Service to reliably fetch language IDs.
 *
 * 06/2025 created (extracted from ShopwarePropertyService)
 * 06/2025 rewritten for more robust language ID detection.
 */
class ShopwareLanguageService
{
    private ?string $enLangID = null;
    private ?string $deLangID = null;

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Gets the English language ID, falling back if not found.
     *
     * @return string|null
     */
    public function getLanguageId_EN(): ?string
    {
        if ($this->enLangID !== null) {
            return $this->enLangID ?: null;
        }

        $result = $this->connection->fetchOne('
            SELECT LOWER(HEX(l.id))
            FROM language l
            JOIN locale loc ON l.translation_code_id = loc.id
            WHERE loc.code LIKE \'en-%\'
            ORDER BY loc.code = \'en-GB\' DESC, l.created_at ASC
            LIMIT 1
        ');
        $this->enLangID = $result ?: false;

        return $this->enLangID ?: null;
    }

    /**
     * Gets the German language ID, falling back if not found.
     *
     * @return string|null
     */
    public function getLanguageId_DE(): ?string
    {
        if ($this->deLangID !== null) {
            return $this->deLangID ?: null;
        }

        $result = $this->connection->fetchOne('
            SELECT LOWER(HEX(l.id))
            FROM language l
            JOIN locale loc ON l.translation_code_id = loc.id
            WHERE loc.code LIKE \'de-%\'
            ORDER BY loc.code = \'de-DE\' DESC, l.created_at ASC
            LIMIT 1
        ');
        $this->deLangID = $result ?: false;

        return $this->deLangID ?: null;
    }

    /**
     * Gets the system's default language ID using a multi-step fallback approach.
     *
     * @return string The default language ID (hex).
     */
    public function getDefaultLanguageId(): string
    {
        // 1. Get from system_config (most reliable system-wide default)
        $configValue = $this->connection->fetchOne(
            'SELECT configuration_value FROM system_config WHERE configuration_key = :key AND sales_channel_id IS NULL LIMIT 1',
            ['key' => 'core.defaultLanguage']
        );

        if ($configValue) {
            $config = json_decode($configValue, true);
            if (isset($config['_value']) && Uuid::isValid($config['_value'])) {
                return strtolower($config['_value']);
            }
        }

        // 2. Fallback: Get language ID from the default Sales Channel (Storefront)
        $defaultSalesChannelTypeId = Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT);
        $languageId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(language_id)) FROM sales_channel WHERE type_id = :typeId ORDER BY created_at ASC LIMIT 1',
            ['typeId' => $defaultSalesChannelTypeId]
        );
        if ($languageId) {
            return $languageId;
        }

        // 3. Last resort fallback: get the very first language created
        return $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM language ORDER BY created_at ASC LIMIT 1'
        ) ?: '';
    }
}
