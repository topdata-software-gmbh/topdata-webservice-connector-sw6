<?php

namespace Topdata\TopdataConnectorSW6\Service\Import;

use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataBrandService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataDeviceService;
use Topdata\TopdataConnectorSW6\Service\MediaHelperService;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataConnectorSW6\Util\UtilStringFormatting;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Handles the import and association of media (images) for devices.
 * This service fetches device media information from a remote source, processes it,
 * and updates the local database by associating media files with devices.
 * Extracted from DeviceImportService in 04/2025.
 */
class DeviceMediaImportService
{
    const IMAGE_PREFIX = 'td-'; // Moved from DeviceImportService
    const BATCH_SIZE   = 5000; // Keeping consistent batch size, might adjust if needed

    private Context $context;

    public function __construct(
        private readonly LoggerInterface         $logger,
        private readonly EntityRepository        $topdataDeviceRepository, // Assuming this is the correct one passed in services.xml
        private readonly MediaHelperService      $mediaHelperService,
        private readonly TopdataWebserviceClient $topdataWebserviceClient,
        private readonly TopdataBrandService     $topdataBrandService,
        private readonly TopdataDeviceService    $topdataDeviceService
        // private readonly Connection              $connection // Add if needed by moved logic
    )
    {
        $this->context = Context::createDefaultContext();
    }

