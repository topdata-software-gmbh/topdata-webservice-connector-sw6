<?php

namespace Topdata\TopdataConnectorSW6\Service\Linking;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\ProductRelationshipTypeEnum;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Service\ProductImportSettingsService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Service responsible for managing product linking relationships and cross-selling functionality.
 * Handles various types of product relationships such as similar products, alternates, variants,
 * color variants, capacity variants, related products, and bundled products.
 *
 * aka ProductCrossSellingService
 *
 * 11/2024 created (extracted from MappingHelperService)
 */
class ProductProductRelationshipService
{
    const CHUNK_SIZE         = 30;
    const MAX_CROSS_SELLINGS = 24;

    private Context $context;


    public function __construct(
        private readonly ProductImportSettingsService $productImportSettingsService,
        private readonly Connection                   $connection,
        private readonly TopdataToProductService      $topdataToProductHelperService,
        private readonly EntityRepository             $productCrossSellingRepository,
        private readonly EntityRepository             $productCrossSellingAssignedProductsRepository,
    )
    {
        $this->context = Context::createDefaultContext();
    }

    /**
     * 04/2025 introduced to decouple the enum value from type in database... but maybe we can change the types in the database instead to the UPPERCASE enum values for consistency?
     */
    private static function _getCrossDbType(ProductRelationshipTypeEnum $crossType)
    {
        return match ($crossType) {
            ProductRelationshipTypeEnum::SIMILAR          => 'similar',
            ProductRelationshipTypeEnum::ALTERNATE        => 'alternate',
            ProductRelationshipTypeEnum::RELATED          => 'related',
            ProductRelationshipTypeEnum::BUNDLED          => 'bundled',
            ProductRelationshipTypeEnum::COLOR_VARIANT    => 'colorVariant',
            ProductRelationshipTypeEnum::CAPACITY_VARIANT => 'capacityVariant',
            ProductRelationshipTypeEnum::VARIANT          => 'variant',
            default                                       => throw new \RuntimeException("Unknown cross-selling type: {$crossType->value}"),
        };
    }


    private static function _getCrossNameTranslations(ProductRelationshipTypeEnum $crossType): array
    {
        return match ($crossType) {
            ProductRelationshipTypeEnum::CAPACITY_VARIANT => [
                'de-DE' => 'Kapazitätsvarianten',
                'en-GB' => 'Capacity Variants',
                'nl-NL' => 'capaciteit varianten',
            ],
            ProductRelationshipTypeEnum::COLOR_VARIANT    => [
                'de-DE' => 'Farbvarianten',
                'en-GB' => 'Color Variants',
                'nl-NL' => 'kleur varianten',
            ],
            ProductRelationshipTypeEnum::ALTERNATE        => [
                'de-DE' => 'Alternative Produkte',
                'en-GB' => 'Alternate Products',
                'nl-NL' => 'alternatieve producten',
            ],
            ProductRelationshipTypeEnum::RELATED          => [
                'de-DE' => 'Zubehör',
                'en-GB' => 'Accessories',
                'nl-NL' => 'Accessoires',
            ],
            ProductRelationshipTypeEnum::VARIANT          => [
                'de-DE' => 'Varianten',
                'en-GB' => 'Variants',
                'nl-NL' => 'varianten',
            ],
            ProductRelationshipTypeEnum::BUNDLED          => [
                'de-DE' => 'Im Bundle',
                'en-GB' => 'In Bundle',
                'nl-NL' => 'In een bundel',
            ],
            ProductRelationshipTypeEnum::SIMILAR          => [
                'de-DE' => 'Ähnlich',
                'en-GB' => 'Similar',
                'nl-NL' => 'Vergelijkbaar',
            ],
            default                                => throw new \RuntimeException("Unknown cross-selling type: {$crossType->value}"),
        };
    }


    /**
     * Finds similar products based on the remote product data
     *
     * @param object $remoteProductData The product data from remote source
     * @return array Array of similar products
     */
    private function _findSimilarProducts($remoteProductData): array
    {
        $similarProducts = [];
        $topid_products = $this->topdataToProductHelperService->getTopdataProductMappings();

        // ---- Check for products with same accessories
        if (isset($remoteProductData->product_same_accessories->products) && count($remoteProductData->product_same_accessories->products)) {
            foreach ($remoteProductData->product_same_accessories->products as $tid) {
                if (!isset($topid_products[$tid])) {
                    continue;
                }
                $similarProducts[$tid] = $topid_products[$tid][0];
            }
        }

        // ---- Check for products with same application
        if (isset($remoteProductData->product_same_application_in->products) && count($remoteProductData->product_same_application_in->products)) {
            foreach ($remoteProductData->product_same_application_in->products as $tid) {
                if (!isset($topid_products[$tid])) {
                    continue;
                }
                $similarProducts[$tid] = $topid_products[$tid][0];
            }
        }

        // ---- Check for product variants
        if (isset($remoteProductData->product_variants->products) && count($remoteProductData->product_variants->products)) {
            foreach ($remoteProductData->product_variants->products as $rprod) {
                if (!isset($topid_products[$rprod->id])) {
                    continue;
                }
                $similarProducts[$rprod->id] = $topid_products[$rprod->id][0];
            }
        }

        return $similarProducts;
    }


