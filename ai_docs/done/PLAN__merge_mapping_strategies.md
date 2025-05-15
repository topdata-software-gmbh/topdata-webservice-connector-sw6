# Plan: Merging Mapping Strategy Classes

## Overview

This document outlines the plan for merging the `MappingStrategy_EanOem` and `MappingStrategy_Distributor` classes into a unified strategy. The main goal is to reduce code duplication and extend the caching mechanism (currently only used for EAN/OEM mappings) to distributor mappings as well.

## Current Situation Analysis

### Similarities between the strategies:
1. Both extend `AbstractMappingStrategy` and implement the `map(ImportConfig $importConfig): void` method
2. Both fetch mappings from the Topdata webservice
3. Both store mappings in the database via `TopdataToProductService`
4. Both use batch processing for efficiency
5. Both have similar constructor dependencies

### Key Differences:
1. **Caching**: `MappingStrategy_EanOem` uses `MappingCacheService` for caching, while `MappingStrategy_Distributor` doesn't
2. **Identifier Types**: 
   - `MappingStrategy_EanOem` handles EAN, OEM, and PCD identifiers
   - `MappingStrategy_Distributor` handles distributor SKUs
3. **Webservice Methods**:
   - `MappingStrategy_EanOem` calls `matchMyEANs`, `matchMyOems`, and `matchMyPcds`
   - `MappingStrategy_Distributor` calls `matchMyDistributor`
4. **Data Structure**: The response structure from the webservice is different for each strategy

### Benefits of Merging:
1. Code reuse and reduction of duplication
2. Consistent caching mechanism for all mapping types
3. Simplified maintenance and future enhancements
4. Potentially improved performance for distributor mappings

## Implementation Plan

### 1. Create the New Unified Strategy Class

```php
<?php

namespace Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy;

use Exception;
use Override;
use Topdata\TopdataConnectorSW6\Constants\MappingTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\MergedPluginConfigKeyConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportConfig;
use Topdata\TopdataConnectorSW6\Service\Cache\MappingCacheService;
use Topdata\TopdataConnectorSW6\Service\Config\MergedPluginConfigHelperService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Service\Import\ShopwareProductPropertyService;
use Topdata\TopdataConnectorSW6\Service\Import\ShopwareProductService;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilMappingHelper;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilFormatter;

/**
 * Unified mapping strategy that handles EAN, OEM, PCD, and Distributor mappings.
 * Supports caching for all mapping types.
 * 
 * 05/2025 created (merged from MappingStrategy_EanOem and MappingStrategy_Distributor)
 */
final class MappingStrategy_Unified extends AbstractMappingStrategy
{
    const BATCH_SIZE = 500;
    
    /**
     * Tracks product IDs already mapped in a single run to avoid duplicates
     */
    private array $setted = [];
    
    public function __construct(
        private readonly MergedPluginConfigHelperService $mergedPluginConfigHelperService,
        private readonly TopdataToProductService         $topdataToProductService,
        private readonly TopdataWebserviceClient         $topdataWebserviceClient,
        private readonly ShopwareProductService          $shopwareProductService,
        private readonly MappingCacheService             $mappingCacheService,
        private readonly ShopwareProductPropertyService  $shopwareProductPropertyService,
    )
    {
    }
    
    // Implementation methods will follow...
}
```

### 2. Implement the Main `map()` Method

```php
/**
 * Maps products using the appropriate strategy based on the mapping type.
 *
 * @throws Exception if any critical error occurs during the mapping process
 */
#[Override]
public function map(ImportConfig $importConfig): void
{
    $mappingType = $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::MAPPING_TYPE);
    
    // Check if this is a distributor mapping type
    $isDistributorMapping = in_array($mappingType, [
        MappingTypeConstants::DISTRIBUTOR_DEFAULT,
        MappingTypeConstants::DISTRIBUTOR_CUSTOM,
        MappingTypeConstants::DISTRIBUTOR_CUSTOM_FIELD
    ]);
    
    if ($isDistributorMapping) {
        $this->mapDistributor($importConfig);
    } else {
        $this->mapEanOem($importConfig);
    }
}
```

### 3. Implement the EAN/OEM Mapping Method

