<?php

namespace Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy;

use Topdata\TopdataConnectorSW6\DTO\ImportConfig;

/**
 * 03/2025 created
 */
abstract  class AbstractMappingStrategy
{
    abstract public function map(ImportConfig $importConfig): void;
}