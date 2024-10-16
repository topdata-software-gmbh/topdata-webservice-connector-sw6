<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;

/**
 * Service class to handle options for Topdata's Topfeed plugin.
 *
 * This class is responsible for loading configuration settings from the Topfeed plugin
 * and setting them as options that can be used elsewhere in the application.
 *
 * @package Topdata\TopdataConnectorSW6\Service
 */
class OptionsHelperService
{
    /**
     * @var array $options Array to store options.
     */
    private array $options = [];

    /**
     * Constructor for OptionsHelperService.
     *
     * @param SystemConfigService $systemConfigService Service to access system configuration.
     */
    public function __construct(
        private readonly SystemConfigService   $systemConfigService,
    )
    {
    }

    /**
     * Load Topdata Topfeed plugin configuration.
     *
     * This method copies settings from Topdata's Topfeed plugin config to the options array.
     *
     *
     * 06/2024 created
     * 10/2024 moved from ImportCommand to OptionsHelperService
     *
     */
    public function loadTopdataTopFeedPluginConfig(): void
    {
        $pluginConfig = $this->systemConfigService->get('TopdataTopFeedSW6.config');
        $this->setOptions($pluginConfig);
        $this->setOption(OptionConstants::PRODUCT_COLOR_VARIANT, $pluginConfig['productVariantColor']); // FIXME? 'productColorVariant' != 'productVariantColor'
        $this->setOption(OptionConstants::PRODUCT_CAPACITY_VARIANT, $pluginConfig['productVariantCapacity']); // FIXME? 'productCapacityVariant' != 'productVariantCapacity'
    }

    /**
     * Set an option.
     *
     * An "option" can be either something from command line or a plugin setting.
     *
     * @param string $name The option name.
     * @param mixed $value The option value.
     */
    public function setOption($name, $value): void
    {
        // $this->cliStyle->blue("option: $name = $value");
        $this->options[$name] = $value;
    }

    /**
     * Set multiple options at once.
     *
     * @param array $keyValueArray An array of option name-value pairs.
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
     * @param string $name The option name.
     * @return mixed The option value or false if the option is not set.
     */
    public function getOption(string $name): mixed
    {
        return (isset($this->options[$name])) ? $this->options[$name] : false;
    }

}