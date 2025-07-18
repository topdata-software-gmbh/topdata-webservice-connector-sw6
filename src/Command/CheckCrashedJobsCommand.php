<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataConnectorSW6\Service\TopdataReportService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:foundation:check-crashed-jobs',
    description: 'Check for crashed jobs and mark them in the report table'
)]
class CheckCrashedJobsCommand extends AbstractTopdataCommand
{
    public function __construct(
        private readonly TopdataReportService $topdataReportService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('This command checks for jobs that are marked as running but have no active process.')
            ->addOption(
                'delete-no-pid',
                null,
                InputOption::VALUE_NONE,
                'Delete reports that have no PID associated'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::title('Checking for crashed jobs');

        $crashedCount = $this->topdataReportService->findAndMarkCrashedJobs();
        $deletedCount = 0;

        // New functionality for deleting reports with no PID
        if ($input->getOption('delete-no-pid')) {
            $noPidReports = $this->topdataReportService->findReportsWithNoPid();
            $deletedCount = $noPidReports->count();
            
            if ($deletedCount > 0) {
                $this->topdataReportService->deleteReports($noPidReports);
                CliLogger::note("Deleted $deletedCount reports with no PID");
            }
        }

        $successMessage = "Marked $crashedCount jobs as crashed";
        if ($deletedCount > 0) {
            $successMessage .= " and deleted $deletedCount reports with no PID";
        }
        CliLogger::success($successMessage);

        return Command::SUCCESS;
    }
}
