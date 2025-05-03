# Plan: Enhance ImportReport Counters for ProductDeviceRelationshipServiceV2

**Objective:** Enhance the import process reporting in two phases:
1.  Integrate the `ImportReport` utility class into `ProductDeviceRelationshipServiceV2` and add detailed counters to track the device-product linking process (V2).
2.  Improve the CLI output of the import counters to include descriptions for better readability.

**Proposed Counters:**

*   `linking_v2.products.found`: Total unique Shopware product IDs identified for processing.
*   `linking_v2.products.chunks`: Number of chunks the product IDs were split into.
*   `linking_v2.chunks.processed`: Number of chunks successfully processed.
*   `linking_v2.webservice.calls`: Number of webservice calls made to fetch device links.
*   `linking_v2.webservice.device_ids_fetched`: Total unique device webservice IDs fetched from the webservice.
*   `linking_v2.database.devices_found`: Total corresponding devices found in the local database.
*   `linking_v2.links.deleted`: Total number of existing device-product links deleted across all chunks.
*   `linking_v2.links.inserted`: Total number of new device-product links inserted across all chunks.
*   `linking_v2.status.devices.enabled`: Total number of devices marked as enabled.
*   `linking_v2.status.devices.disabled`: Total number of devices marked as disabled.
*   `linking_v2.status.brands.enabled`: Total number of brands marked as enabled.
*   `linking_v2.status.brands.disabled`: Total number of brands marked as disabled.
*   `linking_v2.status.series.enabled`: Total number of series marked as enabled.
*   `linking_v2.status.series.disabled`: Total number of series marked as disabled.
*   `linking_v2.status.types.enabled`: Total number of device types marked as enabled.
*   `linking_v2.status.types.disabled`: Total number of device types marked as disabled.
*   `linking_v2.active.devices`: Final count of active devices at the end of the process.
*   `linking_v2.active.brands`: Final count of active brands at the end of the process.
*   `linking_v2.active.series`: Final count of active series at the end of the process.
*   `linking_v2.active.types`: Final count of active device types at the end of the process.

**Phase 1: Add Counters to Linking Service**

*   **Goal:** Implement the proposed counters within the `ProductDeviceRelationshipServiceV2` logic.
*   **Steps:**
    1.  **Integrate `ImportReport`:** Modify `src/Service/Linking/ProductDeviceRelationshipServiceV2.php` to use the `ImportReport` class (ensure `use Topdata\TopdataConnectorSW6\Util\ImportReport;` exists).
    2.  **Add Counters:** Insert calls to `ImportReport::incCounter()` or `ImportReport::setCounter()` at the appropriate points in the `syncDeviceProductRelationshipsV2` method to update the counters listed in the "Proposed Counters" section. Refer to the "Conceptual Flow Diagram" for guidance on placement.
    3.  **Remove Redundant Logging:** In `src/Service/Linking/ProductDeviceRelationshipServiceV2.php`, remove the `CliLogger::getCliStyle()->dumpDict()` call (previously lines 375-380) as this specific debug information will now be captured more systematically by `ImportReport`.
    4.  **Testing (Phase 1):** Manually run an import that triggers V2 linking and verify (e.g., via debugging or temporary dumps) that the `ImportReport::$counters` array is populated correctly according to the logic.

**Phase 2: Enhance CLI Counter Output**

*   **Goal:** Modify the `ImportService` to display the counters collected by `ImportReport` in a table format that includes descriptions.
*   **Steps:**
    1.  **Add `use` Statement:** In `src/Service/ImportService.php`, add `use Symfony\Component\Console\Helper\Table;`.
    2.  **Define Descriptions:** Create a static associative array within `ImportService` mapping counter keys (e.g., `'linking_v2.products.found'`) to their descriptions (e.g., `'Total unique Shopware product IDs identified for processing.'`). Use the descriptions from the "Proposed Counters" section.
    3.  **Replace `dumpCounters` Call:** In the `runImport` method of `src/Service/ImportService.php` (around line 85), replace the line `CliLogger::getCliStyle()->dumpCounters(ImportReport::getCountersSorted(), 'Counters Report');` with the following logic:
        *   Get the `SymfonyStyle` object: `$io = CliLogger::getCliStyle();`
        *   Get the sorted counters: `$counters = ImportReport::getCountersSorted();`
        *   Get the descriptions map: `$descriptions = self::$counterDescriptions; // Or however the map is stored`
        *   Create a `Table` instance: `$table = new Table($io);`
        *   Set headers: `$table->setHeaders(['Counter', 'Value', 'Description']);`
        *   Prepare rows: Iterate through `$counters`. For each `$key => $value`, look up `$descriptions[$key]` (handle cases where the description might be missing). Add the row `[$key, $value, $description]` using `$table->addRow([...]);`.
        *   Render the table: `$io->title('Counters Report'); $table->render();`
    4.  **Testing (Phase 2):** Run the import command and verify that the CLI output now shows the counters table with the 'Counter', 'Value', and 'Description' columns, correctly populated.

**Conceptual Flow Diagram:**

```mermaid
graph TD
    A[Start syncDeviceProductRelationshipsV2] --> B{Fetch Product Mappings};
    B --> C{Found Mappings?};
    C -- No --> X[End];
    C -- Yes --> D[Extract Unique Product IDs];
    D --> E[Chunk Product IDs];
    E --> F{Loop Through Chunks};
    F -- Next Chunk --> G[Fetch WS Links for Chunk];
    G --> H{Process WS Response};
    H --> I[Fetch Local Devices];
    I --> J[Update Active Sets (Brands, Series, Types)];
    J --> K[Delete Existing Links for Chunk];
    K --> L[Prepare New Links];
    L --> M[Insert New Links for Chunk];
    M --> F;
    F -- All Chunks Done --> N[Enable/Disable Entities Based on Active Sets];
    N --> O[Update ImportReport Counters];
    O --> X;

    subgraph Counters Updated
        D -- set --> linking_v2.products.found;
        E -- set --> linking_v2.products.chunks;
        F -- inc --> linking_v2.chunks.processed;
        G -- inc --> linking_v2.webservice.calls;
        H -- inc --> linking_v2.webservice.device_ids_fetched;
        I -- inc --> linking_v2.database.devices_found;
        K -- inc --> linking_v2.links.deleted;
        M -- inc --> linking_v2.links.inserted;
        N -- set --> linking_v2.status.devices.enabled;
        N -- set --> linking_v2.status.devices.disabled;
        N -- set --> linking_v2.status.brands.enabled;
        N -- set --> linking_v2.status.brands.disabled;
        N -- set --> linking_v2.status.series.enabled;
        N -- set --> linking_v2.status.series.disabled;
        N -- set --> linking_v2.status.types.enabled;
        N -- set --> linking_v2.status.types.disabled;
        N -- set --> linking_v2.active.devices;
        N -- set --> linking_v2.active.brands;
        N -- set --> linking_v2.active.series;
        N -- set --> linking_v2.active.types;
    end

    style X fill:#f9f,stroke:#333,stroke-width:2px