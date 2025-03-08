<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Core\Content\TopdataReport\TopdataReportEntity;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use TopdataSoftwareGmbH\Util\UtilDebug;

/**
 * TODO: move to Foundation plugin
 * 
 * Command to display statistics from the last import operation
 */
#[AsCommand(
    name: 'topdata:connector:last-report',
    description: 'Display statistics from the last import operation'
)]
class LastReportCommand extends AbstractTopdataCommand
{
    public function __construct(
        private readonly EntityRepository $topdataReportRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::title('Last Import Report');
        
        $criteria = new Criteria();
        // TODO: $criteria->addFilter( new EqualsFilter('reportType', TopdataReportTypeEnum::IMPORT));
        $criteria->setLimit(1);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        $result = $this->topdataReportRepository->search($criteria, Context::createDefaultContext());
        /** @var TopdataReportEntity $report */
        $report = $result->first();
        UtilDebug::dd($report->getReportData());
        //$counters = $result->first()?->get('counters') ?? [];

        if (empty($counters)) {
            CliLogger::warning('No import report available. Run an import first.');
            return Command::SUCCESS;
        }

        $table = new Table(CliLogger::getCliStyle());
        $table->setHeaders(['Metric', 'Count']);
        $table->setColumnStyle(1, (new \Symfony\Component\Console\Helper\TableCellStyle())->setAlignment('right'));

        foreach (ImportReport::getCountersSorted($counters) as $key => $value) {
            $table->addRow([
                str_replace('_', ' ', ucwords($key)),
                number_format($value, 0, ',', '.')
            ]);
        }

        $table->addRow(new TableSeparator());
        $table->addRow(['<comment>Total Records</comment>', number_format(array_sum($counters), 0, ',', '.')]);

        $table->render();

        return Command::SUCCESS;
    }
}