    /**
     * Finds color variant products based on the remote product data
     *
     * @param object $remoteProductData The product data from remote source
     * @return array Array of color variant products
     */
    private function _findColorVariantProducts($remoteProductData): array
    {
        $linkedProducts = [];
        $topid_products = $this->topdataToProductHelperService->getTopdataProductMappings();
        if (isset($remoteProductData->product_special_variants->color) && count($remoteProductData->product_special_variants->color)) {
            foreach ($remoteProductData->product_special_variants->color as $tid) {
                if (!isset($topid_products[$tid])) {
                    continue;
                }
                $linkedProducts[$tid] = $topid_products[$tid][0];
            }
        }

        return $linkedProducts;
    }

    /**
     * Finds capacity variant products based on the remote product data
     *
     * @param object $remoteProductData The product data from remote source
     * @return array Array of capacity variant products
     */
    private function _findCapacityVariantProducts($remoteProductData): array
    {
        $linkedProducts = [];
        $topid_products = $this->topdataToProductHelperService->getTopdataProductMappings();
        if (isset($remoteProductData->product_special_variants->capacity) && count($remoteProductData->product_special_variants->capacity)) {
            foreach ($remoteProductData->product_special_variants->capacity as $tid) {
                if (!isset($topid_products[$tid])) {
                    continue;
                }
                $linkedProducts[$tid] = $topid_products[$tid][0];
            }
        }

        return $linkedProducts;
    }

    /**
     * Finds general variant products based on the remote product data
     *
     * @param object $remoteProductData The product data from remote source
     * @return array Array of variant products
     */
    private function _findVariantProducts($remoteProductData): array
    {
        $products = [];
        $topid_products = $this->topdataToProductHelperService->getTopdataProductMappings();

        // ---- Process product variants that don't have a specific type
        if (isset($remoteProductData->product_variants->products) && count($remoteProductData->product_variants->products)) {
            foreach ($remoteProductData->product_variants->products as $rprod) {
                if ($rprod->type !== null) {
                    continue;
                }

                if (!isset($topid_products[$rprod->id])) {
                    continue;
                }
                $products[$rprod->id] = $topid_products[$rprod->id][0];
            }
        }

        return $products;
    }

