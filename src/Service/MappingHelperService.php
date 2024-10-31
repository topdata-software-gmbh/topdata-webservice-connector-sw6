<?php
/**
 * @author    Christoph Muskalla <muskalla@cm-s.eu>
 * @copyright 2019 CMS (http://www.cm-s.eu)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Command\ProductsCommand;
use Topdata\TopdataConnectorSW6\Constants\BatchSizeConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\Helper\CliStyle;
use Topdata\TopdataConnectorSW6\Helper\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\ImportReport;

/**
 * MappingHelperService class.
 *
 * This class is responsible for mapping and synchronizing data between Topdata and Shopware 6.
 * It handles various operations such as product mapping, device synchronization, and cross-selling setup.
 *
 * TODO: This class is quite large and should be refactored into smaller, more focused classes.
 *
 * 04/2024 Renamed from MappingHelper to MappingHelperService
 */
class MappingHelperService
{
    /**
     * Constants for cross-selling types.
     */
    const CROSS_SIMILAR          = 'similar';
    const CROSS_ALTERNATE        = 'alternate';
    const CROSS_RELATED          = 'related';
    const CROSS_BUNDLED          = 'bundled';
    const CROSS_COLOR_VARIANT    = 'colorVariant';
    const CROSS_CAPACITY_VARIANT = 'capacityVariant';
    const CROSS_VARIANT          = 'variant';

    /**
     * List of specifications to ignore during import.
     */
    const IGNORE_SPECS = [
        21  => 'Hersteller-Nr. (intern)',
        24  => 'Product Code (PCD) Intern',
        32  => 'Kurzbeschreibung',
        573 => 'Kurzbeschreibung (statisch)',
        583 => 'Beschreibung (statisch)',
        293 => 'Gattungsbegriff 1',
        294 => 'Gattungsbegriff 2',
        295 => 'Gattungsbegriff 3',
        299 => 'Originalprodukt (J/N)',
        307 => 'Hersteller-Nr. (alt)',
        308 => 'Hersteller-Nr. (Alternative)',
        311 => 'Fake Eintrag',
        340 => 'Automatisch gematched',
        341 => 'Security Code System',
        361 => 'Produktart (Überkompatibilität)',
        367 => 'Product Code (PCD) Alternative',
        368 => 'Produktcode (PCD) alt',
        371 => 'EAN/GTIN 08 (alt)',
        391 => 'MPS Ready',
        22  => 'EAN/GTIN-13 (intern)',
        23  => 'EAN/GTIN-08 (intern)',
        370 => 'EAN/GTIN 13 (alt)',
        372 => 'EAN/GTIN-13 (Alternative)',
        373 => 'EAN/GTIN-08 (Alternative)',
        26  => 'eCl@ss v6.1.0',
        28  => 'unspsc 111201',
        331 => 'eCl@ss v5.1.4',
        332 => 'eCl@ss v6.2.0',
        333 => 'eCl@ss v7.0.0',
        334 => 'eCl@ss v7.1.0',
        335 => 'eCl@ss v8.0.0',
        336 => 'eCl@ss v8.1.0',
        337 => 'eCl@ss v9.0.0',
        721 => 'eCl@ss v9.1.0',
        34  => 'Gruppe Pelikan',
        35  => 'Gruppe Carma',
        36  => 'Gruppe Reuter',
        37  => 'Gruppe Kores',
        38  => 'Gruppe DK',
        39  => 'Gruppe Pelikan (falsch)',
        40  => 'Gruppe USA (Druckwerk)',
        122 => 'Druckwerk',
        8   => 'Leergut',
        30  => 'Marketingtext',
    ];

    private array $productImportSettings = [];
    private ?array $brandWsArray = null; // aka mapWsIdToBrand
    private ?array $seriesArray = null;
    private ?array $typesArray = null;
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
    private ?array $topidProducts = null;
    private TopdataWebserviceClient $topdataWebserviceClient;
    private bool $verbose = false;
    private Context $context;
    private string $systemDefaultLocaleCode;
    private CliStyle $cliStyle;


    public function __construct(
        private readonly LoggerInterface        $logger,
        private readonly Connection             $connection,
        private readonly EntityRepository       $topdataBrandRepository,
        private readonly EntityRepository       $topdataDeviceRepository,
        private readonly EntityRepository       $topdataSeriesRepository,
        private readonly EntityRepository       $topdataDeviceTypeRepository,
        private readonly EntityRepository       $productRepository,
        private readonly ProductsCommand        $productCommand, // TODO: remove this dependency
        private readonly EntitiesHelperService  $entitiesHelperService,
        private readonly EntityRepository       $productCrossSellingRepository,
        private readonly EntityRepository       $productCrossSellingAssignedProductsRepository,
        private readonly ProductMappingService  $productMappingService,
        private readonly OptionsHelperService   $optionsHelperService,
        private readonly ProgressLoggingService $progressLoggingService,
        private readonly LocaleHelperService    $localeHelperService,
    )
    {
        $this->systemDefaultLocaleCode = $this->localeHelperService->getLocaleCodeOfSystemLanguage();
        $this->context = Context::createDefaultContext();
    }


    /**
     * Set the Topdata webservice client.
     *
     * @param TopdataWebserviceClient $topDataApi The webservice client
     */
    public function setTopdataWebserviceClient(TopdataWebserviceClient $topDataApi): void
    {
        $this->topdataWebserviceClient = $topDataApi;
    }

    /**
     * Set the verbose mode.
     *
     * @param bool $verbose Whether to enable verbose mode
     */
    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
        $this->progressLoggingService->setVerbose($verbose);
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

    /**
     * it populates $this->topidProducts once, unless $forceReload is true.
     */
    private function _fetchTopidProducts(bool $forceReload = false): array
    {
        if (null === $this->topidProducts || $forceReload) {
            $rows = $this->connection->fetchAllAssociative('
                SELECT 
                    topdata_to_product.top_data_id, 
                    LOWER(HEX(topdata_to_product.product_id)) as product_id, 
                    LOWER(HEX(topdata_to_product.product_version_id)) as product_version_id, 
                    LOWER(HEX(product.parent_id)) as parent_id 
                FROM `topdata_to_product`, product 
                WHERE topdata_to_product.product_id = product.id 
                    AND topdata_to_product.product_version_id = product.version_id 
                ORDER BY topdata_to_product.top_data_id
            ');

            $this->cliStyle->info('_fetchTopidProducts :: fetched ' . count($rows) . ' products');

            $this->topidProducts = [];
            foreach ($rows as $row) {
                $this->topidProducts[$row['top_data_id']][] = [
                    'product_id'         => $row['product_id'],
                    'product_version_id' => $row['product_version_id'],
                    'parent_id'          => $row['parent_id'],
                ];
            }
        }

        return $this->topidProducts;
    }

    private function _getEnabledDevices(): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['*'])
            ->from('topdata_device')
            ->where('is_enabled = 1');

