<?php declare(strict_types = 1);

namespace Topdata\TopdataConnectorSW6\Component\Helper;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Shopware\Core\Kernel;

class PluginHelper
{
    private $container;
    
    public function __construct(ContainerBuilder $container)
    { 
        $this->container = $container;
    }
    
    public function isPluginActive(string $pluginClass) : bool
    {
        $activePlugins = $this->container->getParameter('kernel.active_plugins');
        return isset($activePlugins[$pluginClass]);
    }
    
    public function activePlugins() : array
    {
        return $this->container->getParameter('kernel.active_plugins');
    }
}