    /**
     * Adds cross-selling relationships between products
     *
     * @param array $currentProduct The current product information
     * @param array $linkedProductIds Array of products to be linked
     * @param ProductRelationshipTypeEnum $crossType The type of cross-selling relationship
     */
    private function _addProductCrossSelling(array $currentProduct, array $linkedProductIds, ProductRelationshipTypeEnum $crossType): void
    {
        if ($currentProduct['parent_id']) {
            //don't create cross if product is variation!
            return;
        }

        // ---- Check if cross-selling already exists for this product and type

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $currentProduct['product_id']));
        $criteria->addFilter(new EqualsFilter('topdataExtension.type', self::_getCrossDbType($crossType)));
        $productCrossSellingEntity = $this->productCrossSellingRepository->search($criteria, $this->context)->first();

        if ($productCrossSellingEntity) {
            // ---- Remove existing cross-selling product assignments
            $crossId = $productCrossSellingEntity->getId();
            $this->connection->executeStatement("
                    DELETE 
                    FROM product_cross_selling_assigned_products 
                    WHERE cross_selling_id = 0x$crossId
            ");
        } else {
            // ---- Create new cross-selling entity
            $crossId = Uuid::randomHex();
            $data = [
                'id'               => $crossId,
                'productId'        => $currentProduct['product_id'],
                'productVersionId' => $currentProduct['product_version_id'],
                'name'             => self::_getCrossNameTranslations($crossType),
                'position'         => self::_getCrossPosition($crossType),
                'type'             => ProductCrossSellingDefinition::TYPE_PRODUCT_LIST,
                'sortBy'           => ProductCrossSellingDefinition::SORT_BY_NAME,
                'sortDirection'    => FieldSorting::ASCENDING,
                'active'           => true,
                'limit'            => self::MAX_CROSS_SELLINGS,
                'topdataExtension' => [
                    'type' => self::_getCrossDbType($crossType)
                ],
            ];
            $this->productCrossSellingRepository->create([$data], $this->context);
            CliLogger::activity();
        }

        $i = 1;
        $data = [];
        foreach ($linkedProductIds as $prodId) {
            $data[] = [
                'crossSellingId'   => $crossId,
                'productId'        => $prodId['product_id'],
                'productVersionId' => $prodId['product_version_id'],
                'position'         => $i++,
            ];
        }

        $this->productCrossSellingAssignedProductsRepository->create($data, $this->context);
        CliLogger::activity();
    }


    /**
     * Finds alternate products based on the remote product data
     *
     * @param object $remoteProductData The product data from remote source
     * @return array Array of alternate products
     */
    private function _findAlternateProducts($remoteProductData): array
    {
        $alternateProducts = [];
        $topid_products = $this->topdataToProductHelperService->getTopdataProductMappings();
        if (isset($remoteProductData->product_alternates->products) && count($remoteProductData->product_alternates->products)) {
            foreach ($remoteProductData->product_alternates->products as $tid) {
                if (!isset($topid_products[$tid])) {
                    continue;
                }
                $alternateProducts[$tid] = $topid_products[$tid][0];
            }
        }

        return $alternateProducts;
    }

    /**
     * Finds related products (accessories) based on the remote product data
     *
     * @param object $remoteProductData The product data from remote source
     * @return array Array of related products
     */
    private function _findRelatedProducts($remoteProductData): array
    {
        $relatedProducts = [];
        $topid_products = $this->topdataToProductHelperService->getTopdataProductMappings();
        if (isset($remoteProductData->product_accessories->products) && count($remoteProductData->product_accessories->products)) {
            foreach ($remoteProductData->product_accessories->products as $tid) {
                if (!isset($topid_products[$tid])) {
                    continue;
                }
                $relatedProducts[$tid] = $topid_products[$tid][0];
            }
        }

        return $relatedProducts;
    }

    /**
     * Finds bundled products based on the remote product data
     *
     * @param object $remoteProductData The product data from remote source
     * @return array Array of bundled products
     */
    private function findBundledProducts($remoteProductData): array
    {
        $bundledProducts = [];
        $topid_products = $this->topdataToProductHelperService->getTopdataProductMappings();
        if (isset($remoteProductData->bundle_content->products) && count($remoteProductData->bundle_content->products)) {
            foreach ($remoteProductData->bundle_content->products as $tid) {
                if (!isset($topid_products[$tid->products_id])) {
                    continue;
                }
                $bundledProducts[$tid->products_id] = $topid_products[$tid->products_id][0];
            }
        }

        return $bundledProducts;
    }

    /**
     * Generic method to process product relationships
     * Handles database insertion and cross-selling for all relationship types
     *
     * @param array $productId_versionId The current product ID information
     * @param array $relatedProducts Array of products to be linked
     * @param string $tableName The database table to insert into
     * @param string $idColumnPrefix The prefix for the ID column in the table
     * @param ProductRelationshipTypeEnum $crossType The type of cross-selling relationship
     * @param bool $enableCrossSelling Whether to enable cross-selling
     * @param string $dateTime The current date/time string
     */
    private function _processProductRelationship(
        array                       $productId_versionId,
        array                       $relatedProducts,
        string                      $tableName,
        string                      $idColumnPrefix,
        ProductRelationshipTypeEnum $crossType,
        bool                        $enableCrossSelling,
        string                      $dateTime
    ): void
    {
        if (empty($relatedProducts)) {
            return;
        }

        $dataInsert = [];
        foreach ($relatedProducts as $tempProd) {
            $dataInsert[] = "(0x{$productId_versionId['product_id']}, 0x{$productId_versionId['product_version_id']}, 0x{$tempProd['product_id']}, 0x{$tempProd['product_version_id']}, '$dateTime')";
        }

        $insertDataChunks = array_chunk($dataInsert, self::CHUNK_SIZE);
        foreach ($insertDataChunks as $chunk) {
            $columns = implode(', ', [
                'product_id',
                'product_version_id',
                "{$idColumnPrefix}_product_id",
                "{$idColumnPrefix}_product_version_id",
                'created_at'
            ]);

            $this->connection->executeStatement(
                "INSERT INTO $tableName ($columns) VALUES " . implode(',', $chunk)
            );
            CliLogger::activity();
        }

        if ($enableCrossSelling) {
            $this->_addProductCrossSelling($productId_versionId, $relatedProducts, $crossType);
        }
    }



    /**
     * Unlinks products from similar, alternate, related, bundled, color variant, capacity variant and variant products.
     *
     * @param array $productIds Array of product IDs to unlink.
     * 04/2025 moved from MappingHelperService::unlinkProducts() to ProductRelationshipService::unlinkProducts()
     */
    public function unlinkProducts(array $productIds): void
    {
        if (!count($productIds)) {
            return;
        }

        $ids = $this->productImportSettingsService->filterProductIdsByConfig('productSimilar', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_similar WHERE product_id IN ($ids)");
        }

        $ids = $this->productImportSettingsService->filterProductIdsByConfig('productAlternate', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_alternate WHERE product_id IN ($ids)");
        }

        $ids = $this->productImportSettingsService->filterProductIdsByConfig('productRelated', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_related WHERE product_id IN ($ids)");
        }

        $ids = $this->productImportSettingsService->filterProductIdsByConfig('productBundled', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_bundled WHERE product_id IN ($ids)");
        }

        $ids = $this->productImportSettingsService->filterProductIdsByConfig('productColorVariant', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_color_variant WHERE product_id IN ($ids)");
        }

        $ids = $this->productImportSettingsService->filterProductIdsByConfig('productCapacityVariant', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_capacity_variant WHERE product_id IN ($ids)");
        }

        $ids = $this->productImportSettingsService->filterProductIdsByConfig('productVariant', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_variant WHERE product_id IN ($ids)");
        }
    }


    /**
     * Main method to link products with various relationships based on remote product data
     *
     * ==== MAIN ====
     *
     * 11/2024 created
     *
     * @param array $productId_versionId Product ID and version information
     * @param object $remoteProductData The product data from remote source
     */
    public function linkProducts(array $productId_versionId, $remoteProductData): void
    {
        $dateTime = date('Y-m-d H:i:s');
        $productId = $productId_versionId['product_id'];

        // ---- Process similar products
        if ($this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productSimilar, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findSimilarProducts($remoteProductData),
                'topdata_product_to_similar',
                'similar',
                ProductRelationshipTypeEnum::SIMILAR,
                $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productSimilarCross, $productId),
                $dateTime
            );
        }

        // ---- Process alternate products
        if ($this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productAlternate, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findAlternateProducts($remoteProductData),
                'topdata_product_to_alternate',
                'alternate',
                ProductRelationshipTypeEnum::ALTERNATE,
                $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productAlternateCross, $productId),
                $dateTime
            );
        }

        // ---- Process related products (accessories)
        if ($this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productRelated, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findRelatedProducts($remoteProductData),
                'topdata_product_to_related',
                'related',
                ProductRelationshipTypeEnum::RELATED,
                $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productRelatedCross, $productId),
                $dateTime
            );
        }

        // ---- Process bundled products
        if ($this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productBundled, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->findBundledProducts($remoteProductData),
                'topdata_product_to_bundled',
                'bundled',
                ProductRelationshipTypeEnum::BUNDLED,
                $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productBundledCross, $productId),
                $dateTime
            );
        }

        // ---- Process color variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productColorVariant, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findColorVariantProducts($remoteProductData),
                'topdata_product_to_color_variant',
                'color_variant',
                ProductRelationshipTypeEnum::COLOR_VARIANT,
                $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productVariantColorCross, $productId),
                $dateTime
            );
        }

        // ---- Process capacity variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productCapacityVariant, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findCapacityVariantProducts($remoteProductData),
                'topdata_product_to_capacity_variant',
                'capacity_variant',
                ProductRelationshipTypeEnum::CAPACITY_VARIANT,
                $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productVariantCapacityCross, $productId),
                $dateTime
            );
        }

        // ---- Process general variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productVariant, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findVariantProducts($remoteProductData),
                'topdata_product_to_variant',
                'variant',
                ProductRelationshipTypeEnum::VARIANT,
                $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productVariantCross, $productId),
                $dateTime
            );
        }
    }

    /**
     * 04/2025 created
     */
    private static function _getCrossPosition(ProductRelationshipTypeEnum $crossType): int
    {
        return match ($crossType) {
            ProductRelationshipTypeEnum::CAPACITY_VARIANT => 1,
            ProductRelationshipTypeEnum::COLOR_VARIANT    => 2,
            ProductRelationshipTypeEnum::ALTERNATE        => 3,
            ProductRelationshipTypeEnum::RELATED          => 4,
            ProductRelationshipTypeEnum::VARIANT          => 5,
            ProductRelationshipTypeEnum::BUNDLED          => 6,
            ProductRelationshipTypeEnum::SIMILAR          => 7,
            default                                       => throw new \RuntimeException("Unknown cross-selling type: {$crossType->value}"),
        };
    }

}
