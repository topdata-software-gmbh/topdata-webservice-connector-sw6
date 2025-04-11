<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataConnectorSW6\Constants\GlobalPluginConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportCommandCliOptionsDTO;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataDeviceSynonymsService;
use Topdata\TopdataConnectorSW6\Service\Import\DeviceImportService;
use Topdata\TopdataConnectorSW6\Service\Import\MappingHelperService;
use Topdata\TopdataConnectorSW6\Service\Import\ProductMappingService;
use Topdata\TopdataConnectorSW6\Service\Linking\ProductDeviceRelationshipService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
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

    /**
     * TODO create new TopdataImportException with these error codes
     */
    // Error codes for various failure scenarios
    const ERROR_CODE_SUCCESS                          = 0;
    const ERROR_CODE_PLUGIN_INACTIVE                  = 1;
    const ERROR_CODE_MISSING_PLUGIN_CONFIGURATION     = 2;
    const ERROR_CODE_MAPPING_PRODUCTS_FAILED          = 3;
    const ERROR_CODE_DEVICE_IMPORT_FAILED             = 4;
    const ERROR_CODE_PRODUCT_TO_DEVICE_LINKING_FAILED = 5;
    const ERROR_CODE_LOAD_DEVICE_MEDIA_FAILED         = 6;
    const ERROR_CODE_LOAD_PRODUCT_INFORMATION_FAILED  = 7;
    const ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED       = 8;
    const ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED_2     = 9;



    public function __construct(
        private readonly SystemConfigService              $systemConfigService,
        private readonly MappingHelperService             $mappingHelperService,
        private readonly ConfigCheckerService             $configCheckerService,
        private readonly TopfeedOptionsHelperService      $topfeedOptionsHelperService,
        private readonly PluginHelperService              $pluginHelperService,
        private readonly ProductMappingService            $productMappingService,
        private readonly TopdataDeviceSynonymsService     $deviceSynonymsService,
        private readonly ProductInformationService        $productInformationService,
        private readonly ProductDeviceRelationshipService $productDeviceRelationshipService,
        private readonly DeviceImportService              $deviceImportService,
    )
    {
    }

    /**
     * Executes the import process based on the provided CLI options.
     *
     * This method serves as the main entry point for the import operation.
     * It checks plugin status, configuration, and then dispatches to specific
     * import operations based on the provided CLI options.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     * @return int The error code indicating the success or failure of the import process.
     */
    public function execute(ImportCommandCliOptionsDTO $cliOptionsDto): int
    {
        CliLogger::writeln('Starting work...');

        // ---- Check if plugin is active
        if (!$this->pluginHelperService->isWebserviceConnectorPluginAvailable()) {
            CliLogger::error('The TopdataConnectorSW6 plugin is inactive!');
            return self::ERROR_CODE_PLUGIN_INACTIVE;
        }

        // ---- Check if plugin is configured
        if ($this->configCheckerService->isConfigEmpty()) {
            CliLogger::warning(GlobalPluginConstants::ERROR_MESSAGE_NO_WEBSERVICE_CREDENTIALS);
            // TODO: print some nice message using UtilMarkdown

            return self::ERROR_CODE_MISSING_PLUGIN_CONFIGURATION;
        }

        CliLogger::getCliStyle()->dumpDict($cliOptionsDto->toDict(), 'CLI Options DTO');

        // ---- Init webservice client
        $this->_initOptions();

        // ---- Execute import operations based on options
        if ($errorCode = $this->executeImportOperations($cliOptionsDto)) {
            return $errorCode;
        }

        // ---- Dump report
        CliLogger::getCliStyle()->dumpCounters(ImportReport::getCountersSorted(), 'Report');

        return self::ERROR_CODE_SUCCESS;
    }


    /**
     * Executes the import operations based on the provided CLI options.
     *
     * This method determines which import operations to execute based on the
     * options provided in the ImportCommandCliOptionsDTO. It calls the relevant
     * helper methods to perform the import operations.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     * @return int|null the error code or null if no error occurred.. todo remove the error code thing. just use exceptions
     */
    private function executeImportOperations(ImportCommandCliOptionsDTO $cliOptionsDto): ?int
    {
        // ---- Mapping
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionMapping()) {
            CliLogger::getCliStyle()->blue('--all || --mapping');
            CliLogger::section('Mapping Products');
            $this->productMappingService->mapProducts();
        }

        // ---- Device operations
        if ($result = $this->_handleDeviceOperations($cliOptionsDto)) {
            return $result;
        }

        // ---- Product operations
        if ($result = $this->_handleProductOperations($cliOptionsDto)) {
            return $result;
        }

        return null;
    }

    /**
     * Handles device-related import operations.
     *
     * This method imports brands, series, device types and devices.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     * @return int|null An error code if the device import fails, or null if successful.
     */
    private function _handleDeviceOperations(ImportCommandCliOptionsDTO $cliOptionsDto): ?int
    {
        // ---- Import all device related data
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionDevice()) {
            CliLogger::getCliStyle()->blue('--all || --device');
            if (
                !$this->mappingHelperService->setBrands()
                || !$this->deviceImportService->setSeries()
                || !$this->deviceImportService->setDeviceTypes()
                || !$this->deviceImportService->setDevices()
            ) {
                CliLogger::error('Device import failed!');

                return self::ERROR_CODE_DEVICE_IMPORT_FAILED;
            }
        } elseif ($cliOptionsDto->getOptionDeviceOnly()) {
            // ---- Import only devices
            CliLogger::getCliStyle()->blue('--device-only');
            if (!$this->deviceImportService->setDevices()) {
                CliLogger::error('Device import failed!');

                return self::ERROR_CODE_DEVICE_IMPORT_FAILED;
            }
        }

        return null;
    }

    /**
     * Handles product-related import operations.
     *
     * This method manages the import of product-related data, including linking products to devices,
     * loading device media, handling product information, and setting device synonyms.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     * @return int|null An error code if any of the product import operations fail, or null if all are successful.
     */
    private function _handleProductOperations(ImportCommandCliOptionsDTO $cliOptionsDto): ?int
    {
        // ---- Product to device linking
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionProduct()) {
            CliLogger::getCliStyle()->blue('--all || --product');
            if (!$this->productDeviceRelationshipService->syncDeviceProductRelationships()) {
                CliLogger::error('Set products to devices failed!');

                return self::ERROR_CODE_PRODUCT_TO_DEVICE_LINKING_FAILED;
            }
        }

        // ---- Device media
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionDeviceMedia()) {
            CliLogger::getCliStyle()->blue('--all || --device-media');
            if (!$this->deviceImportService->setDeviceMedia()) {
                CliLogger::error('Load device media failed!');
                return self::ERROR_CODE_LOAD_DEVICE_MEDIA_FAILED;
            }
        }

        // ---- Product information
        if ($result = $this->_handleProductInformation($cliOptionsDto)) {
            return $result;
        }

        // ---- Device synonyms
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionDeviceSynonyms()) {
            CliLogger::getCliStyle()->blue('--all || --device-synonyms');
            if (!$this->deviceSynonymsService->setDeviceSynonyms()) {
                CliLogger::error('Set device synonyms failed!');

                return self::ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED;
            }
        }

        // ---- Product variations
        if ($result = $this->_handleProductVariations($cliOptionsDto)) {
            return $result;
        }

        return null;
    }

    /**
     * Handles product information import operations.
     *
     * This method imports or updates product information based on the provided CLI options.
     * It checks if the TopFeed plugin is available and then uses the ProductInformationService
     * to set the product information.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     * @return int|null An error code if loading product information fails, or null if successful.
     */
    private function _handleProductInformation(ImportCommandCliOptionsDTO $cliOptionsDto): ?int
    {
        // ---- Determine if product-related operation should be processed based on CLI options.
        if (
            !$cliOptionsDto->getOptionAll() &&
            !$cliOptionsDto->getOptionProductInformation() &&
            !$cliOptionsDto->getOptionProductMediaOnly()
        ) {
            return null;
        }

        // ---- Check if TopFeed plugin is available
        if (!$this->pluginHelperService->isTopFeedPluginAvailable()) {
            CliLogger::writeln('You need TopFeed plugin to update product information!');

            return null;
        }

        // ---- go
        $this->topfeedOptionsHelperService->loadTopdataTopFeedPluginConfig();

        // ---- Load product information or update media
        if (!$this->productInformationService->setProductInformation($cliOptionsDto->getOptionProductMediaOnly())) {
            CliLogger::error('Load product information failed!');

            return self::ERROR_CODE_LOAD_PRODUCT_INFORMATION_FAILED;
        }

        return null;
    }


    /**
     * Handles product variations import operations.
     *
     * This method creates product variations based on color and capacity, if the TopFeed plugin is available.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto The DTO containing the CLI options.
     * @return int|null An error code if setting device synonyms fails during product variation creation, or null if successful.
     */
    private function _handleProductVariations(ImportCommandCliOptionsDTO $cliOptionsDto): ?int
    {
        // ---- Check if product variations should be created
        if ($cliOptionsDto->getOptionProductVariations()) {
            // ---- Check if TopFeed plugin is available
            if ($this->pluginHelperService->isTopFeedPluginAvailable()) {
                // ---- Create product variations
                if (!$this->mappingHelperService->setProductColorCapacityVariants()) {
                    CliLogger::error('Set device synonyms failed!');

                    return self::ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED_2;
                }
            } else {
                CliLogger::warning('You need TopFeed plugin to create variated products!');
            }
        }

        return null;
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