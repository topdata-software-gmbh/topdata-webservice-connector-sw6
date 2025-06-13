<?php

namespace Topdata\TopdataConnectorSW6\Service\Import;

use Doctrine\DBAL\Connection;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataBrandService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataDeviceService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataDeviceTypeService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataSeriesService;
use Topdata\TopdataConnectorSW6\Service\MediaHelperService;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataConnectorSW6\Util\UtilStringFormatting;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilFormatter;

/**
 * 04/2025 created (extracted from MappingHelperService)
 */
class DeviceImportService
{

    const BATCH_SIZE   = 5000;

    private Context $context;

    public function __construct(
        private readonly Connection               $connection,
        private readonly EntityRepository         $topdataDeviceRepository,
        private readonly EntityRepository         $topdataSeriesRepository,
        private readonly EntityRepository         $topdataDeviceTypeRepository,
        private readonly TopdataWebserviceClient  $topdataWebserviceClient,
        private readonly TopdataSeriesService     $topdataSeriesService,
        private readonly TopdataDeviceTypeService $topdataDeviceTypeService,
        private readonly TopdataBrandService      $topdataBrandService
    )
    {
        $this->context = Context::createDefaultContext();
    }


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
        CliLogger::section("Device type");


        // Log the activity of getting data from the remote server
        CliLogger::writeln('Getting data from remote server [DeviceType]...');
        CliLogger::lap(true);

        // Fetch device types from the remote server
        $types = $this->topdataWebserviceClient->getModelTypes();

        // Log the number of fetched device types
        ImportReport::setCounter('Fetched DeviceTypes', count($types->data));
        CliLogger::writeln('Fetched ' . count($types->data) . ' device types from remote server');

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
                ImportReport::incCounter('DeviceTypes Total Processed');
                ImportReport::incCounter('DeviceTypes Brand Lookups');

                // Get the brand by its web service ID
                $brand = $this->topdataBrandService->getBrandByWsId($brandWsId);
                if (!$brand) {
                    ImportReport::incCounter('DeviceTypes Brand Not Found');
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
                    ImportReport::incCounter('DeviceTypes Created');
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
                    ImportReport::incCounter('DeviceTypes Updated');
                } else {
                    ImportReport::incCounter('DeviceTypes Unchanged');
                }

                // Create new types in batches of 100
                if (count($dataCreate) > 100) {
                    $topdataDeviceTypeRepository->create($dataCreate, $this->context);
                    ImportReport::incCounter('DeviceTypes Create Batches');
                    $dataCreate = [];
                    CliLogger::activity();
                }

