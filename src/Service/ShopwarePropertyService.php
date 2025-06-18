<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Property\PropertyGroupDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataFoundationSW6\Service\LocaleHelperService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;


/**
 * 06/2025 created (extracted from EntitiesHelperService)
 */
class ShopwarePropertyService
{
    private ?array $propertyGroupsOptionsArray = null;
    private readonly string $systemDefaultLocaleCode;
    private readonly Context $context;
    private ?string $enLangID = null;
    private ?string $deLangID = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly LocaleHelperService $localeHelperService
    ) {
        $this->systemDefaultLocaleCode = $this->localeHelperService->getLocaleCodeOfSystemLanguage();
        $this->context = Context::createDefaultContext();
    }


    /**
     * @todo [I18N] Refactor for proper multi-language support.
     *
     * NOTE ON CURRENT LIMITATION:
     * This method currently has a significant limitation regarding multi-language support.
     * It receives a single string for a property name (`$propGroupName`) and its value (`$propValue`),
     * which are fetched from the Topdata webservice in only ONE language (defined in the plugin config).
     *
     * It then proceeds to create translations for multiple languages (e.g., English and German)
     * but uses the *same single-language string* for all of them. For example, if the API language
     * is German, both the 'de-DE' and 'en-GB' translations in Shopware will be in German.
     *
     * TO IMPLEMENT "REAL I18N":
     * 1. The `TopdataWebserviceClient` must be refactored to allow changing the request language at runtime
     *    (e.g., with a `setLanguage()` method).
     * 2. The import process must be updated to loop through all active Shopware languages, call the
     *    webservice for each language, and collect all translations.
     * 3. This `getPropertyId` method must be changed to accept arrays of translations for group names
     *    and values, and then use the repository's `create` or `update` methods to save all
     *    translations in a single, correct operation, removing the direct SQL inserts below.
     */
    public function getPropertyId(string $propGroupName, string $propValue): string
    {
        $propGroups = $this->getPropertyGroupsOptionsArray();

        $currentGroup = null;
        $currentGroupId = null;

        foreach ($propGroups as $id => $propertyGroup) {
            if ($propertyGroup['name'] == $propGroupName) {
                $currentGroupId = $id;
                $currentGroup = $propertyGroup;
                break;
            }
        }

        if ($currentGroup === null) {
            $currentGroupId = Uuid::randomHex();
            $currentOptionId = Uuid::randomHex();

            $this->propertyGroupRepository->create([
                [
                    'id' => $currentGroupId,
                    'sortingType' => PropertyGroupDefinition::SORTING_TYPE_ALPHANUMERIC,
                    'displayType' => PropertyGroupDefinition::DISPLAY_TYPE_TEXT,
                    'filterable' => false,
                    'name' => [
                        $this->systemDefaultLocaleCode => $propGroupName,
                    ],
                    'options' => [
                        [
                            'id' => $currentOptionId,
                            'name' => [
                                $this->systemDefaultLocaleCode => $propValue,
                            ],
                        ],
                    ],
                ],
            ], $this->context);

            $this->addOptionPropertyGroupsOptionsArray($currentGroupId, $propGroupName, $currentOptionId, $propValue);
            return $currentOptionId;
        }

        foreach ($currentGroup['options'] as $id => $value) {
            if ($value == $propValue) {
                return $id;
            }
        }

        $currentOptionId = Uuid::randomHex();
        $currentDateTime = date('Y-m-d H:i:s');
        $enId = $this->getEnID();
        $deId = $this->getDeID();
        CliLogger::debug("# new property group option $propValue");

        $this->connection->executeStatement(
            'INSERT INTO property_group_option (id, property_group_id, created_at) VALUES (0x' . $currentOptionId . ', 0x' . $currentGroupId . ', "' . $currentDateTime . '")'
        );

        // TODO: [I18N] The lines below are the core of the issue. The same `$propValue`
        // is being inserted for both English and German language IDs. This needs to be
        // replaced by a repository call with a proper translations array when implementing "real i18n".
        if ($enId) {
            $this->connection->insert('property_group_option_translation', [
                'property_group_option_id' => Uuid::fromHexToBytes($currentOptionId),
                'language_id' => Uuid::fromHexToBytes($enId),
                'name' => $propValue,
                'created_at' => $currentDateTime,
            ]);
        }

        if ($deId) {
            $this->connection->insert('property_group_option_translation', [
                'property_group_option_id' => Uuid::fromHexToBytes($currentOptionId),
                'language_id' => Uuid::fromHexToBytes($deId),
                'name' => $propValue,
                'created_at' => $currentDateTime,
            ]);
        }

        $this->addOptionPropertyGroupsOptionsArray($currentGroupId, $propGroupName, $currentOptionId, $propValue);
        return $currentOptionId;
    }

    public function getPropertyGroupsOptionsArray(): array
    {
        if (is_array($this->propertyGroupsOptionsArray)) {
            return $this->propertyGroupsOptionsArray;
        }

        $this->propertyGroupsOptionsArray = [];

        $systemLangIdBytes = $this->connection->fetchOne(
            'SELECT language.id FROM language JOIN locale ON language.translation_code_id = locale.id WHERE locale.code = :code',
            ['code' => $this->systemDefaultLocaleCode]
        );

        if ($systemLangIdBytes === false) {
            throw new \RuntimeException(sprintf(
                'System default language with locale code "%s" could not be found in the database.',
                $this->systemDefaultLocaleCode
            ));
        }

        $langIdHex = bin2hex($systemLangIdBytes);

        $result = $this->connection->executeQuery("
            SELECT LOWER(HEX(pg.id)) pg_id, pgt.name pg_name, LOWER(HEX(pgo.id)) pgo_id, pgot.name pgo_name
            FROM property_group_option as pgo, property_group_option_translation as pgot, property_group as pg, property_group_translation as pgt
            WHERE (pg.id = pgo.property_group_id)
                AND (pg.id = pgt.property_group_id)
                AND (pgt.language_id = 0x$langIdHex)
                AND (pgo.id = pgot.property_group_option_id)
                AND (pgot.language_id = 0x$langIdHex)
        ")->fetchAllAssociative();

        foreach ($result as $res) {
            if (!isset($this->propertyGroupsOptionsArray[$res['pg_id']])) {
                $this->propertyGroupsOptionsArray[$res['pg_id']] = [
                    'name'    => $res['pg_name'],
                    'options' => [],
                ];
            }
            $this->propertyGroupsOptionsArray[$res['pg_id']]['options'][$res['pgo_id']] = $res['pgo_name'];
        }

        return $this->propertyGroupsOptionsArray;
    }

    public function addOptionPropertyGroupsOptionsArray($groupId, $groupName, $groupOptId, $groupOptVal): void
    {
        if (!isset($this->propertyGroupsOptionsArray[$groupId])) {
            $this->propertyGroupsOptionsArray[$groupId] = [
                'name'    => $groupName,
                'options' => [],
            ];
        }
        $this->propertyGroupsOptionsArray[$groupId]['options'][$groupOptId] = $groupOptVal;
    }

    private function getEnID(): ?string
    {
        if ($this->enLangID || ($this->enLangID === false)) {
            return $this->enLangID;
        }
        $result = $this->connection->executeQuery('SELECT LOWER(HEX(id)) as id FROM language WHERE name="English" LIMIT 1')->fetchOne();
        $this->enLangID = $result ?: null;
        return $this->enLangID;
    }

    private function getDeID(): ?string
    {
        if ($this->deLangID || ($this->deLangID === false)) {
            return $this->deLangID;
        }
        $result = $this->connection->executeQuery('SELECT LOWER(HEX(id)) as id FROM language WHERE name="Deutsch" LIMIT 1')->fetchOne();
        $this->deLangID = $result ?: null;
        return $this->deLangID;
    }

}
