<?php

namespace Topdata\TopdataConnectorSW6\Service\Import;

use Doctrine\DBAL\Connection;
use Topdata\TopdataConnectorSW6\Constants\MappingTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\MergedPluginConfigKeyConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportConfig;
use Topdata\TopdataConnectorSW6\Service\Config\MergedPluginConfigHelperService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy\AbstractMappingStrategy;
use Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy\MappingStrategy_ProductNumberAs;
use Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy\MappingStrategy_Unified;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Service class for mapping products between Topdata and Shopware 6.
 * This service handles the process of mapping products from Topdata to Shopware 6,
 * utilizing different mapping strategies based on the configured mapping type.
 * 07/2024 created (extracted from MappingHelperService).
 * 05/2025 updated to use the unified mapping strategy.
 */
class ProductMappingService
{
    const BATCH_SIZE                    = 500;
    const BATCH_SIZE_TOPDATA_TO_PRODUCT = 99;

    /**
     * @var array already processed products
     */
    private array $setted;

    public function __construct(
        private readonly MergedPluginConfigHelperService $mergedPluginConfigHelperService,
        private readonly MappingStrategy_ProductNumberAs $mappingStrategy_ProductNumberAs,
        private readonly MappingStrategy_Unified         $mappingStrategy_Unified,
        private readonly TopdataToProductService         $topdataToProductService,
    )
    {
    }

    /**
     * Maps products from Topdata to Shopware 6 based on the configured mapping type.
     * This method truncates the `topdata_to_product` table and then executes the appropriate
     * mapping strategy.
     */
    public function mapProducts(ImportConfig $importConfig): void
    {
        UtilProfiling::startTimer();
        CliLogger::info('ProductMappingService::mapProducts() - using mapping type: ' . $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::MAPPING_TYPE));

        // ---- Clear existing mappings
        $this->topdataToProductService->deleteAll('ProductMappingService::mapProducts - Clear existing mappings before mapping');

        // ---- Create the appropriate strategy based on mapping type
        $strategy = $this->_createMappingStrategy();

        // ---- Execute the strategy
        $strategy->map($importConfig);
        UtilProfiling::stopTimer();
    }

    /**
     * Creates the appropriate mapping strategy based on the configured mapping type.
     *
     * @return AbstractMappingStrategy The mapping strategy to use.
     * @throws \Exception If an unknown mapping type is encountered.
     */
    private function _createMappingStrategy(): AbstractMappingStrategy
    {
        $mappingType = $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::MAPPING_TYPE);

        return match ($mappingType) {
            // ---- Product Number Mapping Strategy
            MappingTypeConstants::PRODUCT_NUMBER_AS_WS_ID => $this->mappingStrategy_ProductNumberAs,

            // ---- Unified Mapping Strategy (handles both EAN/OEM and Distributor)
            MappingTypeConstants::DEFAULT,
            MappingTypeConstants::CUSTOM,
            MappingTypeConstants::CUSTOM_FIELD,
            MappingTypeConstants::DISTRIBUTOR_DEFAULT,
            MappingTypeConstants::DISTRIBUTOR_CUSTOM,
            MappingTypeConstants::DISTRIBUTOR_CUSTOM_FIELD => $this->mappingStrategy_Unified,

            // ---- unknown mapping type --> throw exception
            default => throw new \Exception('Unknown mapping type: ' . $mappingType),
        };
    }
}