                // Update existing types in batches of 100
                if (count($dataUpdate) > 100) {
                    $topdataDeviceTypeRepository->update($dataUpdate, $this->context);
                    ImportReport::incCounter('DeviceTypes Update Batches');
                    $dataUpdate = [];
                    CliLogger::activity();
                }
            }
        }

        // Create any remaining new types
        if (count($dataCreate)) {
            $topdataDeviceTypeRepository->create($dataCreate, $this->context);
            ImportReport::incCounter('DeviceTypes Create Batches');
            CliLogger::activity();
        }

        // Update any remaining existing types
        if (count($dataUpdate)) {
            $topdataDeviceTypeRepository->update($dataUpdate, $this->context);
            ImportReport::incCounter('DeviceTypes Update Batches');
            CliLogger::activity();
        }

        // Clear the fetched types data
        $types = null;

        // Log summary
        CliLogger::writeln('');
        CliLogger::writeln('=== DeviceTypes Summary ===');
        CliLogger::writeln('Total processed: ' . ImportReport::getCounter('DeviceTypes Total Processed'));
        CliLogger::writeln('Brand lookups: ' . ImportReport::getCounter('DeviceTypes Brand Lookups'));
        CliLogger::writeln('Brand not found: ' . ImportReport::getCounter('DeviceTypes Brand Not Found'));
        CliLogger::writeln('Created: ' . ImportReport::getCounter('DeviceTypes Created'));
        CliLogger::writeln('Updated: ' . ImportReport::getCounter('DeviceTypes Updated'));
        CliLogger::writeln('Unchanged: ' . ImportReport::getCounter('DeviceTypes Unchanged'));
        CliLogger::writeln('Create batches: ' . ImportReport::getCounter('DeviceTypes Create Batches'));
        CliLogger::writeln('Update batches: ' . ImportReport::getCounter('DeviceTypes Update Batches'));

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
        CliLogger::section("Series");

        CliLogger::writeln('Getting data from remote server [Series]...');
        CliLogger::lap(true);
        $series = $this->topdataWebserviceClient->getModelSeries();
        CliLogger::activity('Got ' . UtilFormatter::formatInteger(count($series->data)) . " series records from remote server\n");
        ImportReport::setCounter('Fetched Series', count($series->data));

        $topdataSeriesRepository = $this->topdataSeriesRepository;
        $dataCreate = [];
        $dataUpdate = [];
        CliLogger::activity('Processing data');
        $allSeries = $this->topdataSeriesService->getSeriesArray(true);

        foreach ($series->data as $s) {
            foreach ($s->brandIds as $brandWsId) {
                ImportReport::incCounter('Series Total Processed');
                ImportReport::incCounter('Series Brand Lookups');

                $brand = $this->topdataBrandService->getBrandByWsId((int)$brandWsId);
                if (!$brand) {
                    ImportReport::incCounter('Series Brand Not Found');
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
                    ImportReport::incCounter('Series Created');
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
                    ImportReport::incCounter('Series Updated');
                } else {
                    ImportReport::incCounter('Series Unchanged');
                }

                if (count($dataCreate) > 100) {
                    $topdataSeriesRepository->create($dataCreate, $this->context);
                    ImportReport::incCounter('Series Create Batches');
                    $dataCreate = [];
                    CliLogger::activity();
                }

                if (count($dataUpdate) > 100) {
                    $topdataSeriesRepository->update($dataUpdate, $this->context);
                    ImportReport::incCounter('Series Update Batches');
                    $dataUpdate = [];
                    CliLogger::activity();
                }
            }
        }

        if (count($dataCreate)) {
            $topdataSeriesRepository->create($dataCreate, $this->context);
            ImportReport::incCounter('Series Create Batches');
            CliLogger::activity();
        }

        if (count($dataUpdate)) {
            $topdataSeriesRepository->update($dataUpdate, $this->context);
            ImportReport::incCounter('Series Update Batches');
            CliLogger::activity();
        }

        // Log summary
        CliLogger::writeln('');
        CliLogger::writeln('=== Series Summary ===');
        CliLogger::writeln('Total processed: ' . ImportReport::getCounter('Series Total Processed'));
        CliLogger::writeln('Brand lookups: ' . ImportReport::getCounter('Series Brand Lookups'));
        CliLogger::writeln('Brand not found: ' . ImportReport::getCounter('Series Brand Not Found'));
        CliLogger::writeln('Created: ' . ImportReport::getCounter('Series Created'));
        CliLogger::writeln('Updated: ' . ImportReport::getCounter('Series Updated'));
        CliLogger::writeln('Unchanged: ' . ImportReport::getCounter('Series Unchanged'));
        CliLogger::writeln('Create batches: ' . ImportReport::getCounter('Series Create Batches'));
        CliLogger::writeln('Update batches: ' . ImportReport::getCounter('Series Update Batches'));

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
            CliLogger::activity("\nGetting device chunk $chunkNumber from remote server...");

            ImportReport::incCounter('Device Chunks');
            $response = $this->topdataWebserviceClient->getModels($limit, $start);
            CliLogger::activity(CliLogger::lap() . "sec\n");

            if (!isset($response->data) || count($response->data) == 0) {
                $repeat = false;
                break;
            }

            $recordsInChunk = count($response->data);
            ImportReport::incCounter('Devices Records Fetched', $recordsInChunk);
            CliLogger::activity("Processing Device Chunk $chunkNumber ($recordsInChunk records)");

            foreach ($response->data as $s) {
                ImportReport::incCounter('Devices Total Processed');

                $brandArr = $this->topdataBrandService->getBrandByWsId((int)$s->bId);

                if (!$brandArr) {
                    ImportReport::incCounter('Devices Brand Not Found');
                    continue;
                }

                $code = $brandArr['code'] . '_' . UtilStringFormatting::formCode($s->val);

                if (isset($duplicates[$code])) {
                    ImportReport::incCounter('Devices Duplicates Skipped');
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
                ImportReport::incCounter('Devices Database Lookups');
                $rez = $this
                    ->connection
                    ->createQueryBuilder()
                    ->select('*')
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
                        ImportReport::incCounter('Devices Without Brand Id');
                        $deviceArr['brand_id'] = 0x0; // or null?
                    } else {
                        ImportReport::incCounter('Devices With Brand Id');
                        $deviceArr['brand_id'] = bin2hex($deviceArr['brand_id']);
                    }
                    // type
                    if (empty($deviceArr['type_id'])) {
                        ImportReport::incCounter('Devices Without Type Id');
                        $deviceArr['type_id'] = 0x0; // or null?
                    } else {
                        ImportReport::incCounter('Devices With Type Id');
                        $deviceArr['type_id'] = bin2hex($deviceArr['type_id']);
                    }
                    // series
                    if (empty($deviceArr['series_id'])) {
                        ImportReport::incCounter('Devices Without Series Id');
                        $deviceArr['series_id'] = 0x0; // or null?
                    } else {
                        ImportReport::incCounter('Devices With Series Id');
                        $deviceArr['series_id'] = bin2hex($deviceArr['series_id']);
                    }
                }

                $serieId = null;
                $serie = [];
                if ($s->mId) {
                    ImportReport::incCounter('Devices Series Lookups');
                    foreach ($seriesArray as $serieItem) {
                        if ($serieItem['ws_id'] == (int)$s->mId && $serieItem['brand_id'] == $brandArr['id']) {
                            $serie = $serieItem;
                            break;
                        }
                    }
                }
                if ($serie) {
                    ImportReport::incCounter('Devices Series Found');
                    $serieId = $serie['id'];
                    $search_keywords[] = $serie['label'];
                }

                $typeId = null;
                $type = [];
                if ($s->dId) {
                    ImportReport::incCounter('Devices Type Lookups');
                    foreach ($typesArray as $typeItem) {
                        if ($typeItem['ws_id'] == (int)$s->dId && $typeItem['brand_id'] == $brandArr['id']) {
                            $type = $typeItem;
                            break;
                        }
                    }
                }

                if ($type) {
                    ImportReport::incCounter('Devices Type Found');
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
                    ImportReport::incCounter('Devices Created');
                } elseif (
                    $deviceArr['brand_id'] != $brandArr['id']
                    || $deviceArr['type_id'] != $typeId
                    || $deviceArr['series_id'] != $serieId
                    || $deviceArr['model'] != $s->val
                    || $deviceArr['keywords'] != $keywords
                    || $deviceArr['ws_id'] != $s->id
                ) {
                    $dataUpdate[] = [
                        'id'       => $deviceArr['id'],
                        'brandId'  => $brandArr['id'],
                        'typeId'   => $typeId,
                        'seriesId' => $serieId,
                        'model'    => $s->val,
                        'keywords' => $keywords,
                        'wsId'     => (int)$s->id,
                    ];
                    ImportReport::incCounter('Devices Updated');
                } else {
                    ImportReport::incCounter('Devices Unchanged');
                }

                if (count($dataCreate) > 50) {
                    $created += count($dataCreate);
                    $this->topdataDeviceRepository->create($dataCreate, $this->context);
                    ImportReport::incCounter('Devices Create Batches');
                    $dataCreate = [];
                    CliLogger::activity('+');
                }

                if (count($dataUpdate) > 50) {
                    $updated += count($dataUpdate);
                    $this->topdataDeviceRepository->update($dataUpdate, $this->context);
                    ImportReport::incCounter('Devices Update Batches');
                    $dataUpdate = [];
                    CliLogger::activity('*');
                }
            }
            if (count($dataCreate)) {
                $created += count($dataCreate);
                $this->topdataDeviceRepository->create($dataCreate, $this->context);
                ImportReport::incCounter('Devices Create Batches');
                $dataCreate = [];
                CliLogger::activity('+');
            }
            if (count($dataUpdate)) {
                $updated += count($dataUpdate);
                $this->topdataDeviceRepository->update($dataUpdate, $this->context);
                ImportReport::incCounter('Devices Update Batches');
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

        // Enhanced reporting with all counters
        CliLogger::writeln('');
        CliLogger::writeln('=== Devices Import Summary ===');
        CliLogger::writeln('Chunks processed: ' . ImportReport::getCounter('Device Chunks'));
        CliLogger::writeln('Total records fetched: ' . ImportReport::getCounter('Devices Records Fetched'));
        CliLogger::writeln('Total records processed: ' . ImportReport::getCounter('Devices Total Processed'));
        CliLogger::writeln('Brand not found: ' . ImportReport::getCounter('Devices Brand Not Found'));
        CliLogger::writeln('Duplicates skipped: ' . ImportReport::getCounter('Devices Duplicates Skipped'));
        CliLogger::writeln('Database lookups: ' . ImportReport::getCounter('Devices Database Lookups'));
        CliLogger::writeln('With brand ID: ' . ImportReport::getCounter('Devices With Brand Id'));
        CliLogger::writeln('Without brand ID: ' . ImportReport::getCounter('Devices Without Brand Id'));
        CliLogger::writeln('With type ID: ' . ImportReport::getCounter('Devices With Type Id'));
        CliLogger::writeln('Without type ID: ' . ImportReport::getCounter('Devices Without Type Id'));
        CliLogger::writeln('With series ID: ' . ImportReport::getCounter('Devices With Series Id'));
        CliLogger::writeln('Without series ID: ' . ImportReport::getCounter('Devices Without Series Id'));
        CliLogger::writeln('Series lookups: ' . ImportReport::getCounter('Devices Series Lookups'));
        CliLogger::writeln('Series found: ' . ImportReport::getCounter('Devices Series Found'));
        CliLogger::writeln('Type lookups: ' . ImportReport::getCounter('Devices Type Lookups'));
        CliLogger::writeln('Type found: ' . ImportReport::getCounter('Devices Type Found'));
        CliLogger::writeln('Created: ' . ImportReport::getCounter('Devices Created'));
        CliLogger::writeln('Updated: ' . ImportReport::getCounter('Devices Updated'));
        CliLogger::writeln('Unchanged: ' . ImportReport::getCounter('Devices Unchanged'));
        CliLogger::writeln('Create batches: ' . ImportReport::getCounter('Devices Create Batches'));
        CliLogger::writeln('Update batches: ' . ImportReport::getCounter('Devices Update Batches'));
        CliLogger::writeln('Total time: ' . $totalSecs . ' seconds');

        CliLogger::getCliStyle()->dumpDict([
            'created'    => $created,
            'updated'    => $updated,
            'total time' => $totalSecs,
        ], 'Devices Report');

        $this->connection->getConfiguration()->setSQLLogger($SQLlogger);

        UtilProfiling::stopTimer();
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