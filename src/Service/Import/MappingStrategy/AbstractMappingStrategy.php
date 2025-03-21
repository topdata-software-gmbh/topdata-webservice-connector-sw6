<?php

namespace Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy;

/**
 * 03/2025 created
 */
abstract  class AbstractMappingStrategy
{
    abstract public function map(): void;
}