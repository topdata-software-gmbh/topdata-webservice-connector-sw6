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
use Topdata\TopdataFoundationSW6\Service\TopConfigRegistry;
use Topdata\TopdataFoundationSW6\Util\Configuration\UtilToml;
use TopdataSoftwareGmbH\Util\UtilDebug;

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
        private readonly SystemConfigService   $systemConfigService, // legacy
        private readonly TopConfigRegistry     $topConfigRegistry, // new
        private readonly PluginHelperService   $pluginHelperService
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('print-config', 'p', InputOption::VALUE_NONE, 'Print the current configuration and exit');
    }

    /**
     * ==== MAIN ====
     * 11/2024 created
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {

        // ---- print config and exit
        if ($input->getOption('print-config')) {
            $pluginSystemConfig = $this->systemConfigService->get('TopdataConnectorSW6.config');
            $topConfigToml = UtilToml::flatConfigToToml($this->topConfigRegistry->getTopConfig('TopdataConnectorSW6')->getFlatConfig());
            $topConfigFlat = $this->topConfigRegistry->getTopConfig('TopdataConnectorSW6')->getFlatConfig();
            \Topdata\TopdataFoundationSW6\Util\CliLogger::writeln($topConfigToml);
//            \Topdata\TopdataFoundationSW6\Util\CliLogger::getCliStyle()->dumpDict($pluginSystemConfig, 'TopdataConnectorSW6.config');
//            \Topdata\TopdataFoundationSW6\Util\CliLogger::getCliStyle()->dumpDict($topConfigFlat, 'topConfigFlat(TopdataConnectorSW6)');
            $this->done();
            return Command::SUCCESS;
        }

        // ---- check connection to webservice
        \Topdata\TopdataFoundationSW6\Util\CliLogger::getCliStyle()->section('Test connection to the TopData webservice');

        \Topdata\TopdataFoundationSW6\Util\CliLogger::writeln('Check plugin is active...');
        if (!$this->pluginHelperService->isWebserviceConnectorPluginAvailable()) {
            \Topdata\TopdataFoundationSW6\Util\CliLogger::getCliStyle()->error('The TopdataConnectorSW6 plugin is inactive!');
            \Topdata\TopdataFoundationSW6\Util\CliLogger::writeln('Activate the TopdataConnectorSW6 plugin first. Abort.');
            return self::ERROR_CODE_TOPDATA_WEBSERVICE_CONNECTOR_PLUGIN_INACTIVE;
        }

        \Topdata\TopdataFoundationSW6\Util\CliLogger::writeln('Testing connection...');
        $result = $this->connectionTestService->testConnection();

        if (!$result['success']) {
            \Topdata\TopdataFoundationSW6\Util\CliLogger::getCliStyle()->error($result['message']);
            \Topdata\TopdataFoundationSW6\Util\CliLogger::writeln('Abort.');
            return self::ERROR_CODE_CONNECTION_ERROR;
        }

        \Topdata\TopdataFoundationSW6\Util\CliLogger::success($result['message']);
        $this->done();

        return Command::SUCCESS;
    }
}
