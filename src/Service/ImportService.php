<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Topdata\TopdataConnectorSW6\DTO\ImportConfig;
use Topdata\TopdataConnectorSW6\Exception\MissingPluginConfigurationException;
use Topdata\TopdataConnectorSW6\Exception\TopdataConnectorPluginInactiveException;
use Topdata\TopdataConnectorSW6\Service\Cache\MappingCacheService;
use Topdata\TopdataConnectorSW6\Service\Checks\ConfigCheckerService;
use Topdata\TopdataConnectorSW6\Service\Config\MergedPluginConfigHelperService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataDeviceSynonymsService;
use Topdata\TopdataConnectorSW6\Service\Import\DeviceImportService;
use Topdata\TopdataConnectorSW6\Service\Import\DeviceMediaImportService;
use Topdata\TopdataConnectorSW6\Service\Import\MappingHelperService;
use Topdata\TopdataConnectorSW6\Service\Import\ProductMappingService;
use Topdata\TopdataConnectorSW6\Service\Linking\ProductDeviceRelationshipServiceV1;
use Topdata\TopdataConnectorSW6\Service\Linking\ProductDeviceRelationshipServiceV2;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataFoundationSW6\Service\PluginHelperService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Symfony\Component\Console\Helper\Table;

/**
 * Service class responsible for handling the import operations.
 * This class orchestrates the import process, coordinating various helper services
 * to map products, import device information, and link products to devices.
 * It also handles loading device media, setting device synonyms, and updating product information.
 */
class ImportService
{
    public function __construct(
        private readonly MappingHelperService               $mappingHelperService,
        private readonly ConfigCheckerService               $configCheckerService,
        private readonly MergedPluginConfigHelperService    $mergedPluginConfigHelperService,
        private readonly PluginHelperService                $pluginHelperService,
        private readonly ProductMappingService              $productMappingService,
        private readonly TopdataDeviceSynonymsService       $deviceSynonymsService,
        private readonly ProductInformationServiceV1Slow    $productInformationServiceV1Slow,
        private readonly ProductInformationServiceV2        $productInformationServiceV2,
        private readonly ProductDeviceRelationshipServiceV1 $productDeviceRelationshipServiceV1,
        private readonly ProductDeviceRelationshipServiceV2 $productDeviceRelationshipServiceV2,
        private readonly DeviceImportService                $deviceImportService,
        private readonly DeviceMediaImportService           $deviceMediaImportService, // Added for refactoring
        private readonly MappingCacheService                $mappingCacheService, // Added for cache integration
    )
    {
    }

    private static array $counterDescriptions = [
        'linking_v2.products.found' => 'Total unique Shopware product IDs identified for processing.',
        'linking_v2.products.chunks' => 'Number of chunks the product IDs were split into.',
        'linking_v2.chunks.processed' => 'Number of chunks successfully processed.',
        'linking_v2.webservice.calls' => 'Number of webservice calls made to fetch device links.',
        'linking_v2.webservice.device_ids_fetched' => 'Total unique device webservice IDs fetched from the webservice.',
        'linking_v2.database.devices_found' => 'Total corresponding devices found in the local database.',
        'linking_v2.links.deleted' => 'Total number of existing device-product links deleted across all chunks.',
        'linking_v2.links.inserted' => 'Total number of new device-product links inserted across all chunks.',
        'linking_v2.status.devices.enabled' => 'Total number of devices marked as enabled.',
        'linking_v2.status.devices.disabled' => 'Total number of devices marked as disabled.',
        'linking_v2.status.brands.enabled' => 'Total number of brands marked as enabled.',
        'linking_v2.status.brands.disabled' => 'Total number of brands marked as disabled.',
        'linking_v2.status.series.enabled' => 'Total number of series marked as enabled.',
        'linking_v2.status.series.disabled' => 'Total number of series marked as disabled.',
        'linking_v2.status.types.enabled' => 'Total number of device types marked as enabled.',
        'linking_v2.status.types.disabled' => 'Total number of device types marked as disabled.',
        'linking_v2.active.devices' => 'Final count of active devices at the end of the process.',
        'linking_v2.active.brands' => 'Final count of active brands at the end of the process.',
        'linking_v2.active.series' => 'Final count of active series at the end of the process.',
        'linking_v2.active.types' => 'Final count of active device types at the end of the process.',
    ];

