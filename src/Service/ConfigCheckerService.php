<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * a service which checks if the plugin configuration is valid.
 *
 * 04/2024 created
 */
class ConfigCheckerService
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService)
    {
    }

    /**
     * 04/2024 created.
     */
    public function isConfigEmpty(): bool
    {
        $config = $this->systemConfigService->get('TopdataConnectorSW6.config');

        return empty($config['apiUsername']) ||
            empty($config['apiKey']) ||
            empty($config['apiSalt']) ||
            empty($config['apiLanguage']);
    }
}