    /**
     * Sets the device media by fetching data from the remote server and updating the local database.
     *
     * This method retrieves device media information from the remote server, processes the data, and updates the local database
     * by creating new entries or updating existing ones. It uses the `TopdataWebserviceClient` to fetch the data and
     * the `EntityRepository` to perform database operations.
     *
     * @throws Exception
     *
     * 04/2025 moved from MappingHelperService::setDeviceMedia() to DeviceImportService::setDeviceMedia()
     * 04/2025 moved from DeviceImportService::setDeviceMedia() to DeviceMediaImportService::setDeviceMedia()
     */
    public function setDeviceMedia(): void
    {
        UtilProfiling::startTimer();
        CliLogger::writeln('Devices Media start');

        // ---- Fetch enabled devices
        $available_Printers = [];
        foreach ($this->topdataDeviceService->_getEnabledDevices() as $pr) {
            $available_Printers[$pr['ws_id']] = true;
        }
        $numDevicesTotal = count($available_Printers);
        $numDevicesProcessed = 0;
        $chunkSize = self::BATCH_SIZE;
        CliLogger::writeln("Chunk size is $chunkSize devices");
        CliLogger::writeln("Available devices: $numDevicesTotal");
        $start = 0;
        $chunkNumber = 0;
        CliLogger::lap(true);

        // ---- Main loop to process devices in chunks
        while (true) {
            $chunkNumber++;
            CliLogger::activity("\nFetching media chunk $chunkNumber from remote server...");
            ImportReport::incCounter('Device Media Chunks');
            $models = $this->topdataWebserviceClient->getModels($chunkSize, $start);
            CliLogger::activity(CliLogger::lap() . 'sec. ');
            CliLogger::mem();
            CliLogger::writeln('');

            // ---- Check if there is no data, break the loop
            if (!isset($models->data) || count($models->data) == 0) {
                break;
            }

            $recordsInChunk = count($models->data);
            ImportReport::incCounter('Device Media Records Fetched', $recordsInChunk);
            CliLogger::activity("Processing data chunk $chunkNumber ($recordsInChunk records)");

            // ---- Iterate through each device model in the chunk
            foreach ($models->data as $s) {
                ImportReport::incCounter('Device Media Total Processed');

                // ---- Skip if the device is not available
                if (!isset($available_Printers[$s->id])) {
                    ImportReport::incCounter('Device Media Devices Skipped - Not Available');
                    continue;
                }

                if ($numDevicesProcessed++ % 4 == 0) {
                    CliLogger::progress($numDevicesProcessed, $numDevicesTotal);
                }

                // ---- Get the brand by its Webservice ID
                $brand = $this->topdataBrandService->getBrandByWsId((int)$s->bId);
                if (!$brand) {
                    ImportReport::incCounter('Device Media Devices Skipped - No Brand');
                    continue;
                }

                // ---- Construct the device code
                $code = $brand['code'] . '_' . UtilStringFormatting::formCode($s->val);
                $device = $this->topdataDeviceRepository->search(
                    (new Criteria())
                        ->addFilter(new EqualsFilter('code', $code))
                        ->addAssociation('media')
                        ->setLimit(1),
                    $this->context
                )
                    ->getEntities()
                    ->first();

                // ---- Skip if the device is not found
                if (!$device) {
                    ImportReport::incCounter('Device Media Devices Skipped - Device Not Found');
                    continue;
                }

                ImportReport::incCounter('Device Media Devices Found');
                $currentMedia = $device->getMedia();

                // ---- Delete media if the image is null
                if (is_null($s->img) && $currentMedia) {
                    $this->topdataDeviceRepository->update([
                        [
                            'id'      => $device->getId(),
                            'mediaId' => null,
                        ],
                    ], $this->context);

                    ImportReport::incCounter('Device Media Images Deleted');

                    /*
                     * @todo Use \Shopware\Core\Content\Media\DataAbstractionLayer\MediaRepositoryDecorator
                     * for deleting file physically?
                     */

                    continue;
                }

                // ---- Skip if the image is null
                if (is_null($s->img)) {
                    ImportReport::incCounter('Device Media Images Skipped - No Image');
                    continue;
                }

                // ---- Skip if the current media is newer than the fetched media
                if ($currentMedia && (date_timestamp_get($currentMedia->getCreatedAt()) > strtotime($s->img_date))) {
                    ImportReport::incCounter('Device Media Images Skipped - Current Newer');
                    continue;
                }

                $imageDate = strtotime(explode(' ', $s->img_date)[0]);

                // ---- Try to update the media
                try {
                    $mediaId = $this->mediaHelperService->getMediaId($s->img, $imageDate, self::IMAGE_PREFIX);
                    if ($mediaId) {
                        $this->topdataDeviceRepository->update([
                            [
                                'id'      => $device->getId(),
                                'mediaId' => $mediaId,
                            ],
                        ], $this->context);
                        ImportReport::incCounter('Device Media Images Updated');
                    }
                } catch (Exception $e) {
                    ImportReport::incCounter('Device Media Errors');
                    $this->logger->error($e->getMessage());
                    CliLogger::writeln('Exception: ' . $e->getMessage());
                }
            }
            CliLogger::writeln("processed $numDevicesProcessed of $numDevicesTotal devices " . CliLogger::lap() . 'sec. ');
            $start += $chunkSize;
            if (count($models->data) < $chunkSize) {
                break;
            }
        }

        // START MODIFICATION
        $summaryData = [
            ['Chunks processed', ImportReport::getCounter('Device Media Chunks') ?? 0],
            ['Total records fetched', ImportReport::getCounter('Device Media Records Fetched') ?? 0],
            ['Total records processed', ImportReport::getCounter('Device Media Total Processed') ?? 0],
            ['Devices found', ImportReport::getCounter('Device Media Devices Found') ?? 0],
            ['Devices skipped (not available)', ImportReport::getCounter('Device Media Devices Skipped - Not Available') ?? 0],
            ['Devices skipped (no brand)', ImportReport::getCounter('Device Media Devices Skipped - No Brand') ?? 0],
            ['Devices skipped (device not found)', ImportReport::getCounter('Device Media Devices Skipped - Device Not Found') ?? 0],
            ['Images updated', ImportReport::getCounter('Device Media Images Updated') ?? 0],
            ['Images deleted', ImportReport::getCounter('Device Media Images Deleted') ?? 0],
            ['Images skipped (no image)', ImportReport::getCounter('Device Media Images Skipped - No Image') ?? 0],
            ['Images skipped (current newer)', ImportReport::getCounter('Device Media Images Skipped - Current Newer') ?? 0],
            ['Errors encountered', ImportReport::getCounter('Device Media Errors') ?? 0],
        ];

        CliLogger::getCliStyle()->table(['Metric', 'Count'], $summaryData, 'Device Media Import Summary');
        // END MODIFICATION

        CliLogger::writeln('Devices Media done');

        UtilProfiling::stopTimer();
    }
}