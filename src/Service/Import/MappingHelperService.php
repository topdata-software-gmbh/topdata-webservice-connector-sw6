<?php
/**
 * @author    Christoph Muskalla <muskalla@cm-s.eu>
 * @copyright 2019 CMS (http://www.cm-s.eu)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Topdata\TopdataConnectorSW6\Service\Import;

use Doctrine\DBAL\Connection;
use PDO;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Service\Config\MergedPluginConfigHelperService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataDeviceService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataDeviceTypeService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataSeriesService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Service\MediaHelperService;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataConnectorSW6\Util\UtilStringFormatting;
use Topdata\TopdataFoundationSW6\Service\LocaleHelperService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilFormatter;

/**
 * MappingHelperService class.
 *
 * This class is responsible for mapping and synchronizing data between Topdata and Shopware 6.
 * It handles various operations such as product mapping, device synchronization, and cross-selling setup.
 *
 * TODO: This class is quite large and should be refactored into smaller, more focused classes.
 * this class is quite large and has multiple responsibilities. Here are several suggestions for extracting functionality into separate classes:
 *
 * 1 ProductVariantService
 *
 * • Extract all variant-related methods like setProductColorCapacityVariants(), createVariatedProduct(), collectColorVariants(), collectCapacityVariants()
 * • This would handle all logic related to product variants and their creation
 *
 * 3 ProductImportSettingsService
 *
 * • Extract _loadProductImportSettings(), getProductOption(), _getProductExtraOption()
 * • Would handle all product import configuration and settings
 *
 * 5 ProductMediaService
 *
 * • Extract media-related functionality from prepareProduct() and setProductInformation()
 * • Would handle all product media operations
 *
 * 6 ProductPropertyService
 *
 * • Extract property-related functionality from prepareProduct()
 * • Would handle all product property operations
 *
 *
 * The main MappingHelperService would then orchestrate these services and maintain only the core mapping logic between Topdata and Shopware 6.
 *
 * This separation would:
 *
 * • Make the code more maintainable
 * • Make testing easier
 * • Follow the Single Responsibility Principle better
 * • Make the code more reusable
 * • Reduce the complexity of the main service
 *
 * 04/2024 Renamed from MappingHelper to MappingHelperService
 */
class MappingHelperService
{

    const CAPACITY_NAMES = [
        'Kapazität (Zusatz)',
        'Kapazität',
        'Capacity',
    ];

    const COLOR_NAMES = [
        'Farbe',
        'Color',
    ];

    /**
     * Array to store mapped products.
     *
     * Structure:
     * [
     *      ws_id1 => [
     *          'product_id' => hexid1,
     *          'product_version_id' => hexversionid1
     *      ],
     *      ws_id2 => [
     *          'product_id' => hexid2,
     *          'product_version_id' => hexversionid2
     *      ]
     *  ]
     */
    private Context $context;
    private string $systemDefaultLocaleCode;


    public function __construct(
        private readonly LoggerInterface                 $logger,
        private readonly Connection                      $connection,
        private readonly EntityRepository                $topdataBrandRepository,
        private readonly EntityRepository                $topdataDeviceRepository,
        private readonly EntityRepository                $topdataSeriesRepository,
        private readonly EntityRepository                $topdataDeviceTypeRepository,
        private readonly EntityRepository                $productRepository,
        private readonly ProductMappingService           $productMappingService,
        private readonly MergedPluginConfigHelperService $optionsHelperService,
        private readonly LocaleHelperService             $localeHelperService,
        private readonly TopdataToProductService         $topdataToProductHelperService,
        private readonly MediaHelperService              $mediaHelperService,
        private readonly TopdataDeviceService            $topdataDeviceService,
        private readonly TopdataWebserviceClient         $topdataWebserviceClient,
        private readonly TopdataSeriesService            $topdataSeriesService,
        private readonly TopdataDeviceTypeService        $topdataDeviceTypeService
    )
    {
        $this->systemDefaultLocaleCode = $this->localeHelperService->getLocaleCodeOfSystemLanguage();
        $this->context = Context::createDefaultContext();
    }



    //    /**
    //     * 10/2024 UNUSED --> commented out
    //     */
    //    private function getAlbumByNameAndParent($name, $parentID = null)
    //    {
    //        $query = $this->connection->createQueryBuilder();
    //        $query->select('*')
    //            ->from('s_media_album', 'alb')
    //            ->where('alb.name = :name')
    //            ->setParameter(':name', $name);
    //        if (is_null($parentID)) {
    //            $query->andWhere('alb.parentID is null');
    //        } else {
    //            $query->andWhere('alb.parentID = :parentID')
    //                ->setParameter(':parentID', ($parentID));
    //        }
    //
    //        $return = $query->execute()->fetchAllAssociative();
    //        if (isset($return[0])) {
    //            return $return[0];
    //        } else {
    //            return false;
    //        }
    //    }

