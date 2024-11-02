<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportCommandCliOptionsDTO;
use Topdata\TopdataConnectorSW6\Helper\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataFoundationSW6\Helper\CliStyle;

/**
 * 11/2024 created (extracted from ImportCommand)
 */
class ImportService
{
    const ERROR_CODE_PLUGIN_INACTIVE                  = 1;
    const ERROR_CODE_MISSING_PLUGIN_CONFIGURATION     = 2;
    const ERROR_CODE_MAPPING_PRODUCTS_FAILED          = 3;
    const ERROR_CODE_DEVICE_IMPORT_FAILED             = 4;
    const ERROR_CODE_PRODUCT_TO_DEVICE_LINKING_FAILED = 5;
    const ERROR_CODE_LOAD_DEVICE_MEDIA_FAILED         = 6;
    const ERROR_CODE_LOAD_PRODUCT_INFORMATION_FAILED  = 7;
    const ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED       = 8;
    const ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED_2     = 9;

    private bool $verbose = true;

    public function __construct(
        private readonly SystemConfigService   $systemConfigService,
        private readonly ContainerBagInterface $containerBag,
        private readonly LoggerInterface       $logger,
        private readonly MappingHelperService  $mappingHelperService,
        private readonly ConfigCheckerService  $configCheckerService,
        private readonly OptionsHelperService  $optionsHelperService,
    )
    {
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
        $this->mappingHelperService->setVerbose($verbose);
    }

    /**
     * ===== MAIN ====
     *
     * 11/2024 created
     */
    public function execute(ImportCommandCliOptionsDTO $cliOptionsDto, CliStyle $cliStyle): int
    {
        $this->mappingHelperService->setCliStyle($cliStyle);

        if ($this->verbose) {
            $cliStyle->writeln('Starting work...');
        }

        // Check if plugin is active
        $activePlugins = $this->containerBag->get('kernel.active_plugins');
        if (!isset($activePlugins['Topdata\TopdataConnectorSW6\TopdataConnectorSW6'])) {
            if ($this->verbose) {
                $cliStyle->error('The TopdataConnectorSW6 plugin is inactive!');
            }
            return self::ERROR_CODE_PLUGIN_INACTIVE;
        }

        // Check if plugin is configured
        if ($this->configCheckerService->isConfigEmpty()) {
            if ($this->verbose) {
                $cliStyle->writeln('Fill in the connection parameters in admin: Extensions > My Extensions > Topdata Webservice Connector > [...] > Configure');
            }
            return self::ERROR_CODE_MISSING_PLUGIN_CONFIGURATION;
        }

        $cliStyle->dumpDict($cliOptionsDto->toDict(), 'CLI Options DTO');

        // Init webservice client
        $this->initializeWebserviceClient();

        // Execute import operations based on options
        if ($result = $this->executeImportOperations($cliOptionsDto, $activePlugins, $cliStyle)) {
            return $result;
        }

        // Dump report
        $cliStyle->dumpCounters(ImportReport::getCountersSorted(), 'Report');

        return Command::SUCCESS;
    }

    private function initializeWebserviceClient(): void
    {
        $pluginConfig = $this->systemConfigService->get('TopdataConnectorSW6.config');
        $topdataWebserviceClient = new TopdataWebserviceClient(
            $this->logger,
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

    private function executeImportOperations(
        ImportCommandCliOptionsDTO $cliOptionsDto,
        array                      $activePlugins,
        CliStyle                   $cliStyle
    ): ?int
    {
        // Mapping
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceMapping()) {
            $cliStyle->section('Mapping Products');
            if (!$this->mappingHelperService->mapProducts()) {
                if ($this->verbose) {
                    $cliStyle->error('Mapping failed!');
                }
                return self::ERROR_CODE_MAPPING_PRODUCTS_FAILED;
            }
        }

        // Device operations
        if ($result = $this->handleDeviceOperations($cliOptionsDto, $cliStyle)) {
            return $result;
        }

        // Product operations
        if ($result = $this->handleProductOperations($cliOptionsDto, $activePlugins, $cliStyle)) {
            return $result;
        }

        return null;
    }

    private function handleDeviceOperations(ImportCommandCliOptionsDTO $cliOptionsDto, CliStyle $cliStyle): ?int
    {
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceDevice()) {
            if (
                !$this->mappingHelperService->setBrands()
                || !$this->mappingHelperService->setSeries()
                || !$this->mappingHelperService->setDeviceTypes()
                || !$this->mappingHelperService->setDevices()
            ) {
                if ($this->verbose) {
                    $cliStyle->error('Device import failed!');
                }
                return self::ERROR_CODE_DEVICE_IMPORT_FAILED;
            }
        } elseif ($cliOptionsDto->isServiceDeviceOnly()) {
            if (!$this->mappingHelperService->setDevices()) {
                if ($this->verbose) {
                    $cliStyle->error('Device import failed!');
                }
                return self::ERROR_CODE_DEVICE_IMPORT_FAILED;
            }
        }

        return null;
    }

