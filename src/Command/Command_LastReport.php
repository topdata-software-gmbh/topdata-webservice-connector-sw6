<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Core\Content\TopdataReport\TopdataReportEntity;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * TODO: move to Foundation plugin
 * 
 * Command to display statistics from the last import operation
 */
#[AsCommand(
    name: 'topdata:connector:last-report',
    description: 'Display statistics from the last import operation'
)]
class Command_LastReport extends AbstractTopdataCommand
{
    public function __construct(
        private readonly EntityRepository $topdataReportRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::title('Last Report');

        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        $result = $this->topdataReportRepository->search($criteria, Context::createDefaultContext());

        if ($result->count() === 0) {
            CliLogger::error('No report found');
            return Command::FAILURE;
        }

        /** @var TopdataReportEntity $report */
        $report = $result->first();

        // Display general report information
        $table = CliLogger::getCliStyle()->createTable();
        $table->setHeaderTitle('Report Information');
        $table->setHeaders(['Property', 'Value']);

        $rows = [
            ['Report ID', $report->getId()],
            ['Job Type', $report->getJobType()],
            ['Job Status', $report->getJobStatus()],
            ['Command Line', $report->getCommandLine()],
            ['PID', $report->getPid() ?? 'N/A'],
            ['Started At', $report->getStartedAt()->format('Y-m-d H:i:s')],
            ['Finished At', $report->getFinishedAt() ? $report->getFinishedAt()->format('Y-m-d H:i:s') : 'Not finished'],
        ];

        $table->setRows($rows);
        $table->render();

        CliLogger::getCliStyle()->newLine();

        // Display report data as JSON
        CliLogger::title('Report Data');

        $reportData = $report->getReportData();
        if (empty($reportData)) {
            CliLogger::writeln('<yellow>No report data available</yellow>');
        } else {
            $jsonData = json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            CliLogger::writeln($jsonData);
        }

        $this->done();

        return Command::SUCCESS;
    }
}
