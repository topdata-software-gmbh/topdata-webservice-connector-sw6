<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Psr\Log\LoggerInterface;
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

        $config = $this->systemConfigService->get('TopdataConnectorSW6.config');
        if ($this->configCheckerService->isConfigEmpty()) {
            if ($this->verbose) {
                $this->cliStyle->writeln('Fill in the connection parameters in admin: Extensions > My Extensions > Topdata Webservice Connector > [...] > Configure');
            }

            return 2;
        }
        $option = [
            'isServiceAll'                => $input->getOption('all'),
            'isServiceMapping'            => $input->getOption('mapping'),
            'isServiceDevice'             => $input->getOption('device'),
            'isServiceDeviceOnly'         => $input->getOption('device-only'),
            'isServiceDeviceMedia'        => $input->getOption('device-media'),
            'isServiceDeviceSynonyms'     => $input->getOption('device-synonyms'),
            'isServiceProduct'            => $input->getOption('product'),
            'isServiceProductInformation' => $input->getOption('product-info'),
            'isServiceProductMedia'       => $input->getOption('product-media-only'),
            'isProductVariations'         => $input->getOption('product-variated'),
        ];

        //        if($option['isServiceAll']) {
        //            $this->cliStyle->writeln('Option "all" is currently disabled. Use partial service options instead.');
        //            return ['success' => true];
        //        }

        $topdataWebserviceClient = new TopdataWebserviceClient(
            $this->logger,
            $config['apiUsername'],
            $config['apiKey'],
            $config['apiSalt'],
            $config['apiLanguage']
        );

        $mappingHelper = $this->mappingHelperService;
        $mappingHelper->setTopdataWebserviceClient($topdataWebserviceClient);

        $mappingHelper->setVerbose($this->verbose);

        $configDefaults = [
            'attributeOem'         => '',
            'attributeEan'         => '',
            'attributeOrdernumber' => '',
        ];

        $config = array_merge($configDefaults, $config);

        $mappingHelper->setOption('mappingType', $config['mappingType']);
        $mappingHelper->setOption('attributeOem', $config['attributeOem']);
        $mappingHelper->setOption('attributeEan', $config['attributeEan']);
        $mappingHelper->setOption('attributeOrdernumber', $config['attributeOrdernumber']);

        if (!$option['isServiceAll']) {
            if ($input->getOption('start')) {
                $mappingHelper->setOption('start', (int)$input->getOption('start'));
            }
            if ($input->getOption('end')) {
                $mappingHelper->setOption('end', (int)$input->getOption('end'));
            }
        }

        //mapping
        if ($option['isServiceAll'] || $option['isServiceMapping']) {
            if ($this->verbose) {
                $this->cliStyle->writeln('mapping products started...');
            }
            if (!$mappingHelper->mapProducts()) {
                if ($this->verbose) {
                    $this->cliStyle->writeln('Mapping failed!');
                }

                return 3;
            }
        }

        //set printer infos
        if ($option['isServiceAll'] || $option['isServiceDevice']) {
            if (
                !$mappingHelper->setBrands()
                || !$mappingHelper->setSeries()
                || !$mappingHelper->setDeviceTypes()
                || !$mappingHelper->setDevices()
            ) {
                if ($this->verbose) {
                    $this->cliStyle->writeln('Device import failed!');
                }

                return 4;
            }
        } elseif ($option['isServiceDeviceOnly']) {
            if (!$mappingHelper->setDevices()) {
                if ($this->verbose) {
                    $this->cliStyle->writeln('Device import failed!');
                }

                return 4;
            }
        }

        //set printer to products
        if ($option['isServiceAll'] || $option['isServiceProduct']) {
            if (!$mappingHelper->setProducts()) {
                if ($this->verbose) {
                    $this->cliStyle->writeln('Set products to devices failed!');
                }

                return 5;
            }
        }

        //set device media
        if ($option['isServiceAll'] || $option['isServiceDeviceMedia']) {
            if (!$mappingHelper->setDeviceMedia()) {
                if ($this->verbose) {
                    $this->cliStyle->writeln('Load device media failed!');
                }

                return 6;
            }
        }

        //set product information
        if ($option['isServiceAll'] || $option['isServiceProductInformation']) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                /* TopFeed plugin is enabled */
                $this->loadTopFeedConfig($mappingHelper);
                if (!$mappingHelper->setProductInformation()) {
                    if ($this->verbose) {
                        $this->cliStyle->writeln('Load product information failed!');
                    }

                    return 7;
                }
            } elseif ($option['isServiceProductInformation'] && $this->verbose) {
                $this->cliStyle->writeln('You need TopFeed plugin to update product information!');
            }
        } elseif ($option['isServiceProductMedia']) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                /* TopFeed plugin is enabled */
                $this->loadTopFeedConfig($mappingHelper);
                if (!$mappingHelper->setProductInformation(true)) {
                    if ($this->verbose) {
                        $this->cliStyle->writeln('Load product information failed!');
                    }

                    return 7;
                }
            } elseif ($this->verbose) {
                $this->cliStyle->writeln('You need TopFeed plugin to update product information!');
            }
        }

        //set device synonyms
        if ($option['isServiceAll'] || $option['isServiceDeviceSynonyms']) {
            if (!$mappingHelper->setDeviceSynonyms()) {
                if ($this->verbose) {
                    $this->cliStyle->writeln('Set device synonyms failed!');
                }

                return 8;
            }
        }

        //set variated products
        if ($option['isProductVariations']) {
            if (isset($activePlugins['Topdata\TopdataTopFeedSW6\TopdataTopFeedSW6'])) {
                if (!$mappingHelper->setProductColorCapacityVariants()) {
                    if ($this->verbose) {
                        $this->cliStyle->writeln('Set device synonyms failed!');
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
        $configFeed = $this->systemConfigService->get('TopdataTopFeedSW6.config');
        $mappingHelper->setOptions($configFeed);
        $mappingHelper->setOption('productColorVariant', $configFeed['productVariantColor']);
        $mappingHelper->setOption('productCapacityVariant', $configFeed['productVariantCapacity']);
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
