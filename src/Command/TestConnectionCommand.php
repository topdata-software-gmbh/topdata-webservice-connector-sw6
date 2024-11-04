<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataConnectorSW6\Service\ConnectionTestService;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Service\PluginHelperService;

/**
 * Test connection to the TopData webservice.
 */
#[AsCommand(
    name: 'topdata:connector:test-connection',
    description: 'Test connection to the TopData webservice',
)]
class TestConnectionCommand extends AbstractTopdataCommand
{
    const ERROR_CODE_TOPDATA_WEBSERVICE_CONNECTOR_PLUGIN_INACTIVE = 1;
    const ERROR_CODE_MISSING_CONFIG                               = 2;
    const ERROR_CODE_CONNECTION_ERROR                             = 3;
    const ERROR_CODE_EXCEPTION                                    = 4;


    public function __construct(
        private readonly ConnectionTestService $connectionTestService,
        private readonly SystemConfigService   $systemConfigService,
        private readonly PluginHelperService   $pluginHelperService
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('print-config', 'p', InputOption::VALUE_NONE, 'Print the current configuration and exit');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {

        // ---- print config and exit
        if ($input->getOption('print-config')) {
            $config = $this->systemConfigService->get('TopdataConnectorSW6.config');
            $this->cliStyle->dumpDict($config, 'TopdataConnectorSW6.config');
            $this->done();
            return Command::SUCCESS;
        }

        // ---- check connection to webservice
        $this->cliStyle->section('Test connection to the TopData webservice');

        $this->cliStyle->writeln('Check plugin is active...');
        if (!$this->pluginHelperService->isWebserviceConnectorPluginAvailable()) {
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
