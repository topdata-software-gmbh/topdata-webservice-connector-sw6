<?php

namespace Topdata\TopdataConnectorSW6\Service\Shopware;

use Doctrine\DBAL\Connection;


/**
 * 06/2025 created (extracted from ShopwarePropertyService)
 */
class ShopwareLanguageService
{

    private ?string $enLangID = null;
    private ?string $deLangID = null;

    public function __construct(
        private readonly Connection $connection,
    )
    {
    }


    public function getLanguageId_EN(): ?string
    {
        if ($this->enLangID || ($this->enLangID === false)) {
            return $this->enLangID;
        }
        $result = $this->connection->executeQuery('SELECT LOWER(HEX(id)) as id FROM language WHERE name="English" LIMIT 1')->fetchOne();
        $this->enLangID = $result ?: null;
        return $this->enLangID;
    }


    public function getLanguageId_DE(): ?string
    {
        if ($this->deLangID || ($this->deLangID === false)) {
            return $this->deLangID;
        }
        $result = $this->connection->executeQuery('SELECT LOWER(HEX(id)) as id FROM language WHERE name="Deutsch" LIMIT 1')->fetchOne();
        $this->deLangID = $result ?: null;
        return $this->deLangID;
    }



    /**
     * Gets the default language ID from system config.
     * In Shopware 6, the default language is stored in system_config with key 'core.defaultLanguage'
     *
     * @return string The default language ID.
     */
    public function getDefaultLanguageId(): string
    {
        // First try to get from system_config
        $defaultLangId = $this->connection->fetchOne(
            'SELECT configuration_value FROM system_config WHERE configuration_key = ? LIMIT 1',
            ['core.defaultLanguage']
        );

        if ($defaultLangId) {
            // The value is stored as JSON, so decode it
            $config = json_decode($defaultLangId, true);
            if (isset($config['_value'])) {
                return strtolower(str_replace('-', '', $config['_value']));
            }
        }

        // Fallback: get the first language (usually the system default)
        return $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM language ORDER BY created_at ASC LIMIT 1'
        ) ?: '';
    }

}
