<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Service class responsible for managing product import settings in a HIERARCHICAL way.
 *
 * This service allows retrieving and loading product-specific import settings,
 * overriding the global settings defined in OptionsHelperService.
 * It fetches settings based on the product's category and stores them for later use.
 */
class ProductImportSettingsService
{
    const OPTION_NAME_productName                 = 'productName';
    const OPTION_NAME_productDescription          = 'productDescription';
    const OPTION_NAME_productBrand                = 'productBrand';
    const OPTION_NAME_productEan                  = 'productEan';
    const OPTION_NAME_productOem                  = 'productOem';
    const OPTION_NAME_productImages               = 'productImages';
    const OPTION_NAME_specReferencePCD            = 'specReferencePCD';
    const OPTION_NAME_specReferenceOEM            = 'specReferenceOEM';
    const OPTION_NAME_productSpecifications       = 'productSpecifications';
    const OPTION_NAME_productImagesDelete         = 'productImagesDelete'; // not used?
    const OPTION_NAME_productSimilar              = 'productSimilar';
    const OPTION_NAME_productSimilarCross         = 'productSimilarCross';
    const OPTION_NAME_productAlternate            = 'productAlternate';
    const OPTION_NAME_productAlternateCross       = 'productAlternateCross';
    const OPTION_NAME_productRelated              = 'productRelated';
    const OPTION_NAME_productRelatedCross         = 'productRelatedCross';
    const OPTION_NAME_productBundled              = 'productBundled';
    const OPTION_NAME_productBundledCross         = 'productBundledCross';
    const OPTION_NAME_productColorVariant         = 'productColorVariant';
    const OPTION_NAME_productVariantColorCross    = 'productVariantColorCross';
    const OPTION_NAME_productCapacityVariant      = 'productCapacityVariant';
    const OPTION_NAME_productVariantCapacityCross = 'productVariantCapacityCross';
    const OPTION_NAME_productVariant              = 'productVariant';
    const OPTION_NAME_productVariantCross         = 'productVariantCross';



    private array $productImportSettings = [];

    public function __construct(
        private readonly TopfeedOptionsHelperService $optionsHelperService,
        private readonly Connection                  $connection,
    )
    {
    }

    /**
     * Retrieves the value of a product option based on the provided option name and product ID.
     *
     * This method first checks if the product has specific import settings. If so, it retrieves the option value
     * from these settings. If not, it falls back to the global option settings.
     *
     * @param string $optionName The name of the option to retrieve.
     * @param string $productId The ID of the product for which to retrieve the option.
     * @return bool Returns true if the option is enabled, false otherwise.
     */
    public function getProductOption(string $optionName, string $productId): bool
    {
        if (isset($this->productImportSettings[$productId])) {
            // ---- get mapped option name
            $mappedOptionName = [
                self::OPTION_NAME_productName           => 'name',
                self::OPTION_NAME_productDescription    => 'description',
                self::OPTION_NAME_productBrand          => 'brand',
                self::OPTION_NAME_productEan            => 'EANs',
                self::OPTION_NAME_productOem            => 'MPNs',
                self::OPTION_NAME_productImages         => 'pictures',
                self::OPTION_NAME_productImagesDelete   => 'unlinkOldPictures',
                self::OPTION_NAME_productSpecifications => 'properties',
                self::OPTION_NAME_specReferencePCD      => 'PCDsProp',
                self::OPTION_NAME_specReferenceOEM      => 'MPNsProp',

                self::OPTION_NAME_productSimilar         => 'importSimilar',
                self::OPTION_NAME_productAlternate       => 'importAlternates',
                self::OPTION_NAME_productRelated         => 'importAccessories',
                self::OPTION_NAME_productBundled         => 'importBoundles',
                self::OPTION_NAME_productVariant         => 'importVariants',
                self::OPTION_NAME_productColorVariant    => 'importColorVariants',
                self::OPTION_NAME_productCapacityVariant => 'importCapacityVariants',

                self::OPTION_NAME_productSimilarCross         => 'crossSimilar',
                self::OPTION_NAME_productAlternateCross       => 'crossAlternates',
                self::OPTION_NAME_productRelatedCross         => 'crossAccessories',
                self::OPTION_NAME_productBundledCross         => 'crossBoundles',
                self::OPTION_NAME_productVariantCross         => 'crossVariants',
                self::OPTION_NAME_productVariantColorCross    => 'crossColorVariants',
                self::OPTION_NAME_productVariantCapacityCross => 'crossCapacityVariants',
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


}