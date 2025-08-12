<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataConnectorSW6\Constants\GlobalPluginConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportConfig;
use Topdata\TopdataConnectorSW6\Exception\MissingPluginConfigurationException;
use Topdata\TopdataConnectorSW6\Service\ImportService;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\TopdataConnectorSW6;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Constants\TopdataJobTypeConstants;
use Topdata\TopdataConnectorSW6\Service\TopdataReportService;
use Topdata\TopdataFoundationSW6\Service\PluginHelperService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilThrowable;

/**
 * This command imports data from the TopData Webservice.
 * It provides various options to control the import process, such as importing all data,
 * mapping products, importing devices, and updating media and product information.
 */
class Command_Import extends AbstractTopdataCommand
{
    private ?LockInterface $lock = null;

    public function __construct(
        private readonly ImportService           $importService,
        private readonly TopdataReportService    $topdataReportService,
        private readonly SystemConfigService     $systemConfigService,
        private readonly LockFactory             $lockFactory,
        private readonly TopdataWebserviceClient $topdataWebserviceClient,
        private readonly PluginHelperService     $pluginHelperService,
    )
    {
        parent::__construct();
    }

    /**
     * Retrieves basic report data for the import process.
     *
     * @return array An array containing counters and a user ID.
     */
    private function _getBasicReportData(ImportConfig $importConfig): array
    {
        $pluginConfig = $this->systemConfigService->get('TopdataConnectorSW6.config');

        return [
            'counters'  => ImportReport::getCountersSorted(),
            'profiling' => UtilProfiling::getProfiling(),
            'apiConfig' => [
                'uid'      => $pluginConfig['apiUid'] ?? null,
                'baseUrl'  => $importConfig->getBaseUrl() ?? ($pluginConfig['apiBaseUrl'] ?? null),
                'language' => $pluginConfig['apiLanguage'] ?? null,
            ],
        ];
    }

    /**
     * Configures the command with its name, description, and available options.
     */
    protected function configure(): void
    {
        $this->setName('topdata:connector:import');
        $this->setDescription('Import data from the TopData Webservice');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'full update with webservice');
        $this->addOption('mapping', null, InputOption::VALUE_NONE, 'Mapping all existing products to webservice');
        $this->addOption('device', null, InputOption::VALUE_NONE, 'fetch devices from webservice');
        $this->addOption('device-only', null, InputOption::VALUE_NONE, 'fetch devices from webservice (no brands/series/types are fetched);'); // TODO: remove this option
        $this->addOption('product', null, InputOption::VALUE_NONE, 'link devices to products on the store');
        $this->addOption('device-media', null, InputOption::VALUE_NONE, 'update device media data');
        $this->addOption('device-synonyms', null, InputOption::VALUE_NONE, 'link active devices to synonyms');
        $this->addOption('product-info', null, InputOption::VALUE_NONE, 'update product information from webservice (TopFeed plugin needed);');
        $this->addOption('product-media-only', null, InputOption::VALUE_NONE, 'update only product media from webservice (TopFeed plugin needed);');
        $this->addOption('product-variated', null, InputOption::VALUE_NONE, 'Generate variated products based on color and capacity information (Import variants with other colors, Import variants with other capacities should be enabled in TopFeed plugin, product information should be already imported);');
        $this->addOption('experimental-v2', 'x', InputOption::VALUE_NONE, 'switch to use the faster v2 of the connector'); // 04/2025 added
        $this->addOption('product-device', '', InputOption::VALUE_NONE, 'fetch the product device relations from webservice'); // 04/2025 added
        $this->addOption('purge-cache', null, InputOption::VALUE_NONE, 'purge the mapping cache before import'); // 05/2025 added
        $this->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Override base URL for import operation'); // 06/2025 added
    }

    /**
     * Executes the import command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int 0 if everything went fine, or an error code.
     *
     * @throws \Throwable
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // ---- Create lock
        $this->lock = $this->lockFactory->createLock('topdata-connector-import', 3600); // 1 hour TTL

        // ---- Attempt to acquire the lock
        if (!$this->lock->acquire()) {
            CliLogger::warning('The command topdata:connector:import is already running.');

            return Command::SUCCESS; // Exit gracefully
        }

        // ---- Print the plugin version ----
        $pluginVersion = $this->pluginHelperService->getPluginVersion(TopdataConnectorSW6::class);
        CliLogger::info("Running TopdataConnectorSW6 version: $pluginVersion");
        // ---------------------------------

        try {
            // ---- Get the command line (for the report)
            $commandLine = $_SERVER['argv'] ? implode(' ', $_SERVER['argv']) : 'topdata:connector:import';

            // ---- Start the import report
            $this->topdataReportService->newJobReport(TopdataJobTypeConstants::WEBSERVICE_IMPORT, $commandLine);

            // ---- print used credentials (TODO: a nice horizontal table and redact credentials)
//            $pluginConfig = $this->systemConfigService->get('TopdataConnectorSW6.config');
//            CliLogger::dump($pluginConfig);


            try {
                // ---- Create Input Config DTO from cli options
                $importConfig = ImportConfig::createFromCliInput($input);
                // ---- Set base URL if provided as CLI option
                if($importConfig->getBaseUrl()) {
                    $this->topdataWebserviceClient->setBaseUrl($importConfig->getBaseUrl());
                }

                CliLogger::info('Using base URL: ' . $this->topdataWebserviceClient->getBaseUrl());

                // ---- Execute the import service
                $this->importService->execute($importConfig);
                // ---- Mark as succeeded or failed based on the result
                $this->topdataReportService->markAsSucceeded($this->_getBasicReportData($importConfig));

                return Command::SUCCESS;
            } catch (\Throwable $e) {
                // ---- Handle exception and mark as failed
                if ($e instanceof MissingPluginConfigurationException) {
                    CliLogger::warning(GlobalPluginConstants::ERROR_MESSAGE_NO_WEBSERVICE_CREDENTIALS);
                }
                $reportData = $this->_getBasicReportData($importConfig);
                $reportData['error'] = UtilThrowable::toArray($e);
                $this->topdataReportService->markAsFailed($reportData);

                throw $e;
            }
        } finally {
            // ---- Release the lock
            if ($this->lock) {
                $this->lock->release();
                $this->lock = null;
            }
        }
    }

}