    /**
     * ==== MAIN ====
     *
     * Executes the import process based on the provided CLI options.
     *
     * This method serves as the main entry point for the import operation.
     * It checks plugin status, configuration, and then dispatches to specific
     * import operations based on the provided CLI options.
     *
     * @param ImportConfig $importConfig The DTO containing the CLI options.
     * @return int The error code indicating the success or failure of the import process.
     * @throws MissingPluginConfigurationException
     */
    public function execute(ImportConfig $importConfig): void
    {
        CliLogger::writeln('Starting work...');

        // ---- Check if plugin is active (can this ever happen? as this code is part of the plugin .. TODO?: remove this check)
        if (!$this->pluginHelperService->isWebserviceConnectorPluginAvailable()) {
            throw new TopdataConnectorPluginInactiveException("The TopdataConnectorSW6 plugin is inactive!");
        }

        // ---- Check if plugin is configured
        if ($this->configCheckerService->isConfigEmpty()) {
            throw new MissingPluginConfigurationException();
        }

        CliLogger::getCliStyle()->dumpDict($importConfig->toDict(), 'ImportConfig');

        // ---- Init webservice client
        $this->mergedPluginConfigHelperService->init();

        // ---- Handle cache purging if requested
        $this->handleCachePurging($importConfig);

        // ---- Execute import operations based on options
        $this->executeImportOperations($importConfig);

        // ---- Dump report
        $io = CliLogger::getCliStyle();
        $counters = ImportReport::getCountersSorted();
        $descriptions = self::$counterDescriptions;

        $table = new Table($io);
        $table->setHeaders(['Counter', 'Value', 'Description']);

        foreach ($counters as $key => $value) {
            $description = $descriptions[$key] ?? ''; // Handle missing descriptions
            $table->addRow([$key, $value, $description]);
        }

        $io->title('Counters Report');
        $table->render();

        // ---- Dump profiling
        UtilProfiling::dumpProfilingToCli();
    }


    /**
     * Executes the import operations based on the provided CLI options.
     *
     * This method determines which import operations to execute based on the
     * options provided in the ImportCommandImportConfig. It calls the relevant
     * helper methods to perform the import operations.
     *
     * @param ImportConfig $importConfig The DTO containing the CLI options.
     */
    private function executeImportOperations(ImportConfig $importConfig): void
    {
        // ---- Product Mapping
        if ($importConfig->getOptionAll() || $importConfig->getOptionMapping()) {
            CliLogger::getCliStyle()->blue('--all || --mapping');
            CliLogger::section('Mapping Products');
            $this->productMappingService->mapProducts($importConfig);
        }

        // ---- Device operations
        $this->_handleDeviceOperations($importConfig);

        // ---- Product operations
        $this->_handleProductOperations($importConfig);
    }

    /**
     * Handles device-related import operations.
     *
     * This method imports brands, series, device types and devices.
     *
     * @param ImportConfig $importConfig The DTO containing the CLI options.
     */
    private function _handleDeviceOperations(ImportConfig $importConfig): void
    {
        // ---- Import all device related data
        if ($importConfig->getOptionAll() || $importConfig->getOptionDevice()) {
            CliLogger::getCliStyle()->blue('--all || --device');
            $this->mappingHelperService->setBrands();
            $this->deviceImportService->setSeries();
            $this->deviceImportService->setDeviceTypes();
            $this->deviceImportService->setDevices();
        } elseif ($importConfig->getOptionDeviceOnly()) {
            // ---- Import only devices (TODO: remove this option)
            CliLogger::getCliStyle()->blue('--device-only');
            $this->deviceImportService->setDevices();
        }
    }

