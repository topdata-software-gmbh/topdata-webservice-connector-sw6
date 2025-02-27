<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Constants\TopdataReportStatusConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportCommandCliOptionsDTO;
use Topdata\TopdataConnectorSW6\Service\ImportService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Service\TopdataReportService;

/**
 * This command imports data from the TopData Webservice.
 */
class ImportCommand extends AbstractTopdataCommand
{
    public function __construct(
        private readonly ImportService        $importService,
        private readonly TopdataReportService $topdataReportService,
    ) {
        parent::__construct();
    }

    /**
     * ==== MAIN ====
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Get the command line
        $commandLine = $_SERVER['argv'] ? implode(' ', $_SERVER['argv']) : 'topdata:connector:import';
        
        // Start the import report
        $this->topdataReportService->startImport($commandLine);
        
        try {
            $this->importService->setCliStyle($this->cliStyle);
            $cliOptionsDto = new ImportCommandCliOptionsDTO($input);
            
            $result = $this->importService->execute($cliOptionsDto);
            
            // Mark as succeeded if the import was successful
            if ($result === 0) {
                $this->topdataReportService->markAsSucceeded(ImportReport::getCountersSorted());
            } else {
                $this->topdataReportService->markAsFailed(ImportReport::getCountersSorted());
            }
            
            return $result;
        } catch (\Exception $e) {
            // Mark as failed if an exception occurred
            $this->topdataReportService->markAsFailed(ImportReport::getCountersSorted());
            throw $e;
        }
    }

    protected function configure(): void
    {
        $this->setName('topdata:connector:import');
        $this->setDescription('Import data from the TopData Webservice');
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