```php
/**
 * Maps products using the EAN/OEM/PCD strategy.
 *
 * @param ImportConfig $importConfig The import configuration
 * @throws Exception if any critical error occurs during the mapping process
 */
private function mapEanOem(ImportConfig $importConfig): void
{
    CliLogger::section('Product Mapping Strategy: EAN/OEM/PCD');

    // 1. Check config
    $useExperimentalCacheV2 = TRUE; // (bool)$importConfig->getOptionExperimentalV2();
    CliLogger::info('Experimental V2 Cache Enabled: ' . ($useExperimentalCacheV2 ? 'Yes' : 'No'));

    // 2. Attempt to load from V2 cache (if enabled)
    if ($useExperimentalCacheV2 && $this->tryLoadFromCacheV2(MappingTypeConstants::EAN_OEM_GROUP)) {
        CliLogger::section('Finished mapping using cached data (V2).');
        return; // Cache was successfully loaded and used, skip fetch/save
    }

    // --- Cache was not used or failed, proceed with fetch and save ---

    // 3. Build identifier maps from Shopware
    [$oemMap, $eanMap] = $this->buildShopwareIdentifierMaps();

    // 4. Fetch corresponding mappings from Topdata Webservice
    $mappingsByType = $this->processWebserviceMappings($oemMap, $eanMap);
    unset($oemMap, $eanMap); // Free memory

    // 5. Save fetched mappings to V2 cache (if enabled)
    if ($useExperimentalCacheV2) {
        $this->saveToCacheV2($mappingsByType, MappingTypeConstants::EAN_OEM_GROUP);
    }

    // 6. Flatten mappings for database persistence
    $flatMappings = $this->flattenMappings($mappingsByType);

    // 7. Persist flattened mappings to the database
    $this->persistMappingsToDatabase($flatMappings);

    CliLogger::section('Finished product mapping (fetched from webservice).');
}
```

### 4. Implement the Distributor Mapping Method

```php
/**
 * Maps products using the distributor mapping strategy.
 *
 * @param ImportConfig $importConfig The import configuration
 * @throws Exception if any critical error occurs during the mapping process
 */
private function mapDistributor(ImportConfig $importConfig): void
{
    CliLogger::section('Product Mapping Strategy: Distributor');
    
    // 1. Check config
    $useExperimentalCacheV2 = TRUE; // (bool)$importConfig->getOptionExperimentalV2();
    CliLogger::info('Experimental V2 Cache Enabled: ' . ($useExperimentalCacheV2 ? 'Yes' : 'No'));
    
    // 2. Attempt to load from V2 cache (if enabled)
    if ($useExperimentalCacheV2 && $this->tryLoadFromCacheV2(MappingTypeConstants::DISTRIBUTOR)) {
        CliLogger::section('Finished mapping using cached data (V2).');
        return; // Cache was successfully loaded and used, skip fetch/save
    }
    
    // --- Cache was not used or failed, proceed with fetch and save ---
    
    // 3. Build article number map from Shopware
    $articleNumberMap = $this->getArticleNumbers();
    
    // 4. Fetch corresponding mappings from Topdata Webservice
    $mappingsByType = [
        MappingTypeConstants::DISTRIBUTOR => $this->processDistributorWebserviceMappings($articleNumberMap)
    ];
    unset($articleNumberMap); // Free memory
    
    // 5. Save fetched mappings to V2 cache (if enabled)
    if ($useExperimentalCacheV2) {
        $this->saveToCacheV2($mappingsByType, MappingTypeConstants::DISTRIBUTOR);
    }
    
    // 6. Flatten mappings for database persistence
    $flatMappings = $this->flattenMappings($mappingsByType);
    
    // 7. Persist flattened mappings to the database
    $this->persistMappingsToDatabase($flatMappings);
    
    CliLogger::section('Finished product mapping (fetched from webservice).');
}
```

### 5. Implement the Cache Loading Method

