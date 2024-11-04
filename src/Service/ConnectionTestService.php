<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataConnectorSW6\Helper\TopdataWebserviceClient;
use Topdata\TopdataFoundationSW6\Service\PluginHelperService;

/**
 * 11/2024 created (extracted from TestConnectionCommand)
 */
class ConnectionTestService
{
    public function __construct(
        private readonly SystemConfigService   $systemConfigService,
        private readonly LoggerInterface       $logger,
        private readonly ConfigCheckerService  $configCheckerService,
    ) {
    }

    public function testConnection(): array
    {
        $config = $this->systemConfigService->get('TopdataConnectorSW6.config');
        
        if ($this->configCheckerService->isConfigEmpty()) {
            return [
                'success' => false,
                'message' => 'Fill in the connection parameters in admin: Extensions > My Extensions > Topdata Webservice Connector > [...] > Configure'
            ];
        }

        try {
            $webservice = new TopdataWebserviceClient(
                $this->logger,
                $config['apiUsername'],
                $config['apiKey'],
                $config['apiSalt'],
                $config['apiLanguage']
            );
            
            $info = $webservice->getUserInfo();

            if (isset($info->error)) {
                return [
                    'success' => false,
                    'message' => "Connection error: {$info->error[0]->error_message}"
                ];
            }

            return [
                'success' => true,
                'message' => 'Connection success!'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Connection error: {$e->getMessage()}"
            ];
        }
    }
}
