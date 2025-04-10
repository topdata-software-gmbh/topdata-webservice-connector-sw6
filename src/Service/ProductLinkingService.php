<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\CrossSellingTypeConstant;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
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
class ProductLinkingService
{
    const CHUNK_SIZE = 30;

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
     * Returns an array mapping numeric keys to cross-selling type constants
     */
    private static function getCrossTypes(): array
    {
        return [
            1 => CrossSellingTypeConstant::CROSS_CAPACITY_VARIANT,
            2 => CrossSellingTypeConstant::CROSS_COLOR_VARIANT,
            3 => CrossSellingTypeConstant::CROSS_ALTERNATE,
            4 => CrossSellingTypeConstant::CROSS_RELATED,
            5 => CrossSellingTypeConstant::CROSS_VARIANT,
            6 => CrossSellingTypeConstant::CROSS_BUNDLED,
            7 => CrossSellingTypeConstant::CROSS_SIMILAR,
        ];
    }

    /**
     * TODO: refactor: use match()
     * TODO: make it static
     */
    private function getCrossName(string $crossType)
    {
        $names = [
            CrossSellingTypeConstant::CROSS_CAPACITY_VARIANT => [
                'de-DE' => 'Kapazitätsvarianten',
                'en-GB' => 'Capacity Variants',
                'nl-NL' => 'capaciteit varianten',
            ],
            CrossSellingTypeConstant::CROSS_COLOR_VARIANT    => [
                'de-DE' => 'Farbvarianten',
                'en-GB' => 'Color Variants',
                'nl-NL' => 'kleur varianten',
            ],
            CrossSellingTypeConstant::CROSS_ALTERNATE        => [
                'de-DE' => 'Alternative Produkte',
                'en-GB' => 'Alternate Products',
                'nl-NL' => 'alternatieve producten',
            ],
            CrossSellingTypeConstant::CROSS_RELATED          => [
                'de-DE' => 'Zubehör',
                'en-GB' => 'Accessories',
                'nl-NL' => 'Accessoires',
            ],
            CrossSellingTypeConstant::CROSS_VARIANT          => [
                'de-DE' => 'Varianten',
                'en-GB' => 'Variants',
                'nl-NL' => 'varianten',
            ],
            CrossSellingTypeConstant::CROSS_BUNDLED          => [
                'de-DE' => 'Im Bundle',
                'en-GB' => 'In Bundle',
                'nl-NL' => 'In een bundel',
            ],
            CrossSellingTypeConstant::CROSS_SIMILAR          => [
                'de-DE' => 'Ähnlich',
                'en-GB' => 'Similar',
                'nl-NL' => 'Vergelijkbaar',
            ],
        ];

        return isset($names[$crossType]) ? $names[$crossType] : $crossType;
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
        $topid_products = $this->topdataToProductHelperService->getTopidProducts();

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
    private function findColorVariantProducts($remoteProductData): array
    {
        $linkedProducts = [];
        $topid_products = $this->topdataToProductHelperService->getTopidProducts();
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
    private function findCapacityVariantProducts($remoteProductData): array
    {
        $linkedProducts = [];
        $topid_products = $this->topdataToProductHelperService->getTopidProducts();
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
    private function findVariantProducts($remoteProductData): array
    {
        $products = [];
        $topid_products = $this->topdataToProductHelperService->getTopidProducts();

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
     * @param array $currentProductId The current product ID information
     * @param array $linkedProductIds Array of products to be linked
     * @param string $crossType The type of cross-selling relationship
     */
    private function addProductCrossSelling(array $currentProductId, array $linkedProductIds, string $crossType): void
    {
        if ($currentProductId['parent_id']) {
            //don't create cross if product is variation!
            return;
        }

        // ---- Check if cross-selling already exists for this product and type

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $currentProductId['product_id']));
        $criteria->addFilter(new EqualsFilter('topdataExtension.type', $crossType));
        $productCrossSellingEntity = $this->productCrossSellingRepository->search($criteria, $this->context)->first();

        if ($productCrossSellingEntity) {
            $crossId = $productCrossSellingEntity->getId();
            //            $this
            //                ->productCrossSellingAssignedProductsRepository
            //                ->delete([['crossSellingId'=>$crossId]], $this->context);

            // ---- Remove existing cross-selling product assignments
            $this
                ->connection
                ->executeStatement("DELETE 
                    FROM product_cross_selling_assigned_products 
                    WHERE cross_selling_id = 0x$crossId");
        } else {
            // ---- Create new cross-selling entity
            $crossId = Uuid::randomHex();
            $data = [
                'id'               => $crossId,
                'productId'        => $currentProductId['product_id'],
                'productVersionId' => $currentProductId['product_version_id'],
                'name'             => $this->getCrossName($crossType),
                'position'         => array_search($crossType, self::getCrossTypes()),
                'type'             => ProductCrossSellingDefinition::TYPE_PRODUCT_LIST,
                'sortBy'           => ProductCrossSellingDefinition::SORT_BY_NAME,
                'sortDirection'    => FieldSorting::ASCENDING,
                'active'           => true,
                'limit'            => 24,
                'topdataExtension' => ['type' => $crossType],
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
    private function findAlternateProducts($remoteProductData): array
    {
        $alternateProducts = [];
        $topid_products = $this->topdataToProductHelperService->getTopidProducts();
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
    private function findRelatedProducts($remoteProductData): array
    {
        $relatedProducts = [];
        $topid_products = $this->topdataToProductHelperService->getTopidProducts();
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
        $topid_products = $this->topdataToProductHelperService->getTopidProducts();
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
     * @param string $crossType The type of cross-selling relationship
     * @param bool $enableCrossSelling Whether to enable cross-selling
     * @param string $dateTime The current date/time string
     */
    private function processProductRelationship(
        array  $productId_versionId,
        array  $relatedProducts,
        string $tableName,
        string $idColumnPrefix,
        string $crossType,
        bool   $enableCrossSelling,
        string $dateTime
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
            $this->addProductCrossSelling($productId_versionId, $relatedProducts, $crossType);
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
        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productSimilar, $productId)) {
            $this->processProductRelationship(
                $productId_versionId,
                $this->_findSimilarProducts($remoteProductData),
                'topdata_product_to_similar',
                'similar',
                CrossSellingTypeConstant::CROSS_SIMILAR,
                $this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productSimilarCross, $productId),
                $dateTime
            );
        }

        // ---- Process alternate products
        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productAlternate, $productId)) {
            $this->processProductRelationship(
                $productId_versionId,
                $this->findAlternateProducts($remoteProductData),
                'topdata_product_to_alternate',
                'alternate',
                CrossSellingTypeConstant::CROSS_ALTERNATE,
                $this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productAlternateCross, $productId),
                $dateTime
            );
        }

        // ---- Process related products (accessories)
        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productRelated, $productId)) {
            $this->processProductRelationship(
                $productId_versionId,
                $this->findRelatedProducts($remoteProductData),
                'topdata_product_to_related',
                'related',
                CrossSellingTypeConstant::CROSS_RELATED,
                $this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productRelatedCross, $productId),
                $dateTime
            );
        }

