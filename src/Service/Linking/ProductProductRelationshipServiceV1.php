<?php

namespace Topdata\TopdataConnectorSW6\Service\Linking;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use RuntimeException;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
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
 * 06/2025 deprecated
 * @deprecated - use ProductProductRelationshipServiceV2
 */
class ProductProductRelationshipServiceV1
{
    const CHUNK_SIZE         = 30;
    const MAX_CROSS_SELLINGS = 24;
    const BULK_INSERT_SIZE = 500;
    const USE_TRANSACTIONS = true;

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
    private function _processProductRelationship__ORIG(
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

            $SQL = "INSERT INTO $tableName ($columns) VALUES " . implode(',', $chunk);
            CliLogger::debug($SQL);
            $this->connection->executeStatement($SQL);
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
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productSimilar         => 'topdata_product_to_similar',
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productAlternate       => 'topdata_product_to_alternate',
            MergedPluginConfigKeyConstants::RELATIONSHIO_OPTION_productRelated         => 'topdata_product_to_related',
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productBundled         => 'topdata_product_to_bundled',
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productColorVariant    => 'topdata_product_to_color_variant',
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productCapacityVariant => 'topdata_product_to_capacity_variant',
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productVariant         => 'topdata_product_to_variant',
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
     * @return int The number of deleted rows.
     */
    private function _deleteFromTableWhereProductIdsIn(string $tableName, array $productIds): int
    {
        CliLogger::debug("unlinking " . count($productIds) . " products from $tableName");
        // dump($productIds);

        // The product IDs are expected to be UUIDs in hex format (without 0x)
        $SQL = "DELETE FROM `{$tableName}` WHERE product_id IN (:ids)";
        CLiLogger::debug($SQL);
        $numDeleted = $this->connection->executeStatement(
            $SQL,
            [
                'ids' => array_map('hex2bin', $productIds),
            ],
            [
                'ids' => ArrayParameterType::BINARY,
            ]
        );
        CliLogger::debug("deleted $numDeleted rows from $tableName");

        return (int)$numDeleted;
    }


    /**
     * Maps relationship types to their corresponding database table names
     *
     * @param string $type The relationship type
     * @return string The database table name
     */
    private function getTableForType(string $type): string
    {
        $map = [
            'similar'          => 'topdata_product_to_similar',
            'alternate'        => 'topdata_product_to_alternate',
            'related'          => 'topdata_product_to_related',
            'bundled'          => 'topdata_product_to_bundled',
            'color_variant'    => 'topdata_product_to_color_variant',
            'capacity_variant' => 'topdata_product_to_capacity_variant',
            'variant'          => 'topdata_product_to_variant',
        ];
        return $map[$type] ?? '';
    }

    /**
     * Maps relationship types to their corresponding ID column prefixes
     *
     * @param string $type The relationship type
     * @return string The ID column prefix
     */
    private function getIdColumnPrefix(string $type): string
    {
        $map = [
            'similar'          => 'similar',
            'alternate'        => 'alternate',
            'related'          => 'related',
            'bundled'          => 'bundled',
            'color_variant'    => 'color_variant',
            'capacity_variant' => 'capacity_variant',
            'variant'          => 'variant',
        ];
        return $map[$type] ?? '';
    }

    /**
     * Processes all relationship types in bulk using database transactions
     *
     * @param array $productId_versionId Product ID and version information
     * @param array $allRelationships Array of all relationship types and their products
     * @param string $dateTime The current date/time string
     */
    private function _processBulkRelationships(
        array  $productId_versionId,
        array  $allRelationships,
        string $dateTime
    ): void
    {
        if (self::USE_TRANSACTIONS) {
            $this->connection->beginTransaction();
        }

        try {
            foreach ($allRelationships as $type => $products) {
                if (empty($products)) {
                    continue;
                }

                $tableName = $this->getTableForType($type);
                $idColumnPrefix = $this->getIdColumnPrefix($type);

                if (empty($tableName) || empty($idColumnPrefix)) {
                    continue;
                }

                // Process products in batches
                $productChunks = array_chunk($products, self::BULK_INSERT_SIZE);

                foreach ($productChunks as $chunk) {
                    $values = [];
                    foreach ($chunk as $tempProd) {
                        $values[] = [
                            'product_id'                       => hex2bin($productId_versionId['product_id']),
                            'product_version_id'               => hex2bin($productId_versionId['product_version_id']),
                            "{$idColumnPrefix}_product_id"         => hex2bin($tempProd['product_id']),
                            "{$idColumnPrefix}_product_version_id" => hex2bin($tempProd['product_version_id']),
                            'created_at'                       => $dateTime
                        ];
                    }

                    // Use bulk insert with proper type mapping
                    foreach ($values as $value) {
                        $this->connection->insert(
                            $tableName,
                            $value,
                            [
                                'product_id'                       => Types::BINARY,
                                'product_version_id'               => Types::BINARY,
                                "{$idColumnPrefix}_product_id"         => Types::BINARY,
                                "{$idColumnPrefix}_product_version_id" => Types::BINARY,
                                'created_at'                       => Types::STRING
                            ]
                        );
                    }

                    CliLogger::activity();
                }
            }

            if (self::USE_TRANSACTIONS) {
                $this->connection->commit();
            }
        } catch (\Exception $e) {
            if (self::USE_TRANSACTIONS) {
                $this->connection->rollBack();
            }
            throw $e;
        }
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

        // Collect all relationships first
        $allRelationships = [];
        $crossSellingData = [];

        // ---- Collect similar products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productSimilar, $productId)) {
            CliLogger::debug("Collecting similar products for product $productId");
            $similarProducts = $this->_findSimilarProducts($remoteProductData);
            if (!empty($similarProducts)) {
                $allRelationships['similar'] = $similarProducts;
                if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productSimilarCross, $productId)) {
                    $crossSellingData[] = [
                        'products' => $similarProducts,
                        'type' => ProductRelationshipTypeEnumV1::SIMILAR
                    ];
                }
            }
        }

        // ---- Collect alternate products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productAlternate, $productId)) {
            CliLogger::debug("Collecting alternate products for product $productId");
            $alternateProducts = $this->_findAlternateProducts($remoteProductData);
            if (!empty($alternateProducts)) {
                $allRelationships['alternate'] = $alternateProducts;
                if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productAlternateCross, $productId)) {
                    $crossSellingData[] = [
                        'products' => $alternateProducts,
                        'type' => ProductRelationshipTypeEnumV1::ALTERNATE
                    ];
                }
            }
        }

        // ---- Collect related products (accessories)
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIO_OPTION_productRelated, $productId)) {
            CliLogger::debug("Collecting related products for product $productId");
            $relatedProducts = $this->_findRelatedProducts($remoteProductData);
            if (!empty($relatedProducts)) {
                $allRelationships['related'] = $relatedProducts;
                if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productRelatedCross, $productId)) {
                    $crossSellingData[] = [
                        'products' => $relatedProducts,
                        'type' => ProductRelationshipTypeEnumV1::RELATED
                    ];
                }
            }
        }

        // ---- Collect bundled products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productBundled, $productId)) {
            CliLogger::debug("Collecting bundled products for product $productId");
            $bundledProducts = $this->findBundledProducts($remoteProductData);
            if (!empty($bundledProducts)) {
                $allRelationships['bundled'] = $bundledProducts;
                if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productBundledCross, $productId)) {
                    $crossSellingData[] = [
                        'products' => $bundledProducts,
                        'type' => ProductRelationshipTypeEnumV1::BUNDLED
                    ];
                }
            }
        }

        // ---- Collect color variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productColorVariant, $productId)) {
            CliLogger::debug("Collecting color variant products for product $productId");
            $colorVariantProducts = $this->_findColorVariantProducts($remoteProductData);
            if (!empty($colorVariantProducts)) {
                $allRelationships['color_variant'] = $colorVariantProducts;
                if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productVariantColorCross, $productId)) {
                    $crossSellingData[] = [
                        'products' => $colorVariantProducts,
                        'type' => ProductRelationshipTypeEnumV1::COLOR_VARIANT
                    ];
                }
            }
        }

        // ---- Collect capacity variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productCapacityVariant, $productId)) {
            CliLogger::debug("Collecting capacity variant products for product $productId");
            $capacityVariantProducts = $this->_findCapacityVariantProducts($remoteProductData);
            if (!empty($capacityVariantProducts)) {
                $allRelationships['capacity_variant'] = $capacityVariantProducts;
                if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productVariantCapacityCross, $productId)) {
                    $crossSellingData[] = [
                        'products' => $capacityVariantProducts,
                        'type' => ProductRelationshipTypeEnumV1::CAPACITY_VARIANT
                    ];
                }
            }
        }

        // ---- Collect general variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productVariant, $productId)) {
            CliLogger::debug("Collecting general variant products for product $productId");
            $variantProducts = $this->_findVariantProducts($remoteProductData);
            if (!empty($variantProducts)) {
                $allRelationships['variant'] = $variantProducts;
                if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productVariantCross, $productId)) {
                    $crossSellingData[] = [
                        'products' => $variantProducts,
                        'type' => ProductRelationshipTypeEnumV1::VARIANT
                    ];
                }
            }
        }

        // Process all relationships in bulk
        if (!empty($allRelationships)) {
            CliLogger::debug("Processing bulk relationships for product $productId");
            $this->_processBulkRelationships($productId_versionId, $allRelationships, $dateTime);
        }

        // Process cross-selling relationships
        foreach ($crossSellingData as $crossData) {
            $this->_addProductCrossSelling($productId_versionId, $crossData['products'], $crossData['type']);
        }

        UtilProfiling::stopTimer();
    }


    /**
     * ==== NEW BULK METHOD ====
     *
     * Main method to link MULTIPLE products with various relationships based on remote product data.
     * This method is optimized for performance by processing products in a single batch.
     *
     * @param array $productsToProcess Array of products to process, each element containing 'productId_versionId' and 'remoteProductData'
     */
    public function linkMultipleProducts(array $productsToProcess): void
    {
        if (empty($productsToProcess)) {
            return;
        }

        UtilProfiling::startTimer();
        $dateTime = date('Y-m-d H:i:s');
        CliLogger::debug("Starting bulk processing for " . count($productsToProcess) . " products.");

        $allRelationships = [];
        $allCrossSellingData = [];

        // 1. Collect all relationships and cross-selling data from all products
        foreach ($productsToProcess as $productData) {
            $productId_versionId = $productData['productId_versionId'];
            $remoteProductData = $productData['remoteProductData'];
            $productId = $productId_versionId['product_id'];

            $relationshipFinders = [
                'similar'          => ['config' => MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productSimilar, 'cross_config' => MergedPluginConfigKeyConstants::OPTION_NAME_productSimilarCross, 'finder' => [$this, '_findSimilarProducts'], 'cross_type' => ProductRelationshipTypeEnumV1::SIMILAR],
                'alternate'        => ['config' => MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productAlternate, 'cross_config' => MergedPluginConfigKeyConstants::OPTION_NAME_productAlternateCross, 'finder' => [$this, '_findAlternateProducts'], 'cross_type' => ProductRelationshipTypeEnumV1::ALTERNATE],
                'related'          => ['config' => MergedPluginConfigKeyConstants::RELATIONSHIO_OPTION_productRelated, 'cross_config' => MergedPluginConfigKeyConstants::OPTION_NAME_productRelatedCross, 'finder' => [$this, '_findRelatedProducts'], 'cross_type' => ProductRelationshipTypeEnumV1::RELATED],
                'bundled'          => ['config' => MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productBundled, 'cross_config' => MergedPluginConfigKeyConstants::OPTION_NAME_productBundledCross, 'finder' => [$this, 'findBundledProducts'], 'cross_type' => ProductRelationshipTypeEnumV1::BUNDLED],
                'color_variant'    => ['config' => MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productColorVariant, 'cross_config' => MergedPluginConfigKeyConstants::OPTION_NAME_productVariantColorCross, 'finder' => [$this, '_findColorVariantProducts'], 'cross_type' => ProductRelationshipTypeEnumV1::COLOR_VARIANT],
                'capacity_variant' => ['config' => MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productCapacityVariant, 'cross_config' => MergedPluginConfigKeyConstants::OPTION_NAME_productVariantCapacityCross, 'finder' => [$this, '_findCapacityVariantProducts'], 'cross_type' => ProductRelationshipTypeEnumV1::CAPACITY_VARIANT],
                'variant'          => ['config' => MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productVariant, 'cross_config' => MergedPluginConfigKeyConstants::OPTION_NAME_productVariantCross, 'finder' => [$this, '_findVariantProducts'], 'cross_type' => ProductRelationshipTypeEnumV1::VARIANT],
            ];

            foreach ($relationshipFinders as $type => $details) {
                if ($this->productImportSettingsService->isProductOptionEnabled($details['config'], $productId)) {
                    $foundProducts = call_user_func($details['finder'], $remoteProductData);
                    if (!empty($foundProducts)) {
                        if (!isset($allRelationships[$type])) {
                            $allRelationships[$type] = [];
                        }
                        foreach ($foundProducts as $targetProduct) {
                            $allRelationships[$type][] = ['source' => $productId_versionId, 'target' => $targetProduct];
                        }

                        if ($this->productImportSettingsService->isProductOptionEnabled($details['cross_config'], $productId)) {
                            $allCrossSellingData[] = ['source' => $productId_versionId, 'products' => $foundProducts, 'type' => $details['cross_type']];
                        }
                    }
                }
            }
        }

        // 2. Process all collected relationships in bulk
        if (!empty($allRelationships)) {
            CliLogger::debug("Processing bulk relationships...");
            $this->_processAllRelationshipsBulk($allRelationships, $dateTime);
        }

        // 3. Process all collected cross-selling data in bulk
        if (!empty($allCrossSellingData)) {
            CliLogger::debug("Processing bulk cross-selling...");
            $this->_processAllCrossSellingsBulk($allCrossSellingData);
        }

        UtilProfiling::stopTimer();
    }

    /**
     * Processes all relationships for multiple products in a true bulk fashion.
     */
    private function _processAllRelationshipsBulk(array $allRelationships, string $dateTime): void
    {
        if (self::USE_TRANSACTIONS) {
            $this->connection->beginTransaction();
        }

        try {
            foreach ($allRelationships as $type => $relations) {
                if (empty($relations)) {
                    continue;
                }

                $tableName = $this->getTableForType($type);
                $idColumnPrefix = $this->getIdColumnPrefix($type);

                if (empty($tableName) || empty($idColumnPrefix)) {
                    continue;
                }

                $valuesSql = [];
                foreach ($relations as $relation) {
                    $sourceProd = $relation['source'];
                    $targetProd = $relation['target'];
                    $valuesSql[] = "(0x{$sourceProd['product_id']}, 0x{$sourceProd['product_version_id']}, 0x{$targetProd['product_id']}, 0x{$targetProd['product_version_id']}, '$dateTime')";
                }

                $chunks = array_chunk($valuesSql, self::BULK_INSERT_SIZE);
                foreach ($chunks as $chunk) {
                    $columns = implode(', ', [
                        'product_id',
                        'product_version_id',
                        "{$idColumnPrefix}_product_id",
                        "{$idColumnPrefix}_product_version_id",
                        'created_at'
                    ]);

                    $sql = "INSERT INTO `$tableName` ($columns) VALUES " . implode(',', $chunk);
                    $this->connection->executeStatement($sql);
                    CliLogger::activity();
                }
            }

            if (self::USE_TRANSACTIONS) {
                $this->connection->commit();
            }
        } catch (\Exception $e) {
            if (self::USE_TRANSACTIONS) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Processes all cross-selling for multiple products in a true bulk fashion.
     */
    private function _processAllCrossSellingsBulk(array $allCrossSellingData): void
    {
        // 1. Filter out variants, which don't get cross-sellings
        $dataToProcess = array_filter($allCrossSellingData, fn($data) => empty($data['source']['parent_id']));
        if (empty($dataToProcess)) {
            return;
        }

        // 2. Fetch existing cross-selling entities for all products in the batch
        $productIds = array_unique(array_map(fn($data) => $data['source']['product_id'], $dataToProcess));
        $dbTypes = array_unique(array_map(fn($data) => self::_getCrossDbType($data['type']), $dataToProcess));

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('productId', $productIds));
        $criteria->addFilter(new EqualsAnyFilter('topdataExtension.type', $dbTypes));
        $criteria->addAssociation('topdataExtension');
        $existingCrossSells = $this->productCrossSellingRepository->search($criteria, $this->context)->getEntities();

        $existingMap = []; // [productId][dbType] => crossSellingEntity
        foreach ($existingCrossSells as $cs) {
            $ext = $cs->getExtension('topdataExtension');
            if ($ext && $ext->get('type')) {
                $existingMap[$cs->getProductId()][$ext->get('type')] = $cs;
            }
        }

        // 3. Prepare data for bulk create/update operations
        $crossSellsToCreate = [];
        $assignmentsToDeleteIds = [];
        $allAssignmentsToCreate = [];

        foreach ($dataToProcess as $data) {
            $sourceProduct = $data['source'];
            $linkedProducts = $data['products'];
            $crossType = $data['type'];
            $dbType = self::_getCrossDbType($crossType);
            $productId = $sourceProduct['product_id'];

            if (isset($existingMap[$productId][$dbType])) {
                // Exists: mark old assignments for deletion
                $crossId = $existingMap[$productId][$dbType]->getId();
                $assignmentsToDeleteIds[] = $crossId;
            } else {
                // Does not exist: prepare new cross-sell entity for creation
                $crossId = Uuid::randomHex();
                $crossSellsToCreate[] = [
                    'id'               => $crossId,
                    'productId'        => $productId,
                    'productVersionId' => $sourceProduct['product_version_id'],
                    'name'             => self::_getCrossNameTranslations($crossType),
                    'position'         => self::_getCrossPosition($crossType),
                    'type'             => ProductCrossSellingDefinition::TYPE_PRODUCT_LIST,
                    'sortBy'           => ProductCrossSellingDefinition::SORT_BY_NAME,
                    'sortDirection'    => FieldSorting::ASCENDING,
                    'active'           => true,
                    'limit'            => self::MAX_CROSS_SELLINGS,
                    'topdataExtension' => ['type' => $dbType],
                ];
            }

            // Prepare new assignments for creation
            $i = 1;
            foreach ($linkedProducts as $prodIdData) {
                $allAssignmentsToCreate[] = [
                    'crossSellingId'   => $crossId,
                    'productId'        => $prodIdData['product_id'],
                    'productVersionId' => $prodIdData['product_version_id'],
                    'position'         => $i++,
                ];
            }
        }

        // 4. Execute all DB operations in bulk
        if (!empty($assignmentsToDeleteIds)) {
            $this->connection->executeStatement(
                "DELETE FROM product_cross_selling_assigned_products WHERE cross_selling_id IN (:ids)",
                ['ids' => array_map('hex2bin', array_unique($assignmentsToDeleteIds))],
                ['ids' => ArrayParameterType::BINARY]
            );
            CliLogger::activity();
        }

        if (!empty($crossSellsToCreate)) {
            $this->productCrossSellingRepository->create($crossSellsToCreate, $this->context);
            CliLogger::activity();
        }

        if (!empty($allAssignmentsToCreate)) {
            foreach (array_chunk($allAssignmentsToCreate, self::BULK_INSERT_SIZE) as $chunk) {
                $this->productCrossSellingAssignedProductsRepository->create($chunk, $this->context);
                CliLogger::activity();
            }
        }
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