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

class TestConnection extends Command
{
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var ContainerBagInterface
     */
    private $containerBag;

    /**
     * @var LoggerInterface
     */
    private $logger;

    protected static $defaultName = 'topdata:connector:test-connection';

    public function __construct(
        SystemConfigService $systemConfigService,
        ContainerBagInterface $ContainerBag,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->containerBag        = $ContainerBag;
        $this->logger              = $logger;
        parent::__construct();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Check plugin is active...');
        $activePlugins = $this->containerBag->get('kernel.active_plugins');
        if (!isset($activePlugins['Topdata\TopdataConnectorSW6\TopdataConnectorSW6'])) {
            $output->writeln('Plugin is inactive!');
            $output->writeln('Activate plugin first. Abort.');

            return 1;
        }
        $output->writeln('Getting connection params...');
        $config = $this->systemConfigService->get('TopdataConnectorSW6.config');
        if ($config['apiUsername'] == '' || $config['apiKey'] == '' || $config['apiSalt'] == '' || $config['apiLanguage'] == '') {
            $output->writeln('Fill in connection parameters in admin -> Settings -> System -> Plugins -> TopdataConnector config');
            $output->writeln('Abort.');

            return 2;
        }

        $output->writeln('Connecting to TopData api server...');
        try {
            $webservice = new TopdataWebserviceClient($this->logger, $config['apiUsername'], $config['apiKey'], $config['apiSalt'], $config['apiLanguage']);
            $info       = $webservice->getUserInfo();

            if (isset($info->error)) {
                $output->writeln('Connection error:');
                $output->writeln($info->error[0]->error_message);
                $output->writeln('Abort.');

                return 3;
            } else {
                $output->writeln('Connection success!');

                return 0;
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->logger->error($errorMessage);
            $output->writeln('Connection error:');
            $output->writeln($errorMessage);
            $output->writeln('Abort.');

            return 4;
        }

        return 0;
    }
}
