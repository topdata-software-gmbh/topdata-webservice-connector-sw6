<?php

namespace Topdata\TopdataConnectorSW6\Service\Config;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\MergedPluginConfigKeyConstants;
use Topdata\TopdataConnectorSW6\Service\Shopware\BreadcrumbService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Service class responsible for managing product import settings in a hierarchical way ("hierarchical configuration override").
 *
 * This service allows retrieving and loading product-specific import settings,
 * overriding the global settings defined in OptionsHelperService.
 * It fetches settings based on the product's category and stores them for later use.
 */
class ProductImportSettingsService
{


    /**
     * @var array a map {productId => ...} containing product import settings.
     */
    private array $productImportSettings = [];

    /**
     * Constructor for the ProductImportSettingsService.
     *
     * @param MergedPluginConfigHelperService $optionsHelperService The service for retrieving merged plugin configurations.
     * @param Connection $connection The database connection.
     */
    public function __construct(
        private readonly MergedPluginConfigHelperService $optionsHelperService,
        private readonly Connection $connection,
        private readonly BreadcrumbService $breadcrumbService
    )
    {
    }

    /**
     * Logs all categories that have configuration overrides enabled.
     * The categories are displayed as a breadcrumb path.
     */
    public function logCategoryOverrides(): void
    {
        CliLogger::section('Categories with Configuration Overrides');

        $overriddenCategories = $this->connection->fetchFirstColumn('
            SELECT LOWER(HEX(category_id))
            FROM topdata_category_extension
            WHERE plugin_settings = 0
        ');

        if (empty($overriddenCategories)) {
            CliLogger::info('No category overrides found.');

            return;
        }

        $breadcrumbs = [];
        foreach ($overriddenCategories as $categoryId) {
            $breadcrumbs[] = $this->breadcrumbService->getCategoryBreadcrumb($categoryId);
        }

        CliLogger::getCliStyle()->list($breadcrumbs);
    }

    /**
     * Retrieves the value of a product option based on the provided option name and product ID.
     *
     * This method first checks if the product has specific import settings. If so, it retrieves the option value
     * from these settings. If not, it falls back to the global option settings.
     *
     * @param string $optionName The name of the option to retrieve, see MergedPluginConfigKeyConstants.
     * @param string $productId The ID of the product for which to retrieve the option.
     * @return bool Returns true if the option is enabled, false otherwise.
     */
    public function isProductOptionEnabled(string $optionName, string $productId): bool
    {
        if (isset($this->productImportSettings[$productId])) {
            // ---- get mapped option name
            $mappedOptionName = [
                MergedPluginConfigKeyConstants::OPTION_NAME_productName                    => 'name',
                MergedPluginConfigKeyConstants::OPTION_NAME_productDescription             => 'description',
                MergedPluginConfigKeyConstants::OPTION_NAME_productBrand                   => 'brand',
                MergedPluginConfigKeyConstants::OPTION_NAME_productEan                     => 'EANs',
                MergedPluginConfigKeyConstants::OPTION_NAME_productOem                     => 'MPNs',
                MergedPluginConfigKeyConstants::OPTION_NAME_productImages                  => 'pictures',
                MergedPluginConfigKeyConstants::OPTION_NAME_productImagesDelete            => 'unlinkOldPictures',
                MergedPluginConfigKeyConstants::OPTION_NAME_productSpecifications          => 'properties',
                MergedPluginConfigKeyConstants::OPTION_NAME_specReferencePCD               => 'PCDsProp',
                MergedPluginConfigKeyConstants::OPTION_NAME_specReferenceOEM               => 'MPNsProp',
                // ----
                MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productSimilar         => 'importSimilar',
                MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productAlternate       => 'importAlternates',
                MergedPluginConfigKeyConstants::RELATIONSHIO_OPTION_productRelated         => 'importAccessories',
                MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productBundled         => 'importBoundles',
                MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productVariant         => 'importVariants',
                MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productColorVariant    => 'importColorVariants',
                MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productCapacityVariant => 'importCapacityVariants',
                // ----
                MergedPluginConfigKeyConstants::OPTION_NAME_productSimilarCross            => 'crossSimilar',
                MergedPluginConfigKeyConstants::OPTION_NAME_productAlternateCross          => 'crossAlternates',
                MergedPluginConfigKeyConstants::OPTION_NAME_productRelatedCross            => 'crossAccessories',
                MergedPluginConfigKeyConstants::OPTION_NAME_productBundledCross            => 'crossBoundles',
                MergedPluginConfigKeyConstants::OPTION_NAME_productVariantCross            => 'crossVariants',
                MergedPluginConfigKeyConstants::OPTION_NAME_productVariantColorCross       => 'crossColorVariants',
                MergedPluginConfigKeyConstants::OPTION_NAME_productVariantCapacityCross    => 'crossCapacityVariants',
            ][$optionName] ?? '';

            return $this->productImportSettings[$productId][$mappedOptionName] ?? false;
        }

        // return option from topFEED config
        return $this->optionsHelperService->getOption($optionName) ? true : false;
    }


    /**
     * Loads product import settings for the given product IDs.
     *
     * This method fetches the category paths for the given product IDs and then loads the import settings
     * for each category. The settings are then mapped to the corresponding products.
     *
     * @param array $productIds An array of product IDs for which to load import settings.
     */
    public function loadProductImportSettings(array $productIds): void
    {
        // ---- Initialize the product import settings array
        $this->productImportSettings = [];

        // ---- Return early if no product IDs are provided
        if (!count($productIds)) {
            return;
        }

        // ---- Load each product category path
        $productCategories = [];
        $allCategories = [];
        $ids = '0x' . implode(',0x', $productIds);
        $temp = $this->connection->fetchAllAssociative('
        SELECT LOWER(HEX(id)) as id, category_tree
          FROM product 
          WHERE (category_tree is NOT NULL) 
            AND (id IN (' . $ids . '))
    ');

        // ---- Parse the category tree for each product
        foreach ($temp as $item) {
            $parsedIds = json_decode($item['category_tree'], true);
            foreach ($parsedIds as $id) {
                if (is_string($id) && Uuid::isValid($id)) {
                    $productCategories[$item['id']][] = $id;
                    $allCategories[$id] = false;
                }
            }
        }

        // ---- Return early if no categories are found
        if (!count($allCategories)) {
            return;
        }

        // ---- Load each category's settings
        $ids = '0x' . implode(',0x', array_keys($allCategories));
        $temp = $this->connection->fetchAllAssociative('
        SELECT LOWER(HEX(category_id)) as id, import_settings
          FROM topdata_category_extension 
          WHERE (plugin_settings=0) AND (category_id IN (' . $ids . '))
    ');

        // ---- Map the settings to the corresponding categories
        foreach ($temp as $item) {
            $allCategories[$item['id']] = json_decode($item['import_settings'], true);
        }

        // ---- Set product settings based on category
        foreach ($productCategories as $productId => $categoryTree) {
            for ($i = (count($categoryTree) - 1); $i >= 0; $i--) {
                if (isset($allCategories[$categoryTree[$i]])
                    &&
                    $allCategories[$categoryTree[$i]] !== false
                ) {
                    $this->productImportSettings[$productId] = $allCategories[$categoryTree[$i]];
                    break;
                }
            }
        }
    }


    /**
     * Filters product IDs based on a given configuration option.
     *
     * @param string $optionName The name of the configuration option to check., see MergedPluginConfigKeyConstants.
     * @param array $productIds An array of product IDs to filter.
     * @return array An array of product IDs that match the configuration option.
     *
     * 04/2025 moved from ProductInformationServiceV1Slow::_filterIdsByConfig() to ProductImportSettingsService::filterProductIdsByConfig()
     */
    public function filterProductIdsByConfig(string $optionName, array $productIds): array
    {
        $returnIds = [];
        // ---- Iterate over each product ID
        foreach ($productIds as $pid) {
            // ---- Check if the product option is enabled for the current product ID
            if ($this->isProductOptionEnabled($optionName, $pid)) {
                $returnIds[] = $pid;
            }
        }

        return $returnIds;
    }


}