    /**
     * TODO: remove the return of an error code, just throw a exceptions
     * Handles product-related import operations.
     *
     * This method manages the import of product-related data, including linking products to devices,
     * loading device media, handling product information, and setting device synonyms.
     *
     * @param ImportConfig $importConfig The DTO containing the CLI options.
     */
    private function _handleProductOperations(ImportConfig $importConfig): void
    {
        // ---- Product to device linking
        if ($importConfig->getOptionAll() || $importConfig->getOptionProductDevice()) {
            CliLogger::getCliStyle()->blue('--all || --product-device');
            if ($importConfig->getOptionExperimentalV2()) {
                CliLogger::getCliStyle()->caution('Using experimental V2 device linking logic!');
                $this->productDeviceRelationshipServiceV2->syncDeviceProductRelationshipsV2();
            } else {
                // Keep the original call as the default
                $this->productDeviceRelationshipServiceV1->syncDeviceProductRelationshipsV1();
            }
        }

        // ---- Device media
        if ($importConfig->getOptionAll() || $importConfig->getOptionDeviceMedia()) {
            CliLogger::getCliStyle()->blue('--all || --device-media');
            $this->deviceMediaImportService->setDeviceMedia(); // Use the new dedicated service
        }

        // ---- Product information
        $this->_handleProductInformation($importConfig);

        // ---- Device synonyms
        if ($importConfig->getOptionAll() || $importConfig->getOptionDeviceSynonyms()) {
            CliLogger::getCliStyle()->blue('--all || --device-synonyms');
            $this->deviceSynonymsService->setDeviceSynonyms();
        }

        // ---- Product variations
        $this->_handleProductVariations($importConfig);
    }

    /**
     * Handles product information import operations.
     *
     * This method imports or updates product information based on the provided CLI options.
     * It checks if the TopFeed plugin is available and then uses the ProductInformationServiceV1Slow
     * to set the product information.
     *
     * @param ImportConfig $importConfig The DTO containing the CLI options.
     */
    private function _handleProductInformation(ImportConfig $importConfig): void
    {
        // ---- Determine if product-related operation should be processed based on CLI options.
        if (
            !$importConfig->getOptionAll() &&
            !$importConfig->getOptionProductInformation() &&
            !$importConfig->getOptionProductMediaOnly()
        ) {
            return;
        }

        // ---- Check if TopFeed plugin is available
        if (!$this->pluginHelperService->isTopFeedPluginAvailable()) {
            CliLogger::writeln('You need TopFeed plugin to update product information!');

            return;
        }

        // ---- Load product information or update media
        if ($importConfig->getOptionExperimentalV2()) {
            $this->productInformationServiceV2->setProductInformationV2();
        } else {
            $this->productInformationServiceV1Slow->setProductInformationV1Slow($importConfig->getOptionProductMediaOnly());
        }
    }


    /**
     * Handles product variations import operations.
     *
     * This method creates product variations based on color and capacity, if the TopFeed plugin is available.
     *
     * @param ImportConfig $importConfig The DTO containing the CLI options.
     */
    private function _handleProductVariations(ImportConfig $importConfig): void
    {
        // ---- Check if product variations should be created
        if ($importConfig->getOptionProductVariations()) {
            // ---- Check if TopFeed plugin is available
            if ($this->pluginHelperService->isTopFeedPluginAvailable()) {
                // ---- Create product variations
                $this->mappingHelperService->setProductColorCapacityVariants();
            } else {
                CliLogger::warning('You need TopFeed plugin to create variated products!');
            }
        }
    }

    /**
     * Handles cache purging if requested via the --purge-cache option.
     *
     * @param ImportConfig $importConfig The DTO containing the CLI options.
     */
    private function handleCachePurging(ImportConfig $importConfig): void
    {
        if ($importConfig->getOptionPurgeCache()) {
            CliLogger::getCliStyle()->blue('--purge-cache');
            CliLogger::section('Purging Mapping Cache');
            
            // Purge the cache
            $this->mappingCacheService->purgeMappingsCache();
            
            CliLogger::success('Mapping cache purged successfully.');
        }
    }

}