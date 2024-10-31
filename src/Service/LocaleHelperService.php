<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

/**
 * 10/2024 created
 */
class LocaleHelperService
{
    public function __construct(
        private readonly Connection $connection
    )
    {
    }


    /**
     * 10/2024 extracted from multiple services [duplicate code]
     *
     * Get the locale code of the system language.
     *
     * @return string The locale code
     */
    public function getLocaleCodeOfSystemLanguage(): string
    {
        return $this->connection
            ->fetchOne(
                'SELECT lo.code FROM language as la JOIN locale as lo on lo.id = la.locale_id  WHERE la.id = UNHEX(:systemLanguageId)',
                ['systemLanguageId' => Defaults::LANGUAGE_SYSTEM]
            );
    }

}