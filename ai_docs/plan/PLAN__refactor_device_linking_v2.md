# Refactoring Plan: Differential Device-Product Linking (V2)

**Goal:** Implement a new, more robust method for synchronizing device-to-product relationships that avoids disabling all entities upfront, maintaining data consistency. This new logic will be activated by the existing `--experimental-v2` CLI flag.

**Current Problem:** The existing `ProductDeviceRelationshipService::syncDeviceProductRelationships` method disables all brands, devices, series, and types, and deletes all links at the beginning of the process. This causes temporary data inconsistency in related tables during the import.

**Proposed Solution:** Create a new service (`ProductDeviceRelationshipServiceV2`) implementing a differential update approach.

---

**Implementation Tracking:**

*   Before starting Phase 1, create a checklist file: `ai_docs/checklist/CHECKLIST__refactor_device_linking_v2.md`.
*   This checklist should mirror the steps outlined in this plan.
*   As each step is completed during implementation, update the checklist accordingly.

---

## Phase 1: Create New Service Structure

1.  **Create New Service File:**
    *   Path: `src/Service/Linking/ProductDeviceRelationshipServiceV2.php`
2.  **Define Class & Dependencies:**
    *   Define the class `ProductDeviceRelationshipServiceV2`.
    *   Copy the `__construct` method from the existing `ProductDeviceRelationshipService` to inject the same dependencies (Connection, Helper Services, Webservice Client).
3.  **Define Public Method Stub:**
    *   Create an empty public method signature within the new class: `public function syncDeviceProductRelationshipsV2(): void`.
4.  **Register Service:**
    *   Add the new `ProductDeviceRelationshipServiceV2` to the dependency injection container configuration.
    *   File: `src/Resources/config/services.xml`

## Phase 2: Implement Differential Update Logic in New Service

1.  **Implement `syncDeviceProductRelationshipsV2()`:**
    *   Populate the method created in Phase 1 with the differential update logic:
        *   Fetch mapped products (`getTopdataProductMappings`).
        *   Chunk product IDs.
        *   Initialize empty sets to store the database IDs of active entities (`$activeDeviceDbIds`, `$activeBrandDbIds`, `$activeSeriesDbIds`, `$activeTypeDbIds`).
        *   Loop through product chunks:
            *   Fetch product-device links from the webservice for the current chunk.
            *   Process the response to identify linked device Webservice IDs (`ws_id`).
            *   Fetch corresponding local device details (DB IDs, `brand_id`, `series_id`, `type_id`) using `getDeviceArrayByWsIdArray`.
            *   Add the fetched database IDs to the respective active sets (`$activeDeviceDbIds`, `$activeBrandDbIds`, etc.).
            *   Identify the Shopware Product database IDs corresponding to the current chunk's webservice product IDs.
            *   **Delete** existing links from `topdata_device_to_product` *only* for these specific Shopware Product DB IDs (`WHERE product_id IN (...)`).
            *   **Insert** the new links fetched from the webservice for the current chunk.
        *   After the loop completes:
            *   **Enable Active Entities:** For each entity type (brand, series, type, device), run an `UPDATE` query to set `is_enabled = 1` for all records whose database IDs are present in the corresponding active set (e.g., `UPDATE topdata_device SET is_enabled = 1 WHERE id IN ($activeDeviceDbIds)`). Check if the set is not empty before executing.
            *   **Disable Inactive Entities:** For each entity type, run an `UPDATE` query to set `is_enabled = 0` for all records whose database IDs are *not* present in the corresponding active set (e.g., `UPDATE topdata_device SET is_enabled = 0 WHERE id NOT IN ($activeDeviceDbIds)`). Check if the set is not empty before executing.
        *   Integrate appropriate `CliLogger` and `UtilProfiling` calls throughout the method.

## Phase 3: Integrate New Service

1.  **Inject New Service:**
    *   Add `ProductDeviceRelationshipServiceV2` as a dependency to the `ImportService` constructor.
    *   File: `src/Service/ImportService.php`
2.  **Conditional Logic in `ImportService`:**
    *   Modify the `_handleProductOperations` method in `ImportService.php`.
    *   Locate the call: `$this->productDeviceRelationshipService->syncDeviceProductRelationships();`
    *   Replace it with conditional logic to check the `--experimental-v2` flag (retrieved from the `ImportCommandImportConfig`):
        ```php
        if ($importConfig->getOptionExperimentalV2()) {
            CliLogger::getCliStyle()->caution('Using experimental V2 device linking logic!');
            $this->productDeviceRelationshipServiceV2->syncDeviceProductRelationshipsV2();
        } else {
            // Keep the original call as the default
            $this->productDeviceRelationshipService->syncDeviceProductRelationships();
        }
        ```

---

This plan ensures the new logic is isolated, the existing default behavior is preserved, and activation is controlled via the existing CLI flag.