    //    /**
    //     * 10/2024 UNUSED --> commented out
    //     */
    //    private function getKeysByCustomField(string $optionName, string $colName = 'name'): array
    //    {
    //        $query = $this->connection->createQueryBuilder();
    //
    //        //        $query->select(['val.value', 'det.id'])
    //        //            ->from('s_filter_articles', 'art')
    //        //            ->innerJoin('art', 's_articles_details','det', 'det.articleID = art.articleID')
    //        //            ->innerJoin('art', 's_filter_values','val', 'art.valueID = val.id')
    //        //            ->innerJoin('val', 's_filter_options', 'opt', 'opt.id = val.optionID')
    //        //            ->where('opt.name = :option')
    //        //            ->setParameter(':option', $optionName)
    //        //        ;
    //
    //        $query->select(['pgot.name ' . $colName, 'p.id', 'p.version_id'])
    //            ->from('product', 'p')
    //            ->innerJoin('p', 'product_property', 'pp', '(pp.product_id = p.id) AND (pp.product_version_id = p.version_id)')
    //            ->innerJoin('pp', 'property_group_option_translation', 'pgot', 'pgot.property_group_option_id = pp.property_group_option_id')
    //            ->innerJoin('pp', 'property_group_option', 'pgo', 'pgo.id = pp.property_group_option_id')
    //            ->innerJoin('pgo', 'property_group_translation', 'pgt', 'pgt.property_group_id = pgo.property_group_id')
    //            ->where('pgt.name = :option')
    //            ->setParameter(':option', $optionName);
    //        //print_r($query->getSQL());die();
    //        $returnArray = $query->execute()->fetchAllAssociative();
    //
    //        //        foreach ($returnArray as $key=>$val) {
    //        //            $returnArray[$key] = [
    //        //                $colName => $val[$colName],
    //        //                'id' => bin2hex($val['id']),
    //        //                'version_id' => bin2hex($val['version_id']),
    //        //            ];
    //        //        }
    //        return $returnArray;
    //    }


