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

        // Initialize counters
        ImportReport::resetCounter('Device Media Total Processed');
        ImportReport::resetCounter('Device Media Chunks');
        ImportReport::resetCounter('Device Media Records Fetched');
        ImportReport::resetCounter('Device Media Devices Found');
        ImportReport::resetCounter('Device Media Devices Skipped - Not Available');
        ImportReport::resetCounter('Device Media Devices Skipped - No Brand');
        ImportReport::resetCounter('Device Media Devices Skipped - Device Not Found');
        ImportReport::resetCounter('Device Media Images Deleted');
        ImportReport::resetCounter('Device Media Images Skipped - No Image');
        ImportReport::resetCounter('Device Media Images Skipped - Current Newer');
        ImportReport::resetCounter('Device Media Images Updated');
        ImportReport::resetCounter('Device Media Errors');

        // Fetch enabled devices
        $available_Printers = [];
        foreach ($this->topdataDeviceService->_getEnabledDevices() as $pr) {
            $available_Printers[$pr['ws_id']] = true;
        }
        $availablePrintersCount = count($available_Printers);
        $processedPrintarsCount = 0;
        $chunkSize = self::BATCH_SIZE;
        CliLogger::writeln("Chunk size is $chunkSize devices");
        CliLogger::writeln("Available devices: $availablePrintersCount");
        $start = 0;
        $chunkNumber = 0;
        CliLogger::lap(true);

        while (true) {
            $chunkNumber++;
            CliLogger::activity("\nFetching media chunk $chunkNumber from remote server...");
            ImportReport::incCounter('Device Media Chunks');
            $models = $this->topdataWebserviceClient->getModels($chunkSize, $start);
            CliLogger::activity(CliLogger::lap() . 'sec. ');
            CliLogger::mem();
            CliLogger::writeln('');

            if (!isset($models->data) || count($models->data) == 0) {
                break;
            }

            $recordsInChunk = count($models->data);
            ImportReport::incCounter('Device Media Records Fetched', $recordsInChunk);
            CliLogger::activity("Processing data chunk $chunkNumber ($recordsInChunk records)");

            $processCounter = 1;
            foreach ($models->data as $s) {
                ImportReport::incCounter('Device Media Total Processed');

                if (!isset($available_Printers[$s->id])) {
                    ImportReport::incCounter('Device Media Devices Skipped - Not Available');
                    continue;
                }

                $processedPrintarsCount++;

                $processCounter++;
                if ($processCounter >= 4) {
                    $processCounter = 1;
                    CliLogger::activity();
                }

                $brand = $this->topdataBrandService->getBrandByWsId((int)$s->bId);
                if (!$brand) {
                    ImportReport::incCounter('Device Media Devices Skipped - No Brand');
                    continue;
                }

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

                if (!$device) {
                    ImportReport::incCounter('Device Media Devices Skipped - Device Not Found');
                    continue;
                }

                ImportReport::incCounter('Device Media Devices Found');
                $currentMedia = $device->getMedia();

                // Delete media if the image is null
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

                if (is_null($s->img)) {
                    ImportReport::incCounter('Device Media Images Skipped - No Image');
                    continue;
                }

                // Skip if the current media is newer than the fetched media
                if ($currentMedia && (date_timestamp_get($currentMedia->getCreatedAt()) > strtotime($s->img_date))) {
                    ImportReport::incCounter('Device Media Images Skipped - Current Newer');
                    continue;
                }

                $imageDate = strtotime(explode(' ', $s->img_date)[0]);

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
            CliLogger::writeln("processed $processedPrintarsCount of $availablePrintersCount devices " . CliLogger::lap() . 'sec. ');
            $start += $chunkSize;
            if (count($models->data) < $chunkSize) {
                break;
            }
        }

        // Final summary with all counters
        CliLogger::writeln('');
        CliLogger::writeln('=== Device Media Import Summary ===');
        CliLogger::writeln('Chunks processed: ' . ImportReport::getCounter('Device Media Chunks'));
        CliLogger::writeln('Total records fetched: ' . ImportReport::getCounter('Device Media Records Fetched'));
        CliLogger::writeln('Total records processed: ' . ImportReport::getCounter('Device Media Total Processed'));
        CliLogger::writeln('Devices found: ' . ImportReport::getCounter('Device Media Devices Found'));
        CliLogger::writeln('Devices skipped (not available): ' . ImportReport::getCounter('Device Media Devices Skipped - Not Available'));
        CliLogger::writeln('Devices skipped (no brand): ' . ImportReport::getCounter('Device Media Devices Skipped - No Brand'));
        CliLogger::writeln('Devices skipped (device not found): ' . ImportReport::getCounter('Device Media Devices Skipped - Device Not Found'));
        CliLogger::writeln('Images updated: ' . ImportReport::getCounter('Device Media Images Updated'));
        CliLogger::writeln('Images deleted: ' . ImportReport::getCounter('Device Media Images Deleted'));
        CliLogger::writeln('Images skipped (no image): ' . ImportReport::getCounter('Device Media Images Skipped - No Image'));
        CliLogger::writeln('Images skipped (current newer): ' . ImportReport::getCounter('Device Media Images Skipped - Current Newer'));
        CliLogger::writeln('Errors encountered: ' . ImportReport::getCounter('Device Media Errors'));
        CliLogger::writeln('Devices Media done');

        UtilProfiling::stopTimer();
    }
}