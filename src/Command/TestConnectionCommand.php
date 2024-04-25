<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Topdata\TopdataConnectorSW6\Component\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Service\ConfigCheckerService;

/**
 * Test connection to the TopData webservice
 */
class TestConnectionCommand extends AbstractCommand
{
    const ERROR_CODE_TOPDATA_WEBSERVICE_CONNECTOR_PLUGIN_INACTIVE = 1;
    const ERROR_CODE_MISSING_CONFIG                               = 2;
    const ERROR_CODE_CONNECTION_ERROR                             = 3;
    const ERROR_CODE_EXCEPTION                                    = 4;


    private SystemConfigService $systemConfigService;
    private ContainerBagInterface $containerBag;
    private LoggerInterface $logger;

    protected static $defaultName = 'topdata:connector:test-connection';
    protected static $defaultDescription = 'Test connection to the TopData webservice';
    private ConfigCheckerService $configCheckerService;

    public function __construct(
        SystemConfigService   $systemConfigService,
        ContainerBagInterface $ContainerBag,
        LoggerInterface       $logger,
        ConfigCheckerService  $configCheckerService
    )
    {
        $this->systemConfigService = $systemConfigService;
        $this->containerBag = $ContainerBag;
        $this->logger = $logger;
        $this->configCheckerService = $configCheckerService;

        parent::__construct();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cliStyle->writeln('Check plugin is active...');
        $activePlugins = $this->containerBag->get('kernel.active_plugins');
        if (!isset($activePlugins['Topdata\TopdataConnectorSW6\TopdataConnectorSW6'])) {
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
