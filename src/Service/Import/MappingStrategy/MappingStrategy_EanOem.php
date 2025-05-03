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
use Topdata\TopdataConnectorSW6\Service\Import\ShopwareProductService;
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
        private readonly TopdataToProductService         $topdataToProductHelperService,
        private readonly TopdataWebserviceClient         $topdataWebserviceClient,
        private readonly ShopwareProductService          $shopwareProductService,
        private readonly MappingCacheService             $mappingCacheService,
    ) {
    }

    /**
     * Builds mapping arrays for OEM and EAN numbers based on Shopware data.
     *
     * Creates two mapping arrays based on the configured mapping type (custom, custom field, or default).
     * Normalizes manufacturer numbers and EAN codes.
     *
     * @return array{0: array<string, array<string, array{id: string, version_id: string}>>, 1: array<string, array<string, array{id: string, version_id: string}>>}
     *               Returns an array containing two maps:
     *               [0] => OEM map: [normalized_oem => [product_id-version_id => ['id' => ..., 'version_id' => ...]]]
     *               [1] => EAN map: [normalized_ean => [product_id-version_id => ['id' => ..., 'version_id' => ...]]]
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
                        $this->shopwareProductService->getKeysByOptionValue($oemAttribute, 'manufacturer_number')
                    );
                }
                if ($eanAttribute) {
                    $eans = UtilMappingHelper::_fixArrayBinaryIds(
                        $this->shopwareProductService->getKeysByOptionValue($eanAttribute, 'ean')
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
     *
     * @param string $type (e.g., MappingTypeConstants::EAN, MappingTypeConstants::OEM, MappingTypeConstants::PCD)
     * @param string $webserviceMethod The method name on TopdataWebserviceClient (e.g., 'matchMyEANs')
     * @param array  $identifierMap The map built from Shopware data (e.g., $eanMap or $oemMap)
     * @param string $logLabel A label for logging (e.g., 'EANs', 'OEMs', 'PCDs')
     * @return array Raw mappings data [{'topDataId': ..., 'productId': ..., 'productVersionId': ...}]
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
                        // Normalize identifier from webservice similarly to how Shopware data was normalized
                        $normalizedIdentifier = (string)$identifier;
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
     * Handles the overall persistence logic: caching (if enabled) and database insertion.
     *
     * Determines whether to load from cache, save to cache, and/or insert into the database.
     *
     * @param array<string, array> $mappingsByType Mappings fetched from webservice, grouped by type. Empty if attempting to load from cache.
     * @param bool $useCacheV2 Whether the experimental V2 caching is enabled.
     * @return bool True if mappings were successfully loaded from cache (indicating subsequent fetch/insert can be skipped), False otherwise.
     */
    private function handlePersistence(array $mappingsByType, bool $useCacheV2): bool
    {
        // --- Phase 1: Attempt to Load from Cache (if V2 enabled and no mappings provided yet) ---
        if ($useCacheV2 && empty($mappingsByType)) {
            if ($this->mappingCacheService->hasCachedMappings()) {
                CliLogger::info('Valid cache found (experimental V2), attempting to load mappings...');
                $totalLoaded = $this->mappingCacheService->loadMappingsFromCache();
                if ($totalLoaded > 0) {
                    CliLogger::info('Successfully loaded ' . UtilFormatter::formatInteger($totalLoaded) . ' total mappings from cache.');
                    ImportReport::setCounter('Mappings Loaded from Cache', $totalLoaded);
                    ImportReport::setCounter('Webservice Calls Skipped', 1); // Or count EAN/OEM/PCD separately if needed
                    return true; // Signal that loading from cache succeeded
                } else {
                    CliLogger::warning('Cache exists but failed to load mappings. Proceeding with fetch.');
                }
            } else {
                CliLogger::info('No valid cache found (experimental V2), proceeding with fetch.');
            }
            // If cache didn't exist or loading failed, fall through to process/save phase
        }

        // --- Phase 2: Process & Save Mappings (if mappings were provided) ---
        if (!empty($mappingsByType)) {
            // Flatten mappings for DB insertion
            $allMappingsFlat = [];
            foreach ($mappingsByType as $mappingType => $typeMappings) {
                if (!empty($typeMappings)) {
                    $allMappingsFlat = array_merge($allMappingsFlat, $typeMappings);

                    // Save individual types to cache if V2 is enabled
                    if ($useCacheV2) {
                        CliLogger::info("Saving " . count($typeMappings) . " $mappingType mappings to cache...");
                        $this->mappingCacheService->saveMappingsToCache($typeMappings, $mappingType);
                    }
                }
            }

            // Insert flattened list into the database
            if (!empty($allMappingsFlat)) {
                $this->insertMappingsToDatabase($allMappingsFlat);
            } else {
                CliLogger::info('No new mappings found to insert into the database.');
            }
        } else if (!$useCacheV2) {
            // Case: Not using V2 cache, and called potentially after a failed cache load attempt (or first run)
            // We don't have mappings yet, so we just indicate that cache wasn't used.
            CliLogger::info('Not using V2 cache or no mappings provided for persistence yet.');
        }


        return false; // Signal that loading from cache did not happen or was not attempted
    }

    /**
     * Inserts mappings into the database in batches.
     *
     * @param array $mappings A flat list of mapping data arrays.
     */
    private function insertMappingsToDatabase(array $mappings): void
    {
        $totalToInsert = count($mappings);
        if ($totalToInsert === 0) {
            CliLogger::info('No mappings to insert into database.');
            return;
        }

        CliLogger::info('Inserting ' . UtilFormatter::formatInteger($totalToInsert) . ' total mappings into database...');
        $insertedCount = 0;
        foreach (array_chunk($mappings, self::BATCH_SIZE) as $batch) {
            $this->topdataToProductHelperService->insertMany($batch);
            $insertedCount += count($batch);
            CliLogger::progress($insertedCount, $totalToInsert, 'Inserted mappings batch');
        }
        CliLogger::writeln('DONE. Finished inserting mappings.');
        ImportReport::setCounter('Mappings Inserted/Updated', $totalToInsert); // Or adjust based on insertMany's actual return if it differentiates
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
        $useExperimentalCacheV2 = (bool) $importConfig->getOptionExperimentalV2();
        CliLogger::info('Experimental V2 Cache Enabled: ' . ($useExperimentalCacheV2 ? 'Yes' : 'No'));

        // 2. Attempt to load from cache FIRST (if V2 enabled)
        //    handlePersistence returns true if cache was successfully loaded and used.
        if ($this->handlePersistence([], $useExperimentalCacheV2)) {
            CliLogger::section('Finished mapping using cached data.');
            return; // Cache was used, no need to fetch from webservice
        }

        // 3. Build identifier maps from Shopware (if cache wasn't used)
        [$oemMap, $eanMap] = $this->buildShopwareIdentifierMaps();

        // 4. Fetch corresponding mappings from Topdata Webservice
        $mappingsByType = $this->processWebserviceMappings($oemMap, $eanMap);
        unset($oemMap, $eanMap); // Free memory

        // 5. Handle Persistence: Save to Cache (if V2) and Insert to Database
        //    This call will now handle saving and inserting the $mappingsByType we just fetched.
        $this->handlePersistence($mappingsByType, $useExperimentalCacheV2);

        CliLogger::section('Finished product mapping.');
    }
}