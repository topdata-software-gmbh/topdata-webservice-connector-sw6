<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service\Shopware;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Service to generate breadcrumb paths for Shopware categories.
 *
 * 06/2025 created
 */
class BreadcrumbService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ShopwareLanguageService $languageService
    ) {
    }

    /**
     * Generates a breadcrumb string for a given category ID.
     *
     * @param string $categoryId The hexadecimal ID of the category.
     * @return string The breadcrumb path (e.g., "Root > Parent > Child") or the category ID if not found.
     */
    public function getCategoryBreadcrumb(string $categoryId): string
    {
        if (!Uuid::isValid($categoryId)) {
            CliLogger::warning("Invalid category ID for breadcrumb: $categoryId");

            return "Invalid Category ID: $categoryId";
        }

        $defaultLangId = $this->languageService->getDefaultLanguageId();
        $fallbackLangId = $this->languageService->getLanguageId_EN(); // Use English as a fallback

        if (empty($defaultLangId)) {
            CliLogger::warning('Could not determine default language for category breadcrumbs.');

            return $categoryId;
        }

        $pathString = $this->connection->fetchOne(
            'SELECT path FROM category WHERE id = UNHEX(:categoryId)',
            ['categoryId' => $categoryId]
        );

        if (!$pathString) {
            return $categoryId; // Category not found
        }

        $ancestorIdsHex = array_filter(explode('|', $pathString));
        if (empty($ancestorIdsHex)) {
            return $categoryId; // No path found
        }

        $langIdsToFetch = [$defaultLangId];
        if ($fallbackLangId && $fallbackLangId !== $defaultLangId) {
            $langIdsToFetch[] = $fallbackLangId;
        }

        $translations = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(category_id)) as id, LOWER(HEX(language_id)) as lang_id, name
             FROM category_translation
             WHERE category_id IN (:ids) AND language_id IN (:lang_ids)',
            [
                'ids' => array_map('hex2bin', $ancestorIdsHex),
                'lang_ids' => array_map('hex2bin', $langIdsToFetch),
            ],
            [
                'ids' => ArrayParameterType::BINARY,
                'lang_ids' => ArrayParameterType::BINARY,
            ]
        );

        $nameMap = [];
        foreach ($translations as $translation) {
            $nameMap[$translation['id']][$translation['lang_id']] = $translation['name'];
        }

        $breadcrumbParts = [];
        foreach ($ancestorIdsHex as $id) {
            $name = $nameMap[$id][$defaultLangId] ?? ($fallbackLangId ? ($nameMap[$id][$fallbackLangId] ?? null) : null) ?? '(Category Not Translated)';
            $breadcrumbParts[] = $name;
        }

        return implode(' > ', $breadcrumbParts);
    }
}