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
            CliLogger::activity("\nFetching media chunk $chunkNumber from remote server...");
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

                $brand = $this->topdataBrandService->getBrandByWsId((int)$s->bId);
                if (!$brand) {
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
                    continue;
                }

                $currentMedia = $device->getMedia();

                // Delete media if the image is null
                if (is_null($s->img) && $currentMedia) {
                    $this->topdataDeviceRepository->update([
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
                        $this->topdataDeviceRepository->update([
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
}