    private function handleProductOperations(
        ImportCommandCliOptionsDTO $cliOptionsDto,
        array                      $activePlugins,
        CliStyle                   $cliStyle
    ): ?int
    {
        // Product to device linking
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceProduct()) {
            if (!$this->mappingHelperService->setProducts()) {
                if ($this->verbose) {
                    $cliStyle->error('Set products to devices failed!');
                }
                return self::ERROR_CODE_PRODUCT_TO_DEVICE_LINKING_FAILED;
            }
        }

        // Device media
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceDeviceMedia()) {
            if (!$this->mappingHelperService->setDeviceMedia()) {
                if ($this->verbose) {
                    $cliStyle->error('Load device media failed!');
                }
                return self::ERROR_CODE_LOAD_DEVICE_MEDIA_FAILED;
            }
        }

        // Product information
        if ($result = $this->handleProductInformation($cliOptionsDto, $activePlugins, $cliStyle)) {
            return $result;
        }

        // Device synonyms
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceDeviceSynonyms()) {
            if (!$this->mappingHelperService->setDeviceSynonyms()) {
                if ($this->verbose) {
                    $cliStyle->error('Set device synonyms failed!');
                }
                return self::ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED;
            }
        }

        // Product variations
        if ($result = $this->handleProductVariations($cliOptionsDto, $activePlugins, $cliStyle)) {
            return $result;
        }

        return null;
    }

    private function handleProductInformation(
        ImportCommandCliOptionsDTO $cliOptionsDto,
        array                      $activePlugins,
        CliStyle                   $cliStyle
    ): ?int
    {
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceProductInformation()) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                $this->optionsHelperService->loadTopdataTopFeedPluginConfig();
                if (!$this->mappingHelperService->setProductInformation()) {
                    if ($this->verbose) {
                        $cliStyle->error('Load product information failed!');
                    }
                    return self::ERROR_CODE_LOAD_PRODUCT_INFORMATION_FAILED;
                }
            } elseif ($cliOptionsDto->isServiceProductInformation() && $this->verbose) {
                $cliStyle->writeln('You need TopFeed plugin to update product information!');
            }
        } elseif ($cliOptionsDto->isServiceProductMedia()) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                $this->optionsHelperService->loadTopdataTopFeedPluginConfig();
                if (!$this->mappingHelperService->setProductInformation(true)) {
                    if ($this->verbose) {
                        $cliStyle->error('Load product information failed!');
                    }
                    return self::ERROR_CODE_LOAD_PRODUCT_INFORMATION_FAILED;
                }
            } elseif ($this->verbose) {
                $cliStyle->writeln('You need TopFeed plugin to update product information!');
            }
        }

        return null;
    }

    private function handleProductVariations(
        ImportCommandCliOptionsDTO $cliOptionsDto,
        array                      $activePlugins,
        CliStyle                   $cliStyle
    ): ?int
    {
        if ($cliOptionsDto->isProductVariations()) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                if (!$this->mappingHelperService->setProductColorCapacityVariants()) {
                    if ($this->verbose) {
                        $cliStyle->error('Set device synonyms failed!');
                    }
                    return self::ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED_2;
                }
            } elseif ($this->verbose) {
                $cliStyle->warning('You need TopFeed plugin to create variated products!');
            }
        }

        return null;
    }
}
