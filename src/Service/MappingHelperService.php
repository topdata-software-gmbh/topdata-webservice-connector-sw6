<?php
/**
 * @author    Christoph Muskalla <muskalla@cm-s.eu>
 * @copyright 2019 CMS (http://www.cm-s.eu)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Exception;
use PDO;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\BatchSizeConstants;
use Topdata\TopdataConnectorSW6\Constants\WebserviceFilterTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilStringFormatting;
use Topdata\TopdataFoundationSW6\Service\LocaleHelperService;
use Topdata\TopdataFoundationSW6\Service\ManufacturerService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

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

    const IMAGE_PREFIX = 'td-';

    private ?array $brandWsArray = null; // aka mapWsIdToBrand

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
        private readonly LoggerInterface               $logger,
        private readonly Connection                    $connection,
        private readonly EntityRepository              $topdataBrandRepository,
        private readonly EntityRepository              $topdataDeviceRepository,
        private readonly EntityRepository              $topdataSeriesRepository,
        private readonly EntityRepository              $topdataDeviceTypeRepository,
        private readonly EntityRepository              $productRepository,
        private readonly ProductMappingService         $productMappingService,
        private readonly OptionsHelperService          $optionsHelperService,
        private readonly LocaleHelperService           $localeHelperService,
        private readonly TopdataToProductHelperService $topdataToProductHelperService,
        private readonly MediaHelperService            $mediaHelperService,
        private readonly TopdataDeviceService          $topdataDeviceService,
        private readonly TopdataWebserviceClient       $topdataWebserviceClient,
        private readonly TopdataSeriesService $topdataSeriesService,
        private readonly TopdataDeviceTypeService $topdataDeviceTypeService
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
     *
     * @return bool Returns true if the operation is successful, false otherwise.
     */
    public function setBrands(): bool
    {
        try {
            // Start the section for brands in the CLI output
            CliLogger::section("\n\nBrands");

            // Log the start of the data fetching process
            CliLogger::writeln('Getting data from remote server...');
            CliLogger::lap(true);

            // Fetch the brands from the remote server
            $brands = $this->topdataWebserviceClient->getBrands();
            CliLogger::activity('Got ' . count($brands->data) . " brands from remote server\n");
            ImportReport::setCounter('Fetched Brands', count($brands->data));
            $topdataBrandRepository = $this->topdataBrandRepository;

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
                $brand = $topdataBrandRepository
                    ->search(
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
                    $topdataBrandRepository->create($dataCreate, $this->context);
                    $dataCreate = [];
                    CliLogger::activity();
                }

                // Update existing brands in batches of 100
                if (count($dataUpdate) > 100) {
                    $topdataBrandRepository->update($dataUpdate, $this->context);
                    $dataUpdate = [];
                    CliLogger::activity();
                }
            }

            // Create any remaining new brands
            if (count($dataCreate)) {
                $topdataBrandRepository->create($dataCreate, $this->context);
                CliLogger::activity();
            }

            // Update any remaining existing brands
            if (count($dataUpdate)) {
                $topdataBrandRepository->update($dataUpdate, $this->context);
                CliLogger::activity();
            }

            // Log the completion of the brands process
            CliLogger::writeln("\nBrands done " . CliLogger::lap() . 'sec');
            $topdataBrandRepository = null;
            $duplicates = null;
            $brands = null;

            return true;
        } catch (Exception $e) {
            // Log any exceptions that occur
            $this->logger->error($e->getMessage());
            CliLogger::writeln('Exception abgefangen: ' . $e->getMessage());
        }

        return false;
    }

    public function setSeries()
    {
        try {
            CliLogger::section("\n\nSeries");
            CliLogger::writeln('Getting data from remote server...');
            CliLogger::lap(true);
            $series = $this->topdataWebserviceClient->getModelSeriesByBrandId();
            CliLogger::activity('Got ' . count($series->data) . " records from remote server\n");
            ImportReport::setCounter('Fetched Series', count($series->data));
            $topdataSeriesRepository = $this->topdataSeriesRepository;
            $dataCreate = [];
            $dataUpdate = [];
            CliLogger::activity('Processing data');
            $allSeries = $this->topdataSeriesService->getSeriesArray(true);
            foreach ($series->data as $s) {
                foreach ($s->brandIds as $brandWsId) {
                    $brand = $this->getBrandByWsIdArray((int)$brandWsId);
                    if (!$brand) {
                        continue;
                    }

                    $serie = false;
                    foreach ($allSeries as $seriesItem) {
                        if ($seriesItem['ws_id'] == $s->id && $seriesItem['brand_id'] == $brand['id']) {
                            $serie = $seriesItem;
                            break;
                        }
                    }

                    $code = $brand['code'] . '_' . $s->id . '_' . UtilStringFormatting::formCode($s->val);

                    if (!$serie) {
                        $dataCreate[] = [
                            'code'    => $code,
                            'brandId' => $brand['id'],
                            //or? 'brand' => $brand,
                            'label'   => $s->val,
                            'sort'    => (int)$s->top,
                            'wsId'    => (int)$s->id,
                            'enabled' => false,
                        ];
                    } elseif (
                        $serie['code'] != $code
                        || $serie['label'] != $s->val
                        || $serie['sort'] != (int)$s->top
                        || $serie['brand_id'] != $brand['id']
                    ) {
                        $dataUpdate[] = [
                            'id'      => $serie['id'],
                            'code'    => $code,
                            'brandId' => $brand['id'],
                            'label'   => $s->val,
                            'sort'    => (int)$s->top,
                        ];
                    }

                    if (count($dataCreate) > 100) {
                        $topdataSeriesRepository->create($dataCreate, $this->context);
                        $dataCreate = [];
                        CliLogger::activity();
                    }

                    if (count($dataUpdate) > 100) {
                        $topdataSeriesRepository->update($dataUpdate, $this->context);
                        $dataUpdate = [];
                        CliLogger::activity();
                    }
                }
            }

            if (count($dataCreate)) {
                $topdataSeriesRepository->create($dataCreate, $this->context);
                CliLogger::activity();
            }

            if (count($dataUpdate)) {
                $topdataSeriesRepository->update($dataUpdate, $this->context);
                CliLogger::activity();
            }
            CliLogger::writeln("\nSeries done " . CliLogger::lap() . 'sec');
            $series = null;
            $topdataSeriesRepository = null;

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            CliLogger::writeln('Exception abgefangen: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Sets the device types by fetching data from the remote server and updating the local database.
     *
     * This method retrieves device types from the remote server, processes the data, and updates the local database
     * by creating new entries or updating existing ones. It uses the `TopdataWebserviceClient` to fetch the data and
     * the `EntityRepository` to perform database operations.
     *
     * @return bool returns true if the operation is successful, false otherwise
     */
    public function setDeviceTypes()
    {
        try {
            // Start a new section in the CLI output for device types
            CliLogger::section("\n\nDevice type");

            // Log the activity of getting data from the remote server
            CliLogger::writeln('Getting data from remote server...');
            CliLogger::lap(true);

            // Fetch device types from the remote server
            $types = $this->topdataWebserviceClient->getModelTypeByBrandId();

            // Log the number of fetched device types
            ImportReport::setCounter('Fetched DeviceTypes', count($types->data));

            // Initialize the repository and data arrays
            $topdataDeviceTypeRepository = $this->topdataDeviceTypeRepository;
            $dataCreate = [];
            $dataUpdate = [];

            // Log the activity of processing data
            CliLogger::activity('Processing data...');

            // Get all existing types from the local database
            $allTypes = $this->topdataDeviceTypeService->getTypesArray(true);

            // Process each fetched device type
            foreach ($types->data as $s) {
                foreach ($s->brandIds as $brandWsId) {
                    // Get the brand by its web service ID
                    $brand = $this->getBrandByWsIdArray($brandWsId);
                    if (!$brand) {
                        continue;
                    }

                    // Check if the type already exists in the local database
                    $type = false;
                    foreach ($allTypes as $typeItem) {
                        if ($typeItem['ws_id'] == $s->id && $typeItem['brand_id'] == $brand['id']) {
                            $type = $typeItem;
                            break;
                        }
                    }

                    // Generate a unique code for the type
                    $code = $brand['code'] . '_' . $s->id . '_' . UtilStringFormatting::formCode($s->val);

                    // Prepare data for creating or updating the type
                    if (!$type) {
                        $dataCreate[] = [
                            'code'    => $code,
                            'brandId' => $brand['id'],
                            'label'   => $s->val,
                            'sort'    => (int)$s->top,
                            'wsId'    => (int)$s->id,
                            'enabled' => false,
                        ];
                    } elseif (
                        $type['label'] != $s->val
                        || $type['sort'] != (int)$s->top
                        || $type['brand_id'] != $brand['id']
                        || $type['code'] != $code
                    ) {
                        $dataUpdate[] = [
                            'id'      => $type['id'],
                            'code'    => $code,
                            'brandId' => $brand['id'],
                            'label'   => $s->val,
                            'sort'    => (int)$s->top,
                        ];
                    }

                    // Create new types in batches of 100
                    if (count($dataCreate) > 100) {
                        $topdataDeviceTypeRepository->create($dataCreate, $this->context);
                        $dataCreate = [];
                        CliLogger::activity();
                    }

                    // Update existing types in batches of 100
                    if (count($dataUpdate) > 100) {
                        $topdataDeviceTypeRepository->update($dataUpdate, $this->context);
                        $dataUpdate = [];
                        CliLogger::activity();
                    }
                }
            }

            // Create any remaining new types
            if (count($dataCreate)) {
                $topdataDeviceTypeRepository->create($dataCreate, $this->context);
                CliLogger::activity();
            }

            // Update any remaining existing types
            if (count($dataUpdate)) {
                $topdataDeviceTypeRepository->update($dataUpdate, $this->context);
                CliLogger::activity();
            }

            // Clear the fetched types data
            $types = null;

            // Log the completion of the device type processing
            CliLogger::writeln("\nDeviceType done " . CliLogger::lap() . 'sec');

            return true;
        } catch (Exception $e) {
            // Log any exceptions that occur during the process
            $this->logger->error($e->getMessage());
            CliLogger::writeln("\n" . 'Exception occured: ' . $e->getMessage() . '');
        }

        return false;
    }

    /**
     * this is called when --device or --device-only CLI options are set.
     */
    public function setDevices(): bool
    {
        try {
            $duplicates = [];
            $dataCreate = [];
            $dataUpdate = [];
            $updated = 0;
            $created = 0;
            $start = 0;
            $limit = 5000;
            $SQLlogger = $this->connection->getConfiguration()->getSQLLogger();
            $this->connection->getConfiguration()->setSQLLogger(null);
            CliLogger::section('Devices');
            CliLogger::writeln("Devices begin (Chunk size is $limit devices)");
            CliLogger::mem();
            CliLogger::writeln('');
            $functionTimeStart = microtime(true);
            $chunkNumber = 0;
            if ((int)$this->optionsHelperService->getOption(OptionConstants::START)) {
                $chunkNumber = (int)$this->optionsHelperService->getOption(OptionConstants::START) - 1;
                $start = $chunkNumber * $limit;
            }
            $repeat = true;
            CliLogger::lap(true);
            $seriesArray = $this->topdataSeriesService->getSeriesArray(true);
            $typesArray = $this->topdataDeviceTypeService->getTypesArray(true);
            while ($repeat) {
                if ($start) {
                    CliLogger::mem();
                    CliLogger::activity(CliLogger::lap() . 'sec');
                }
                $chunkNumber++;
                if ((int)$this->optionsHelperService->getOption(OptionConstants::END) && ($chunkNumber > (int)$this->optionsHelperService->getOption(OptionConstants::END))) {
                    break;
                }
                CliLogger::activity("\nGetting device chunk $chunkNumber from remote server...");
                ImportReport::incCounter('Device Chunks');
                $models = $this->topdataWebserviceClient->getModels($limit, $start);
                CliLogger::activity(CliLogger::lap() . "sec\n");
                if (!isset($models->data) || count($models->data) == 0) {
                    $repeat = false;
                    break;
                }
                CliLogger::activity("Processing data chunk $chunkNumber");
                $i = 1;
                foreach ($models->data as $s) {
                    $i++;
                    if ($i > 500) {
                        $i = 1;
                        CliLogger::activity();
                    }

                    $brandArr = $this->getBrandByWsIdArray((int)$s->bId);

                    if (!$brandArr) {
                        continue;
                    }

                    $code = $brandArr['code'] . '_' . UtilStringFormatting::formCode($s->val);

                    if (isset($duplicates[$code])) {
                        continue;
                    }
                    $duplicates[$code] = true;

                    $search_keywords = [];

                    $search_keywords[] = $brandArr['label']
                        . ' '
                        . $s->val
                        . ' '
                        . $brandArr['label'];

                    if (count(UtilStringFormatting::getWordsFromString($brandArr['label'])) > 1) {
                        $search_keywords[] = UtilStringFormatting::firstLetters($brandArr['label'])
                            . ' '
                            . $s->val
                            . ' '
                            . UtilStringFormatting::firstLetters($brandArr['label']);
                    }

                    $deviceArr = [];
                    $rez = $this
                        ->connection
                        ->createQueryBuilder()
                        ->select('*')
//                        ->select(['LOWER(HEX(id))','LOWER(HEX(brand_id))', 'LOWER(HEX(type_id))', 'LOWER(HEX(series_id))','code','model','keywords','sort','ws_id'])
                        ->from('topdata_device')
                        ->where('code="' . $code . '"')
                        ->setMaxResults(1)
                        ->execute()
                        ->fetchAllAssociative();

                    if (isset($rez[0])) {
                        $deviceArr = $rez[0];
                        $deviceArr['id'] = bin2hex($deviceArr['id']);
                        // brand
                        if (empty($deviceArr['brand_id'])) {
                            ImportReport::incCounter('Device Without Brand Id');
                            $deviceArr['brand_id'] = 0x0; // or null?
                        } else {
                            ImportReport::incCounter('Device With Brand Id');
                            $deviceArr['brand_id'] = bin2hex($deviceArr['brand_id']);
                        }
                        // type
                        if (empty($deviceArr['type_id'])) {
                            ImportReport::incCounter('Device Without Type Id');
                            $deviceArr['type_id'] = 0x0; // or null?
                        } else {
                            ImportReport::incCounter('Device With Type Id');
                            $deviceArr['type_id'] = bin2hex($deviceArr['type_id']);
                        }
                        // series
                        if (empty($deviceArr['series_id'])) {
                            ImportReport::incCounter('Device Without Series Id');
                            $deviceArr['series_id'] = 0x0; // or null?
                        } else {
                            ImportReport::incCounter('Device With Series Id');
                            $deviceArr['series_id'] = bin2hex($deviceArr['series_id']);
                        }
                    }

                    $serieId = null;
                    $serie = [];
                    if ($s->mId) {
                        foreach ($seriesArray as $serieItem) {
                            if ($serieItem['ws_id'] == (int)$s->mId && $serieItem['brand_id'] == $brandArr['id']) {
                                $serie = $serieItem;
                                break;
                            }
                        }
                    }
                    if ($serie) {
                        $serieId = $serie['id'];
                        $search_keywords[] = $serie['label'];
                    }

                    $typeId = null;
                    $type = [];
                    if ($s->dId) {
                        foreach ($typesArray as $typeItem) {
                            if ($typeItem['ws_id'] == (int)$s->dId && $typeItem['brand_id'] == $brandArr['id']) {
                                $type = $typeItem;
                                break;
                            }
                        }
                    }

                    if ($type) {
                        $typeId = $type['id'];
                        $search_keywords[] = $type['label'];
                    }

                    $keywords = $this->formSearchKeywords($search_keywords);

                    if (!$deviceArr) {
                        $dataCreate[] = [
                            'brandId'  => $brandArr['id'],
                            'typeId'   => $typeId,
                            'seriesId' => $serieId,
                            'code'     => $code,
                            'model'    => $s->val,
                            'keywords' => $keywords,
                            'sort'     => (int)$s->top,
                            'wsId'     => (int)$s->id,
                            'enabled'  => false,
                            'mediaId'  => null,
                        ];
                    } elseif (
                        $deviceArr['brand_id'] != $brandArr['id']
                        || $deviceArr['type_id'] != $typeId
                        || $deviceArr['series_id'] != $serieId
                        || $deviceArr['model'] != $s->val
                        || $deviceArr['keywords'] != $keywords
//                        || $deviceArr['sort'] != $s->top
                        || $deviceArr['ws_id'] != $s->id
                    ) {
                        $dataUpdate[] = [
                            'id'       => $deviceArr['id'],
                            'brandId'  => $brandArr['id'],
                            //                        'brandId' => $brand->getId(),
                            'typeId'   => $typeId,
                            'seriesId' => $serieId,
                            'model'    => $s->val,
                            'keywords' => $keywords,
                            //                            'sort' => (int)$s->top,
                            'wsId'     => (int)$s->id,
                            //                            'enabled' => false
                        ];
                    }

                    if (count($dataCreate) > 50) {
                        $created += count($dataCreate);
                        $this->topdataDeviceRepository->create($dataCreate, $this->context);
                        $dataCreate = [];
                        CliLogger::activity('+');
                    }

                    if (count($dataUpdate) > 50) {
                        $updated += count($dataUpdate);
                        $this->topdataDeviceRepository->update($dataUpdate, $this->context);
                        $dataUpdate = [];
                        CliLogger::activity('*');
                    }
                }
                if (count($dataCreate)) {
                    $created += count($dataCreate);
                    $this->topdataDeviceRepository->create($dataCreate, $this->context);
                    $dataCreate = [];
                    CliLogger::activity('+');
                }
                if (count($dataUpdate)) {
                    $updated += count($dataUpdate);
                    $this->topdataDeviceRepository->update($dataUpdate, $this->context);
                    $dataUpdate = [];
                    CliLogger::activity('*');
                }

                $start += $limit;
                if (count($models->data) < $limit) {
                    $repeat = false;
                    break;
                }
            }

            $models = null;
            $duplicates = null;
            CliLogger::writeln('');
            $totalSecs = microtime(true) - $functionTimeStart;

            CliLogger::getCliStyle()->dumpDict([
                'created'    => $created,
                'updated'    => $updated,
                'total time' => $totalSecs,
            ], 'Devices Report');

            $this->connection->getConfiguration()->setSQLLogger($SQLlogger);

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            CliLogger::error('Exception abgefangen: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Sets the device media by fetching data from the remote server and updating the local database.
     *
     * This method retrieves device media information from the remote server, processes the data, and updates the local database
     * by creating new entries or updating existing ones. It uses the `TopdataWebserviceClient` to fetch the data and
     * the `EntityRepository` to perform database operations.
     *
     * @return bool returns true if the operation is successful, false otherwise
     * @todo: add start/end chunk support, display chunk number, packet reading and packet writing for devices!
     *        display memory usage
     *
     * chunk 6 finished
     *
     */
    public function setDeviceMedia(): bool
    {
        // Log the start of the device media process
        CliLogger::writeln('Devices Media start');
        $this->brandWsArray = null;
        try {
            $topdataDeviceRepository = $this->topdataDeviceRepository; // TODO: remove this

            // Fetch enabled devices
            $available_Printers = [];
            foreach ($this->topdataDeviceService->_getEnabledDevices() as $pr) {
                $available_Printers[$pr['ws_id']] = true;
            }
            $availablePrintersCount = count($available_Printers);
            $processedPrintarsCount = 0;
            $limit = 5000;
            CliLogger::writeln("Chunk size is $limit devices");
            $start = 0;
            $chunkNumber = 0;
            if ((int)$this->optionsHelperService->getOption(OptionConstants::START)) {
                $chunkNumber = (int)$this->optionsHelperService->getOption(OptionConstants::START) - 1;
                $start = $chunkNumber * $limit;
            }
            CliLogger::lap(true);
            while (true) {
                $chunkNumber++;
                if ((int)$this->optionsHelperService->getOption(OptionConstants::END) && ($chunkNumber > (int)$this->optionsHelperService->getOption(OptionConstants::END))) {
                    break;
                }
                CliLogger::activity("\nGetting media chunk $chunkNumber from remote server...");
                ImportReport::incCounter('Device Media Chunks');
                $models = $this->topdataWebserviceClient->getModels($limit, $start);
                CliLogger::activity(CliLogger::lap() . 'sec. ');
                CliLogger::mem();
                CliLogger::writeln('');
                if (!isset($models->data) || count($models->data) == 0) {
                    break;
                }
                CliLogger::activity("Processing data chunk $chunkNumber");

                $processCounter = 1;
                foreach ($models->data as $s) {
                    if (!isset($available_Printers[$s->id])) {
                        continue;
                    }

                    $processedPrintarsCount++;

                    $processCounter++;
                    if ($processCounter >= 4) {
                        $processCounter = 1;
                        CliLogger::activity();
                    }

                    $brand = $this->getBrandByWsIdArray($s->bId);
                    if (!$brand) {
                        continue;
                    }

                    $code = $brand['code'] . '_' . UtilStringFormatting::formCode($s->val);
                    $device = $topdataDeviceRepository
                        ->search(
                            (new Criteria())
                                ->addFilter(new EqualsFilter('code', $code))
                                ->addAssociation('media')
                                ->setLimit(1),
                            $this->context
                        )
                        ->getEntities()
                        ->first();
                    if (!$device) {
                        continue;
                    }

                    $currentMedia = $device->getMedia();

                    // Delete media if the image is null
                    if (is_null($s->img) && $currentMedia) {
                        $topdataDeviceRepository->update([
                            [
                                'id'      => $device->getId(),
                                'mediaId' => null,
                            ],
                        ], $this->context);

                        /*
                         * @todo Use \Shopware\Core\Content\Media\DataAbstractionLayer\MediaRepositoryDecorator
                         * for deleting file physically?
                         */

                        continue;
                    }

                    if (is_null($s->img)) {
                        continue;
                    }

                    // Skip if the current media is newer than the fetched media
                    if ($currentMedia && (date_timestamp_get($currentMedia->getCreatedAt()) > strtotime($s->img_date))) {
                        continue;
                    }

                    $imageDate = strtotime(explode(' ', $s->img_date)[0]);

                    try {
                        $mediaId = $this->mediaHelperService->getMediaId($s->img, $imageDate, self::IMAGE_PREFIX);
                        if ($mediaId) {
                            $topdataDeviceRepository->update([
                                [
                                    'id'      => $device->getId(),
                                    'mediaId' => $mediaId,
                                ],
                            ], $this->context);
                        }
                    } catch (Exception $e) {
                        $this->logger->error($e->getMessage());
                        CliLogger::writeln('Exception: ' . $e->getMessage());
                    }
                }
                CliLogger::writeln("processed $processedPrintarsCount of $availablePrintersCount devices " . CliLogger::lap() . 'sec. ');
                $start += $limit;
                if (count($models->data) < $limit) {
                    $repeat = false;
                    break;
                }
            }

            CliLogger::writeln('');
            CliLogger::writeln('Devices Media done');

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            CliLogger::writeln('Exception: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * ==== MAIN ====
     *
     * The setProducts() method in the MappingHelperService class is responsible for linking devices to products. Here's a step-by-step breakdown of what it does:
     * It starts by disabling all devices, brands, series, and types in the database. This is done by setting the is_enabled field to 0 for each of these entities.
     * It unlinks all products by deleting all entries in the topdata_device_to_product table.
     * It retrieves all the product IDs from the topid_products array.
     * It then chunks these product IDs into groups of 100 and for each chunk, it does the following:
     * It makes a call to the remote server to get product data for the current chunk of product IDs.
     * It processes the returned product data. For each product, if it has associated devices, it adds these devices to the deviceWS array.
     * It then gets the device data for all the devices in the deviceWS array from the database.
     * For each device, it checks if the device's brand, series, and type are enabled. If not, it adds them to the respective arrays (enabledBrands, enabledSeries, enabledTypes).
     * It then checks if the device has associated products in the deviceWS array. If it does, it prepares data for inserting these associations into the topdata_device_to_product table.
     * It then inserts these associations into the topdata_device_to_product table in chunks of 30.
     * After all the associations have been inserted, it enables all the brands, series, and types that were added to the enabledBrands, enabledSeries, and enabledTypes arrays.
     * Finally, it returns true if everything went well, or false if an exception was thrown at any point.
     * This method is part of a larger process of syncing product and device data between a local database and a remote server. It ensures that the local database has up-to-date associations between products and the devices they are compatible with.
     */
    public function setProducts(): bool
    {
        //        $this->connection->beginTransaction();
        try {
            CliLogger::getCliStyle()->yellow('Devices to products linking begin');
            CliLogger::getCliStyle()->yellow('Disabling all devices, brands, series and types, unlinking products, caching products...');
            CliLogger::lap(true);

            // ---- disable all brands
            $cntA = $this->connection->createQueryBuilder()
                ->update('topdata_brand')
                ->set('is_enabled', '0')
                ->executeStatement();

            // ---- disable all devices
            $cntB = $this->connection->createQueryBuilder()
                ->update('topdata_device')
                ->set('is_enabled', '0')
                ->executeStatement();

            // ---- disable all series
            $cntC = $this->connection->createQueryBuilder()
                ->update('topdata_series')
                ->set('is_enabled', '0')
                ->executeStatement();

            // ---- disable all device types
            $cntD = $this->connection->createQueryBuilder()
                ->update('topdata_device_type')
                ->set('is_enabled', '0')
                ->executeStatement();


            // ---- delete all device-to-product relations
            $cntE = $this->connection->createQueryBuilder()
                ->delete('topdata_device_to_product')
                ->executeStatement();

            // ---- just info
            CliLogger::getCliStyle()->dumpDict([
                'disabled brands '            => $cntA,
                'disabled devices '           => $cntB,
                'disabled series '            => $cntC,
                'disabled device types '      => $cntD,
                'unlinked device-to-product ' => $cntE,
            ]);

            $topidProducts = $this->topdataToProductHelperService->getTopidProducts();

            CliLogger::activity(CliLogger::lap() . "sec\n");
            $enabledBrands = [];
            $enabledSeries = [];
            $enabledTypes = [];

            $topidsChunked = array_chunk(array_keys($topidProducts), 100);
            foreach ($topidsChunked as $idxChunk => $productIds) {

                // ---- fetch products from webservice
                CliLogger::writeln("Getting data from remote server part " . ($idxChunk + 1) . '/' . count($topidsChunked) . '...');
                $response = $this->topdataWebserviceClient->myProductList([
                    'products' => implode(',', $productIds),
                    'filter'   => WebserviceFilterTypeConstants::product_application_in,
                ]);
                CliLogger::activity(CliLogger::lap() . "sec\n");

                if (!isset($response->page->available_pages)) {
                    throw new Exception($response->error[0]->error_message . 'webservice no pages');
                }
                CliLogger::mem();
                CliLogger::activity("\nProcessing data of " . count($response->products) . " products ...");
                $deviceWS = [];
                foreach ($response->products as $product) {
                    if (!isset($topidProducts[$product->products_id])) {
                        continue;
                    }
                    if (isset($product->product_application_in->products) && count($product->product_application_in->products)) {
                        foreach ($product->product_application_in->products as $tid) {
                            foreach ($topidProducts[$product->products_id] as $tps) {
                                $deviceWS[$tid][] = $tps;
                            }
                        }
                    }
                }

                //                $deviceWS = [
                //                    123 = [
                //                        ['product_id' = 00ffcc, 'product_version_id' = 00ffc2],
                //                        ['product_id' = 00ffcc, 'product_version_id' = 00ffc2]
                //                    ],
                //                    1138 = [
                //                        ['product_id' = 00afcc, 'product_version_id' = 00afc2],
                //                        ['product_id' = 00bfcc, 'product_version_id' = 00bfc2]
                //                    ]
                //                ]

                /*
                 * Important!
                 * There could be many devices with same ws_id!!!
                 */

                $deviceIdsToEnable = array_keys($deviceWS);
                $devices = $this->getDeviceArrayByWsIdArray($deviceIdsToEnable);
                CliLogger::activity();
                if (!count($devices)) {
                    continue;
                }

                $chunkedDeviceIdsToEnable = array_chunk($deviceIdsToEnable, BatchSizeConstants::ENABLE_DEVICES);
                foreach ($chunkedDeviceIdsToEnable as $chunk) {
                    $sql = 'UPDATE topdata_device SET is_enabled = 1 WHERE (is_enabled = 0) AND (ws_id IN (' . implode(',', $chunk) . '))';
                    $cnt = $this->connection->executeStatement($sql);
                    CliLogger::getCliStyle()->blue("Enabled $cnt devices");
                    // \Topdata\TopdataFoundationSW6\Util\CliLogger::activity();
                }

                /* device_id, product_id, product_version_id, created_at */
                $insertData = [];
                $createdAt = date('Y-m-d H:i:s');

                foreach ($devices as $device) {
                    if ($device['brand_id'] && !isset($enabledBrands[$device['brand_id']])) {
                        $enabledBrands[$device['brand_id']] = '0x' . $device['brand_id'];
                    }

                    if ($device['series_id'] && !isset($enabledSeries[$device['series_id']])) {
                        $enabledSeries[$device['series_id']] = '0x' . $device['series_id'];
                    }

                    if ($device['type_id'] && !isset($enabledTypes[$device['type_id']])) {
                        $enabledTypes[$device['type_id']] = '0x' . $device['type_id'];
                    }

                    if (isset($deviceWS[$device['ws_id']])) {
                        foreach ($deviceWS[$device['ws_id']] as $prod) {
                            $insertData[] = "(0x{$device['id']}, 0x{$prod['product_id']}, 0x{$prod['product_version_id']}, '$createdAt')";
                        }
                    }
                }

                $insertDataChunks = array_chunk($insertData, 30);

                foreach ($insertDataChunks as $chunk) {
                    $this->connection->executeStatement('
                        INSERT INTO topdata_device_to_product (device_id, product_id, product_version_id, created_at) VALUES ' . implode(',', $chunk) . '
                    ');
                    CliLogger::activity();
                }

                CliLogger::activity(CliLogger::lap() . "sec\n");
                CliLogger::mem();
            }

            CliLogger::getCliStyle()->yellow('Activating brands, series and device types...');
            CliLogger::getCliStyle()->dumpDict([
                'enabledBrands' => count($enabledBrands),
                'enabledSeries' => count($enabledSeries),
                'enabledTypes'  => count($enabledTypes),

            ]);

            // ---- enable brands
            $ArraybrandIds = array_chunk($enabledBrands, BatchSizeConstants::ENABLE_BRANDS);
            foreach ($ArraybrandIds as $brandIds) {
                $cnt = $this->connection->executeStatement('
                    UPDATE topdata_brand SET is_enabled = 1 WHERE id IN (' . implode(',', $brandIds) . ')
                ');
                CliLogger::getCliStyle()->blue("Enabled $cnt brands");
                CliLogger::activity();
            }

            // ---- enable series
            $ArraySeriesIds = array_chunk($enabledSeries, BatchSizeConstants::ENABLE_SERIES);
            foreach ($ArraySeriesIds as $seriesIds) {
                $cnt = $this->connection->executeStatement('
                    UPDATE topdata_series SET is_enabled = 1 WHERE id IN (' . implode(',', $seriesIds) . ')
                ');
                CliLogger::getCliStyle()->blue("Enabled $cnt series");
                CliLogger::activity();
            }

            // ---- enable device types
            $ArrayTypeIds = array_chunk($enabledTypes, BatchSizeConstants::ENABLE_DEVICE_TYPES);
            foreach ($ArrayTypeIds as $typeIds) {
                $cnt = $this->connection->executeStatement('
                    UPDATE topdata_device_type SET is_enabled = 1 WHERE id IN (' . implode(',', $typeIds) . ')
                ');
                CliLogger::getCliStyle()->blue("Enabled $cnt types");
                CliLogger::activity();
            }
            CliLogger::activity(CliLogger::lap() . "sec\n");
            //            $this->connection->commit();
            CliLogger::writeln('Devices to products linking done.');

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            //            $this->connection->rollBack();
            CliLogger::error('Exception: ' . $e->getMessage());
        }

        return false;
    }


    private function getDeviceArrayByWsIdArray(array $wsIds): array
    {
        if (!count($wsIds)) {
            return [];
        }
        $result = []; // a list of devices

        // $this->brandWsArray = []; // FIXME: why is this here?
        $queryRez = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('topdata_device')
            ->where('ws_id IN (' . implode(',', $wsIds) . ')')
            ->execute()
            ->fetchAllAssociative();
        foreach ($queryRez as $device) {
            $device['id'] = bin2hex($device['id']);
            $device['brand_id'] = bin2hex($device['brand_id'] ?? '');
            $device['type_id'] = bin2hex($device['type_id'] ?? '');
            $device['series_id'] = bin2hex($device['series_id'] ?? '');
            $result[] = $device;
        }

        return $result;
    }

    private function getBrandByWsIdArray(int $brandWsId): array
    {
        if ($this->brandWsArray === null) {
            $this->brandWsArray = [];
            $query = $this->connection->createQueryBuilder();
            $rez = $query
                ->select(['id', 'code', 'label', 'ws_id'])
                ->from('topdata_brand')
                ->execute()
                ->fetchAllAssociative();
            foreach ($rez as $brand) {
                $brand['id'] = bin2hex($brand['id']);
                $this->brandWsArray[$brand['ws_id']] = $brand;
            }
        }

        return isset($this->brandWsArray[$brandWsId]) ? $this->brandWsArray[$brandWsId] : [];
    }



    private function formSearchKeywords(array $keywords): string
    {
        $result = [];
        foreach ($keywords as $keyword) {
            $temp = mb_strtolower(trim($keyword));
            $result[] = $temp;
            $result[] = str_replace(['_', '/', '-', ' ', '.'], '', $temp);
            $result[] = trim(preg_replace('/\s+/', ' ', str_replace(['_', '/', '-', '.'], ' ', $temp)));
        }

        return mb_substr(implode(' ', array_unique($result)), 0, 250);
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

    public function setProductColorCapacityVariants(): bool
    {
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
            if ($this->optionsHelperService->getOption(OptionConstants::START) && ($i + 1 < $this->optionsHelperService->getOption(OptionConstants::START))) {
                continue;
            }

            if ($this->optionsHelperService->getOption(OptionConstants::END) && ($i + 1 > $this->optionsHelperService->getOption(OptionConstants::END))) {
                break;
            }

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

        return true;
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
