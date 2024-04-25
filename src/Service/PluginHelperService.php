<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Shopware\Core\Kernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 04/2024 PluginHelper --> PluginHelperService; unused
 */
class PluginHelperService
{
    private $container;

    public function __construct(ContainerBuilder $container)
    {
        $this->container = $container;
    }

    public function isPluginActive(string $pluginClass): bool
    {
        $activePlugins = $this->container->getParameter('kernel.active_plugins');

        return isset($activePlugins[$pluginClass]);
    }

    public function activePlugins(): array
    {
        return $this->container->getParameter('kernel.active_plugins');
    }
}
