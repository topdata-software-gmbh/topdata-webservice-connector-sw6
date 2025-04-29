<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataConnectorSW6\Constants\GlobalPluginConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportCommandCliOptionsDTO;
use Topdata\TopdataConnectorSW6\Exception\MissingPluginConfigurationException;
use Topdata\TopdataConnectorSW6\Exception\TopdataConnectorPluginInactiveException;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataDeviceSynonymsService;
use Topdata\TopdataConnectorSW6\Service\Import\DeviceImportService;
use Topdata\TopdataConnectorSW6\Service\Import\MappingHelperService;
use Topdata\TopdataConnectorSW6\Service\Import\ProductMappingService;
use Topdata\TopdataConnectorSW6\Service\Linking\ProductDeviceRelationshipService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataFoundationSW6\Service\PluginHelperService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Service class responsible for handling the import operations.
 * This class orchestrates the import process, coordinating various helper services
 * to map products, import device information, and link products to devices.
 * It also handles loading device media, setting device synonyms, and updating product information.
 */
class ImportService
{
    public function __construct(
        private readonly SystemConfigService              $systemConfigService,
        private readonly MappingHelperService             $mappingHelperService,
        private readonly ConfigCheckerService             $configCheckerService,
        private readonly TopfeedOptionsHelperService      $topfeedOptionsHelperService,
        private readonly PluginHelperService              $pluginHelperService,
        private readonly ProductMappingService            $productMappingService,
        private readonly TopdataDeviceSynonymsService     $deviceSynonymsService,
        private readonly ProductInformationServiceV1Slow  $productInformationServiceV1Slow,
        private readonly ProductInformationServiceV2      $productInformationServiceV2,
        private readonly ProductDeviceRelationshipService $productDeviceRelationshipService,
        private readonly DeviceImportService              $deviceImportService,
    )
    {
    }

    /**
     * ==== MAIN ====
     *
     * Executes the import process based on the provided CLI options.
     *
     * This method serves as the main entry point for the import operation.
     * It checks plugin status, configuration, and then dispatches to specific
     * import operations based on the provided CLI options.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     * @return int The error code indicating the success or failure of the import process.
     * @throws MissingPluginConfigurationException
     */
    public function execute(ImportCommandCliOptionsDTO $cliOptionsDto): void
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

        CliLogger::getCliStyle()->dumpDict($cliOptionsDto->toDict(), 'CLI Options DTO');

        // ---- Init webservice client
        $this->_initOptions();

        // ---- Execute import operations based on options
        $this->executeImportOperations($cliOptionsDto);

        // ---- Dump report
        CliLogger::getCliStyle()->dumpCounters(ImportReport::getCountersSorted(), 'Counters Report');

        // ---- Dump profiling
        UtilProfiling::dumpProfilingToCli();
    }


    /**
     * Executes the import operations based on the provided CLI options.
     *
     * This method determines which import operations to execute based on the
     * options provided in the ImportCommandCliOptionsDTO. It calls the relevant
     * helper methods to perform the import operations.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     */
    private function executeImportOperations(ImportCommandCliOptionsDTO $cliOptionsDto): void
    {
        // ---- Product Mapping
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionMapping()) {
            CliLogger::getCliStyle()->blue('--all || --mapping');
            CliLogger::section('Mapping Products');
            $this->productMappingService->mapProducts();
        }

        // ---- Device operations
        $this->_handleDeviceOperations($cliOptionsDto);

        // ---- Product operations
        $this->_handleProductOperations($cliOptionsDto);
    }

    /**
     * Handles device-related import operations.
     *
     * This method imports brands, series, device types and devices.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     */
    private function _handleDeviceOperations(ImportCommandCliOptionsDTO $cliOptionsDto): void
    {
        // ---- Import all device related data
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionDevice()) {
            CliLogger::getCliStyle()->blue('--all || --device');
            $this->mappingHelperService->setBrands();
            $this->deviceImportService->setSeries();
            $this->deviceImportService->setDeviceTypes();
            $this->deviceImportService->setDevices();
        } elseif ($cliOptionsDto->getOptionDeviceOnly()) {
            // ---- Import only devices
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
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     */
    private function _handleProductOperations(ImportCommandCliOptionsDTO $cliOptionsDto): void
    {
        // ---- Product to device linking
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionProduct()) {
            CliLogger::getCliStyle()->blue('--all || --product');
            $this->productDeviceRelationshipService->syncDeviceProductRelationships();
        }

        // ---- Device media
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionDeviceMedia()) {
            CliLogger::getCliStyle()->blue('--all || --device-media');
            $this->deviceImportService->setDeviceMedia();
        }

        // ---- Product information
        $this->_handleProductInformation($cliOptionsDto);

        // ---- Device synonyms
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionDeviceSynonyms()) {
            CliLogger::getCliStyle()->blue('--all || --device-synonyms');
            $this->deviceSynonymsService->setDeviceSynonyms();
        }

        // ---- Product variations
        $this->_handleProductVariations($cliOptionsDto);
    }

    /**
     * Handles product information import operations.
     *
     * This method imports or updates product information based on the provided CLI options.
     * It checks if the TopFeed plugin is available and then uses the ProductInformationServiceV1Slow
     * to set the product information.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     */
    private function _handleProductInformation(ImportCommandCliOptionsDTO $cliOptionsDto): void
    {
        // ---- Determine if product-related operation should be processed based on CLI options.
        if (
            !$cliOptionsDto->getOptionAll() &&
            !$cliOptionsDto->getOptionProductInformation() &&
            !$cliOptionsDto->getOptionProductMediaOnly()
        ) {
            return;
        }

        // ---- Check if TopFeed plugin is available
        if (!$this->pluginHelperService->isTopFeedPluginAvailable()) {
            CliLogger::writeln('You need TopFeed plugin to update product information!');

            return;
        }

        // ---- go
        $this->topfeedOptionsHelperService->loadTopdataTopFeedPluginConfig();

        // ---- Load product information or update media
        if ($cliOptionsDto->getOptionExperimentalV2()) {
            $this->productInformationServiceV2->setProductInformationV2();
        } else {
            $this->productInformationServiceV1Slow->setProductInformationV1Slow($cliOptionsDto->getOptionProductMediaOnly());
        }
    }


    /**
     * Handles product variations import operations.
     *
     * This method creates product variations based on color and capacity, if the TopFeed plugin is available.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     */
    private function _handleProductVariations(ImportCommandCliOptionsDTO $cliOptionsDto): void
    {
        // ---- Check if product variations should be created
        if ($cliOptionsDto->getOptionProductVariations()) {
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
     * Initializes the options for the import process.
     *
     * This method retrieves configuration settings from the system configuration
     * and sets the corresponding options in the OptionsHelperService.
     */
    public function _initOptions(): void
    {
        $configDefaults = [
            'attributeOem'         => '',
            'attributeEan'         => '',
            'attributeOrdernumber' => '',  // fixme: this is not an ordernumber, but a product number
        ];

        $pluginConfig = $this->systemConfigService->get('TopdataConnectorSW6.config');
        $pluginConfig = array_merge($configDefaults, $pluginConfig);

        $this->topfeedOptionsHelperService->setOptions([
            OptionConstants::MAPPING_TYPE          => $pluginConfig['mappingType'],
            OptionConstants::ATTRIBUTE_OEM         => $pluginConfig['attributeOem'],
            OptionConstants::ATTRIBUTE_EAN         => $pluginConfig['attributeEan'],
            OptionConstants::ATTRIBUTE_ORDERNUMBER => $pluginConfig['attributeOrdernumber'],
        ]);
    }


}