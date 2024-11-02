<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataConnectorSW6\Helper\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Service\ConfigCheckerService;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Service\PluginHelperService;

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
        private readonly SystemConfigService   $systemConfigService,
        private readonly LoggerInterface       $logger,
        private readonly ConfigCheckerService  $configCheckerService,
        private readonly PluginHelperService   $pluginHelperService,
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
        if (!$this->pluginHelperService->isPluginActive('Topdata\TopdataConnectorSW6\TopdataConnectorSW6')) {
            // a bit silly check, as this command is part of the TopdataConnectorSW6 plugin
            $this->cliStyle->error('The TopdataConnectorSW6 plugin is inactive!');
            $this->cliStyle->writeln('Activate the TopdataConnectorSW6 plugin first. Abort.');

            return self::ERROR_CODE_TOPDATA_WEBSERVICE_CONNECTOR_PLUGIN_INACTIVE;
        }
        $this->cliStyle->writeln('Getting connection params...');
        $config = $this->systemConfigService->get('TopdataConnectorSW6.config');
        if ($this->configCheckerService->isConfigEmpty()) {
            $this->cliStyle->error('Fill in the connection parameters in admin: Extensions > My Extensions > Topdata Webservice Connector > [...] > Configure');
            $this->cliStyle->writeln('Abort.');

            return self::ERROR_CODE_MISSING_CONFIG;
        }

        $this->cliStyle->writeln('Connecting to TopData api server...');
        try {
            $webservice = new TopdataWebserviceClient($this->logger, $config['apiUsername'], $config['apiKey'], $config['apiSalt'], $config['apiLanguage']);
            $info = $webservice->getUserInfo();

            if (isset($info->error)) {
                $this->cliStyle->error("Connection error: {$info->error[0]->error_message}");
                $this->cliStyle->writeln('Abort.');

                return self::ERROR_CODE_CONNECTION_ERROR;
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            // $this->logger->error($errorMessage);
            $this->cliStyle->error("Connection error: $errorMessage");
            $this->cliStyle->writeln('Abort.');

            return self::ERROR_CODE_EXCEPTION;
        }

        $this->cliStyle->success('Connection success!');
        $this->done();

        return Command::SUCCESS;
    }
}