```php
/**
 * Attempts to load mappings directly from the V2 cache into the database.
 * This bypasses fetching from the webservice if the cache is valid and populated.
 *
 * @param string $mappingGroup The mapping group to load (EAN_OEM_GROUP or DISTRIBUTOR)
 * @return bool True if mappings were successfully loaded from cache, False otherwise.
 */
private function tryLoadFromCacheV2(string $mappingGroup): bool
{
    if (!$this->mappingCacheService->hasCachedMappings()) {
        CliLogger::info('No valid V2 cache found, proceeding with fetch.');
        return false;
    }

    CliLogger::info('Valid V2 cache found, attempting to load mappings...');
    
    // For EAN/OEM group, we load EAN, OEM, and PCD mappings
    // For Distributor group, we load only Distributor mappings
    $mappingTypes = ($mappingGroup === MappingTypeConstants::EAN_OEM_GROUP) 
        ? [MappingTypeConstants::EAN, MappingTypeConstants::OEM, MappingTypeConstants::PCD]
        : [MappingTypeConstants::DISTRIBUTOR];
    
    $totalLoaded = 0;
    foreach ($mappingTypes as $mappingType) {
        $loaded = $this->mappingCacheService->loadMappingsFromCache($mappingType);
        $totalLoaded += $loaded;
        CliLogger::info("Loaded $loaded $mappingType mappings from cache.");
    }

    if ($totalLoaded > 0) {
        CliLogger::info('Successfully loaded ' . UtilFormatter::formatInteger($totalLoaded) . ' total mappings from cache into database.');
        ImportReport::setCounter('Mappings Loaded from Cache', $totalLoaded);
        ImportReport::setCounter('Webservice Calls Skipped', 1); // Indicate that API fetch was skipped
        return true; // Signal success
    }

    CliLogger::warning('V2 cache exists but failed to load mappings (or was empty). Proceeding with fetch.');
    return false; // Signal failure
}
```

### 6. Implement the Cache Saving Method

```php
/**
 * Saves the fetched mappings to the V2 cache, if enabled.
 * This is done per mapping type (EAN, OEM, PCD, Distributor).
 *
 * @param array<string, array> $mappingsByType Mappings fetched from webservice, grouped by type.
 * @param string $mappingGroup The mapping group being saved (EAN_OEM_GROUP or DISTRIBUTOR)
 */
private function saveToCacheV2(array $mappingsByType, string $mappingGroup): void
{
    CliLogger::info('Saving fetched mappings to V2 cache...');
    $totalCached = 0;
    
    foreach ($mappingsByType as $mappingType => $typeMappings) {
        if (!empty($typeMappings)) {
            $count = count($typeMappings);
            CliLogger::info("-> Caching " . UtilFormatter::formatInteger($count) . " $mappingType mappings...");
            
            // Extract only the necessary fields for caching (topDataId and value)
            // This makes the cache independent of Shopware product IDs
            $cacheMappings = array_map(function($mapping) {
                return [
                    'topDataId' => $mapping['topDataId'],
                    'value'     => $mapping['value']
                ];
            }, $typeMappings);
            
            $this->mappingCacheService->saveMappingsToCache($cacheMappings, $mappingType);
            $totalCached += $count;
        }
    }
    
    // Display cache statistics after saving
    $cacheStats = $this->mappingCacheService->getCacheStats();
    CliLogger::info('--- Cache Statistics ---');
    CliLogger::info('Total cached mappings: ' . UtilFormatter::formatInteger($cacheStats['total']));
    if (isset($cacheStats['by_type'])) {
        CliLogger::info('Mappings by type:');
        foreach ($cacheStats['by_type'] as $type => $count) {
            CliLogger::info("  - {$type}: " . UtilFormatter::formatInteger($count));
        }
    }
    if (isset($cacheStats['oldest'])) {
        CliLogger::info('Oldest entry: ' . $cacheStats['oldest']);
    }
    if (isset($cacheStats['newest'])) {
        CliLogger::info('Newest entry: ' . $cacheStats['newest']);
    }
    CliLogger::info('------------------------');
    
    if ($totalCached > 0) {
        CliLogger::info('Finished saving ' . UtilFormatter::formatInteger($totalCached) . ' mappings to V2 cache.');
        ImportReport::setCounter('Mappings Saved to Cache', $totalCached);
    } else {
        CliLogger::info('No new mappings were fetched to save to V2 cache.');
    }
}
```

### 7. Implement the Distributor Webservice Processing Method

