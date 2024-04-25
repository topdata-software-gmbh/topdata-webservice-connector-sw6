<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\ImportExport\Processing\Mapping\Mapping;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Topdata\TopdataConnectorSW6\Helper\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Service\ConfigCheckerService;
use Topdata\TopdataConnectorSW6\Service\MappingHelperService;

/**
 * This command imports data from the TopData Webservice
 */
class ImportCommand extends AbstractCommand
{
    protected static $defaultName = 'topdata:connector:import';
    protected static $defaultDescription = 'Import data from the TopData Webservice';

    private bool $verbose = true;
    private SystemConfigService $systemConfigService;
    private ContainerBagInterface $containerBag;
    private LoggerInterface $logger;
    private MappingHelperService $mappingHelperService;
    private ConfigCheckerService $configCheckerService;


    public function __construct(
        SystemConfigService   $systemConfigService,
        ContainerBagInterface $ContainerBag,
        LoggerInterface       $logger,
        MappingHelperService  $mappingHelperService,
        ConfigCheckerService  $configCheckerService
    )
    {
        parent::__construct();
        $this->systemConfigService = $systemConfigService;
        $this->containerBag = $ContainerBag;
        $this->logger = $logger;
        $this->mappingHelperService = $mappingHelperService;
        $this->configCheckerService = $configCheckerService;
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // ---- init
        $this->mappingHelperService->setCliStyle($this->cliStyle);


        $this->verbose = ($input->getOption('verbose') >= 1);

        if ($this->verbose) {
            $this->cliStyle->writeln('Starting work...');
        }

        $activePlugins = $this->containerBag->get('kernel.active_plugins');
        if (!isset($activePlugins['Topdata\TopdataConnectorSW6\TopdataConnectorSW6'])) {
            if ($this->verbose) {
                $this->cliStyle->error('The TopdataConnectorSW6 plugin is inactive!');
            }

            return 1;
        }

        $pluginConfig = $this->systemConfigService->get('TopdataConnectorSW6.config');
        if ($this->configCheckerService->isConfigEmpty()) {
            if ($this->verbose) {
                $this->cliStyle->writeln('Fill in the connection parameters in admin: Extensions > My Extensions > Topdata Webservice Connector > [...] > Configure');
            }

            return 2;
        }
        $option = [
            'isServiceAll'                => $input->getOption('all'), // full update with webservice
            'isServiceMapping'            => $input->getOption('mapping'), // Mapping all existing products to webservice
            'isServiceDevice'             => $input->getOption('device'), // add devices from webservice
            'isServiceDeviceOnly'         => $input->getOption('device-only'), // add devices from webservice (no brands/series/types are fetched);
            'isServiceDeviceMedia'        => $input->getOption('device-media'), // update device media data
            'isServiceDeviceSynonyms'     => $input->getOption('device-synonyms'), // link active devices to synonyms
            'isServiceProduct'            => $input->getOption('product'), // link devices to products on the store
            'isServiceProductInformation' => $input->getOption('product-info'), // update product information from webservice (TopFeed plugin needed)
            'isServiceProductMedia'       => $input->getOption('product-media-only'), // update only product media from webservice (TopFeed plugin needed)
            'isProductVariations'         => $input->getOption('product-variated'), // Generate variated products based on color and capacity information (Import variants with other colors, Import variants with other capacities should be enabled in TopFeed plugin, product information should be already imported)
        ];

        //        if($option['isServiceAll']) {
        //            $this->cliStyle->writeln('Option "all" is currently disabled. Use partial service options instead.');
        //            return ['success' => true];
        //        }

        $topdataWebserviceClient = new TopdataWebserviceClient(
            $this->logger,
            $pluginConfig['apiUsername'],
            $pluginConfig['apiKey'],
            $pluginConfig['apiSalt'],
            $pluginConfig['apiLanguage']
        );

        $mappingHelperService = $this->mappingHelperService;
        $mappingHelperService->setTopdataWebserviceClient($topdataWebserviceClient);

        $mappingHelperService->setVerbose($this->verbose);

        $configDefaults = [
            'attributeOem'         => '',
            'attributeEan'         => '',
            'attributeOrdernumber' => '',
        ];

        $pluginConfig = array_merge($configDefaults, $pluginConfig);

        $mappingHelperService->setOption(MappingHelperService::OPTION_NAME_MAPPING_TYPE, $pluginConfig['mappingType']);
        $mappingHelperService->setOption(MappingHelperService::OPTION_NAME_ATTRIBUTE_OEM, $pluginConfig['attributeOem']);
        $mappingHelperService->setOption(MappingHelperService::OPTION_NAME_ATTRIBUTE_EAN, $pluginConfig['attributeEan']);
        $mappingHelperService->setOption(MappingHelperService::OPTION_NAME_ATTRIBUTE_ORDERNUMBER, $pluginConfig['attributeOrdernumber']);

        if (!$option['isServiceAll']) {
            if ($input->getOption('start')) {
                $mappingHelperService->setOption(MappingHelperService::OPTION_NAME_START, (int)$input->getOption('start'));
            }
            if ($input->getOption('end')) {
                $mappingHelperService->setOption(MappingHelperService::OPTION_NAME_END, (int)$input->getOption('end'));
            }
        }

        //mapping
        if ($option['isServiceAll'] || $option['isServiceMapping']) {

            $this->cliStyle->section('Mapping products');

            if (!$mappingHelperService->mapProducts()) {
                if ($this->verbose) {
                    $this->cliStyle->error('Mapping failed!');
                }

                return 3;
            }
        }

        //set printer infos
        if ($option['isServiceAll'] || $option['isServiceDevice']) {
            if (
                !$mappingHelperService->setBrands()
                || !$mappingHelperService->setSeries()
                || !$mappingHelperService->setDeviceTypes()
                || !$mappingHelperService->setDevices()
            ) {
                if ($this->verbose) {
                    $this->cliStyle->error('Device import failed!');
                }

                return 4;
            }
        } elseif ($option['isServiceDeviceOnly']) {
            if (!$mappingHelperService->setDevices()) {
                if ($this->verbose) {
                    $this->cliStyle->error('Device import failed!');
                }

                return 4;
            }
        }

        //set printer to products
        if ($option['isServiceAll'] || $option['isServiceProduct']) {
            if (!$mappingHelperService->setProducts()) {
                if ($this->verbose) {
                    $this->cliStyle->error('Set products to devices failed!');
                }

                return 5;
            }
        }

        //set device media
        if ($option['isServiceAll'] || $option['isServiceDeviceMedia']) {
            if (!$mappingHelperService->setDeviceMedia()) {
                if ($this->verbose) {
                    $this->cliStyle->error('Load device media failed!');
                }

                return 6;
            }
        }

        //set product information
        if ($option['isServiceAll'] || $option['isServiceProductInformation']) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                /* TopFeed plugin is enabled */
                $this->loadTopFeedConfig($mappingHelperService);
                if (!$mappingHelperService->setProductInformation()) {
                    if ($this->verbose) {
                        $this->cliStyle->error('Load product information failed!');
                    }

                    return 7;
                }
            } elseif ($option['isServiceProductInformation'] && $this->verbose) {
                $this->cliStyle->writeln('You need TopFeed plugin to update product information!');
            }
        } elseif ($option['isServiceProductMedia']) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                /* TopFeed plugin is enabled */
                $this->loadTopFeedConfig($mappingHelperService);
                if (!$mappingHelperService->setProductInformation(true)) {
                    if ($this->verbose) {
                        $this->cliStyle->error('Load product information failed!');
                    }

                    return 7;
                }
            } elseif ($this->verbose) {
                $this->cliStyle->writeln('You need TopFeed plugin to update product information!');
            }
        }

        //set device synonyms
        if ($option['isServiceAll'] || $option['isServiceDeviceSynonyms']) {
            if (!$mappingHelperService->setDeviceSynonyms()) {
                if ($this->verbose) {
                    $this->cliStyle->error('Set device synonyms failed!');
                }

                return 8;
            }
        }

        //set variated products
        if ($option['isProductVariations']) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                if (!$mappingHelperService->setProductColorCapacityVariants()) {
                    if ($this->verbose) {
                        $this->cliStyle->error('Set device synonyms failed!');
                    }

                    return 9;
                }
            } elseif ($this->verbose) {
                $this->cliStyle->writeln('You need TopFeed plugin to create variated products!');
            }
        }

        //test
        if ($input->getOption('test')) {
            //            $rez = $mappingHelper->getKeysByCustomFieldUnique('Distributor product number');
            //            print_r($rez);
        }

        return 0;
    }

    private function loadTopFeedConfig($mappingHelper)
    {
        $pluginConfig = $this->systemConfigService->get('TopdataTopFeedSW6.config');
        $mappingHelper->setOptions($pluginConfig);
        $mappingHelper->setOption(MappingHelperService::OPTION_NAME_PRODUCT_COLOR_VARIANT, $pluginConfig['productVariantColor']); // FIXME? 'productColorVariant' != 'productVariantColor'
        $mappingHelper->setOption(MappingHelperService::OPTION_NAME_PRODUCT_CAPACITY_VARIANT, $pluginConfig['productVariantCapacity']); // FIXME? 'productCapacityVariant' != 'productVariantCapacity'
    }

    protected function configure(): void
    {
        $this->addOption(
            'test',
            null,
            InputOption::VALUE_NONE,
            'for developer tests'
        );
        $this->addOption(
            'all',
            null,
            InputOption::VALUE_NONE,
            'full update with webservice'
        );
        $this->addOption(
            'mapping',
            null,
            InputOption::VALUE_NONE,
            'Mapping all existing products to webservice'
        );
        $this->addOption(
            'device',
            null,
            InputOption::VALUE_NONE,
            'add devices from webservice'
        );
        $this->addOption(
            'device-only',
            null,
            InputOption::VALUE_NONE,
            'add devices from webservice (no brands/series/types are fetched);'
        );
        $this->addOption(
            'product',
            null,
            InputOption::VALUE_NONE,
            'link devices to products on the store'
        );
        $this->addOption(
            'device-media',
            null,
            InputOption::VALUE_NONE,
            'update device media data'
        );
        $this->addOption(
            'device-synonyms',
            null,
            InputOption::VALUE_NONE,
            'link active devices to synonyms'
        );
        $this->addOption(
            'product-info',
            null,
            InputOption::VALUE_NONE,
            'update product information from webservice (TopFeed plugin needed);'
        );
        $this->addOption(
            'product-media-only',
            null,
            InputOption::VALUE_NONE,
            'update only product media from webservice (TopFeed plugin needed);'
        );
        $this->addOption(
            'product-variated',
            null,
            InputOption::VALUE_NONE,
            'Generate variated products based on color and capacity information (Import variants with other colors, Import variants with other capacities should be enabled in TopFeed plugin, product information should be already imported);'
        );
        $this->addOption('start', null, InputOption::VALUE_OPTIONAL, 'First piece of data to handle');
        $this->addOption('end', null, InputOption::VALUE_OPTIONAL, 'Last piece of data to handle');
    }
}
