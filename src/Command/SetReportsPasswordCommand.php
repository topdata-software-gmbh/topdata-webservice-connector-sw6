<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataConnectorSW6\Service\TopdataReportService;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Command to set the password for accessing Topdata reports.
 * 03/2025 created
 */
#[AsCommand(
    name: 'topdata:foundation:reports:set-password',
    description: 'Set password for reports access',
)]
class SetReportsPasswordCommand extends AbstractTopdataCommand
{
    public function __construct(
        private readonly TopdataReportService $reportService,
        private readonly SystemConfigService $systemConfigService
    ) {
        parent::__construct();
    }

    /**
     * Configures the command by adding the 'password' argument.
     */
    protected function configure(): void {
        $this->addArgument('password', InputArgument::REQUIRED, 'New password');
    }

    /**
     * Executes the command to set the reports password.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ---- Get the password from the input argument
        $password = $input->getArgument('password');

        // ---- Set the reports password using the TopdataReportService
        $this->reportService->setReportsPassword($password);

        // ---- Output a success message
        $output->writeln('Password updated successfully');

        $this->done();

        return Command::SUCCESS;
    }
}