```php
/**
 * Processes distributor mappings from the webservice.
 *
 * @param array $articleNumberMap Mapping of article numbers to products from Shopware
 * @return array Distributor mappings data
 * @throws Exception if any error occurs during API communication
 */
private function processDistributorWebserviceMappings(array $articleNumberMap): array
{
    $this->setted = []; // Reset for this run
    $mappings = [];
    $stored = 0;
    
    CliLogger::info(UtilFormatter::formatInteger(count($articleNumberMap)) . ' products to check ...');
    
    try {
        // Iterate through the pages of distributor data from the web service
        for ($page = 1; ; $page++) {
            $response = $this->topdataWebserviceClient->matchMyDistributor(['page' => $page]);
            
            if (!isset($response->page->available_pages)) {
                throw new Exception('distributor webservice no pages');
            }
            
            $available_pages = (int)$response->page->available_pages;
            
            // Process each product in the current page
            foreach ($response->match as $prod) {
                $topDataId = $prod->products_id;
                
                foreach ($prod->distributors as $distri) {
                    foreach ($distri->artnrs as $artnr) {
                        $originalValue = (string)$artnr;
                        $key = $originalValue; // For distributor, we use the original value as the key
                        
                        if (isset($articleNumberMap[$key])) {
                            foreach ($articleNumberMap[$key] as $articleNumberValue) {
                                $shopwareProductKey = $articleNumberValue['id'] . '-' . $articleNumberValue['version_id'];
                                
                                // Check if this specific Shopware product (id+version) hasn't been mapped yet in this run
                                if (!isset($this->setted[$shopwareProductKey])) {
                                    $mappings[] = [
                                        'topDataId'        => $topDataId,
                                        'productId'        => $articleNumberValue['id'],
                                        'productVersionId' => $articleNumberValue['version_id'],
                                        'value'            => $originalValue, // Store original value for caching
                                    ];
                                    
                                    $this->setted[$shopwareProductKey] = true; // Mark as mapped for this run
                                    $stored++;
                                    
                                    if (($stored % 50) == 0) {
                                        CliLogger::activity();
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            CliLogger::progress($page, $available_pages, 'fetch distributor data');
            
            if ($page >= $available_pages) {
                break;
            }
        }
    } catch (Exception $e) {
        CliLogger::error('Error fetching distributor data from webservice: ' . $e->getMessage());
        throw $e; // Re-throw for now to indicate failure
    }
    
    CliLogger::writeln("\n" . UtilFormatter::formatInteger($stored) . ' - stored topdata products');
    ImportReport::setCounter('Fetched Distributor SKUs', $stored);
    
    return $mappings;
}
```

### 8. Implement the Article Numbers Method

```php
/**
 * Gets article numbers from Shopware based on the mapping type.
 *
 * @return array Map of article numbers to product data
 * @throws Exception if no products are found
 */
private function getArticleNumbers(): array
{
    // Determine the source of product numbers based on the mapping type
    $mappingType = $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::MAPPING_TYPE);
    $attributeArticleNumber = $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_ORDERNUMBER);

    if ($mappingType == MappingTypeConstants::DISTRIBUTOR_CUSTOM && $attributeArticleNumber != '') {
        // the distributor's SKU is a product property
        $artnos = UtilMappingHelper::convertMultiArrayBinaryIdsToHex(
            $this->shopwareProductPropertyService->getKeysByOptionValueUnique($attributeArticleNumber)
        );
    } elseif ($mappingType == MappingTypeConstants::DISTRIBUTOR_CUSTOM_FIELD && $attributeArticleNumber != '') {
        // the distributor's SKU is a product custom field
        $artnos = $this->shopwareProductService->getKeysByCustomFieldUnique($attributeArticleNumber);
    } else {
        // the distributor's SKU is the product number
        $artnos = UtilMappingHelper::convertMultiArrayBinaryIdsToHex(
            $this->shopwareProductService->getKeysByProductNumber()
        );
    }

    if (count($artnos) == 0) {
        throw new Exception('distributor mapping 0 products found');
    }

    return $artnos;
}
```

### 9. Update the MappingTypeConstants

Add a new constant to `MappingTypeConstants.php` for the distributor mapping type:

