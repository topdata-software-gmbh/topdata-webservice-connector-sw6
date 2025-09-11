## Implementation Plan: Consume Device Availability Strategy (topdata-webservice-connector-sw6)

**Objective:** To read the new `deviceAvailabilityStrategy` setting from the `TopdataTopFinderProSW6` plugin and apply its logic during the product-device linking process, making the deactivation of unlinked devices conditional.

**Prerequisite:** This plan assumes that the `TopdataTopFinderProSW6` plugin has been updated to include a system configuration key named `deviceAvailabilityStrategy`.

### Phase 1: Read the New Configuration Setting

This phase extends the existing configuration service to read settings from the Finder plugin.

**File to Modify:** `src/Service/Config/MergedPluginConfigHelperService.php`

**Actions:**
1.  Create a new private method `_loadOptionsFromTopFinderProPluginConfig()` to fetch the configuration for `TopdataTopFinderProSW6`.
2.  Call this new method from the existing `init()` method to ensure the setting is loaded at the beginning of the import process.

**Implementation Details:**

```php
// In src/Service/Config/MergedPluginConfigHelperService.php

// ... (existing class properties and methods)

    /**
     * NEW: Load Topdata TopFinder Pro plugin configuration.
     */
    private function _loadOptionsFromTopFinderProPluginConfig(): void
    {
        $topfinderPluginConfig = $this->systemConfigService->get('TopdataTopFinderProSW6.config');
        if (!$topfinderPluginConfig) {
            CliLogger::warning('TopdataTopFinderProSW6.config not found in system config. Using default device availability strategy.');
            return;
        }

        $this->_setOptions($topfinderPluginConfig ?? []);
    }

    /**
     * 04/2025 created
     */
    public function init(): void
    {
        $this->_loadOptionsFromConnectorPluginConfig();
        $this->_loadOptionsFromTopFeedPluginConfig();
        $this->_loadOptionsFromTopFinderProPluginConfig(); // Add this line
        CliLogger::dump($this->options, "OPTIONS");
    }

// ... (rest of the class)
```

**Verification:**
After this phase, calling `$this->mergedPluginConfigHelperService->getOption('deviceAvailabilityStrategy')` from another service (like `ImportService`) should return the value set in the Finder plugin's admin UI (e.g., `disableUnlinked` or `keepAllEnabled`).

---

### Phase 2: Apply the Setting to the Import Logic

This phase modifies the product-device linking logic to respect the new setting.

**Architectural Note:** This change will only be applied to the modern `ProductDeviceRelationshipServiceV2`. The legacy `V1` service is destructive by design (disabling all entities first) and will not be modified to prevent introducing regressions. This ensures that users leveraging the more efficient V2 importer get the new feature.

**File to Modify:** `src/Service/Linking/ProductDeviceRelationshipServiceV2.php`

**Actions:**
1.  Inject `MergedPluginConfigHelperService` into the constructor.
2.  In the `syncDeviceProductRelationshipsV2()` method, locate the final section where `UPDATE ... SET is_enabled = 0` queries are executed for devices, brands, series, and types.
3.  Wrap this entire block of logic in a conditional statement that checks if the `deviceAvailabilityStrategy` setting is **not** equal to `keepAllEnabled`.

**Implementation Details:**

```php
// In src/Service/Linking/ProductDeviceRelationshipServiceV2.php

// Add use statement at the top of the file
use Topdata\TopdataConnectorSW6\Service\Config\MergedPluginConfigHelperService;

class ProductDeviceRelationshipServiceV2
{
    const CHUNK_SIZE = 100;

    public function __construct(
        private readonly Connection                      $connection,
        private readonly TopdataToProductService         $topdataToProductHelperService,
        private readonly TopdataDeviceService            $topdataDeviceService,
        private readonly TopdataWebserviceClient         $topdataWebserviceClient,
        private readonly MergedPluginConfigHelperService $mergedPluginConfigHelperService // Add this injection
    )
    {
    }

    // ... (keep the rest of the class methods unchanged until the end of syncDeviceProductRelationshipsV2)

    public function syncDeviceProductRelationshipsV2(): void
    {
        // ... (existing logic for fetching and linking products)

        // After processing all chunks, enable/disable entities based on the active sets
        CliLogger::getCliStyle()->yellow('Updating entity status (enable/disable)...');
        
        $strategy = $this->mergedPluginConfigHelperService->getOption('deviceAvailabilityStrategy');
        
        // --- START MODIFICATION ---
        // Only run the disable logic if the strategy is the default one.
        if ($strategy !== 'keepAllEnabled') {
            CliLogger::info('Strategy "disableUnlinked" is active. Disabling devices/brands/series/types without product links.');

            // Enable active devices (This logic runs in both cases if you want to ensure all linked devices are active)
            if (!empty($activeDeviceDbIds)) {
                // ... (existing logic to enable devices)
            } else {
                // ... (existing logic to disable all devices)
            }

            // Enable active brands
            if (!empty($activeBrandDbIds)) {
                // ... (existing logic to enable brands)
            } else {
                 // ... (existing logic to disable all brands)
            }
            
            // Enable active series
            if (!empty($activeSeriesDbIds)) {
                // ... (existing logic to enable series)
            } else {
                 // ... (existing logic to disable all series)
            }
            
            // Enable active device types
            if (!empty($activeTypeDbIds)) {
                // ... (existing logic to enable types)
            } else {
                // ... (existing logic to disable all types)
            }

        } else {
            CliLogger::info('Strategy "keepAllEnabled" is active. Skipping disable logic. All existing devices will remain enabled.');
            
            // Even with "keepAllEnabled", we should still ensure that all *active* devices are enabled.
            // This covers cases where a device was previously disabled.
            if (!empty($activeDeviceDbIds)) {
                $deviceChunks = array_chunk(array_values($activeDeviceDbIds), BatchSizeConstants::ENABLE_DEVICES);
                // ... (copy just the enable logic for devices, brands, series, and types here)
            }
        }
        // --- END MODIFICATION ---

        CliLogger::getCliStyle()->success('Devices to products linking completed (V2 differential approach)');
        
        UtilProfiling::stopTimer();
    }
}
```

**Note:** For the `keepAllEnabled` strategy, you might decide to *only* run the `UPDATE ... SET is_enabled = 1` queries for the active entities found during the import. This ensures that everything with a valid link becomes active, without touching the status of anything else. The provided plan reflects this safer approach.

**Verification:**
1.  Run the import command: `bin/console topdata:connector:import --product-device --experimental-v2`.
2.  **Scenario A (Default):** With the Finder setting on "Disable devices without products", verify that `UPDATE ... SET is_enabled = 0` queries are executed and that devices no longer linked to products are disabled in the `topdata_device` table.
3.  **Scenario B (New):** With the Finder setting on "Keep all devices enabled", verify that no `UPDATE ... SET is_enabled = 0` queries are run for devices/brands/etc. All devices in the database should remain enabled after the import.

---

### Phase 3: Documentation Review

This phase confirms that no changes are needed for the Connector's user-facing documentation.

**Files to Review:** `manual/` directory.

**Actions:**
1.  Review the existing documentation for the `topdata-webservice-connector-sw6` plugin.
2.  Confirm that no changes are needed. The user-facing setting resides in the Finder plugin, making its manual the correct place for the explanation.

**Verification:**
No changes are made to files in the `manual/` directory of the `topdata-webservice-connector-sw6` plugin.


