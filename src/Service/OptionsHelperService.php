<?php

namespace Topdata\TopdataConnectorSW6\Service;

/**
 * 10/2024 created (extracted from MappingHelperService)
 */
class OptionsHelperService
{
    private array $options = [];

    /**
     * Set an option
     *
     * An "option" can be either something from command line or a plugin setting
     *
     * @param string $name The option name
     * @param mixed $value The option value
     */
    public function setOption($name, $value): void
    {
        // $this->cliStyle->blue("option: $name = $value");
        $this->options[$name] = $value;
    }

    /**
     * Set multiple options at once
     *
     * @param array $keyValueArray An array of option name-value pairs
     */
    public function setOptions(array $keyValueArray): void
    {
        foreach ($keyValueArray as $key => $value) {
            $this->setOption($key, $value);
        }
    }

    public function getOption(string $name)
    {
        return (isset($this->options[$name])) ? $this->options[$name] : false;
    }

}