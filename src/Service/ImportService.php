<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Topdata\TopdataConnectorSW6\Constants\GlobalPluginConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportCommandCliOptionsDTO;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataFoundationSW6\Service\PluginHelperService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilMarkdown;

/**
 * Service class responsible for handling the import operations.
 *
 * @package Topdata\TopdataConnectorSW6\Service
 */
class ImportService
{

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
        private readonly SystemConfigService       $systemConfigService,
        private readonly MappingHelperService      $mappingHelperService,
        private readonly ConfigCheckerService      $configCheckerService,
        private readonly OptionsHelperService      $optionsHelperService,
        private readonly PluginHelperService       $pluginHelperService,
        private readonly ProductMappingService     $productMappingService,
        private readonly DeviceSynonymsService     $deviceSynonymsService,
        private readonly ProductInformationService $productInformationService,
    )
    {
    }

    public function execute(ImportCommandCliOptionsDTO $cliOptionsDto): int
    {
        CliLogger::writeln('Starting work...');

        // Check if plugin is active
        if (!$this->pluginHelperService->isWebserviceConnectorPluginAvailable()) {
            CliLogger::getCliStyle()->error('The TopdataConnectorSW6 plugin is inactive!');
            return self::ERROR_CODE_PLUGIN_INACTIVE;
        }

        // Check if plugin is configured
        if ($this->configCheckerService->isConfigEmpty()) {
            CliLogger::warning(GlobalPluginConstants::ERROR_MESSAGE_NO_WEBSERVICE_CREDENTIALS);
            // TODO: print some nice message using UtilMarkdown

            return self::ERROR_CODE_MISSING_PLUGIN_CONFIGURATION;
        }

        CliLogger::getCliStyle()->dumpDict($cliOptionsDto->toDict(), 'CLI Options DTO');

        // Init webservice client
        $this->initializeTopdataWebserviceClient();

        // Execute import operations based on options
        if ($errorCode = $this->executeImportOperations($cliOptionsDto)) {
            return $errorCode;
        }

        // Dump report
        CliLogger::getCliStyle()->dumpCounters(ImportReport::getCountersSorted(), 'Report');

        return self::ERROR_CODE_SUCCESS;
    }

    /**
     * Initializes the webservice client with the plugin configuration.
     */
    private function initializeTopdataWebserviceClient(): void
    {
//        $pluginConfig = $this->systemConfigService->get('TopdataConnectorSW6.config');
//        $topdataWebserviceClient = new TopdataWebserviceClient(
//            $pluginConfig['apiBaseUrl'],
//            $pluginConfig['apiUid'],
//            $pluginConfig['apiPassword'],
//            $pluginConfig['apiSecurityKey'],
//            $pluginConfig['apiLanguage']
//        );
//        $this->mappingHelperService->setTopdataWebserviceClient($topdataWebserviceClient);
//
        $this->_initOptions();
    }

    /**
     * Executes the import operations based on the provided CLI options.
     * @return int|null the error code or null if no error occurred
     */
    private function executeImportOperations(ImportCommandCliOptionsDTO $cliOptionsDto): ?int
    {
        // Mapping
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionMapping()) {
            CliLogger::getCliStyle()->blue('--all || --mapping');
            CliLogger::section('Mapping Products');
            $this->productMappingService->mapProducts();
        }

        // Device operations
        if ($result = $this->_handleDeviceOperations($cliOptionsDto)) {
            return $result;
        }

        // Product operations
        if ($result = $this->_handleProductOperations($cliOptionsDto)) {
            return $result;
        }

        return null;
    }

    /**
     * Handles device-related import operations.
     *
     * @param ImportCommandCliOptionsDTO $cliOptionsDto
     * @return int|null
     */
    private function _handleDeviceOperations(ImportCommandCliOptionsDTO $cliOptionsDto): ?int
    {
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionDevice()) {
            CliLogger::getCliStyle()->blue('--all || --device');
            if (
                !$this->mappingHelperService->setBrands()
                || !$this->mappingHelperService->setSeries()
                || !$this->mappingHelperService->setDeviceTypes()
                || !$this->mappingHelperService->setDevices()
            ) {
                CliLogger::getCliStyle()->error('Device import failed!');

                return self::ERROR_CODE_DEVICE_IMPORT_FAILED;
            }
        } elseif ($cliOptionsDto->getOptionDeviceOnly()) {
            CliLogger::getCliStyle()->blue('--device-only');
            if (!$this->mappingHelperService->setDevices()) {
                CliLogger::getCliStyle()->error('Device import failed!');

                return self::ERROR_CODE_DEVICE_IMPORT_FAILED;
            }
        }

        return null;
    }

    /**
     * Handles product-related import operations.
     */
    private function _handleProductOperations(ImportCommandCliOptionsDTO $cliOptionsDto): ?int
    {
        // Product to device linking
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionProduct()) {
            CliLogger::getCliStyle()->blue('--all || --product');
            if (!$this->mappingHelperService->setProducts()) {
                CliLogger::getCliStyle()->error('Set products to devices failed!');

                return self::ERROR_CODE_PRODUCT_TO_DEVICE_LINKING_FAILED;
            }
        }

        // Device media
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionDeviceMedia()) {
            CliLogger::getCliStyle()->blue('--all || --device-media');
            if (!$this->mappingHelperService->setDeviceMedia()) {
                CliLogger::getCliStyle()->error('Load device media failed!');
                return self::ERROR_CODE_LOAD_DEVICE_MEDIA_FAILED;
            }
        }

        // Product information
        if ($result = $this->_handleProductInformation($cliOptionsDto)) {
            return $result;
        }

        // Device synonyms
        if ($cliOptionsDto->getOptionAll() || $cliOptionsDto->getOptionDeviceSynonyms()) {
            CliLogger::getCliStyle()->blue('--all || --device-synonyms');
            if (!$this->deviceSynonymsService->setDeviceSynonyms()) {
                CliLogger::getCliStyle()->error('Set device synonyms failed!');

                return self::ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED;
            }
        }

        // Product variations
        if ($result = $this->_handleProductVariations($cliOptionsDto)) {
            return $result;
        }

        return null;
    }

    /**
     * Handles product information import operations.
     */
    /**
     * Handles product information import operations.
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
        $this->optionsHelperService->loadTopdataTopFeedPluginConfig();

        // ---- Load product information or update media
        if (!$this->productInformationService->setProductInformation($cliOptionsDto->getOptionProductMediaOnly())) {
            CliLogger::getCliStyle()->error('Load product information failed!');

            return self::ERROR_CODE_LOAD_PRODUCT_INFORMATION_FAILED;
        }

        return null;
    }


    /**
     * Handles product variations import operations.
     */
    private function _handleProductVariations(ImportCommandCliOptionsDTO $cliOptionsDto): ?int
    {
        if ($cliOptionsDto->getOptionProductVariations()) {
            if ($this->pluginHelperService->isTopFeedPluginAvailable()) {
                if (!$this->mappingHelperService->setProductColorCapacityVariants()) {
                    CliLogger::getCliStyle()->error('Set device synonyms failed!');

                    return self::ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED_2;
                }
            } else {
                CliLogger::warning('You need TopFeed plugin to create variated products!');
            }
        }

        return null;
    }

    public function _initOptions(): void
    {
        $configDefaults = [
            'attributeOem'         => '',
            'attributeEan'         => '',
            'attributeOrdernumber' => '',
        ];

        $pluginConfig = $this->systemConfigService->get('TopdataConnectorSW6.config');
        $pluginConfig = array_merge($configDefaults, $pluginConfig);

        $this->optionsHelperService->setOptions([
            OptionConstants::MAPPING_TYPE          => $pluginConfig['mappingType'],
            OptionConstants::ATTRIBUTE_OEM         => $pluginConfig['attributeOem'],
            OptionConstants::ATTRIBUTE_EAN         => $pluginConfig['attributeEan'],
            OptionConstants::ATTRIBUTE_ORDERNUMBER => $pluginConfig['attributeOrdernumber'],
        ]);
    }
}
