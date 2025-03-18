<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;

/**
 * Service class to handle options for Topdata's Topfeed plugin.
 * as some of the import options are in the settings of the Topfeed plugin, 
 * we need to load them here
 * 
 * 03/2025 renamed from OptionsHelperService to TopfeedOptionsHelperService
 */
class TopfeedOptionsHelperService
{
    private array $options = [];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * Load Topdata Topfeed plugin configuration.
     *
     * This method copies settings from Topdata's Topfeed plugin config to the options array.
     *
     *
     * 06/2024 created
     * 10/2024 moved from ImportCommand to OptionsHelperService
     */
    public function loadTopdataTopFeedPluginConfig(): void
    {
        $topfeedPluginConfig = $this->systemConfigService->get('TopdataTopFeedSW6.config');
        $this->setOptions($topfeedPluginConfig); // FIXME: what a mess! it MERGES system config of the Topfeed plugin with the options array
        $this->setOption(OptionConstants::PRODUCT_COLOR_VARIANT, $topfeedPluginConfig['productVariantColor']); // FIXME? 'productColorVariant' != 'productVariantColor'
        $this->setOption(OptionConstants::PRODUCT_CAPACITY_VARIANT, $topfeedPluginConfig['productVariantCapacity']); // FIXME? 'productCapacityVariant' != 'productVariantCapacity'
    }

    /**
     * Set an option.
     *
     * An "option" can be either something from command line or a plugin setting.
     *
     * @param string $name  the option name
     * @param mixed  $value the option value
     */
    public function setOption($name, $value): void
    {
        // \Topdata\TopdataFoundationSW6\Util\CliLogger::getCliStyle()->blue("option: $name = $value");
        $this->options[$name] = $value;
    }

    /**
     * Set multiple options at once.
     *
     * @param array $keyValueArray an array of option name-value pairs
     */
    public function setOptions(array $keyValueArray): void
    {
        foreach ($keyValueArray as $key => $value) {
            $this->setOption($key, $value);
        }
    }

    /**
     * Get an option.
     *
     * @param  string $name the option name
     * @return mixed  the option value or false if the option is not set
     */
    public function getOption(string $name): mixed
    {
        return $this->options[$name] ?? false;
    }
}
