<?php

namespace Topdata\TopdataConnectorSW6\Service\Linking;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\MergedPluginConfigKeyConstants;
use Topdata\TopdataConnectorSW6\Enum\ProductRelationshipTypeEnumV1;
use Topdata\TopdataConnectorSW6\Service\Config\ProductImportSettingsService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
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
    private static function _getCrossDbType(ProductRelationshipTypeEnumV1 $crossType)
    {
        return match ($crossType) {
            ProductRelationshipTypeEnumV1::SIMILAR          => 'similar',
            ProductRelationshipTypeEnumV1::ALTERNATE        => 'alternate',
            ProductRelationshipTypeEnumV1::RELATED          => 'related',
            ProductRelationshipTypeEnumV1::BUNDLED          => 'bundled',
            ProductRelationshipTypeEnumV1::COLOR_VARIANT    => 'colorVariant',
            ProductRelationshipTypeEnumV1::CAPACITY_VARIANT => 'capacityVariant',
            ProductRelationshipTypeEnumV1::VARIANT          => 'variant',
            default                                         => throw new RuntimeException("Unknown cross-selling type: {$crossType->value}"),
        };
    }


    private static function _getCrossNameTranslations(ProductRelationshipTypeEnumV1 $crossType): array
    {
        return match ($crossType) {
            ProductRelationshipTypeEnumV1::CAPACITY_VARIANT => [
                'de-DE' => 'Kapazitätsvarianten',
                'en-GB' => 'Capacity Variants',
                'nl-NL' => 'capaciteit varianten',
            ],
            ProductRelationshipTypeEnumV1::COLOR_VARIANT    => [
                'de-DE' => 'Farbvarianten',
                'en-GB' => 'Color Variants',
                'nl-NL' => 'kleur varianten',
            ],
            ProductRelationshipTypeEnumV1::ALTERNATE        => [
                'de-DE' => 'Alternative Produkte',
                'en-GB' => 'Alternate Products',
                'nl-NL' => 'alternatieve producten',
            ],
            ProductRelationshipTypeEnumV1::RELATED          => [
                'de-DE' => 'Zubehör',
                'en-GB' => 'Accessories',
                'nl-NL' => 'Accessoires',
            ],
            ProductRelationshipTypeEnumV1::VARIANT          => [
                'de-DE' => 'Varianten',
                'en-GB' => 'Variants',
                'nl-NL' => 'varianten',
            ],
            ProductRelationshipTypeEnumV1::BUNDLED          => [
                'de-DE' => 'Im Bundle',
                'en-GB' => 'In Bundle',
                'nl-NL' => 'In een bundel',
            ],
            ProductRelationshipTypeEnumV1::SIMILAR          => [
                'de-DE' => 'Ähnlich',
                'en-GB' => 'Similar',
                'nl-NL' => 'Vergelijkbaar',
            ],
            default                                => throw new RuntimeException("Unknown cross-selling type: {$crossType->value}"),
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
     * @param ProductRelationshipTypeEnumV1 $crossType The type of cross-selling relationship
     */
    private function _addProductCrossSelling(array $currentProduct, array $linkedProductIds, ProductRelationshipTypeEnumV1 $crossType): void
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
     * @param ProductRelationshipTypeEnumV1 $crossType The type of cross-selling relationship
     * @param bool $enableCrossSelling Whether to enable cross-selling
     * @param string $dateTime The current date/time string
     */
    private function _processProductRelationship(
        array                         $productId_versionId,
        array                         $relatedProducts,
        string                        $tableName,
        string                        $idColumnPrefix,
        ProductRelationshipTypeEnumV1 $crossType,
        bool                          $enableCrossSelling,
        string                        $dateTime
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
     * Orchestrator method to unlink products from various relationships based on plugin configuration.
     * It filters the product IDs for each relationship type and delegates the deletion task.
     *
     * @param string[] $productIds Array of product IDs to unlink.
     * 04/2025 moved from MappingHelperService::unlinkProducts() to ProductRelationshipService::unlinkProducts()
     */
    public function unlinkProducts(array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }

        // Validate that IDs are valid hex UUIDs before processing to prevent errors
        $validProductIds = array_filter($productIds, fn($id) => Uuid::isValid($id));
        if (empty($validProductIds)) {
            return;
        }

        // Map configuration keys to their respective unlinking method and table name
        // TODO: 7 tables which do basically the same thing .. why not just one and a column for the type? maybe for performance reasons?
        $relationshipMap = [
            MergedPluginConfigKeyConstants::OPTION_NAME_productSimilar         => 'topdata_product_to_similar',
            MergedPluginConfigKeyConstants::OPTION_NAME_productAlternate       => 'topdata_product_to_alternate',
            MergedPluginConfigKeyConstants::OPTION_NAME_productRelated         => 'topdata_product_to_related',
            MergedPluginConfigKeyConstants::OPTION_NAME_productBundled         => 'topdata_product_to_bundled',
            MergedPluginConfigKeyConstants::OPTION_NAME_productColorVariant    => 'topdata_product_to_color_variant',
            MergedPluginConfigKeyConstants::OPTION_NAME_productCapacityVariant => 'topdata_product_to_capacity_variant',
            MergedPluginConfigKeyConstants::OPTION_NAME_productVariant         => 'topdata_product_to_variant',
        ];

        foreach ($relationshipMap as $configKey => $tableName) {
            $idsToUnlink = $this->productImportSettingsService->filterProductIdsByConfig(
                $configKey,
                $validProductIds
            );

            if (!empty($idsToUnlink)) {
                $this->_deleteFromTableWhereProductIdsIn($tableName, $idsToUnlink);
            }
            ImportReport::incCounter('links deleted from ' . $tableName, count($idsToUnlink));
        }
    }

    /**
     * Executes a DELETE statement on a given table for a list of product IDs.
     * This method uses parameterized queries to prevent SQL injection.
     *
     * @param string   $tableName   The name of the database table.
     * @param string[] $productIds  The product IDs to delete.
     */
    private function _deleteFromTableWhereProductIdsIn(string $tableName, array $productIds): void
    {
        // The product IDs are expected to be UUIDs in hex format (without 0x)
        // The database layer handles converting them to binary for the query.
        $this->connection->executeStatement(
        // Using backticks for the table name is a good practice
            "DELETE FROM `{$tableName}` WHERE product_id IN (:ids)",
            [
                'ids' => $productIds,
            ],
            [
                'ids' => ArrayParameterType::STRING,
            ]
        );
    }


    /**
     * ==== MAIN ====
     *
     * Main method to link products with various relationships based on remote product data
     *
     * 11/2024 created
     *
     * @param array $productId_versionId Product ID and version information
     * @param object $remoteProductData The product data from remote source
     */
    public function linkProducts(array $productId_versionId, $remoteProductData): void
    {
        UtilProfiling::startTimer();
        $dateTime = date('Y-m-d H:i:s');
        $productId = $productId_versionId['product_id'];

        // ---- Process similar products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productSimilar, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findSimilarProducts($remoteProductData),
                'topdata_product_to_similar',
                'similar',
                ProductRelationshipTypeEnumV1::SIMILAR,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productSimilarCross, $productId),
                $dateTime
            );
        }

        // ---- Process alternate products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productAlternate, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findAlternateProducts($remoteProductData),
                'topdata_product_to_alternate',
                'alternate',
                ProductRelationshipTypeEnumV1::ALTERNATE,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productAlternateCross, $productId),
                $dateTime
            );
        }

        // ---- Process related products (accessories)
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productRelated, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findRelatedProducts($remoteProductData),
                'topdata_product_to_related',
                'related',
                ProductRelationshipTypeEnumV1::RELATED,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productRelatedCross, $productId),
                $dateTime
            );
        }

        // ---- Process bundled products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productBundled, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->findBundledProducts($remoteProductData),
                'topdata_product_to_bundled',
                'bundled',
                ProductRelationshipTypeEnumV1::BUNDLED,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productBundledCross, $productId),
                $dateTime
            );
        }

        // ---- Process color variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productColorVariant, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findColorVariantProducts($remoteProductData),
                'topdata_product_to_color_variant',
                'color_variant',
                ProductRelationshipTypeEnumV1::COLOR_VARIANT,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productVariantColorCross, $productId),
                $dateTime
            );
        }

        // ---- Process capacity variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productCapacityVariant, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findCapacityVariantProducts($remoteProductData),
                'topdata_product_to_capacity_variant',
                'capacity_variant',
                ProductRelationshipTypeEnumV1::CAPACITY_VARIANT,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productVariantCapacityCross, $productId),
                $dateTime
            );
        }

        // ---- Process general variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productVariant, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findVariantProducts($remoteProductData),
                'topdata_product_to_variant',
                'variant',
                ProductRelationshipTypeEnumV1::VARIANT,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productVariantCross, $productId),
                $dateTime
            );
        }

        UtilProfiling::stopTimer();
    }

    /**
     * 04/2025 created
     */
    private static function _getCrossPosition(ProductRelationshipTypeEnumV1 $crossType): int
    {
        return match ($crossType) {
            ProductRelationshipTypeEnumV1::CAPACITY_VARIANT => 1,
            ProductRelationshipTypeEnumV1::COLOR_VARIANT    => 2,
            ProductRelationshipTypeEnumV1::ALTERNATE        => 3,
            ProductRelationshipTypeEnumV1::RELATED          => 4,
            ProductRelationshipTypeEnumV1::VARIANT          => 5,
            ProductRelationshipTypeEnumV1::BUNDLED          => 6,
            ProductRelationshipTypeEnumV1::SIMILAR          => 7,
            default                                         => throw new RuntimeException("Unknown cross-selling type: {$crossType->value}"),
        };
    }

}
