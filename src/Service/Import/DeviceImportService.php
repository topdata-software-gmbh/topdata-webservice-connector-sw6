<?php

namespace Topdata\TopdataConnectorSW6\Service\Import;

use Doctrine\DBAL\Connection;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataDeviceService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataDeviceTypeService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataSeriesService;
use Topdata\TopdataConnectorSW6\Service\MediaHelperService;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataConnectorSW6\Util\UtilStringFormatting;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * 04/2025 created (extracted from MappingHelperService)
 */
class DeviceImportService
{

    const IMAGE_PREFIX = 'td-';
    const BATCH_SIZE   = 5000;

    private Context $context;

    public function __construct(
        private readonly LoggerInterface          $logger,
        private readonly Connection               $connection,
        private readonly EntityRepository         $topdataDeviceRepository,
        private readonly EntityRepository         $topdataSeriesRepository,
        private readonly EntityRepository         $topdataDeviceTypeRepository,
        private readonly MediaHelperService       $mediaHelperService,
        private readonly TopdataDeviceService     $topdataDeviceService,
        private readonly TopdataWebserviceClient  $topdataWebserviceClient,
        private readonly TopdataSeriesService     $topdataSeriesService,
        private readonly TopdataDeviceTypeService $topdataDeviceTypeService
    )
    {
        $this->context = Context::createDefaultContext();
    }

    private ?array $brandWsArray = null; // aka mapWsIdToBrand


    /**
     * Sets the device types by fetching data from the remote server and updating the local database.
     *
     * This method retrieves device types from the remote server, processes the data, and updates the local database
     * by creating new entries or updating existing ones. It uses the `TopdataWebserviceClient` to fetch the data and
     * the `EntityRepository` to perform database operations.
     *
     *
     * 04/2025 moved from MappingHelperService::setDeviceTypes() to DeviceImportService::setDeviceTypes()
     */
    public function setDeviceTypes(): void
    {
        UtilProfiling::startTimer();
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
                $brand = $this->_getBrandByWsIdArray($brandWsId);
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

        UtilProfiling::stopTimer();
    }


    /**
     * 04/2025 moved from MappingHelperService::setSeries() to DeviceImportService::setSeries()
     */
    public function setSeries(): void
    {
        UtilProfiling::startTimer();
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
                $brand = $this->_getBrandByWsIdArray((int)$brandWsId);
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

        UtilProfiling::stopTimer();
    }


    /**
     * this is called when --device or --device-only CLI options are set.
     *
     * 04/2025 moved from MappingHelperService::setDevices() to DeviceImportService::setDevices()
     */
    public function setDevices(): void
    {
        UtilProfiling::startTimer();

        $duplicates = [];
        $dataCreate = [];
        $dataUpdate = [];
        $updated = 0;
        $created = 0;
        $start = 0;
        $limit = self::BATCH_SIZE;
        $SQLlogger = $this->connection->getConfiguration()->getSQLLogger();
        $this->connection->getConfiguration()->setSQLLogger(null);
        CliLogger::section('Devices');
        CliLogger::writeln("Devices begin (Chunk size is $limit devices)");
        CliLogger::mem();
        CliLogger::writeln('');
        $functionTimeStart = microtime(true);
        $chunkNumber = 0;
//            if ((int)$this->optionsHelperService->getOption(OptionConstants::START)) {
//                $chunkNumber = (int)$this->optionsHelperService->getOption(OptionConstants::START) - 1;
//                $start = $chunkNumber * $limit;
//            }
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
//                if ((int)$this->optionsHelperService->getOption(OptionConstants::END) && ($chunkNumber > (int)$this->optionsHelperService->getOption(OptionConstants::END))) {
//                    break;
//                }
            CliLogger::activity("\nGetting device chunk $chunkNumber from remote server...");
            ImportReport::incCounter('Device Chunks');
            $response = $this->topdataWebserviceClient->getModels($limit, $start);
            CliLogger::activity(CliLogger::lap() . "sec\n");
            if (!isset($response->data) || count($response->data) == 0) {
                $repeat = false;
                break;
            }
            CliLogger::activity("Processing data chunk $chunkNumber");
            $i = 1;
            foreach ($response->data as $s) {
                $i++;
                if ($i > 500) {
                    $i = 1;
                    CliLogger::activity();
                }

                $brandArr = $this->_getBrandByWsIdArray((int)$s->bId);

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

                $keywords = $this->_formSearchKeywords($search_keywords);

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
            if (count($response->data) < $limit) {
                $repeat = false;
                break;
            }
        }

        $response = null;
        $duplicates = null;
        CliLogger::writeln('');
        $totalSecs = microtime(true) - $functionTimeStart;

        CliLogger::getCliStyle()->dumpDict([
            'created'    => $created,
            'updated'    => $updated,
            'total time' => $totalSecs,
        ], 'Devices Report');

        $this->connection->getConfiguration()->setSQLLogger($SQLlogger);

        UtilProfiling::stopTimer();
    }


    /**
     * Sets the device media by fetching data from the remote server and updating the local database.
     *
     * This method retrieves device media information from the remote server, processes the data, and updates the local database
     * by creating new entries or updating existing ones. It uses the `TopdataWebserviceClient` to fetch the data and
     * the `EntityRepository` to perform database operations.
     *
     * @throws Exception
     * @todo: add start/end chunk support, display chunk number, packet reading and packet writing for devices!
     *        display memory usage
     *
     * chunk 6 finished
     *
     * 04/2025 moved from MappingHelperService::setDeviceMedia() to DeviceImportService::setDeviceMedia()
     */
    public function setDeviceMedia(): void
    {
        UtilProfiling::startTimer();
        CliLogger::writeln('Devices Media start');
        $this->brandWsArray = null;
        $topdataDeviceRepository = $this->topdataDeviceRepository; // TODO: remove this

        // Fetch enabled devices
        $available_Printers = [];
        foreach ($this->topdataDeviceService->_getEnabledDevices() as $pr) {
            $available_Printers[$pr['ws_id']] = true;
        }
        $availablePrintersCount = count($available_Printers);
        $processedPrintarsCount = 0;
        $chunkSize = self::BATCH_SIZE;
        CliLogger::writeln("Chunk size is $chunkSize devices");
        $start = 0;
        $chunkNumber = 0;
        CliLogger::lap(true);
        while (true) {
            $chunkNumber++;
            CliLogger::activity("\nGetting media chunk $chunkNumber from remote server...");
            ImportReport::incCounter('Device Media Chunks');
            $models = $this->topdataWebserviceClient->getModels($chunkSize, $start);
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

                $brand = $this->_getBrandByWsIdArray($s->bId);
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
            $start += $chunkSize;
            if (count($models->data) < $chunkSize) {
                $repeat = false;
                break;
            }
        }

        CliLogger::writeln('');
        CliLogger::writeln('Devices Media done');

        UtilProfiling::stopTimer();
    }


    /**
     * 04/2025 moved from MappingHelperService::getBrandByWsIdArray() to DeviceImportService::getBrandByWsIdArray()
     */
    private function _getBrandByWsIdArray(int $brandWsId): array
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

    /**
     * 04/2025 moved from MappingHelperService::formSearchKeywords() to DeviceImportService::formSearchKeywords()
     */
    private function _formSearchKeywords(array $keywords): string
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


}