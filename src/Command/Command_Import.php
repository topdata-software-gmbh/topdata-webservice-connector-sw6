<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataConnectorSW6\Constants\GlobalPluginConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportCommandCliOptionsDTO;
use Topdata\TopdataConnectorSW6\Exception\MissingPluginConfigurationException;
use Topdata\TopdataConnectorSW6\Service\ImportService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Constants\TopdataJobTypeConstants;
use Topdata\TopdataFoundationSW6\Service\TopdataReportService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilThrowable;

/**
 * This command imports data from the TopData Webservice.
 * It provides various options to control the import process, such as importing all data,
 * mapping products, importing devices, and updating media and product information.
 */
class Command_Import extends AbstractTopdataCommand
{


    public function __construct(
        private readonly ImportService        $importService,
        private readonly TopdataReportService $topdataReportService,
        private readonly SystemConfigService  $systemConfigService,
    )
    {
        parent::__construct();
    }

    /**
     * Retrieves basic report data for the import process.
     *
     * @return array An array containing counters and a user ID.
     */
    private function _getBasicReportData(): array
    {
        $pluginConfig = $this->systemConfigService->get('TopdataConnectorSW6.config');

        return [
            'counters'  => ImportReport::getCountersSorted(),
            'profiling' => UtilProfiling::getProfiling(),
            'apiConfig' => [
                'uid'      => $pluginConfig['apiUid'],
                'baseUrl'  => $pluginConfig['apiBaseUrl'],
                'language' => $pluginConfig['apiLanguage'],
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
        // ---- Get the command line (for the report)
        $commandLine = $_SERVER['argv'] ? implode(' ', $_SERVER['argv']) : 'topdata:connector:import';

        // ---- Start the import report
        $this->topdataReportService->newJobReport(TopdataJobTypeConstants::WEBSERVICE_IMPORT, $commandLine);

        // ---- print used credentials (TODO: a nice horizontal table and redact credentials)
        $config = $this->systemConfigService->get('TopdataConnectorSW6.config');
        CliLogger::dump($config);


        try {
            // ---- Create DTO from input
            $cliOptionsDto = new ImportCommandCliOptionsDTO($input);
            // ---- Execute the import service
            $this->importService->execute($cliOptionsDto);
            // ---- Mark as succeeded or failed based on the result
            $this->topdataReportService->markAsSucceeded($this->_getBasicReportData());

            return Command::SUCCESS;
        }
        catch (\Throwable $e) {
            // ---- Handle exception and mark as failed
            if( $e instanceof MissingPluginConfigurationException) {
                CliLogger::warning(GlobalPluginConstants::ERROR_MESSAGE_NO_WEBSERVICE_CREDENTIALS);
            }
            $reportData = $this->_getBasicReportData();
            $reportData['error'] = UtilThrowable::toArray($e);
            $this->topdataReportService->markAsFailed($reportData);

            throw $e;
        }
    }

}