```php
<?php

namespace Topdata\TopdataConnectorSW6\Constants;

/**
 * Constants for mapping types.
 */
class MappingTypeConstants
{
    const DEFAULT = 'default';
    const CUSTOM = 'custom';
    const CUSTOM_FIELD = 'custom_field';
    const PRODUCT_NUMBER_AS_WS_ID = 'product_number_as_ws_id';
    const DISTRIBUTOR_DEFAULT = 'distributor_default';
    const DISTRIBUTOR_CUSTOM = 'distributor_custom';
    const DISTRIBUTOR_CUSTOM_FIELD = 'distributor_custom_field';
    
    // Mapping types for cache
    const EAN = 'ean';
    const OEM = 'oem';
    const PCD = 'pcd';
    const DISTRIBUTOR = 'distributor'; // New constant for distributor mappings
    
    // Mapping groups for cache loading/saving
    const EAN_OEM_GROUP = 'ean_oem_group';
}
```

### 10. Update the ProductMappingService

Update the `ProductMappingService` to use the new unified strategy:

```php
<?php

namespace Topdata\TopdataConnectorSW6\Service\Import;

use Doctrine\DBAL\Connection;
use Topdata\TopdataConnectorSW6\Constants\MappingTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\MergedPluginConfigKeyConstants;
use Topdata\TopdataConnectorSW6\DTO\ImportConfig;
use Topdata\TopdataConnectorSW6\Service\Config\MergedPluginConfigHelperService;
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
        private readonly Connection                      $connection,
        private readonly MergedPluginConfigHelperService $mergedPluginConfigHelperService,
        private readonly MappingStrategy_ProductNumberAs $mappingStrategy_ProductNumberAs,
        private readonly MappingStrategy_Unified         $mappingStrategy_Unified,
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
        $this->connection->executeStatement('TRUNCATE TABLE topdata_to_product');

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
```

### 11. Update the services.xml Configuration

Update the `services.xml` file to register the new unified strategy:

```xml
<!-- Mapping Strategies -->
<service id="Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy\MappingStrategy_ProductNumberAs">
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService"/>
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\Import\ShopwareProductService"/>
</service>

<service id="Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy\MappingStrategy_Unified">
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\Config\MergedPluginConfigHelperService"/>
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService"/>
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient"/>
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\Import\ShopwareProductService"/>
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\Cache\MappingCacheService"/>
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\Import\ShopwareProductPropertyService"/>
</service>

<!-- Product Mapping Service -->
<service id="Topdata\TopdataConnectorSW6\Service\Import\ProductMappingService">
    <argument type="service" id="Doctrine\DBAL\Connection"/>
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\Config\MergedPluginConfigHelperService"/>
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy\MappingStrategy_ProductNumberAs"/>
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy\MappingStrategy_Unified"/>
</service>
```

## Implementation of Common Methods

The unified strategy will also need to implement several common methods from the original strategies. These include:

1. `buildShopwareIdentifierMaps()` - From MappingStrategy_EanOem
2. `fetchAndMapIdentifiersFromWebservice()` - From MappingStrategy_EanOem
3. `processWebserviceMappings()` - From MappingStrategy_EanOem
4. `flattenMappings()` - From MappingStrategy_EanOem
5. `persistMappingsToDatabase()` - From MappingStrategy_EanOem

These methods can be copied directly from the original strategies with minimal modifications.

## Testing Plan

1. **Unit Tests**:
   - Test the `map()` method with different mapping types
   - Test the caching mechanism for both EAN/OEM and Distributor mappings
   - Test the webservice processing methods

2. **Integration Tests**:
   - Test the integration with the caching service
   - Test the integration with the webservice client
   - Test the integration with the database services

3. **End-to-End Tests**:
   - Test the complete import process with different mapping types
   - Test the caching behavior in real-world scenarios

4. **Performance Tests**:
   - Compare the performance of the unified strategy with the original strategies
   - Measure the impact of caching on performance

## Migration Plan

1. Create the new `MappingStrategy_Unified` class
2. Update the `ProductMappingService` to use the new unified strategy
3. Update the `services.xml` configuration
4. Add the new constant to `MappingTypeConstants.php`
5. Run tests to ensure everything works correctly
6. Deploy the changes
7. Monitor the system for any issues

## Conclusion

Merging the `MappingStrategy_EanOem` and `MappingStrategy_Distributor` classes into a unified strategy will reduce code duplication, extend caching to distributor mappings, and simplify maintenance. The implementation plan outlined in this document provides a clear path forward for this refactoring effort.