<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Topdata\TopdataConnectorSW6\Constants\GlobalPluginConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportCommandCliOptionsDTO;
use Topdata\TopdataConnectorSW6\Helper\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataFoundationSW6\Service\PluginHelperService;
use Topdata\TopdataFoundationSW6\Trait\CliStyleTrait;

/**
 * Service class responsible for handling the import operations.
 *
 * @package Topdata\TopdataConnectorSW6\Service
 */
class ImportService
{
    use CliStyleTrait;

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
        private readonly SystemConfigService  $systemConfigService,
        private readonly LoggerInterface      $logger,
        private readonly MappingHelperService $mappingHelperService,
        private readonly ConfigCheckerService $configCheckerService,
        private readonly OptionsHelperService $optionsHelperService,
        private readonly PluginHelperService  $pluginHelperService
    )
    {
    }

    public function execute(ImportCommandCliOptionsDTO $cliOptionsDto): int
    {
        $this->cliStyle->writeln('Starting work...');

        // Check if plugin is active
        if (!$this->pluginHelperService->isWebserviceConnectorPluginAvailable()) {
            $this->cliStyle->error('The TopdataConnectorSW6 plugin is inactive!');
            return self::ERROR_CODE_PLUGIN_INACTIVE;
        }

        // Check if plugin is configured
        if ($this->configCheckerService->isConfigEmpty()) {
            $this->cliStyle->warning(GlobalPluginConstants::ERROR_MESSAGE_NO_WEBSERVICE_CREDENTIALS);

            return self::ERROR_CODE_MISSING_PLUGIN_CONFIGURATION;
        }

        $this->cliStyle->dumpDict($cliOptionsDto->toDict(), 'CLI Options DTO');

        // Init webservice client
        $this->initializeWebserviceClient();

        // Execute import operations based on options
        if ($result = $this->executeImportOperations($cliOptionsDto)) {
            return $result;
        }

        // Dump report
        $this->cliStyle->dumpCounters(ImportReport::getCountersSorted(), 'Report');

        return self::ERROR_CODE_SUCCESS;
    }

    /**
     * Initializes the webservice client with the plugin configuration.
     */
    private function initializeWebserviceClient(): void
    {
        $pluginConfig = $this->systemConfigService->get('TopdataConnectorSW6.config');
        $topdataWebserviceClient = new TopdataWebserviceClient(
            $pluginConfig['apiBaseUrl'],
            $pluginConfig['apiUsername'],
            $pluginConfig['apiKey'],
            $pluginConfig['apiSalt'],
            $pluginConfig['apiLanguage']
        );
        $this->mappingHelperService->setTopdataWebserviceClient($topdataWebserviceClient);

        $configDefaults = [
            'attributeOem'         => '',
            'attributeEan'         => '',
            'attributeOrdernumber' => '',
        ];

        $pluginConfig = array_merge($configDefaults, $pluginConfig);

        $this->optionsHelperService->setOptions([
            OptionConstants::MAPPING_TYPE          => $pluginConfig['mappingType'],
            OptionConstants::ATTRIBUTE_OEM         => $pluginConfig['attributeOem'],
            OptionConstants::ATTRIBUTE_EAN         => $pluginConfig['attributeEan'],
            OptionConstants::ATTRIBUTE_ORDERNUMBER => $pluginConfig['attributeOrdernumber'],
        ]);
    }

    /**
     * Executes the import operations based on the provided CLI options.
     * @return int|null the error code or null if no error occurred
     */
    private function executeImportOperations(ImportCommandCliOptionsDTO $cliOptionsDto): ?int
    {
        // Mapping
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceMapping()) {
            $this->cliStyle->section('Mapping Products');
            if (!$this->mappingHelperService->mapProducts()) {
                $this->cliStyle->error('Mapping failed!');

                return self::ERROR_CODE_MAPPING_PRODUCTS_FAILED;
            }
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
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceDevice()) {
            if (
                !$this->mappingHelperService->setBrands()
                || !$this->mappingHelperService->setSeries()
                || !$this->mappingHelperService->setDeviceTypes()
                || !$this->mappingHelperService->setDevices()
            ) {
                $this->cliStyle->error('Device import failed!');

                return self::ERROR_CODE_DEVICE_IMPORT_FAILED;
            }
        } elseif ($cliOptionsDto->isServiceDeviceOnly()) {
            if (!$this->mappingHelperService->setDevices()) {
                $this->cliStyle->error('Device import failed!');

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
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceProduct()) {
            if (!$this->mappingHelperService->setProducts()) {
                $this->cliStyle->error('Set products to devices failed!');

                return self::ERROR_CODE_PRODUCT_TO_DEVICE_LINKING_FAILED;
            }
        }

        // Device media
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceDeviceMedia()) {
            if (!$this->mappingHelperService->setDeviceMedia()) {
                $this->cliStyle->error('Load device media failed!');
                return self::ERROR_CODE_LOAD_DEVICE_MEDIA_FAILED;
            }
        }

        // Product information
        if ($result = $this->_handleProductInformation($cliOptionsDto)) {
            return $result;
        }

        // Device synonyms
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceDeviceSynonyms()) {
            if (!$this->mappingHelperService->setDeviceSynonyms()) {
                $this->cliStyle->error('Set device synonyms failed!');

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
            !$cliOptionsDto->isServiceAll() &&
            !$cliOptionsDto->isServiceProductInformation() &&
            !$cliOptionsDto->isServiceProductMediaOnly()
        ) {
            return null;
        }

        // ---- Check if TopFeed plugin is available
        if (!$this->pluginHelperService->isTopFeedPluginAvailable()) {
            $this->cliStyle->writeln('You need TopFeed plugin to update product information!');

            return null;
        }

        // ---- go
        $this->optionsHelperService->loadTopdataTopFeedPluginConfig();

        // ---- Load product information or update media
        $isMediaOnlyUpdate = $cliOptionsDto->isServiceProductMediaOnly();
        if (!$this->mappingHelperService->setProductInformation($isMediaOnlyUpdate)) {
            $this->cliStyle->error('Load product information failed!');

            return self::ERROR_CODE_LOAD_PRODUCT_INFORMATION_FAILED;
        }

        return null;
    }


    /**
     * Handles product variations import operations.
     */
    private function _handleProductVariations(ImportCommandCliOptionsDTO $cliOptionsDto): ?int
    {
        if ($cliOptionsDto->isProductVariations()) {
            if ($this->pluginHelperService->isTopFeedPluginAvailable()) {
                if (!$this->mappingHelperService->setProductColorCapacityVariants()) {
                    $this->cliStyle->error('Set device synonyms failed!');

                    return self::ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED_2;
                }
            } else {
                $this->cliStyle->warning('You need TopFeed plugin to create variated products!');
            }
        }

        return null;
    }
}
