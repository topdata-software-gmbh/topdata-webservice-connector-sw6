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
use Topdata\TopdataConnectorSW6\Service\Shopware\ShopwareProductPropertyService;
use Topdata\TopdataConnectorSW6\Service\Shopware\ShopwareProductService;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilMappingHelper;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilFormatter;

/**
 * Implements the default mapping strategy for products, using OEM and EAN numbers.
 * 03/2025 created (extracted from ProductMappingService)
 * 04/2025 renamed from MappingStrategy_Default to MappingStrategy_EanOem
 * Refactored 05/2024 to improve cache handling logic and separation of concerns.
 */
final class MappingStrategy_EanOem extends AbstractMappingStrategy
{
    const BATCH_SIZE = 500;

    private array $setted = []; // Tracks product IDs already mapped in a single run to avoid duplicates


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

    /**
     * Builds mapping arrays for OEM and EAN numbers based on Shopware data.
     * (Implementation remains the same as before)
     *
     * @return array{0: array<string, array<string, array{id: string, version_id: string}>>, 1: array<string, array<string, array{id: string, version_id: string}>>}
     */
    private function buildShopwareIdentifierMaps(): array
    {
        CliLogger::info('Building Shopware identifier maps (OEM/EAN)...');
        $oems = [];
        $eans = [];
        $mappingType = $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::MAPPING_TYPE);
        $oemAttribute = $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_OEM);
        $eanAttribute = $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_EAN);

        // ---- Fetch product data based on mapping type configuration
        switch ($mappingType) {
            case MappingTypeConstants::CUSTOM:
                if ($oemAttribute) {
                    $oems = UtilMappingHelper::_fixArrayBinaryIds(
                        $this->shopwareProductPropertyService->getKeysByOptionValue($oemAttribute, 'manufacturer_number') // FIXME: hardcoded name
                    );
                }
                if ($eanAttribute) {
                    $eans = UtilMappingHelper::_fixArrayBinaryIds(
                        $this->shopwareProductPropertyService->getKeysByOptionValue($eanAttribute, 'ean')
                    );
                }
                break;

            case MappingTypeConstants::CUSTOM_FIELD:
                if ($oemAttribute) {
                    $oems = $this->shopwareProductService->getKeysByCustomFieldUnique($oemAttribute, 'manufacturer_number');
                }
                if ($eanAttribute) {
                    $eans = $this->shopwareProductService->getKeysByCustomFieldUnique($eanAttribute, 'ean');
                }
                break;

            default: // Default mapping
                $oems = UtilMappingHelper::_fixArrayBinaryIds($this->shopwareProductService->getKeysByMpn());
                $eans = UtilMappingHelper::_fixArrayBinaryIds($this->shopwareProductService->getKeysByEan());
                break;
        }

        CliLogger::info(UtilFormatter::formatInteger(count($oems)) . ' potential OEM sources found');
        CliLogger::info(UtilFormatter::formatInteger(count($eans)) . ' potential EAN sources found');

        // ---- Build OEM number mapping
        $oemMap = [];
        foreach ($oems as $oemData) {
            if (empty($oemData['manufacturer_number'])) continue;
            // Normalize: lowercase, trim, remove leading zeros
            $normalizedOem = strtolower(ltrim(trim((string)$oemData['manufacturer_number']), '0'));
            if (empty($normalizedOem)) continue;
            $key = $oemData['id'] . '-' . $oemData['version_id'];
            $oemMap[$normalizedOem][$key] = [
                'id'         => $oemData['id'],
                'version_id' => $oemData['version_id'],
            ];
        }
        unset($oems); // Free memory
        CliLogger::info(UtilFormatter::formatInteger(count($oemMap)) . ' unique normalized OEMs mapped');


        // ---- Build EAN number mapping
        $eanMap = [];
        foreach ($eans as $eanData) {
            if (empty($eanData['ean'])) continue;
            // Normalize: remove non-digits, trim, remove leading zeros
            $normalizedEan = ltrim(trim(preg_replace('/[^0-9]/', '', (string)$eanData['ean'])), '0');
            if (empty($normalizedEan)) continue;
            $key = $eanData['id'] . '-' . $eanData['version_id'];
            $eanMap[$normalizedEan][$key] = [
                'id'         => $eanData['id'],
                'version_id' => $eanData['version_id'],
            ];
        }
        unset($eans); // Free memory
        CliLogger::info(UtilFormatter::formatInteger(count($eanMap)) . ' unique normalized EANs mapped');

        return [$oemMap, $eanMap];
    }


    /**
     * Processes a specific type of identifier (EAN, OEM, PCD) from the webservice.
     * Modified to store original webservice values alongside product mappings.
     *
     * @param string $type (e.g., MappingTypeConstants::EAN, MappingTypeConstants::OEM, MappingTypeConstants::PCD)
     * @param string $webserviceMethod The method name on TopdataWebserviceClient (e.g., 'matchMyEANs')
     * @param array $identifierMap The map built from Shopware data (e.g., $eanMap or $oemMap)
     * @param string $logLabel A label for logging (e.g., 'EANs', 'OEMs', 'PCDs')
     * @return array Raw mappings data [{'topDataId': ..., 'productId': ..., 'productVersionId': ..., 'value': ...}]
     * @throws Exception If the webservice response is invalid
     */
    private function fetchAndMapIdentifiersFromWebservice(string $type, string $webserviceMethod, array $identifierMap, string $logLabel): array
    {
        $mappings = [];
        CliLogger::title("Fetching $logLabel from Webservice...");
        $totalFetched = 0;
        $matchedCount = 0;

        if (empty($identifierMap)) {
            CliLogger::warning("Skipping $logLabel fetch, no corresponding identifiers found in Shopware.");
            ImportReport::setCounter("Fetched $logLabel", 0);
            ImportReport::setCounter("$logLabel mappings collected", 0);
            return [];
        }

        try {
            for ($page = 1; ; $page++) {
                $response = $this->topdataWebserviceClient->$webserviceMethod(['page' => $page]);

                if (!isset($response->match, $response->page->available_pages)) {
                    throw new Exception("$type webservice response structure invalid on page $page.");
                }

                $totalFetched += count($response->match);
                $available_pages = (int)$response->page->available_pages;

                foreach ($response->match as $productData) {
                    $topDataId = $productData->products_id;
                    foreach ($productData->values as $identifier) {
                        // Store the original identifier value from the webservice
                        $originalIdentifier = (string)$identifier;
                        
                        // Normalize identifier from webservice similarly to how Shopware data was normalized
                        $normalizedIdentifier = $originalIdentifier;
                        if ($type === MappingTypeConstants::EAN) {
                            $normalizedIdentifier = ltrim(trim(preg_replace('/[^0-9]/', '', $normalizedIdentifier)), '0');
                        } else { // OEM, PCD
                            $normalizedIdentifier = strtolower(ltrim(trim($normalizedIdentifier), '0'));
                        }

                        if (empty($normalizedIdentifier)) continue;

                        // Check if this normalized identifier exists in our Shopware map
                        if (isset($identifierMap[$normalizedIdentifier])) {
                            foreach ($identifierMap[$normalizedIdentifier] as $shopwareProductKey => $shopwareProductData) {
                                // Check if this specific Shopware product (id+version) hasn't been mapped yet in this run
                                if (!isset($this->setted[$shopwareProductKey])) {
                                    $mappings[] = [
                                        'topDataId'        => $topDataId,
                                        'productId'        => $shopwareProductData['id'],
                                        'productVersionId' => $shopwareProductData['version_id'],
                                        'value'            => $originalIdentifier, // Store original value for caching
                                    ];
                                    $this->setted[$shopwareProductKey] = true; // Mark as mapped for this run
                                    $matchedCount++;
                                }
                            }
                        }
                    }
                }

                CliLogger::progress($page, $available_pages, "Fetched $logLabel page");

                if ($page >= $available_pages) {
                    break;
                }
            }
        } catch (Exception $e) {
            CliLogger::error("Error fetching $logLabel from webservice: " . $e->getMessage());
            // Depending on requirements, you might re-throw, return partial data, or return empty
            throw $e; // Re-throw for now to indicate failure
        }

        CliLogger::write("DONE. Fetched " . UtilFormatter::formatInteger($totalFetched) . " $logLabel records from Webservice. ");
        CliLogger::mem();
        ImportReport::setCounter("Fetched $logLabel", $totalFetched);
        ImportReport::setCounter("$logLabel mappings collected", $matchedCount);

        return $mappings;
    }


    /**
     * Processes webservice mappings by fetching data from the API for EAN, OEM, and PCD.
     * Resets and uses the `setted` property to avoid duplicate mappings within a single run.
     * (Implementation remains the same as before)
     *
     * @param array $oemMap Mapping of OEM numbers to products from Shopware
     * @param array $eanMap Mapping of EAN numbers to products from Shopware
     * @return array<string, array> Associative array of mapping data by type [type => [[mapping_data], ...]]
     * @throws Exception if any error occurs during API communication
     */
    private function processWebserviceMappings(array $oemMap, array $eanMap): array
    {
        $this->setted = []; // Reset for this run
        $allMappings = [];

        // Process EAN mappings
        $allMappings[MappingTypeConstants::EAN] = $this->fetchAndMapIdentifiersFromWebservice(
            MappingTypeConstants::EAN,
            'matchMyEANs',
            $eanMap,
            'EANs'
        );

        // Process OEM mappings
        $allMappings[MappingTypeConstants::OEM] = $this->fetchAndMapIdentifiersFromWebservice(
            MappingTypeConstants::OEM,
            'matchMyOems',
            $oemMap,
            'OEMs'
        );

        // Process PCD mappings (uses the same OEM map from Shopware)
        $allMappings[MappingTypeConstants::PCD] = $this->fetchAndMapIdentifiersFromWebservice(
            MappingTypeConstants::PCD,
            'matchMyPcds',
            $oemMap,
            'PCDs'
        );

        unset($this->setted); // Clean up instance variable after use

        return array_filter($allMappings); // Remove empty mapping types
    }

    /**
     * Attempts to load mappings directly from the V2 cache into the database.
     * This bypasses fetching from the webservice if the cache is valid and populated.
     *
     * @return bool True if mappings were successfully loaded from cache, False otherwise.
     */
    private function tryLoadFromCacheV2(): bool
    {
        if (!$this->mappingCacheService->hasCachedMappings()) {
            CliLogger::info('No valid V2 cache found, proceeding with fetch.');
            return false;
        }

        CliLogger::info('Valid V2 cache found, attempting to load mappings...');
        $totalLoaded = $this->mappingCacheService->loadMappingsFromCache();

        if ($totalLoaded > 0) {
            CliLogger::info('Successfully loaded ' . UtilFormatter::formatInteger($totalLoaded) . ' total mappings from cache into database.');
            ImportReport::setCounter('Mappings Loaded from Cache', $totalLoaded);
            ImportReport::setCounter('Webservice Calls Skipped', 1); // Indicate that API fetch was skipped
            return true; // Signal success
        }

        CliLogger::warning('V2 cache exists but failed to load mappings (or was empty). Proceeding with fetch.');
        // Invalidate or clear cache here if desired on load failure? Maybe not, let it be overwritten.
        return false; // Signal failure
    }

    /**
     * Saves the fetched mappings to the V2 cache, if enabled.
     * This is done per mapping type (EAN, OEM, PCD).
     * Modified to extract only topDataId and value for caching.
     *
     * @param array<string, array> $mappingsByType Mappings fetched from webservice, grouped by type.
     */
    private function saveToCacheV2(array $mappingsByType): void
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

    /**
     * Inserts mappings into the database in batches.
     * (Implementation remains the same as before, but now has a single responsibility)
     *
     * @param array $mappings A flat list of mapping data arrays.
     */
    private function persistMappingsToDatabase(array $mappings): void
    {
        $totalToInsert = count($mappings);
        if ($totalToInsert === 0) {
            CliLogger::info('No mappings to insert into database.');
            ImportReport::setCounter('Mappings Inserted/Updated', 0);
            return;
        }

        // Clear existing mappings before inserting new ones (as done by loadMappingsFromCache implicitly)
        // NOTE: Consider if this blanket deletion is always desired. Maybe only delete if $mappings is not empty?
        // Or maybe the cache loading should *not* delete if it fails to load anything? This needs careful thought
        // based on exact requirements. Assuming the V2 cache load *replaces* DB content, we replicate that here.
//        CliLogger::info('Clearing existing mappings from database before insertion...');
//        $this->topdataToProductService->deleteAll();
//        CliLogger::info('Existing mappings cleared.');


        CliLogger::info('Inserting ' . UtilFormatter::formatInteger($totalToInsert) . ' total mappings into database...');
        $insertedCount = 0;
        foreach (array_chunk($mappings, self::BATCH_SIZE) as $batch) {
            $this->topdataToProductService->insertMany($batch);
            $insertedCount += count($batch);
            CliLogger::progress($insertedCount, $totalToInsert, 'Inserted mappings batch');
        }
        CliLogger::writeln('DONE. Finished inserting mappings.');
        ImportReport::setCounter('Mappings Inserted/Updated', $totalToInsert);
    }

    /**
     * Flattens the mappings grouped by type into a single list suitable for database insertion.
     * Removes the 'value' field which is only needed for caching.
     *
     * @param array<string, array> $mappingsByType
     * @return array
     */
    private function flattenMappings(array $mappingsByType): array
    {
        $allMappingsFlat = [];
        foreach ($mappingsByType as $typeMappings) {
            if (!empty($typeMappings)) {
                // Extract only the fields needed for database insertion (exclude 'value')
                $dbMappings = array_map(function($mapping) {
                    return [
                        'topDataId'        => $mapping['topDataId'],
                        'productId'        => $mapping['productId'],
                        'productVersionId' => $mapping['productVersionId']
                    ];
                }, $typeMappings);
                
                // array_merge is potentially slow for very large arrays repeatedly
                // consider alternative if performance is critical
                $allMappingsFlat = array_merge($allMappingsFlat, $dbMappings);
            }
        }
        // Optional: Add array_unique here if duplicates across types are possible AND undesirable
        // $allMappingsFlat = array_map("unserialize", array_unique(array_map("serialize", $allMappingsFlat)));
        // CliLogger::info('Flattened ' . count($allMappingsFlat) . ' unique mappings for persistence.');
        return $allMappingsFlat;
    }


    /**
     * ==== MAIN ====
     *
     * Maps products using the EAN/OEM/PCD strategy.
     *
     * Handles fetching identifiers from Shopware, checking/using cache (if V2 enabled),
     * fetching matches from the Topdata webservice, and persisting the results.
     *
     * @throws Exception if any critical error occurs during the mapping process
     */
    #[Override]
    public function map(ImportConfig $importConfig): void
    {
        CliLogger::section('Product Mapping Strategy: EAN/OEM/PCD');

        // 1. Check config
        $useExperimentalCacheV2 = TRUE; // (bool)$importConfig->getOptionExperimentalV2();
        CliLogger::info('Webservice Cache Enabled (Experimental): ' . ($useExperimentalCacheV2 ? 'Yes' : 'No'));

        // 2. Attempt to load from V2 cache (if enabled)
        //    This method now handles loading *and* populating the DB if successful.
        if ($useExperimentalCacheV2 && $this->tryLoadFromCacheV2()) {
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
            $this->saveToCacheV2($mappingsByType);
        }

        // 6. Flatten mappings for database persistence
        $flatMappings = $this->flattenMappings($mappingsByType);

        // 7. Persist flattened mappings to the database
        $this->persistMappingsToDatabase($flatMappings);

        CliLogger::section('Finished product mapping (fetched from webservice).');
    }
}
