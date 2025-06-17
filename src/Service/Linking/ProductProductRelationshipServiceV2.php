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
use Topdata\TopdataConnectorSW6\Enum\ProductRelationshipTypeEnumV2;
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
 * Updated to use the unified topdata_product_relationships table instead of separate tables.
 *
 * aka ProductCrossSellingService
 *
 * 06/2026 created - this replaces the deprecated ProductProductRelationshipServiceV1, it uses the unified topdata_product_relationships table
 */
class ProductProductRelationshipServiceV2
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
     * Maps enum values to database relationship type values
     * Updated to match the new unified table structure
     */
    private static function _getCrossDbType(ProductRelationshipTypeEnumV2 $crossType): string
    {
        return match ($crossType) {
            ProductRelationshipTypeEnumV2::SIMILAR          => 'similar',
            ProductRelationshipTypeEnumV2::ALTERNATE        => 'alternate',
            ProductRelationshipTypeEnumV2::RELATED          => 'related',
            ProductRelationshipTypeEnumV2::BUNDLED          => 'bundled',
            ProductRelationshipTypeEnumV2::COLOR_VARIANT    => 'color_variant',
            ProductRelationshipTypeEnumV2::CAPACITY_VARIANT => 'capacity_variant',
            ProductRelationshipTypeEnumV2::VARIANT          => 'variant',
            default                                         => throw new RuntimeException("Unknown cross-selling type: {$crossType->value}"),
        };
    }


    private static function _getCrossNameTranslations(ProductRelationshipTypeEnumV2 $crossType): array
    {
        return match ($crossType) {
            ProductRelationshipTypeEnumV2::CAPACITY_VARIANT => [
                'de-DE' => 'Kapazitätsvarianten',
                'en-GB' => 'Capacity Variants',
                'nl-NL' => 'capaciteit varianten',
            ],
            ProductRelationshipTypeEnumV2::COLOR_VARIANT    => [
                'de-DE' => 'Farbvarianten',
                'en-GB' => 'Color Variants',
                'nl-NL' => 'kleur varianten',
            ],
            ProductRelationshipTypeEnumV2::ALTERNATE        => [
                'de-DE' => 'Alternative Produkte',
                'en-GB' => 'Alternate Products',
                'nl-NL' => 'alternatieve producten',
            ],
            ProductRelationshipTypeEnumV2::RELATED          => [
                'de-DE' => 'Zubehör',
                'en-GB' => 'Accessories',
                'nl-NL' => 'Accessoires',
            ],
            ProductRelationshipTypeEnumV2::VARIANT          => [
                'de-DE' => 'Varianten',
                'en-GB' => 'Variants',
                'nl-NL' => 'varianten',
            ],
            ProductRelationshipTypeEnumV2::BUNDLED          => [
                'de-DE' => 'Im Bundle',
                'en-GB' => 'In Bundle',
                'nl-NL' => 'In een bundel',
            ],
            ProductRelationshipTypeEnumV2::SIMILAR          => [
                'de-DE' => 'Ähnlich',
                'en-GB' => 'Similar',
                'nl-NL' => 'Vergelijkbaar',
            ],
            default                                         => throw new RuntimeException("Unknown cross-selling type: {$crossType->value}"),
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
     * @param ProductRelationshipTypeEnumV2 $crossType The type of cross-selling relationship
     */
    private function _addProductCrossSelling(array $currentProduct, array $linkedProductIds, ProductRelationshipTypeEnumV2 $crossType): void
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
                    'type' => $crossType->value
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
     * Generic method to process product relationships using the unified table
     * Updated to use topdata_product_relationships instead of separate tables
     *
     * @param array $productId_versionId The current product ID information
     * @param array $relatedProducts Array of products to be linked
     * @param ProductRelationshipTypeEnumV2 $crossType The type of cross-selling relationship
     * @param bool $enableCrossSelling Whether to enable cross-selling
     * @param string $dateTime The current date/time string
     */
    private function _processProductRelationship(
        array                         $productId_versionId,
        array                         $relatedProducts,
        ProductRelationshipTypeEnumV2 $crossType,
        bool                          $enableCrossSelling,
        string                        $dateTime
    ): void
    {
        if (empty($relatedProducts)) {
            return;
        }

        $relationshipType = self::_getCrossDbType($crossType);
        $dataInsert = [];

        foreach ($relatedProducts as $tempProd) {
            $dataInsert[] = "(0x{$productId_versionId['product_id']}, 0x{$productId_versionId['product_version_id']}, 0x{$tempProd['product_id']}, 0x{$tempProd['product_version_id']}, '{$relationshipType}', '$dateTime', '$dateTime')";
        }

        $insertDataChunks = array_chunk($dataInsert, self::CHUNK_SIZE);
        foreach ($insertDataChunks as $chunk) {
            $columns = implode(', ', [
                'product_id',
                'product_version_id',
                'linked_product_id',
                'linked_product_version_id',
                'relationship_type',
                'created_at',
                'updated_at'
            ]);

            $this->connection->executeStatement(
                "INSERT INTO topdata_product_relationships ($columns) VALUES " . implode(',', $chunk)
            );
            CliLogger::activity();
        }

        if ($enableCrossSelling) {
            $this->_addProductCrossSelling($productId_versionId, $relatedProducts, $crossType);
        }
    }


    /**
     * Orchestrator method to unlink products from various relationships based on plugin configuration.
     * Updated to use the unified topdata_product_relationships table
     *
     * @param string[] $productIds Array of product IDs to unlink.
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

        // Map configuration keys to their respective relationship types
        $relationshipMap = [
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productSimilar         => 'similar',
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productAlternate       => 'alternate',
            MergedPluginConfigKeyConstants::RELATIONSHIO_OPTION_productRelated         => 'related',
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productBundled         => 'bundled',
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productColorVariant    => 'color_variant',
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productCapacityVariant => 'capacity_variant',
            MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productVariant         => 'variant',
        ];

        foreach ($relationshipMap as $configKey => $relationshipType) {
            $idsToUnlink = $this->productImportSettingsService->filterProductIdsByConfig(
                $configKey,
                $validProductIds
            );

            if (!empty($idsToUnlink)) {
                $this->_deleteFromUnifiedTableWhereProductIdsIn($relationshipType, $idsToUnlink);
            }
            ImportReport::incCounter('links deleted for ' . $relationshipType, count($idsToUnlink));
        }
    }

    /**
     * Executes a DELETE statement on the unified table for a list of product IDs and relationship type.
     * Updated to work with the unified topdata_product_relationships table
     *
     * @param string $relationshipType The relationship type to delete
     * @param string[] $productIds The product IDs to delete. The product IDs are expected to be UUIDs in hex format (without 0x and without dashes)
     */
    private function _deleteFromUnifiedTableWhereProductIdsIn(string $relationshipType, array $productIds): int
    {
        return (int)$this->connection->executeStatement(
            "DELETE FROM `topdata_product_relationships` WHERE product_id IN (:ids) AND relationship_type = :type",
            [
                'ids'  => array_map('hex2bin', $productIds),
                'type' => $relationshipType,
            ],
            [
                'ids' => ArrayParameterType::BINARY,
            ]
        );
    }


    /**
     * ==== MAIN ====
     *
     * Main method to link products with various relationships based on remote product data
     * Updated to use the unified table structure
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
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productSimilar, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findSimilarProducts($remoteProductData),
                ProductRelationshipTypeEnumV2::SIMILAR,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productSimilarCross, $productId),
                $dateTime
            );
        }

        // ---- Process alternate products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productAlternate, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findAlternateProducts($remoteProductData),
                ProductRelationshipTypeEnumV2::ALTERNATE,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productAlternateCross, $productId),
                $dateTime
            );
        }

        // ---- Process related products (accessories)
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIO_OPTION_productRelated, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findRelatedProducts($remoteProductData),
                ProductRelationshipTypeEnumV2::RELATED,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productRelatedCross, $productId),
                $dateTime
            );
        }

        // ---- Process bundled products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productBundled, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->findBundledProducts($remoteProductData),
                ProductRelationshipTypeEnumV2::BUNDLED,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productBundledCross, $productId),
                $dateTime
            );
        }

        // ---- Process color variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productColorVariant, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findColorVariantProducts($remoteProductData),
                ProductRelationshipTypeEnumV2::COLOR_VARIANT,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productVariantColorCross, $productId),
                $dateTime
            );
        }

        // ---- Process capacity variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productCapacityVariant, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findCapacityVariantProducts($remoteProductData),
                ProductRelationshipTypeEnumV2::CAPACITY_VARIANT,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productVariantCapacityCross, $productId),
                $dateTime
            );
        }

        // ---- Process general variant products
        if ($this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::RELATIONSHIP_OPTION_productVariant, $productId)) {
            $this->_processProductRelationship(
                $productId_versionId,
                $this->_findVariantProducts($remoteProductData),
                ProductRelationshipTypeEnumV2::VARIANT,
                $this->productImportSettingsService->isProductOptionEnabled(MergedPluginConfigKeyConstants::OPTION_NAME_productVariantCross, $productId),
                $dateTime
            );
        }

        UtilProfiling::stopTimer();
    }

    /**
     * Get cross-selling position for ordering
     */
    private static function _getCrossPosition(ProductRelationshipTypeEnumV2 $crossType): int
    {
        return match ($crossType) {
            ProductRelationshipTypeEnumV2::CAPACITY_VARIANT => 1,
            ProductRelationshipTypeEnumV2::COLOR_VARIANT    => 2,
            ProductRelationshipTypeEnumV2::ALTERNATE        => 3,
            ProductRelationshipTypeEnumV2::RELATED          => 4,
            ProductRelationshipTypeEnumV2::VARIANT          => 5,
            ProductRelationshipTypeEnumV2::BUNDLED          => 6,
            ProductRelationshipTypeEnumV2::SIMILAR          => 7,
            default                                         => throw new RuntimeException("Unknown cross-selling type: {$crossType->value}"),
        };
    }

}