    private function getTopdataCategory()
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['categoryID', 'top_data_ws_id'])
            ->from('s_categories_attributes')
            ->where('top_data_ws_id != \'0\'')
            ->andWhere('top_data_ws_id != \'\'')
            ->andWhere('top_data_ws_id is not null');

        return $query->execute()->fetchAllAssociative(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Sets the brands by fetching data from the remote server and updating the local database.
     *
     * This method retrieves brand data from the remote server, processes the data, and updates the local database
     * by creating new entries or updating existing ones. It uses the `TopdataWebserviceClient` to fetch the data and
     * the `EntityRepository` to perform database operations.
     */
    public function setBrands(): void
    {
        UtilProfiling::startTimer();
        CliLogger::section("Brands");

        // Log the start of the data fetching process
        CliLogger::writeln('Fetching data from remote server [Brand]...');
        CliLogger::lap(true);

        // Fetch the brands from the remote server
        $brands = $this->topdataWebserviceClient->getBrands();
        CliLogger::activity('Got ' . UtilFormatter::formatInteger(count($brands->data)) . " brands from remote server\n");
        ImportReport::setCounter('Fetched Brands', count($brands->data));

        $duplicates = [];
        $dataCreate = [];
        $dataUpdate = [];
        CliLogger::activity('Processing data');

        // Process each brand fetched from the remote server
        foreach ($brands->data as $b) {
            if ($b->main == 0) {
                continue;
            }

            $code = UtilStringFormatting::formCode($b->val);
            if (isset($duplicates[$code])) {
                continue;
            }
            $duplicates[$code] = true;

            // Search for existing brand in the local database
            $brand = $this->topdataBrandRepository->search(
                (new Criteria())->addFilter(new EqualsFilter('code', $code))->setLimit(1),
                $this->context
            )
                ->getEntities()
                ->first();

            // If the brand does not exist, prepare data for creation
            if (!$brand) {
                $dataCreate[] = [
                    'code'    => $code,
                    'name'    => $b->val,
                    'enabled' => false,
                    'sort'    => (int)$b->top,
                    'wsId'    => (int)$b->id,
                ];
                // If the brand exists but has different data, prepare data for update
            } elseif (
                $brand->getName() != $b->val ||
                $brand->getSort() != $b->top ||
                $brand->getWsId() != $b->id
            ) {
                $dataUpdate[] = [
                    'id'   => $brand->getId(),
                    'name' => $b->val,
                    // 'sort' => (int)$b->top,
                    'wsId' => (int)$b->id,
                ];
            }

            // Create new brands in batches of 100
            if (count($dataCreate) > 100) {
                $this->topdataBrandRepository->create($dataCreate, $this->context);
                $dataCreate = [];
                CliLogger::activity();
            }

            // Update existing brands in batches of 100
            if (count($dataUpdate) > 100) {
                $this->topdataBrandRepository->update($dataUpdate, $this->context);
                $dataUpdate = [];
                CliLogger::activity();
            }
        }

        // Create any remaining new brands
        if (count($dataCreate)) {
            $this->topdataBrandRepository->create($dataCreate, $this->context);
            CliLogger::activity();
        }

        // Update any remaining existing brands
        if (count($dataUpdate)) {
            $this->topdataBrandRepository->update($dataUpdate, $this->context);
            CliLogger::activity();
        }

        // Log the completion of the brands process
        CliLogger::writeln("\nBrands done " . CliLogger::lap() . 'sec');
        $duplicates = null;
        $brands = null;

        UtilProfiling::stopTimer();
    }


    private function addToGroup($groups, $ids, $variants): array
    {
        $colorVariants = ($variants == 'color');
        $capacityVariants = ($variants == 'capacity');
        $groupExists = false;
        foreach ($groups as $key => $group) {
            foreach ($ids as $id) {
                if (in_array($id, $group['ids'])) {
                    $groupExists = true;
                    break;
                }
            }

            if ($groupExists) {
                $groups[$key]['ids'] = array_unique(array_merge($group['ids'], $ids));
                if ($colorVariants) {
                    $groups[$key]['color'] = true;
                }
                if ($capacityVariants) {
                    $groups[$key]['capacity'] = true;
                }

                return $groups;
            }
        }

        $groups[] = [
            'ids'              => $ids,
            'color'            => $colorVariants,
            'capacity'         => $capacityVariants,
            'referenceProduct' => false,
        ];

        return $groups;
    }

    private function collectColorVariants($groups): array
    {
        $query = <<<'SQL'
        SELECT LOWER(HEX(product_id)) as id,
        GROUP_CONCAT(LOWER(HEX(color_variant_product_id)) SEPARATOR ',') as variant_ids
        FROM `topdata_product_to_color_variant` 
        GROUP BY product_id
SQL;
        $rez = $this->connection->fetchAllAssociative($query);
        foreach ($rez as $row) {
            $ids = array_merge([$row['id']], explode(',', $row['variant_ids']));
            $groups = $this->addToGroup($groups, $ids, 'color');
        }

        return $groups;
    }

    private function collectCapacityVariants($groups): array
    {
        $query = <<<'SQL'
        SELECT LOWER(HEX(product_id)) as id,
        GROUP_CONCAT(LOWER(HEX(capacity_variant_product_id)) SEPARATOR ',') as variant_ids
        FROM `topdata_product_to_capacity_variant` 
        GROUP BY product_id
SQL;
        $rez = $this->connection->fetchAllAssociative($query);
        foreach ($rez as $row) {
            $ids = array_merge([$row['id']], explode(',', $row['variant_ids']));
            $groups = $this->addToGroup($groups, $ids, 'capacity');
        }

        return $groups;
    }

    private function countProductGroupHits($groups): array
    {
        $return = [];
        $allIds = [];
        foreach ($groups as $group) {
            $allIds = array_merge($allIds, $group['ids']);
        }

        $allIds = array_unique($allIds);
        foreach ($allIds as $id) {
            foreach ($groups as $group) {
                if (in_array($id, $group['ids'])) {
                    if (!isset($return[$id])) {
                        $return[$id] = 0;
                    }
                    $return[$id]++;
                }
            }
        }

        return $return;
    }

    private function mergeIntersectedGroups($groups): array
    {
        $return = [];
        foreach ($groups as $group) {
            $added = false;
            foreach ($return as $key => $g) {
                if (count(array_intersect($group['ids'], $g['ids']))) {
                    $return[$key]['ids'] = array_unique(array_merge($group['ids'], $g['ids']));
                    $return[$key]['color'] = $group['color'] || $g['color'];
                    $return[$key]['capacity'] = $group['capacity'] || $g['capacity'];
                    $added = true;
                    break;
                }
            }
            if (!$added) {
                $return[] = $group;
            }
        }

        return $return;
    }

    public function setProductColorCapacityVariants(): void
    {
        UtilProfiling::startTimer();
        CliLogger::writeln("\nBegin generating variated products based on color and capacity information (Import variants with other colors, Import variants with other capacities should be enabled in TopFeed plugin, product information should be already imported)");
        $groups = [];
        CliLogger::lap(true);
        $groups = $this->collectColorVariants($groups);
        //        echo "\nColor groups:".count($groups)."\n";
        $groups = $this->collectCapacityVariants($groups);
        //        echo "\nColor+capacity groups:".count($groups)."\n";
        $groups = $this->mergeIntersectedGroups($groups);
        CliLogger::writeln('Found ' . count($groups) . ' groups to generate variated products');

        $invalidProd = true;
        for ($i = 0; $i < count($groups); $i++) {
//            if ($this->optionsHelperService->getOption(OptionConstants::START) && ($i + 1 < $this->optionsHelperService->getOption(OptionConstants::START))) {
//                continue;
//            }
//
//            if ($this->optionsHelperService->getOption(OptionConstants::END) && ($i + 1 > $this->optionsHelperService->getOption(OptionConstants::END))) {
//                break;
//            }

            CliLogger::activity('Group ' . ($i + 1) . '...');

            //            print_r($groups[$i]);
            //            echo "\n";

            $criteria = new Criteria($groups[$i]['ids']);
            $criteria->addAssociations(['properties.group', 'visibilities', 'categories']);
            $products = $this
                ->productRepository
                ->search($criteria, $this->context)
                ->getEntities();

            if (count($products)) {
                $invalidProd = false;
                $parentId = null;
                foreach ($groups[$i]['ids'] as $productId) {
                    $product = $products->get($productId);
                    if (!$product) {
                        $invalidProd = true;
                        CliLogger::writeln("\nProduct id=$productId not found!");
                        break;
                    }

                    if ($product->getParentId()) {
                        if (is_null($parentId)) {
                            $parentId = $product->getParentId();
                        }
                        if ($parentId != $product->getParentId()) {
                            $invalidProd = true;
                            CliLogger::writeln("\nMany parent products error (last checked product id=$productId)!");
                            break;
                        }
                    }

                    if ($product->getChildCount() > 0) {
                        CliLogger::writeln("\nProduct id=$productId has childs!");
                        $invalidProd = true;
                        break;
                    }

                    $prodOptions = [];

                    foreach ($product->getProperties() as $property) {
                        if ($groups[$i]['color'] && in_array($property->getGroup()->getName(), self::COLOR_NAMES)) {
                            $prodOptions['colorId'] = $property->getId();
                            $prodOptions['colorGroupId'] = $property->getGroup()->getId();
                        }
                        if ($groups[$i]['capacity'] && in_array($property->getGroup()->getName(), self::CAPACITY_NAMES)) {
                            $prodOptions['capacityId'] = $property->getId();
                            $prodOptions['capacityGroupId'] = $property->getGroup()->getId();
                        }
                    }

                    /*
                     * @todo: Add product property Color=none or Capacity=none if needed but not exist
                     */

                    if (count($prodOptions)) {
                        if (!isset($groups[$i]['options'])) {
                            $groups[$i]['options'] = [];
                        }
                        $prodOptions['skip'] = (bool)($product->getParentId());
                        $groups[$i]['options'][$productId] = $prodOptions;
                    } else {
                        CliLogger::writeln("\nProduct id=$productId has no valid properties!");
                        $invalidProd = true;
                        break;
                    }

                    if (!$groups[$i]['referenceProduct']
                        &&
                        (
                            isset($groups[$i]['options'][$productId]['colorId'])
                            ||
                            isset($groups[$i]['options'][$productId]['capacityId'])
                        )
                    ) {
                        $groups[$i]['referenceProduct'] = $product;
                    }
                }
            }

            if ($invalidProd) {
                CliLogger::activity('Variated product for group will be skip, product ids: ');
                CliLogger::writeln(implode(', ', $groups[$i]['ids']));
            }

            if ($groups[$i]['referenceProduct'] && !$invalidProd) {
                $this->createVariatedProduct($groups[$i], $parentId);
            }
            CliLogger::writeln('done');
        }

        CliLogger::activity(CliLogger::lap() . 'sec ');
        CliLogger::mem();
        CliLogger::writeln('Generating variated products done');

        UtilProfiling::stopTimer();
    }


    private function createVariatedProduct($group, $parentId = null)
    {
        if (is_null($parentId)) {
            /** @var ProductEntity $refProd */
            $refProd = $group['referenceProduct'];
            $parentId = Uuid::randomHex();

            $visibilities = [];
            foreach ($refProd->getVisibilities() as $visibility) {
                $visibilities[] = [
                    'salesChannelId' => $visibility->getSalesChannelId(),
                    'visibility'     => $visibility->getVisibility(),
                ];
            }

            $categories = [];
            foreach ($refProd->getCategories() as $category) {
                $categories[] = [
                    'id' => $category->getId(),
                ];
            }

            $prod = [
                'id'               => $parentId,
                'productNumber'    => 'VAR-' . $refProd->getProductNumber(),
                'active'           => true,
                'taxId'            => $refProd->getTaxId(),
                'stock'            => $refProd->getStock(),
                'shippingFree'     => $refProd->getShippingFree(),
                'purchasePrice'    => 0.0,
                'displayInListing' => true,
                'name'             => [
                    $this->systemDefaultLocaleCode => 'VAR ' . $refProd->getName(),
                ],
                'price'            => [[
                    'net'        => 0.0,
                    'gross'      => 0.0,
                    'linked'     => true,
                    'currencyId' => Defaults::CURRENCY,
                ]],
            ];

            if ($refProd->getManufacturerId()) {
                $prod['manufacturer'] = [
                    'id' => $refProd->getManufacturerId(),
                ];
            }

            if ($visibilities) {
                $prod['visibilities'] = $visibilities;
            }

            if ($categories) {
                $prod['categories'] = $categories;
            }

            $this->productRepository->create([$prod], $this->context);
        } else {
            //delete configurator settings
            $this->connection->executeStatement('
                DELETE FROM product_configurator_setting
                WHERE product_id=0x' . $parentId);
        }

        $configuratorSettings = [];
        $optionGroupIds = [];
        $confOptions = [];
        $data = [];
        $productIdsToClearCrosses = [];

        //        echo "\n";
        //        $group['referenceProduct'] = true;
        //        print_r($group);
        //        echo "\n";

        foreach ($group['options'] as $prodId => $item) {
            $options = [];
            if (isset($item['colorId'])) {
                if (!$item['skip']) {
                    $options[] = [
                        'id' => $item['colorId'],
                    ];
                }
                $add = true;
                foreach ($confOptions as $opt) {
                    if ($opt['id'] == $item['colorId']) {
                        $add = false;
                        break;
                    }
                }
                if ($add) {
                    $confOptions[] = [
                        'id'    => $item['colorId'],
                        'group' => [
                            'id' => $item['colorGroupId'],
                        ],
                    ];
                    $optionGroupIds[] = $item['colorGroupId'];
                }
            }

            if (isset($item['capacityId'])) {
                if (!$item['skip']) {
                    $options[] = [
                        'id' => $item['capacityId'],
                    ];
                }

                $add = true;
                foreach ($confOptions as $opt) {
                    if ($opt['id'] == $item['capacityId']) {
                        $add = false;
                        break;
                    }
                }
                if ($add) {
                    $confOptions[] = [
                        'id'    => $item['capacityId'],
                        'group' => [
                            'id' => $item['capacityGroupId'],
                        ],
                    ];
                    $optionGroupIds[] = $item['capacityGroupId'];
                }
            }

            if (count($options)) {
                $data[] = [
                    'id'       => $prodId,
                    'options'  => $options,
                    'parentId' => $parentId,
                ];
                $productIdsToClearCrosses[] = $prodId;
            }
        }

        $configuratorGroupConfig = [];
        $optionGroupIds = array_unique($optionGroupIds);
        //        echo "\n";
        //        print_r($parentId.'='.count($optionGroupIds));
        //        echo "\n";
        foreach ($optionGroupIds as $groupId) {
            $configuratorGroupConfig[] = [
                'id'                    => $groupId,
                'expressionForListings' => true,
                'representation'        => 'box',
            ];
        }

        foreach ($confOptions as $confOpt) {
            $configuratorSettings[] = [
                'option' => $confOpt,
            ];
        }

        if ($configuratorSettings) {
            $data[] = [
                'id'                      => $parentId,
                'configuratorGroupConfig' => $configuratorGroupConfig ?: null,
                'configuratorSettings'    => $configuratorSettings,
            ];
        }

        if (count($data)) {
            $this->productRepository->update($data, $this->context);

            if (count($productIdsToClearCrosses)) {
                //delete crosses for variant products:
                $ids = '0x' . implode(',0x', $productIdsToClearCrosses);
                $this->connection->executeStatement('
                    DELETE FROM product_cross_selling
                    WHERE product_id IN (' . $ids . ')
                ');
            }
        }
    }


}