        return $query->execute()->fetchAllAssociative();
    }

    private function getTopdataCategory()
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['categoryID', 'top_data_ws_id'])
            ->from('s_categories_attributes')
            ->where('top_data_ws_id != \'0\'')
            ->andWhere('top_data_ws_id != \'\'')
            ->andWhere('top_data_ws_id is not null');

        return $query->execute()->fetchAllAssociative(\PDO::FETCH_KEY_PAIR);
    }

    /**
     * This is executed if --mapping option is set.
     */
    public function mapProducts(): bool
    {
        $this->productMappingService->setCliStyle($this->cliStyle);
        $this->productMappingService->setTopdataWebserviceClient($this->topdataWebserviceClient);

        return $this->productMappingService->mapProducts();
    }

    public function setBrands(): bool
    {
        try {
            $this->cliStyle->section("\n\nBrands");
            $this->progressLoggingService->writeln('Getting data from remote server...');
            $this->progressLoggingService->lap(true);
            $brands = $this->topdataWebserviceClient->getBrands();
            $this->progressLoggingService->activity('Got ' . count($brands->data) . " brands from remote server\n");
            ImportReport::setCounter('Fetched Brands', count($brands->data));
            $topdataBrandRepository = $this->topdataBrandRepository;

            $duplicates = [];
            $dataCreate = [];
            $dataUpdate = [];
            $this->progressLoggingService->activity('Processing data');
            foreach ($brands->data as $b) {
                if ($b->main == 0) {
                    continue;
                }

                $code = self::formCode($b->val);
                if (isset($duplicates[$code])) {
                    continue;
                }
                $duplicates[$code] = true;

                $brand = $topdataBrandRepository
                    ->search(
                        (new Criteria())->addFilter(new EqualsFilter('code', $code))->setLimit(1),
                        $this->context
                    )
                    ->getEntities()
                    ->first();

                if (!$brand) {
                    $dataCreate[] = [
                        'code'    => $code,
                        'name'    => $b->val,
                        'enabled' => false,
                        'sort'    => (int)$b->top,
                        'wsId'    => (int)$b->id,
                    ];
                } elseif (
                    $brand->getName() != $b->val ||
                    $brand->getSort() != $b->top ||
                    $brand->getWsId() != $b->id
                ) {
                    $dataUpdate[] = [
                        'id'   => $brand->getId(),
                        'name' => $b->val,
                        //                        'sort' => (int)$b->top,
                        'wsId' => (int)$b->id,
                    ];
                }

                if (count($dataCreate) > 100) {
                    $topdataBrandRepository->create($dataCreate, $this->context);
                    $dataCreate = [];
                    $this->progressLoggingService->activity();
                }

                if (count($dataUpdate) > 100) {
                    $topdataBrandRepository->update($dataUpdate, $this->context);
                    $dataUpdate = [];
                    $this->progressLoggingService->activity();
                }
            }

            if (count($dataCreate)) {
                $topdataBrandRepository->create($dataCreate, $this->context);
                $this->progressLoggingService->activity();
            }

            if (count($dataUpdate)) {
                $topdataBrandRepository->update($dataUpdate, $this->context);
                $this->progressLoggingService->activity();
            }
            $this->progressLoggingService->writeln("\nBrands done " . $this->progressLoggingService->lap() . 'sec');
            $topdataBrandRepository = null;
            $duplicates = null;
            $brands = null;

            return true;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $this->progressLoggingService->writeln('Exception abgefangen: ' . $e->getMessage());
        }

        return false;
    }

    public function setSeries()
    {
        try {
            $this->cliStyle->section("\n\nSeries");
            $this->progressLoggingService->writeln('Getting data from remote server...');
            $this->progressLoggingService->lap(true);
            $series = $this->topdataWebserviceClient->getModelSeriesByBrandId();
            $this->progressLoggingService->activity('Got ' . count($series->data) . " records from remote server\n");
            ImportReport::setCounter('Fetched Series', count($series->data));
            $topdataSeriesRepository = $this->topdataSeriesRepository;
            $dataCreate = [];
            $dataUpdate = [];
            $this->progressLoggingService->activity('Processing data');
            $allSeries = $this->getSeriesArray(true);
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

                    $code = $brand['code'] . '_' . $s->id . '_' . self::formCode($s->val);

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
                        $this->progressLoggingService->activity();
                    }

                    if (count($dataUpdate) > 100) {
                        $topdataSeriesRepository->update($dataUpdate, $this->context);
                        $dataUpdate = [];
                        $this->progressLoggingService->activity();
                    }
                }
            }

            if (count($dataCreate)) {
                $topdataSeriesRepository->create($dataCreate, $this->context);
                $this->progressLoggingService->activity();
            }

            if (count($dataUpdate)) {
                $topdataSeriesRepository->update($dataUpdate, $this->context);
                $this->progressLoggingService->activity();
            }
            $this->progressLoggingService->writeln("\nSeries done " . $this->progressLoggingService->lap() . 'sec');
            $series = null;
            $topdataSeriesRepository = null;

            return true;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $this->progressLoggingService->writeln('Exception abgefangen: ' . $e->getMessage());
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
            $this->cliStyle->section("\n\nDevice type");

            // Log the activity of getting data from the remote server
            $this->progressLoggingService->writeln('Getting data from remote server...');
            $this->progressLoggingService->lap(true);

            // Fetch device types from the remote server
            $types = $this->topdataWebserviceClient->getModelTypeByBrandId();

            // Log the number of fetched device types
            ImportReport::setCounter('Fetched DeviceTypes', count($types->data));

            // Initialize the repository and data arrays
            $topdataDeviceTypeRepository = $this->topdataDeviceTypeRepository;
            $dataCreate = [];
            $dataUpdate = [];

            // Log the activity of processing data
            $this->progressLoggingService->activity('Processing data...');

            // Get all existing types from the local database
            $allTypes = $this->getTypesArray(true);

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
                    $code = $brand['code'] . '_' . $s->id . '_' . self::formCode($s->val);

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
                        $this->progressLoggingService->activity();
                    }

                    // Update existing types in batches of 100
                    if (count($dataUpdate) > 100) {
                        $topdataDeviceTypeRepository->update($dataUpdate, $this->context);
                        $dataUpdate = [];
                        $this->progressLoggingService->activity();
                    }
                }
            }

            // Create any remaining new types
            if (count($dataCreate)) {
                $topdataDeviceTypeRepository->create($dataCreate, $this->context);
                $this->progressLoggingService->activity();
            }

            // Update any remaining existing types
            if (count($dataUpdate)) {
                $topdataDeviceTypeRepository->update($dataUpdate, $this->context);
                $this->progressLoggingService->activity();
            }

            // Clear the fetched types data
            $types = null;

            // Log the completion of the device type processing
            $this->progressLoggingService->writeln("\nDeviceType done " . $this->progressLoggingService->lap() . 'sec');

            return true;
        } catch (\Exception $e) {
            // Log any exceptions that occur during the process
            $this->logger->error($e->getMessage());
            $this->progressLoggingService->writeln("\n" . 'Exception occured: ' . $e->getMessage() . '');
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
            $this->cliStyle->section('Devices');
            $this->progressLoggingService->writeln("Devices begin (Chunk size is $limit devices)");
            $this->progressLoggingService->mem();
            $this->progressLoggingService->writeln('');
            $functionTimeStart = microtime(true);
            $chunkNumber = 0;
            if ((int)$this->optionsHelperService->getOption(OptionConstants::START)) {
                $chunkNumber = (int)$this->optionsHelperService->getOption(OptionConstants::START) - 1;
                $start = $chunkNumber * $limit;
            }
            $repeat = true;
            $this->progressLoggingService->lap(true);
            $seriesArray = $this->getSeriesArray(true);
            $typesArray = $this->getTypesArray(true);
            while ($repeat) {
                if ($start) {
                    $this->progressLoggingService->mem();
                    $this->progressLoggingService->activity($this->progressLoggingService->lap() . 'sec');
                }
                $chunkNumber++;
                if ((int)$this->optionsHelperService->getOption(OptionConstants::END) && ($chunkNumber > (int)$this->optionsHelperService->getOption(OptionConstants::END))) {
                    break;
                }
                $this->progressLoggingService->activity("\nGetting data chunk $chunkNumber from remote server...");
                ImportReport::incCounter('Device Chunks');
                $models = $this->topdataWebserviceClient->getModels($limit, $start);
                $this->progressLoggingService->activity($this->progressLoggingService->lap() . "sec\n");
                if (!isset($models->data) || count($models->data) == 0) {
                    $repeat = false;
                    break;
                }
                $this->progressLoggingService->activity("Processing data chunk $chunkNumber");
                $i = 1;
                foreach ($models->data as $s) {
                    $i++;
                    if ($i > 500) {
                        $i = 1;
                        $this->progressLoggingService->activity();
                    }

                    $brandArr = $this->getBrandByWsIdArray((int)$s->bId);

                    if (!$brandArr) {
                        continue;
                    }

                    $code = $brandArr['code'] . '_' . self::formCode($s->val);

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

                    if (count($this->getWordsFromString($brandArr['label'])) > 1) {
                        $search_keywords[] = $this->firstLetters($brandArr['label'])
                            . ' '
                            . $s->val
                            . ' '
                            . $this->firstLetters($brandArr['label']);
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
                        $this->progressLoggingService->activity('+');
                    }

                    if (count($dataUpdate) > 50) {
                        $updated += count($dataUpdate);
                        $this->topdataDeviceRepository->update($dataUpdate, $this->context);
                        $dataUpdate = [];
                        $this->progressLoggingService->activity('*');
                    }
                }
                if (count($dataCreate)) {
                    $created += count($dataCreate);
                    $this->topdataDeviceRepository->create($dataCreate, $this->context);
                    $dataCreate = [];
                    $this->progressLoggingService->activity('+');
                }
                if (count($dataUpdate)) {
                    $updated += count($dataUpdate);
                    $this->topdataDeviceRepository->update($dataUpdate, $this->context);
                    $dataUpdate = [];
                    $this->progressLoggingService->activity('*');
                }

                $start += $limit;
                if (count($models->data) < $limit) {
                    $repeat = false;
                    break;
                }
            }

            $models = null;
            $duplicates = null;
            $this->progressLoggingService->writeln('');
            $totalSecs = microtime(true) - $functionTimeStart;

            $this->cliStyle->dumpDict([
                'created'    => $created,
                'updated'    => $updated,
                'total time' => $totalSecs,
            ], 'Devices Report');

            $this->connection->getConfiguration()->setSQLLogger($SQLlogger);

            return true;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $this->cliStyle->error('Exception abgefangen: ' . $e->getMessage());
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
        $this->progressLoggingService->writeln('Devices Media start');
        $this->brandWsArray = null;
        try {
            $topdataDeviceRepository = $this->topdataDeviceRepository; // TODO: remove this

            // Fetch enabled devices
            $available_Printers = [];
            foreach ($this->_getEnabledDevices() as $pr) {
                $available_Printers[$pr['ws_id']] = true;
            }
            $availablePrintersCount = count($available_Printers);
            $processedPrintarsCount = 0;
            $limit = 5000;
            $this->progressLoggingService->writeln("Chunk size is $limit devices");
            $start = 0;
            $chunkNumber = 0;
            if ((int)$this->optionsHelperService->getOption(OptionConstants::START)) {
                $chunkNumber = (int)$this->optionsHelperService->getOption(OptionConstants::START) - 1;
                $start = $chunkNumber * $limit;
            }
            $this->progressLoggingService->lap(true);
            while (true) {
                $chunkNumber++;
                if ((int)$this->optionsHelperService->getOption(OptionConstants::END) && ($chunkNumber > (int)$this->optionsHelperService->getOption(OptionConstants::END))) {
                    break;
                }
                $this->progressLoggingService->activity("\nGetting data chunk $chunkNumber from remote server...");
                ImportReport::incCounter('Device Media Chunks');
                $models = $this->topdataWebserviceClient->getModels($limit, $start);
                $this->progressLoggingService->activity($this->progressLoggingService->lap() . 'sec. ');
                $this->progressLoggingService->mem();
                $this->progressLoggingService->writeln('');
                if (!isset($models->data) || count($models->data) == 0) {
                    break;
                }
                $this->progressLoggingService->activity("Processing data chunk $chunkNumber");

                $processCounter = 1;
                foreach ($models->data as $s) {
                    if (!isset($available_Printers[$s->id])) {
                        continue;
                    }

                    $processedPrintarsCount++;

                    $processCounter++;
                    if ($processCounter >= 4) {
                        $processCounter = 1;
                        $this->progressLoggingService->activity();
                    }

                    $brand = $this->getBrandByWsIdArray($s->bId);
                    if (!$brand) {
                        continue;
                    }

                    $code = $brand['code'] . '_' . self::formCode($s->val);
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
                        $mediaId = $this->entitiesHelperService->getMediaId($s->img, $imageDate, 'td-');
                        if ($mediaId) {
                            $topdataDeviceRepository->update([
                                [
                                    'id'      => $device->getId(),
                                    'mediaId' => $mediaId,
                                ],
                            ], $this->context);
                        }
                    } catch (\Exception $e) {
                        $this->logger->error($e->getMessage());
                        $this->progressLoggingService->writeln('Exception: ' . $e->getMessage());
                    }
                }
                $this->progressLoggingService->writeln("processed $processedPrintarsCount of $availablePrintersCount devices " . $this->progressLoggingService->lap() . 'sec. ');
                $start += $limit;
                if (count($models->data) < $limit) {
                    $repeat = false;
                    break;
                }
            }

            $this->progressLoggingService->writeln('');
            $this->progressLoggingService->writeln('Devices Media done');

            return true;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $this->progressLoggingService->writeln('Exception: ' . $e->getMessage());
        }

        return false;
    }

    /**
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
            $this->cliStyle->yellow('Devices to products linking begin');
            $this->cliStyle->yellow('Disabling all devices, brands, series and types, unlinking products, caching products...');
            $this->progressLoggingService->lap(true);
            $cntA = $this->connection->createQueryBuilder()
                ->update('topdata_brand')
                ->set('is_enabled', '0')
                ->executeStatement();

            $cntB = $this->connection->createQueryBuilder()
                ->update('topdata_device')
                ->set('is_enabled', '0')
                ->executeStatement();

            $cntC = $this->connection->createQueryBuilder()
                ->update('topdata_series')
                ->set('is_enabled', '0')
                ->executeStatement();

            $cntD = $this->connection->createQueryBuilder()
                ->update('topdata_device_type')
                ->set('is_enabled', '0')
                ->executeStatement();

            $cntE = $this->connection->createQueryBuilder()
                ->delete('topdata_device_to_product')
                ->executeStatement();

            // ---- just info
            $this->cliStyle->dumpDict([
                'disabled brands '            => $cntA,
                'disabled devices '           => $cntB,
                'disabled series '            => $cntC,
                'disabled device types '      => $cntD,
                'unlinked device-to-product ' => $cntE,
            ]);

            $topidProducts = $this->_fetchTopidProducts();
            if (empty($topidProducts)) {
                // Select how you want our articles to be linked to our product database (mapping) *1
                $this->cliStyle->warning('No mapped products found in database. Did you set the correct mapping in plugin config?');
            }

            $this->progressLoggingService->activity($this->progressLoggingService->lap() . "sec\n");
            $enabledBrands = [];
            $enabledSeries = [];
            $enabledTypes = [];

            $topids = array_chunk(array_keys($topidProducts), 100);
            foreach ($topids as $k => $prs) {
                $this->progressLoggingService->activity("\nGetting data from remote server part " . ($k + 1) . '/' . count($topids) . '...');
                $products = $this->topdataWebserviceClient->myProductList([
                    'products' => implode(',', $prs),
                    'filter'   => 'product_application_in',
                ]);
                $this->progressLoggingService->activity($this->progressLoggingService->lap() . "sec\n");

                if (!isset($products->page->available_pages)) {
                    throw new \Exception($products->error[0]->error_message . 'webservice no pages');
                }
                $this->progressLoggingService->mem();
                $this->progressLoggingService->activity("\nProcessing data...");
                $deviceWS = [];
                foreach ($products->products as $product) {
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
                $this->progressLoggingService->activity();
                if (!count($devices)) {
                    continue;
                }

                $chunkedDeviceIdsToEnable = array_chunk($deviceIdsToEnable, BatchSizeConstants::ENABLE_DEVICES);
                foreach ($chunkedDeviceIdsToEnable as $chunk) {
                    $sql = 'UPDATE topdata_device SET is_enabled = 1 WHERE (is_enabled = 0) AND (ws_id IN (' . implode(',', $chunk) . '))';
                    $cnt = $this->connection->executeStatement($sql);
                    $this->cliStyle->blue("Enabled $cnt devices");
                    // $this->progressLoggingService->activity();
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
                    $this->progressLoggingService->activity();
                }

                $this->progressLoggingService->activity($this->progressLoggingService->lap() . "sec\n");
                $this->progressLoggingService->mem();
            }

            $this->cliStyle->yellow('Activating brands, series and device types...');
            $this->cliStyle->dumpDict([
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
                $this->cliStyle->blue("Enabled $cnt brands");
                $this->progressLoggingService->activity();
            }

            // ---- enable series
            $ArraySeriesIds = array_chunk($enabledSeries, BatchSizeConstants::ENABLE_SERIES);
            foreach ($ArraySeriesIds as $seriesIds) {
                $cnt = $this->connection->executeStatement('
                    UPDATE topdata_series SET is_enabled = 1 WHERE id IN (' . implode(',', $seriesIds) . ')
                ');
                $this->cliStyle->blue("Enabled $cnt series");
                $this->progressLoggingService->activity();
            }

            // ---- enable device types
            $ArrayTypeIds = array_chunk($enabledTypes, BatchSizeConstants::ENABLE_DEVICE_TYPES);
            foreach ($ArrayTypeIds as $typeIds) {
                $cnt = $this->connection->executeStatement('
                    UPDATE topdata_device_type SET is_enabled = 1 WHERE id IN (' . implode(',', $typeIds) . ')
                ');
                $this->cliStyle->blue("Enabled $cnt types");
                $this->progressLoggingService->activity();
            }
            $this->progressLoggingService->activity($this->progressLoggingService->lap() . "sec\n");
            //            $this->connection->commit();
            $this->progressLoggingService->writeln('Devices to products linking done.');

            return true;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            //            $this->connection->rollBack();
            $this->cliStyle->error('Exception: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * 06/2024 made it static.
     */
    private static function formCode(string $label): string
    {
        $replacement = [
            ' ' => '-',
        ];

        return strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', str_replace(array_keys($replacement), array_values($replacement), $label)));
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

    private function getSeriesArray($forceReload = false): array
    {
        if ($this->seriesArray === null || $forceReload) {
            $this->seriesArray = [];
            $results = $this
                ->connection
                ->createQueryBuilder()
                ->select('*')
//                ->select(['id','code', 'label', 'brand_id', 'ws_id'])
                ->from('topdata_series')
                ->execute()
                ->fetchAllAssociative();
            foreach ($results as $r) {
                $this->seriesArray[bin2hex($r['id'])] = $r;
                $this->seriesArray[bin2hex($r['id'])]['id'] = bin2hex($r['id']);
                $this->seriesArray[bin2hex($r['id'])]['brand_id'] = bin2hex($r['brand_id']);
            }
        }

        return $this->seriesArray;
    }

    private function getTypesArray($forceReload = false): array
    {
        if ($this->typesArray === null || $forceReload) {
            $this->typesArray = [];
            $results = $this
                ->connection
                ->createQueryBuilder()
                ->select('*')
//                ->select(['id','code', 'label', 'brand_id', 'ws_id'])
                ->from('topdata_device_type')
                ->execute()
                ->fetchAllAssociative();
            foreach ($results as $r) {
                $this->typesArray[bin2hex($r['id'])] = $r;
                $this->typesArray[bin2hex($r['id'])]['id'] = bin2hex($r['id']);
                $this->typesArray[bin2hex($r['id'])]['brand_id'] = bin2hex($r['brand_id']);
            }
        }

        return $this->typesArray;
    }

    private function prepareProduct(array $productId_versionId, $remoteProductData, $onlyMedia = false): array
    {
        $productData = [];
        $productId = $productId_versionId['product_id'];

        if (!$onlyMedia && $this->getProductOption('productName', $productId) && $remoteProductData->short_description != '') {
            $productData['name'] = trim(substr($remoteProductData->short_description, 0, 255));
        }

        if (!$onlyMedia && $this->getProductOption('productDescription', $productId) && $remoteProductData->short_description != '') {
            $productData['description'] = $remoteProductData->short_description;
        }

        //        $this->getOption('productLongDescription') ???
        //         $productData['description'] = $remoteProductData->short_description;

        if (!$onlyMedia && $this->getProductOption('productBrand', $productId) && $remoteProductData->manufacturer != '') {
            $productData['manufacturerId'] = $this->productCommand->getManufacturerIdByName($remoteProductData->manufacturer);
        }
        if (!$onlyMedia && $this->getProductOption('productEan', $productId) && count($remoteProductData->eans)) {
            $productData['ean'] = $remoteProductData->eans[0];
        }
        if (!$onlyMedia && $this->getProductOption('productOem', $productId) && count($remoteProductData->oems)) {
            $productData['manufacturerNumber'] = $remoteProductData->oems[0];
        }

        if ($this->getProductOption('productImages', $productId)) {
            if (isset($remoteProductData->images) && count($remoteProductData->images)) {
                $media = [];
                foreach ($remoteProductData->images as $k => $img) {
                    if (isset($img->big->url)) {
                        $imageUrl = $img->big->url;
                    } elseif (isset($img->normal->url)) {
                        $imageUrl = $img->normal->url;
                    } elseif (isset($img->thumb->url)) {
                        $imageUrl = $img->thumb->url;
                    } else {
                        continue;
                    }

                    if (isset($img->date)) {
                        $imageDate = strtotime(explode(' ', $img->date)[0]);
                    } else {
                        $imageDate = strtotime('2017-01-01');
                    }

                    try {
                        $echoMediaDownload = $this->verbose ? 'd' : '';
                        $mediaId = $this->entitiesHelperService->getMediaId(
                            $imageUrl,
                            $imageDate,
                            $k . '-' . $remoteProductData->products_id . '-',
                            $echoMediaDownload
                        );
                        if ($mediaId) {
                            $media[] = [
                                'id'       => Uuid::randomHex(), // $mediaId,
                                'position' => $k + 1,
                                'mediaId'  => $mediaId,
                            ];
                        }
                    } catch (\Exception $e) {
                        $this->logger->error($e->getMessage());
                        $this->progressLoggingService->writeln('Exception: ' . $e->getMessage());
                    }
                }
                if (count($media)) {
                    $productData['media'] = $media;
                    //                    $productData['coverId'] = $media[0]['id'];
                }
                $this->progressLoggingService->activity();
            }
        }

        if (!$onlyMedia
            && $this->getProductOption('specReferencePCD', $productId)
            && isset($remoteProductData->reference_pcds)
            && count((array)$remoteProductData->reference_pcds)
        ) {
            $propGroupName = 'Reference PCD';
            foreach ((array)$remoteProductData->reference_pcds as $propValue) {
                $propValue = trim(substr($this->formatStringNoHTML($propValue), 0, 255));
                if ($propValue == '') {
                    continue;
                }
                $propertyId = $this->entitiesHelperService->getPropertyId($propGroupName, $propValue);

                if (!isset($productData['properties'])) {
                    $productData['properties'] = [];
                }
                $productData['properties'][] = ['id' => $propertyId];
            }
            $this->progressLoggingService->activity();
        }

        if (!$onlyMedia
            && $this->getProductOption('specReferenceOEM', $productId)
            && isset($remoteProductData->reference_oems)
            && count((array)$remoteProductData->reference_oems)
        ) {
            $propGroupName = 'Reference OEM';
            foreach ((array)$remoteProductData->reference_oems as $propValue) {
                $propValue = trim(substr($this->formatStringNoHTML($propValue), 0, 255));
                if ($propValue == '') {
                    continue;
                }
                $propertyId = $this->entitiesHelperService->getPropertyId($propGroupName, $propValue);
                if (!isset($productData['properties'])) {
                    $productData['properties'] = [];
                }
                $productData['properties'][] = ['id' => $propertyId];
            }
            $this->progressLoggingService->activity();
        }

        if (!$onlyMedia
            && $this->getProductOption('productSpecifications', $productId)
            && isset($remoteProductData->specifications)
            && count($remoteProductData->specifications)
        ) {
            $ignoreSpecs = self::IGNORE_SPECS;
            foreach ($remoteProductData->specifications as $spec) {
                if (isset($ignoreSpecs[$spec->specification_id])) {
                    continue;
                }
                $propGroupName = trim(substr(trim($this->formatStringNoHTML($spec->specification)), 0, 255));
                if ($propGroupName == '') {
                    continue;
                }
                $propValue = trim(substr($this->formatStringNoHTML(($spec->count > 1 ? $spec->count . ' x ' : '') . $spec->attribute . (isset($spec->attribute_extension) ? ' ' . $spec->attribute_extension : '')), 0, 255));
                if ($propValue == '') {
                    continue;
                }

                $propertyId = $this->entitiesHelperService->getPropertyId($propGroupName, $propValue);
                if (!isset($productData['properties'])) {
                    $productData['properties'] = [];
                }
                $productData['properties'][] = ['id' => $propertyId];
            }
            $this->progressLoggingService->activity();
        }

        if (
            !$onlyMedia
            && $this->optionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS)
            && isset($remoteProductData->waregroups)
        ) {
            foreach ($remoteProductData->waregroups as $waregroupObject) {
                $categoriesChain = json_decode(json_encode($waregroupObject->waregroup_tree), true);
                $categoryId = $this->entitiesHelperService->getCategoryId($categoriesChain, (string)$this->optionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS_PARENT));
                if (!$categoryId) {
                    break;
                }
                if (!isset($productData['categories'])) {
                    $productData['categories'] = [];
                }
                $productData['categories'][] = ['id' => $categoryId];
            }
        }

        if (!count($productData)) {
            return [];
        }

        $productData['id'] = $productId;

        //$this->progressLoggingService->activity('-'.$productId_versionId['product_id'].'-');
        return $productData;
    }

    private function findRelatedProducts($remoteProductData): array
    {
        $relatedProducts = [];
        $topid_products = $this->_fetchTopidProducts();
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
        $topid_products = $this->_fetchTopidProducts();
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

    private function findAlternateProducts($remoteProductData): array
    {
        $alternateProducts = [];
        $topid_products = $this->_fetchTopidProducts();
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

    private function findSimilarProducts($remoteProductData): array
    {
        $similarProducts = [];
        $topid_products = $this->_fetchTopidProducts();

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
        $topid_products = $this->_fetchTopidProducts();
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
        $topid_products = $this->_fetchTopidProducts();
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
        $topid_products = $this->_fetchTopidProducts();

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

    public function setProductInformation($onlyMedia = false): bool
    {
        if ($onlyMedia) {
            $this->cliStyle->section("\n\nProduct media");
        } else {
            $this->cliStyle->section("\n\nProduct information");
        }
        $topid_products = $this->_fetchTopidProducts(true);
        $productDataUpdate = [];
        $productDataUpdateCovers = [];
        $productDataDeleteDuplicateMedia = [];

        $chunkSize = 50;

        $topids = array_chunk(array_keys($topid_products), $chunkSize);
        $this->progressLoggingService->lap(true);
        foreach ($topids as $k => $prs) {
            if ($this->optionsHelperService->getOption(OptionConstants::START) && ($k + 1 < $this->optionsHelperService->getOption(OptionConstants::START))) {
                continue;
            }

            if ($this->optionsHelperService->getOption(OptionConstants::END) && ($k + 1 > $this->optionsHelperService->getOption(OptionConstants::END))) {
                break;
            }

            $this->progressLoggingService->activity('Getting data from remote server part ' . ($k + 1) . '/' . count($topids) . ' (' . count($prs) . ' products)...');
            $products = $this->topdataWebserviceClient->myProductList([
                'products' => implode(',', $prs),
                'filter'   => 'all',
            ]);
            $this->progressLoggingService->activity($this->progressLoggingService->lap() . "sec\n");

            //            $this->debug(implode(',', $prs), true);

            if (!isset($products->page->available_pages)) {
                throw new \Exception($products->error[0]->error_message . 'webservice no pages');
            }
            $this->progressLoggingService->activity('Processing data...');

            $temp = array_slice($topid_products, $k * $chunkSize, $chunkSize);
            $currentChunkProductIds = [];
            foreach ($temp as $p) {
                $currentChunkProductIds[] = $p[0]['product_id'];
            }

            $this->loadProductImportSettings($currentChunkProductIds);

            if (!$onlyMedia) {
                $this->unlinkProducts($currentChunkProductIds); //+
                $this->unlinkProperties($currentChunkProductIds); //+
                $this->unlinkCategories($currentChunkProductIds); //nochange
            }
            $this->unlinkImages($currentChunkProductIds); //+

            foreach ($products->products as $product) {
                if (!isset($topid_products[$product->products_id])) {
                    continue;
                }

                $productData = $this->prepareProduct($topid_products[$product->products_id][0], $product, $onlyMedia);
                if ($productData) {
                    $productDataUpdate[] = $productData;

                    if (isset($productData['media'][0]['id'])) {
                        $productDataUpdateCovers[] = [
                            'id'      => $productData['id'],
                            'coverId' => $productData['media'][0]['id'],
                        ];
                        foreach ($productData['media'] as $tempMedia) {
                            $productDataDeleteDuplicateMedia[] = [
                                'productId' => $productData['id'],
                                'mediaId'   => $tempMedia['mediaId'],
                                'id'        => $tempMedia['id'],
                            ];
                        }
                    }
                }

                if (count($productDataUpdate) > 10) {
                    $this->productRepository->update($productDataUpdate, $this->context);
                    $productDataUpdate = [];
                    $this->progressLoggingService->activity();

                    if (count($productDataUpdateCovers)) {
                        $this->productRepository->update($productDataUpdateCovers, $this->context);
                        $this->progressLoggingService->activity();
                        $productDataUpdateCovers = [];
                    }
                }

                if (!$onlyMedia) {
                    $this->linkProducts($topid_products[$product->products_id][0], $product);
                }
            }
            $this->progressLoggingService->mem();
            $this->progressLoggingService->activity(' ' . $this->progressLoggingService->lap() . "sec\n");
        }

        if (count($productDataUpdate)) {
            $this->progressLoggingService->activity('Updating last ' . count($productDataUpdate) . ' products...');
            $this->productRepository->update($productDataUpdate, $this->context);
            $this->progressLoggingService->mem();
            $this->progressLoggingService->activity(' ' . $this->progressLoggingService->lap() . "sec\n");
        }

        if (count($productDataUpdateCovers)) {
            //            $this->progressLoggingService->activity("\nHas covers!");
            $this->progressLoggingService->activity("\nUpdating last product covers...");
            $this->productRepository->update($productDataUpdateCovers, $this->context);
            $this->progressLoggingService->activity(' ' . $this->progressLoggingService->lap() . "sec\n");
        }

        if (count($productDataDeleteDuplicateMedia)) {
            $this->progressLoggingService->activity("\nDeleting product media duplicates...");
            $chunks = array_chunk($productDataDeleteDuplicateMedia, 100);
            foreach ($chunks as $chunk) {
                $productIds = [];
                $mediaIds = [];
                $pmIds = [];
                foreach ($chunk as $el) {
                    $productIds[] = $el['productId'];
                    $mediaIds[] = $el['mediaId'];
                    $pmIds[] = $el['id'];
                }
                $productIds = '0x' . implode(', 0x', $productIds);
                $mediaIds = '0x' . implode(', 0x', $mediaIds);
                $pmIds = '0x' . implode(', 0x', $pmIds);

                $this->connection->executeStatement("
                    DELETE FROM product_media 
                    WHERE (product_id IN ($productIds)) 
                        AND (media_id IN ($mediaIds)) 
                        AND(id NOT IN ($pmIds))
                ");
                $this->progressLoggingService->activity();
            }
            $this->progressLoggingService->mem();
            $this->progressLoggingService->activity(' ' . $this->progressLoggingService->lap() . "sec\n");
        }

        $this->progressLoggingService->activity("\nProduct information done!", true);

        return true;
    }

    private function unlinkProperties(array $productIds): void
    {
        if (!count($productIds)) {
            return;
        }

        $ids = $this->filterIdsByConfig('productSpecifications', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("UPDATE product SET property_ids = NULL WHERE id IN ($ids)");
            $this->connection->executeStatement("DELETE FROM product_property WHERE product_id IN ($ids)");
        }
    }

    private function unlinkImages(array $productIds): void
    {
        if (!count($productIds)) {
            return;
        }

        $ids = $this->filterIdsByConfig('productImages', $productIds);
        if (!count($ids)) {
            return;
        }
        $ids = $this->filterIdsByConfig('productImagesDelete', $ids);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("UPDATE product SET product_media_id = NULL, product_media_version_id = NULL WHERE id IN ($ids)");
            $this->connection->executeStatement("DELETE FROM product_media WHERE product_id IN ($ids)");
        }
    }

    private function unlinkCategories(array $productIds): void
    {
        if (!count($productIds)
            || !$this->optionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS)
            || !$this->optionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS_DELETE)) {
            return;
        }

        $idsString = '0x' . implode(',0x', $productIds);
        $this->connection->executeStatement("DELETE FROM product_category WHERE product_id IN ($idsString)");
        $this->connection->executeStatement("DELETE FROM product_category_tree WHERE product_id IN ($idsString)");
        $this->connection->executeStatement("UPDATE product SET category_tree = NULL WHERE id IN ($idsString)");
    }

    private function loadProductImportSettings(array $productIds): void
    {
        $this->productImportSettings = [];

        if (!count($productIds)) {
            return;
        }
        //load each product category path
        $productCategories = [];
        $allCategories = [];
        $ids = '0x' . implode(',0x', $productIds);
        $temp = $this->connection->fetchAllAssociative('
            SELECT LOWER(HEX(id)) as id, category_tree
              FROM product 
              WHERE (category_tree is NOT NULL)AND(id IN (' . $ids . '))
        ');

        foreach ($temp as $item) {
            $parsedIds = json_decode($item['category_tree'], true);
            foreach ($parsedIds as $id) {
                if (is_string($id) && Uuid::isValid($id)) {
                    $productCategories[$item['id']][] = $id;
                    $allCategories[$id] = false;
                }
            }
        }

        if (!count($allCategories)) {
            return;
        }

        //load each category settings
        $ids = '0x' . implode(',0x', array_keys($allCategories));
        $temp = $this->connection->fetchAllAssociative('
            SELECT LOWER(HEX(category_id)) as id, import_settings
              FROM topdata_category_extension 
              WHERE (plugin_settings=0)AND(category_id IN (' . $ids . '))
        ');

        foreach ($temp as $item) {
            $allCategories[$item['id']] = json_decode($item['import_settings'], true);
        }

        //set product settings based on category
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

    private function getProductExtraOption(string $optionName, string $productId): bool
    {
        if (isset($this->productImportSettings[$productId])) {
            if (
                isset($this->productImportSettings[$productId][$optionName])
                && $this->productImportSettings[$productId][$optionName]
            ) {
                return true;
            }

            return false;
        }

        return false;
    }

    public function mapProductOption(string $optionName): string
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

    private function getProductOption(string $optionName, string $productId): bool
    {
        if (isset($this->productImportSettings[$productId])) {
            return $this->getProductExtraOption($this->mapProductOption($optionName), $productId);
        }

        return $this->optionsHelperService->getOption($optionName) ? true : false;
    }

    private function filterIdsByConfig(string $optionName, array $productIds): array
    {
        $returnIds = [];
        foreach ($productIds as $pid) {
            if ($this->getProductOption($optionName, $pid)) {
                $returnIds[] = $pid;
            }
        }

        return $returnIds;
    }

    private function unlinkProducts(array $productIds): void
    {
        if (!count($productIds)) {
            return;
        }

        $ids = $this->filterIdsByConfig('productSimilar', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_similar WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productAlternate', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_alternate WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productRelated', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_related WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productBundled', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_bundled WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productColorVariant', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_color_variant WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productCapacityVariant', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_capacity_variant WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productVariant', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_variant WHERE product_id IN ($ids)");
        }
    }

    private function linkProducts(array $productId_versionId, $remoteProductData): void
    {
        $dateTime = date('Y-m-d H:i:s');
        $productId = $productId_versionId['product_id'];

        if ($this->getProductOption('productSimilar', $productId)) {
            $dataInsert = [];
            $temp = $this->findSimilarProducts($remoteProductData);
            foreach ($temp as $tempProd) {
                $dataInsert[] = "(0x{$productId_versionId['product_id']}, 0x{$productId_versionId['product_version_id']}, 0x{$tempProd['product_id']}, 0x{$tempProd['product_version_id']}, '$dateTime')";
            }

            if (count($dataInsert)) {
                $insertDataChunks = array_chunk($dataInsert, 30);
                foreach ($insertDataChunks as $chunk) {
                    $this->connection->executeStatement('
                        INSERT INTO topdata_product_to_similar (product_id, product_version_id, similar_product_id, similar_product_version_id, created_at) VALUES ' . implode(',', $chunk) . '
                    ');
                    $this->progressLoggingService->activity();
                }

                if ($this->getProductOption('productSimilarCross', $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, self::CROSS_SIMILAR);
                }
            }
        }

        if ($this->getProductOption('productAlternate', $productId)) {
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
                    $this->progressLoggingService->activity();
                }

                if ($this->getProductOption('productAlternateCross', $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, self::CROSS_ALTERNATE);
                }
            }
        }

        if ($this->getProductOption('productRelated', $productId)) {
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
                    $this->progressLoggingService->activity();
                }

                if ($this->getProductOption('productRelatedCross', $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, self::CROSS_RELATED);
                }
            }
        }

        if ($this->getProductOption('productBundled', $productId)) {
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
                    $this->progressLoggingService->activity();
                }

                if ($this->getProductOption('productBundledCross', $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, self::CROSS_BUNDLED);
                }
            }
        }

        if ($this->getProductOption('productColorVariant', $productId)) {
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
                    $this->progressLoggingService->activity();
                }

                if ($this->getProductOption('productVariantColorCross', $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, self::CROSS_COLOR_VARIANT);
                }
            }
        }

        if ($this->getProductOption('productCapacityVariant', $productId)) {
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
                    $this->progressLoggingService->activity();
                }

                if ($this->getProductOption('productVariantCapacityCross', $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, self::CROSS_CAPACITY_VARIANT);
                }
            }
        }

        if ($this->getProductOption('productVariant', $productId)) {
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
                    $this->progressLoggingService->activity();
                }

                if ($this->getProductOption('productVariantCross', $productId)) {
                    $this->addProductCrossSelling($productId_versionId, $temp, self::CROSS_VARIANT);
                }
            }
        }
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

    public function setDeviceSynonyms(): bool
    {
        $this->cliStyle->section("\n\nDevice synonyms");
        $availableDevices = [];
        foreach ($this->_getEnabledDevices() as $pr) {
            $availableDevices[$pr['ws_id']] = bin2hex($pr['id']);
        }
        $chunkSize = 50;

        $chunks = array_chunk($availableDevices, $chunkSize, true);
        $this->progressLoggingService->lap(true);

        //        $this->progressLoggingService->activity(print_r([$topids[0], $topids[1]], true), true);
        //        return true;

        foreach ($chunks as $k => $prs) {
            if ($this->optionsHelperService->getOption(OptionConstants::START) && ($k + 1 < $this->optionsHelperService->getOption(OptionConstants::START))) {
                continue;
            }

            if ($this->optionsHelperService->getOption(OptionConstants::END) && ($k + 1 > $this->optionsHelperService->getOption(OptionConstants::END))) {
                break;
            }

            $this->progressLoggingService->activity('Getting data from remote server part ' . ($k + 1) . '/' . count($chunks) . '...');
            $devices = $this->topdataWebserviceClient->myProductList([
                'products' => implode(',', array_keys($prs)),
                'filter'   => 'all',
            ]);
            $this->progressLoggingService->activity($this->progressLoggingService->lap() . "sec\n");

            if (!isset($devices->page->available_pages)) {
                throw new \Exception($devices->error[0]->error_message . ' webservice no pages');
            }
            //            $this->progressLoggingService->mem();
            $this->progressLoggingService->activity("\nProcessing data...");

            $this->connection->executeStatement('DELETE FROM topdata_device_to_synonym WHERE device_id IN (0x' . implode(', 0x', $prs) . ')');

            $variantsMap = [];
            foreach ($devices->products as $product) {
                if (isset($product->product_variants->products)) {
                    foreach ($product->product_variants->products as $variant) {
                        if (($variant->type == 'synonym')
                            && isset($prs[$product->products_id])
                            && isset($availableDevices[$variant->id])
                        ) {
                            $prodId = $prs[$product->products_id];
                            if (!isset($variantsMap[$prodId])) {
                                $variantsMap[$prodId] = [];
                            }
                            $variantsMap[$prodId][] = $availableDevices[$variant->id];
                        }
                    }
                }
            }

            $dateTime = date('Y-m-d H:i:s');
            $dataInsert = [];
            foreach ($variantsMap as $deviceId => $synonymIds) {
                foreach ($synonymIds as $synonymId) {
                    $dataInsert[] = "(0x{$deviceId}, 0x{$synonymId}, '$dateTime')";
                }
            }

            if (count($dataInsert)) {
                $insertDataChunks = array_chunk($dataInsert, 50);
                foreach ($insertDataChunks as $dataChunk) {
                    $this->connection->executeStatement(
                        'INSERT INTO topdata_device_to_synonym (device_id, synonym_id, created_at) VALUES ' . implode(',', $dataChunk)
                    );
                    $this->progressLoggingService->activity();
                }
            }
            $this->progressLoggingService->activity($this->progressLoggingService->lap() . 'sec ');
            $this->progressLoggingService->mem();
            $this->progressLoggingService->writeln('');
        }

        return true;
    }

    public static function formatString($string)
    {
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', (string)$string));
    }

    public static function formatStringNoHTML($string)
    {
        return MappingHelperService::formatString(strip_tags((string)$string));
    }

    public static function getCrossTypes(): array
    {
        return [
            1 => static::CROSS_CAPACITY_VARIANT,
            2 => static::CROSS_COLOR_VARIANT,
            3 => static::CROSS_ALTERNATE,
            4 => static::CROSS_RELATED,
            5 => static::CROSS_VARIANT,
            6 => static::CROSS_BUNDLED,
            7 => static::CROSS_SIMILAR,
        ];
    }

    private function getCrossName(string $crossType)
    {
        $names = [
            static::CROSS_CAPACITY_VARIANT => [
                'de-DE' => 'Kapazitätsvarianten',
                'en-GB' => 'Capacity Variants',
                'nl-NL' => 'capaciteit varianten',
            ],
            static::CROSS_COLOR_VARIANT    => [
                'de-DE' => 'Farbvarianten',
                'en-GB' => 'Color Variants',
                'nl-NL' => 'kleur varianten',
            ],
            static::CROSS_ALTERNATE        => [
                'de-DE' => 'Alternative Produkte',
                'en-GB' => 'Alternate Products',
                'nl-NL' => 'alternatieve producten',
            ],
            static::CROSS_RELATED          => [
                'de-DE' => 'Zubehör',
                'en-GB' => 'Accessories',
                'nl-NL' => 'Accessoires',
            ],
            static::CROSS_VARIANT          => [
                'de-DE' => 'Varianten',
                'en-GB' => 'Variants',
                'nl-NL' => 'varianten',
            ],
            static::CROSS_BUNDLED          => [
                'de-DE' => 'Im Bundle',
                'en-GB' => 'In Bundle',
                'nl-NL' => 'In een bundel',
            ],
            static::CROSS_SIMILAR          => [
                'de-DE' => 'Ähnlich',
                'en-GB' => 'Similar',
                'nl-NL' => 'Vergelijkbaar',
            ],
        ];

        return isset($names[$crossType]) ? $names[$crossType] : $crossType;
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
        $productCrossSellingEntity = $this
            ->productCrossSellingRepository
            ->search($criteria, $this->context)
            ->first();

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
                'position'         => array_search($crossType, static::getCrossTypes()),
                'type'             => ProductCrossSellingDefinition::TYPE_PRODUCT_LIST,
                'sortBy'           => ProductCrossSellingDefinition::SORT_BY_NAME,
                'sortDirection'    => FieldSorting::ASCENDING,
                'active'           => true,
                'limit'            => 24,
                'topdataExtension' => ['type' => $crossType],
            ];
            $this->productCrossSellingRepository->create([$data], $this->context);
            $this->progressLoggingService->activity();
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
        $this->progressLoggingService->activity();
    }

    private function capacityNames(): array
    {
        return [
            'Kapazität (Zusatz)',
            'Kapazität',
            'Capacity',
        ];
    }

    private function colorNames(): array
    {
        return [
            'Farbe',
            'Color',
        ];
    }

    private function addToGroup($groups, $ids, $variants): array
    {
        //        $debug = false;
        //        if(in_array('beb4af5a46a84eb8ad921d46d57d0076', $ids) && $variants=='capacity') {
        //            $debug = true;
        //        }

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
                //                if($debug) {
                //                    echo "\nbefore:";
                //                    print_r($groups[$key]);
                //                }
                $groups[$key]['ids'] = array_unique(array_merge($group['ids'], $ids));
                if ($colorVariants) {
                    $groups[$key]['color'] = true;
                }
                if ($capacityVariants) {
                    $groups[$key]['capacity'] = true;
                }

                //                if($debug) {
                //                    echo "\nafter:";
                //                    print_r($groups[$key]);
                //                }
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
        $this->progressLoggingService->writeln("\nBegin generating variated products based on color and capacity information (Import variants with other colors, Import variants with other capacities should be enabled in TopFeed plugin, product information should be already imported)");
        $groups = [];
        $this->progressLoggingService->lap(true);
        $groups = $this->collectColorVariants($groups);
        //        echo "\nColor groups:".count($groups)."\n";
        $groups = $this->collectCapacityVariants($groups);
        //        echo "\nColor+capacity groups:".count($groups)."\n";
        $groups = $this->mergeIntersectedGroups($groups);
        $this->progressLoggingService->activity('Found ' . count($groups) . ' groups to generate variated products', true);

        $invalidProd = true;
        for ($i = 0; $i < count($groups); $i++) {
            if ($this->optionsHelperService->getOption(OptionConstants::START) && ($i + 1 < $this->optionsHelperService->getOption(OptionConstants::START))) {
                continue;
            }

            if ($this->optionsHelperService->getOption(OptionConstants::END) && ($i + 1 > $this->optionsHelperService->getOption(OptionConstants::END))) {
                break;
            }

            $this->progressLoggingService->activity('Group ' . ($i + 1) . '...');

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
                        $this->progressLoggingService->writeln("\nProduct id=$productId not found!");
                        break;
                    }

                    if ($product->getParentId()) {
                        if (is_null($parentId)) {
                            $parentId = $product->getParentId();
                        }
                        if ($parentId != $product->getParentId()) {
                            $invalidProd = true;
                            $this->progressLoggingService->writeln("\nMany parent products error (last checked product id=$productId)!");
                            break;
                        }
                    }

                    if ($product->getChildCount() > 0) {
                        $this->progressLoggingService->writeln("\nProduct id=$productId has childs!");
                        $invalidProd = true;
                        break;
                    }

                    $prodOptions = [];

                    foreach ($product->getProperties() as $property) {
                        if ($groups[$i]['color'] && in_array($property->getGroup()->getName(), $this->colorNames())) {
                            $prodOptions['colorId'] = $property->getId();
                            $prodOptions['colorGroupId'] = $property->getGroup()->getId();
                        }
                        if ($groups[$i]['capacity'] && in_array($property->getGroup()->getName(), $this->capacityNames())) {
                            $prodOptions['capacityId'] = $property->getId();
                            $prodOptions['capacityGroupId'] = $property->getGroup()->getId();
                        }
                    }

                    //                    echo "\n".$product->getName();
                    //
                    //                    print_r($prodOptions);
                    //                    echo "\n";

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
                        $this->progressLoggingService->writeln("\nProduct id=$productId has no valid properties!");
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
                $this->progressLoggingService->activity('Variated product for group will be skip, product ids: ');
                $this->progressLoggingService->activity(implode(', ', $groups[$i]['ids']), true);
            }

            if ($groups[$i]['referenceProduct'] && !$invalidProd) {
                $this->createVariatedProduct($groups[$i], $parentId);
            }
            $this->progressLoggingService->activity('done', true);
        }

        $this->progressLoggingService->activity($this->progressLoggingService->lap() . 'sec ');
        $this->progressLoggingService->mem();
        $this->progressLoggingService->writeln('Generating variated products done');

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

    private function getWordsFromString(string $string): array
    {
        $rez = [];
        $string = str_replace(['-', '/', '+', '&', '.', ','], ' ', $string);
        $words = explode(' ', $string);
        foreach ($words as $word) {
            if (trim($word)) {
                $rez[] = trim($word);
            }
        }

        return $rez;
    }

    private function firstLetters(string $string): string
    {
        $rez = '';
        foreach ($this->getWordsFromString($string) as $word) {
            $rez .= mb_substr($word, 0, 1);
        }

        return $rez;
    }

    public function setCliStyle(CliStyle $cliStyle): void
    {
        $this->cliStyle = $cliStyle;
    }
}
