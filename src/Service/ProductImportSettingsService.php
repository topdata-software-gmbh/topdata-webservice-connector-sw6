<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Service class responsible for managing product import settings.
 *
 * This service allows retrieving and loading product-specific import settings,
 * overriding the global settings defined in OptionsHelperService.
 * It fetches settings based on the product's category and stores them for later use.
 */
class ProductImportSettingsService
{
    private array $productImportSettings = [];

    public function __construct(
        private readonly OptionsHelperService $optionsHelperService,
        private readonly Connection           $connection,
    )
    {
    }


    /**
     * Maps a given option name to its corresponding key in the product import settings array.
     *
     * @param string $optionName The option name to map.
     * @return string The mapped option name, or an empty string if no mapping is found.
     */
    private function _mapProductOption(string $optionName): string
    {
        $map = [
            'name'              => 'productName',
            'description'       => 'productDescription',
            'brand'             => 'productBrand',
            'EANs'              => 'productEan',
            'MPNs'              => 'productOem',
            'pictures'          => 'productImages',
            'unlinkOldPictures' => 'productImagesDelete',
            'properties'        => 'productSpecifications',
            'PCDsProp'          => 'specReferencePCD',
            'MPNsProp'          => 'specReferenceOEM',

            'importSimilar'          => 'productSimilar',
            'importAlternates'       => 'productAlternate',
            'importAccessories'      => 'productRelated',
            'importBoundles'         => 'productBundled',
            'importVariants'         => 'productVariant',
            'importColorVariants'    => 'productColorVariant',
            'importCapacityVariants' => 'productCapacityVariant',

            'crossSimilar'          => 'productSimilarCross',
            'crossAlternates'       => 'productAlternateCross',
            'crossAccessories'      => 'productRelatedCross',
            'crossBoundles'         => 'productBundledCross',
            'crossVariants'         => 'productVariantCross',
            'crossColorVariants'    => 'productVariantColorCross',
            'crossCapacityVariants' => 'productVariantCapacityCross',
        ];

        $ret = array_search($optionName, $map);
        if ($ret === false) {
            return '';
        }

        return $ret;
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
            $mappedOptionName = $this->_mapProductOption($optionName);

            return $this->productImportSettings[$productId][$mappedOptionName] ?? false;
        }

        return $this->optionsHelperService->getOption($optionName) ? true : false;
    }


//    private function _getProductExtraOption(string $optionName, string $productId): bool
//    {
////        if (isset($this->productImportSettings[$productId])) {
////            if (
////                isset($this->productImportSettings[$productId][$optionName])
////                && $this->productImportSettings[$productId][$optionName]
////            ) {
////                return true;
////            }
////
////            return false;
////        }
////
////        return false;
//
//    }


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
          WHERE (category_tree is NOT NULL)AND(id IN (' . $ids . '))
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