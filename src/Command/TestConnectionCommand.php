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

class TestConnectionCommand extends Command
{
    const ERROR_CODE_TOPDATA_WEBSERVICE_CONNECTOR_PLUGIN_INACTIVE = 1;
    const ERROR_CODE_MISSING_CONFIG                               = 2;
    const ERROR_CODE_CONNECTION_ERROR                             = 3;
    const ERROR_CODE_EXCEPTION                                    = 4;


    private SystemConfigService $systemConfigService;
    private ContainerBagInterface $containerBag;
    private LoggerInterface $logger;

    protected static $defaultName = 'topdata:connector:test-connection';

    public function __construct(
        SystemConfigService   $systemConfigService,
        ContainerBagInterface $ContainerBag,
        LoggerInterface       $logger
    )
    {
        $this->systemConfigService = $systemConfigService;
        $this->containerBag = $ContainerBag;
        $this->logger = $logger;
        parent::__construct();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Check plugin is active...');
        $activePlugins = $this->containerBag->get('kernel.active_plugins');
        if (!isset($activePlugins['Topdata\TopdataConnectorSW6\TopdataConnectorSW6'])) {
            // a bit silly check, as this command is part of the TopdataConnectorSW6 plugin
            $output->writeln('The TopdataConnectorSW6 plugin is inactive!');
            $output->writeln('Activate the TopdataConnectorSW6 plugin first. Abort.');

            return self::ERROR_CODE_TOPDATA_WEBSERVICE_CONNECTOR_PLUGIN_INACTIVE;
        }
        $output->writeln('Getting connection params...');
        $config = $this->systemConfigService->get('TopdataConnectorSW6.config');
        if ($config['apiUsername'] == '' || $config['apiKey'] == '' || $config['apiSalt'] == '' || $config['apiLanguage'] == '') {
            $output->writeln('Fill in connection parameters in admin -> Settings -> System -> Plugins -> TopdataConnector config');
            $output->writeln('Abort.');

            return self::ERROR_CODE_MISSING_CONFIG;
        }

        $output->writeln('Connecting to TopData api server...');
        try {
            $webservice = new TopdataWebserviceClient($this->logger, $config['apiUsername'], $config['apiKey'], $config['apiSalt'], $config['apiLanguage']);
            $info = $webservice->getUserInfo();

            if (isset($info->error)) {
                $output->writeln('Connection error:');
                $output->writeln($info->error[0]->error_message);
                $output->writeln('Abort.');

                return self::ERROR_CODE_CONNECTION_ERROR;
            } else {
                $output->writeln('Connection success!');

                return Command::SUCCESS;
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->logger->error($errorMessage);
            $output->writeln('Connection error:');
            $output->writeln($errorMessage);
            $output->writeln('Abort.');

            return self::ERROR_CODE_EXCEPTION;
        }

        return Command::SUCCESS;
    }
}
