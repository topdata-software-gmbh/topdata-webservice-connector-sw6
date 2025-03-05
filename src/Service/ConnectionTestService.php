<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataConnectorSW6\Constants\GlobalPluginConstants;

/**
 * 11/2024 created (extracted from TestConnectionCommand)
 */
class ConnectionTestService
{


    public function __construct(
        private readonly SystemConfigService     $systemConfigService,
        private readonly ConfigCheckerService    $configCheckerService,
        private readonly TopdataWebserviceClient $topdataWebserviceClient,
    )
    {
    }

    public function testConnection(): array
    {
        $config = $this->systemConfigService->get('TopdataConnectorSW6.config');

        if ($this->configCheckerService->isConfigEmpty()) {
            return [
                'success' => false,
                'message' => GlobalPluginConstants::ERROR_MESSAGE_NO_WEBSERVICE_CREDENTIALS
            ];
        }

        try {
            $info = $this->topdataWebserviceClient->getUserInfo();

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

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => "Connection error: {$e->getMessage()}"
            ];
        }
    }
}
