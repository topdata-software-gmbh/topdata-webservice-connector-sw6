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
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * aka ProductCrossSellingService
 *
 * 11/2024 created (extracted from MappingHelperService)
 */
class ProductLinkingService
{
    private Context $context;


    public function __construct(
        private readonly ProductImportSettingsService  $productImportSettingsService,
        private readonly Connection                    $connection,
        private readonly TopdataToProductHelperService $topdataToProductHelperService,
        private readonly EntityRepository              $productCrossSellingRepository,
        private readonly EntityRepository              $productCrossSellingAssignedProductsRepository,
    )
    {
        $this->context = Context::createDefaultContext();
    }

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


    private function _findSimilarProducts($remoteProductData): array
    {
        $similarProducts = [];
        $topid_products = $this->topdataToProductHelperService->getTopidProducts();

        if (isset($remoteProductData->product_same_accessories->products) && count($remoteProductData->product_same_accessories->products)) {
            foreach ($remoteProductData->product_same_accessories->products as $tid) {
                if (!isset($topid_products[$tid])) {
                    continue;
                }
                $similarProducts[$tid] = $topid_products[$tid][0];
            }
        }

        if (isset($remoteProductData->product_same_application_in->products) && count($remoteProductData->product_same_application_in->products)) {
            foreach ($remoteProductData->product_same_application_in->products as $tid) {
                if (!isset($topid_products[$tid])) {
                    continue;
                }
                $similarProducts[$tid] = $topid_products[$tid][0];
            }
        }

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

    private function findVariantProducts($remoteProductData): array
    {
        $products = [];
        $topid_products = $this->topdataToProductHelperService->getTopidProducts();

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

    private function addProductCrossSelling(array $currentProductId, array $linkedProductIds, string $crossType): void
    {
        if ($currentProductId['parent_id']) {
            //don't create cross if product is variation!
            return;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $currentProductId['product_id']));
        $criteria->addFilter(new EqualsFilter('topdataExtension.type', $crossType));
        $productCrossSellingEntity = $this->productCrossSellingRepository->search($criteria, $this->context)->first();

        if ($productCrossSellingEntity) {
            $crossId = $productCrossSellingEntity->getId();
            //            $this
            //                ->productCrossSellingAssignedProductsRepository
            //                ->delete([['crossSellingId'=>$crossId]], $this->context);

            $this
                ->connection
                ->executeStatement("DELETE 
                    FROM product_cross_selling_assigned_products 
                    WHERE cross_selling_id = 0x$crossId");
        } else {
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
     * ==== MAIN ====
     *
     * 11/2024 created
     */
    public function linkProducts(array $productId_versionId, $remoteProductData): void
    {
        $dateTime = date('Y-m-d H:i:s');
        $productId = $productId_versionId['product_id'];

        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productSimilar, $productId)) {
            $dataInsert = [];
            $temp = $this->_findSimilarProducts($remoteProductData);
            foreach ($temp as $tempProd) {
                $dataInsert[] = "(0x{$productId_versionId['product_id']}, 0x{$productId_versionId['product_version_id']}, 0x{$tempProd['product_id']}, 0x{$tempProd['product_version_id']}, '$dateTime')";
            }

            if (count($dataInsert)) {
                $insertDataChunks = array_chunk($dataInsert, 30);
                foreach ($insertDataChunks as $chunk) {
                    $this->connection->executeStatement('
                        INSERT INTO topdata_product_to_similar (product_id, product_version_id, similar_product_id, similar_product_version_id, created_at) VALUES ' . implode(',', $chunk) . '
                    ');
                    CliLogger::activity();
                }

                if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productSimilarCross, $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, CrossSellingTypeConstant::CROSS_SIMILAR);
                }
            }
        }

        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productAlternate, $productId)) {
            $dataInsert = [];
            $temp = $this->findAlternateProducts($remoteProductData);
            foreach ($temp as $tempProd) {
                $dataInsert[] = "(0x{$productId_versionId['product_id']}, 0x{$productId_versionId['product_version_id']}, 0x{$tempProd['product_id']}, 0x{$tempProd['product_version_id']}, '$dateTime')";
            }

            if (count($dataInsert)) {
                $insertDataChunks = array_chunk($dataInsert, 30);
                foreach ($insertDataChunks as $chunk) {
                    $this->connection->executeStatement('
                        INSERT INTO topdata_product_to_alternate (product_id, product_version_id, alternate_product_id, alternate_product_version_id, created_at) VALUES ' . implode(',', $chunk) . '
                    ');
                    CliLogger::activity();
                }

                if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productAlternateCross, $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, CrossSellingTypeConstant::CROSS_ALTERNATE);
                }
            }
        }

        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productRelated, $productId)) {
            $dataInsert = [];
            $temp = $this->findRelatedProducts($remoteProductData);
            foreach ($temp as $tempProd) {
                $dataInsert[] = "(0x{$productId_versionId['product_id']}, 0x{$productId_versionId['product_version_id']}, 0x{$tempProd['product_id']}, 0x{$tempProd['product_version_id']}, '$dateTime')";
            }

            if (count($dataInsert)) {
                $insertDataChunks = array_chunk($dataInsert, 30);
                foreach ($insertDataChunks as $chunk) {
                    $this->connection->executeStatement('
                        INSERT INTO topdata_product_to_related (product_id, product_version_id, related_product_id, related_product_version_id, created_at) VALUES ' . implode(',', $chunk) . '
                    ');
                    CliLogger::activity();
                }

                if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productRelatedCross, $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, CrossSellingTypeConstant::CROSS_RELATED);
                }
            }
        }

        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productBundled, $productId)) {
            $dataInsert = [];
            $temp = $this->findBundledProducts($remoteProductData);
            foreach ($temp as $tempProd) {
                $dataInsert[] = "(0x{$productId_versionId['product_id']}, 0x{$productId_versionId['product_version_id']}, 0x{$tempProd['product_id']}, 0x{$tempProd['product_version_id']}, '$dateTime')";
            }

            if (count($dataInsert)) {
                $insertDataChunks = array_chunk($dataInsert, 30);
                foreach ($insertDataChunks as $chunk) {
                    $this->connection->executeStatement('
                        INSERT INTO topdata_product_to_bundled (product_id, product_version_id, bundled_product_id, bundled_product_version_id, created_at) VALUES ' . implode(',', $chunk) . '
                    ');
                    CliLogger::activity();
                }

                if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productBundledCross, $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, CrossSellingTypeConstant::CROSS_BUNDLED);
                }
            }
        }

        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productColorVariant, $productId)) {
            $dataInsert = [];
            $temp = $this->findColorVariantProducts($remoteProductData);
            foreach ($temp as $tempProd) {
                $dataInsert[] = "(0x{$productId_versionId['product_id']}, 0x{$productId_versionId['product_version_id']}, 0x{$tempProd['product_id']}, 0x{$tempProd['product_version_id']}, '$dateTime')";
            }

            if (count($dataInsert)) {
                $insertDataChunks = array_chunk($dataInsert, 30);
                foreach ($insertDataChunks as $chunk) {
                    $this->connection->executeStatement('
                        INSERT INTO topdata_product_to_color_variant 
                        (product_id, product_version_id, color_variant_product_id, color_variant_product_version_id, created_at) 
                        VALUES ' . implode(',', $chunk) . '
                    ');
                    CliLogger::activity();
                }

                if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productVariantColorCross, $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, CrossSellingTypeConstant::CROSS_COLOR_VARIANT);
                }
            }
        }

        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productCapacityVariant, $productId)) {
            $dataInsert = [];
            $temp = $this->findCapacityVariantProducts($remoteProductData);
            foreach ($temp as $tempProd) {
                $dataInsert[] = "(0x{$productId_versionId['product_id']}, 0x{$productId_versionId['product_version_id']}, 0x{$tempProd['product_id']}, 0x{$tempProd['product_version_id']}, '$dateTime')";
            }

            if (count($dataInsert)) {
                $insertDataChunks = array_chunk($dataInsert, 30);
                foreach ($insertDataChunks as $chunk) {
                    $this->connection->executeStatement('
                        INSERT INTO topdata_product_to_capacity_variant 
                        (product_id, product_version_id, capacity_variant_product_id, capacity_variant_product_version_id, created_at) 
                        VALUES ' . implode(',', $chunk) . '
                    ');
                    CliLogger::activity();
                }

                if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productVariantCapacityCross, $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, CrossSellingTypeConstant::CROSS_CAPACITY_VARIANT);
                }
            }
        }

        if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productVariant, $productId)) {
            $dataInsert = [];
            $temp = $this->findVariantProducts($remoteProductData);
            foreach ($temp as $tempProd) {
                $dataInsert[] = "(0x{$productId_versionId['product_id']}, 0x{$productId_versionId['product_version_id']}, 0x{$tempProd['product_id']}, 0x{$tempProd['product_version_id']}, '$dateTime')";
            }

            if (count($dataInsert)) {
                $insertDataChunks = array_chunk($dataInsert, 30);
                foreach ($insertDataChunks as $chunk) {
                    $this->connection->executeStatement('
                        INSERT INTO topdata_product_to_variant 
                        (product_id, product_version_id, variant_product_id, variant_product_version_id, created_at) 
                        VALUES ' . implode(',', $chunk) . '
                    ');
                    CliLogger::activity();
                }

                if ($this->productImportSettingsService->getProductOption(ProductImportSettingsService::OPTION_NAME_productVariantCross, $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, CrossSellingTypeConstant::CROSS_VARIANT);
                }
            }
        }
    }

}
