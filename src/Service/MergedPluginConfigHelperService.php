<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataConnectorSW6\Constants\MergedPluginConfigKeyConstants;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Service class to handle options for Topdata's Topfeed plugin.
 * as some of the import options are in the settings of the Topfeed plugin, 
 * we need to load them here
 * 
 * 03/2025 renamed from OptionsHelperService to TopfeedOptionsHelperService
 * 04/2025 renamed TopfeedOptionsHelperService to MergedPluginConfigHelperService
 */
class MergedPluginConfigHelperService
{
    private array $options = [];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * Set an option.
     *
     * An "option" can be either something from command line or a plugin setting.
     *
     * @param string $name  the option name
     * @param mixed  $value the option value
     */
    private function _setOption($name, $value): void
    {
        CliLogger::getCliStyle()->blue("option: $name = $value");
        $this->options[$name] = $value;
    }

    /**
     * Set multiple options at once.
     *
     * @param array $keyValueArray an array of option name-value pairs
     */
    private function _setOptions(array $keyValueArray): void
    {
        foreach ($keyValueArray as $key => $value) {
            $this->_setOption($key, $value);
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






    /**
     * Load Topdata Topfeed plugin configuration.
     *
     * This method copies settings from Topdata's Topfeed plugin config to the options array.
     *
     *
     * 06/2024 created
     * 10/2024 moved from ImportCommand to OptionsHelperService
     */
    public function _loadOptionsFromTopFeedPluginConfig(): void
    {
        $topfeedPluginConfig = $this->systemConfigService->get('TopdataTopFeedSW6.config');
        $this->_setOptions($topfeedPluginConfig);
        $this->_setOption(MergedPluginConfigKeyConstants::PRODUCT_COLOR_VARIANT, $topfeedPluginConfig['productVariantColor']); // FIXME? 'productColorVariant' != 'productVariantColor'
        $this->_setOption(MergedPluginConfigKeyConstants::PRODUCT_CAPACITY_VARIANT, $topfeedPluginConfig['productVariantCapacity']); // FIXME? 'productCapacityVariant' != 'productVariantCapacity'
    }

    /**
     * Initializes the options for the import process.
     *
     * This method retrieves configuration settings from the system configuration
     * and sets the corresponding options in the OptionsHelperService.
     *
     * 04/2025 moved from ImportService::_initOptions() to TopfeedOptionsHelperService::loadOptionsFromConnectorPluginConfig()
     */
    public function _loadOptionsFromConnectorPluginConfig(): void
    {
        $pluginConfig = $this->systemConfigService->get('TopdataConnectorSW6.config');

        $this->_setOptions([
            MergedPluginConfigKeyConstants::MAPPING_TYPE          => $pluginConfig['mappingType'] ,
            MergedPluginConfigKeyConstants::ATTRIBUTE_OEM         => $pluginConfig['attributeOem'] ?? '',
            MergedPluginConfigKeyConstants::ATTRIBUTE_EAN         => $pluginConfig['attributeEan'] ?? '',
            MergedPluginConfigKeyConstants::ATTRIBUTE_ORDERNUMBER => $pluginConfig['attributeOrdernumber'] ?? '',   // fixme: this is not an ordernumber, but a product number
        ]);
    }

    /**
     * 04/2025 created
     */
    public function init(): void
    {
        $this->_loadOptionsFromConnectorPluginConfig();
        $this->_loadOptionsFromTopFeedPluginConfig();
    }


}
