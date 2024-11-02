<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataConnectorSW6\Service\ConnectionTestService;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;

/**
 * Test connection to the TopData webservice.
 */
class TestConnectionCommand extends AbstractTopdataCommand
{
    const ERROR_CODE_TOPDATA_WEBSERVICE_CONNECTOR_PLUGIN_INACTIVE = 1;
    const ERROR_CODE_MISSING_CONFIG                               = 2;
    const ERROR_CODE_CONNECTION_ERROR                             = 3;
    const ERROR_CODE_EXCEPTION                                    = 4;


    public function __construct(
        private readonly ConnectionTestService $connectionTestService
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('topdata:connector:test-connection');
        $this->setDescription('Test connection to the TopData webservice');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cliStyle->writeln('Check plugin is active...');
        if (!$this->connectionTestService->checkPluginActive('Topdata\TopdataConnectorSW6\TopdataConnectorSW6')) {
            $this->cliStyle->error('The TopdataConnectorSW6 plugin is inactive!');
            $this->cliStyle->writeln('Activate the TopdataConnectorSW6 plugin first. Abort.');
            return self::ERROR_CODE_TOPDATA_WEBSERVICE_CONNECTOR_PLUGIN_INACTIVE;
        }

        $this->cliStyle->writeln('Testing connection...');
        $result = $this->connectionTestService->testConnection();

        if (!$result['success']) {
            $this->cliStyle->error($result['message']);
            $this->cliStyle->writeln('Abort.');
            return self::ERROR_CODE_CONNECTION_ERROR;
        }

        $this->cliStyle->success($result['message']);
        $this->done();

        return Command::SUCCESS;
    }
}
