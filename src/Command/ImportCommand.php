<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\ImportExport\Processing\Mapping\Mapping;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportCommandCliOptionsDTO;
use Topdata\TopdataConnectorSW6\Helper\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Service\ConfigCheckerService;
use Topdata\TopdataConnectorSW6\Service\MappingHelperService;
use Topdata\TopdataConnectorSW6\Service\OptionsHelperService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;

/**
 * This command imports data from the TopData Webservice
 */
class ImportCommand extends AbstractCommand
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
        parent::__construct();
    }

    /**
     * ==== MAIN ====
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // ---- init
        $this->mappingHelperService->setCliStyle($this->cliStyle);
        $this->verbose = ($input->getOption('verbose') >= 1);
        $this->mappingHelperService->setVerbose($this->verbose);
        if ($this->verbose) {
            $this->cliStyle->writeln('Starting work...');
        }

        // ---- check if plugin is active
        $activePlugins = $this->containerBag->get('kernel.active_plugins');
        if (!isset($activePlugins['Topdata\TopdataConnectorSW6\TopdataConnectorSW6'])) {
            if ($this->verbose) {
                $this->cliStyle->error('The TopdataConnectorSW6 plugin is inactive!');
            }

            return self::ERROR_CODE_PLUGIN_INACTIVE;
        }

        // ---- check if plugin is configured
        if ($this->configCheckerService->isConfigEmpty()) {
            if ($this->verbose) {
                $this->cliStyle->writeln('Fill in the connection parameters in admin: Extensions > My Extensions > Topdata Webservice Connector > [...] > Configure');
            }

            return self::ERROR_CODE_MISSING_PLUGIN_CONFIGURATION;
        }

        // ---- cli options
        $cliOptionsDto = new ImportCommandCliOptionsDTO($input);
        $this->cliStyle->dumpDict($cliOptionsDto->toDict(), 'CLI Options DTO');

        // ---- init webservice client
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

        $this->optionsHelperService->setOption(OptionConstants::MAPPING_TYPE, $pluginConfig['mappingType']);
        $this->optionsHelperService->setOption(OptionConstants::ATTRIBUTE_OEM, $pluginConfig['attributeOem']);
        $this->optionsHelperService->setOption(OptionConstants::ATTRIBUTE_EAN, $pluginConfig['attributeEan']);
        $this->optionsHelperService->setOption(OptionConstants::ATTRIBUTE_ORDERNUMBER, $pluginConfig['attributeOrdernumber']);

        if (!$cliOptionsDto->isServiceAll()) {
            if ($input->getOption('start')) {
                $this->optionsHelperService->setOption(OptionConstants::START, (int)$input->getOption('start'));
            }
            if ($input->getOption('end')) {
                $this->optionsHelperService->setOption(OptionConstants::END, (int)$input->getOption('end'));
            }
        }

        // ---- mapping
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceMapping()) {

            $this->cliStyle->section('Mapping Products');

            if (!$this->mappingHelperService->mapProducts()) {
                if ($this->verbose) {
                    $this->cliStyle->error('Mapping failed!');
                }

                return self::ERROR_CODE_MAPPING_PRODUCTS_FAILED;
            }
        }

        // ---- set printer infos
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceDevice()) {
            if (
                !$this->mappingHelperService->setBrands()
                || !$this->mappingHelperService->setSeries()
                || !$this->mappingHelperService->setDeviceTypes()
                || !$this->mappingHelperService->setDevices()
            ) {
                if ($this->verbose) {
                    $this->cliStyle->error('Device import failed!');
                }

                return self::ERROR_CODE_DEVICE_IMPORT_FAILED;
            }
        } elseif ($cliOptionsDto->isServiceDeviceOnly()) {
            if (!$this->mappingHelperService->setDevices()) {
                if ($this->verbose) {
                    $this->cliStyle->error('Device import failed!');
                }

                return self::ERROR_CODE_DEVICE_IMPORT_FAILED;
            }
        }

        //set printer to products
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceProduct()) {
            if (!$this->mappingHelperService->setProducts()) {
                if ($this->verbose) {
                    $this->cliStyle->error('Set products to devices failed!');
                }
                return self::ERROR_CODE_PRODUCT_TO_DEVICE_LINKING_FAILED;
            }
        }

        //set device media
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceDeviceMedia()) {
            if (!$this->mappingHelperService->setDeviceMedia()) {
                if ($this->verbose) {
                    $this->cliStyle->error('Load device media failed!');
                }

                return self::ERROR_CODE_LOAD_DEVICE_MEDIA_FAILED;
            }
        }

        //set product information
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceProductInformation()) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                /* TopFeed plugin is enabled */
                $this->optionsHelperService->loadTopdataTopFeedPluginConfig();
                if (!$this->mappingHelperService->setProductInformation()) {
                    if ($this->verbose) {
                        $this->cliStyle->error('Load product information failed!');
                    }

                    return self::ERROR_CODE_LOAD_PRODUCT_INFORMATION_FAILED;
                }
            } elseif ($cliOptionsDto->isServiceProductInformation() && $this->verbose) {
                $this->cliStyle->writeln('You need TopFeed plugin to update product information!');
            }
        } elseif ($cliOptionsDto->isServiceProductMedia()) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                /* TopFeed plugin is enabled */
                $this->optionsHelperService->loadTopdataTopFeedPluginConfig();
                if (!$this->mappingHelperService->setProductInformation(true)) {
                    if ($this->verbose) {
                        $this->cliStyle->error('Load product information failed!');
                    }

                    return self::ERROR_CODE_LOAD_PRODUCT_INFORMATION_FAILED;
                }
            } elseif ($this->verbose) {
                $this->cliStyle->writeln('You need TopFeed plugin to update product information!');
            }
        }

        //set device synonyms
        if ($cliOptionsDto->isServiceAll() || $cliOptionsDto->isServiceDeviceSynonyms()) {
            if (!$this->mappingHelperService->setDeviceSynonyms()) {
                if ($this->verbose) {
                    $this->cliStyle->error('Set device synonyms failed!');
                }

                return self::ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED;
            }
        }

        //set variated products
        if ($cliOptionsDto->isProductVariations()) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                if (!$this->mappingHelperService->setProductColorCapacityVariants()) {
                    if ($this->verbose) {
                        $this->cliStyle->error('Set device synonyms failed!');
                    }

                    return self::ERROR_CODE_SET_DEVICE_SYNONYMS_FAILED_2;
                }
            } elseif ($this->verbose) {
                $this->cliStyle->warning('You need TopFeed plugin to create variated products!');
            }
        }

        //test
        if ($input->getOption('test')) {
            //            $rez = $mappingHelper->getKeysByCustomFieldUnique('Distributor product number');
            //            print_r($rez);
        }


        // ---- dump report
        $this->cliStyle->dumpDict(ImportReport::getCountersSorted(), 'Report');

        return Command::SUCCESS;
    }

    protected function configure(): void
    {
        $this->setName('topdata:connector:import');
        $this->setDescription('Import data from the TopData Webservice');
        $this->addOption('test', null, InputOption::VALUE_NONE, 'for developer tests');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'full update with webservice');
        $this->addOption('mapping', null, InputOption::VALUE_NONE, 'Mapping all existing products to webservice');
        $this->addOption('device', null, InputOption::VALUE_NONE, 'add devices from webservice');
        $this->addOption('device-only', null, InputOption::VALUE_NONE, 'add devices from webservice (no brands/series/types are fetched);');
        $this->addOption('product', null, InputOption::VALUE_NONE, 'link devices to products on the store');
        $this->addOption('device-media', null, InputOption::VALUE_NONE, 'update device media data');
        $this->addOption('device-synonyms', null, InputOption::VALUE_NONE, 'link active devices to synonyms');
        $this->addOption('product-info', null, InputOption::VALUE_NONE, 'update product information from webservice (TopFeed plugin needed);');
        $this->addOption('product-media-only', null, InputOption::VALUE_NONE, 'update only product media from webservice (TopFeed plugin needed);');
        $this->addOption('product-variated', null, InputOption::VALUE_NONE, 'Generate variated products based on color and capacity information (Import variants with other colors, Import variants with other capacities should be enabled in TopFeed plugin, product information should be already imported);');
        $this->addOption('start', null, InputOption::VALUE_OPTIONAL, 'First piece of data to handle');
        $this->addOption('end', null, InputOption::VALUE_OPTIONAL, 'Last piece of data to handle');
    }
}