        // ---- Process bundled products
        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productBundled, $productId)) {
            $this->processProductRelationship(
                $productId_versionId,
                $this->findBundledProducts($remoteProductData),
                'topdata_product_to_bundled',
                'bundled',
                CrossSellingTypeConstant::CROSS_BUNDLED,
                $this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productBundledCross, $productId),
                $dateTime
            );
        }

        // ---- Process color variant products
        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productColorVariant, $productId)) {
            $this->processProductRelationship(
                $productId_versionId,
                $this->findColorVariantProducts($remoteProductData),
                'topdata_product_to_color_variant',
                'color_variant',
                CrossSellingTypeConstant::CROSS_COLOR_VARIANT,
                $this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productVariantColorCross, $productId),
                $dateTime
            );
        }

        // ---- Process capacity variant products
        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productCapacityVariant, $productId)) {
            $this->processProductRelationship(
                $productId_versionId,
                $this->findCapacityVariantProducts($remoteProductData),
                'topdata_product_to_capacity_variant',
                'capacity_variant',
                CrossSellingTypeConstant::CROSS_CAPACITY_VARIANT,
                $this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productVariantCapacityCross, $productId),
                $dateTime
            );
        }

        // ---- Process general variant products
        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productVariant, $productId)) {
            $this->processProductRelationship(
                $productId_versionId,
                $this->findVariantProducts($remoteProductData),
                'topdata_product_to_variant',
                'variant',
                CrossSellingTypeConstant::CROSS_VARIANT,
                $this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productVariantCross, $productId),
                $dateTime
            );
        }
    }

}
