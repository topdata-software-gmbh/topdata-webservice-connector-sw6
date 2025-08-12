# Implementation Plan: Standardize Import Summary Tables

**Objective:** Refactor the summary logging for the "Brands", "Devices", and "Device Media" import steps to use the same professional table format already present for "Series" and "Device Types". This involves adding `ImportReport` counters where missing and implementing a consistent summary rendering logic.

## Phase 1: Implement Brands Import Summary

**Goal:** Add `ImportReport` counters to the `setBrands` method in `MappingHelperService` and render a summary table.

**File to Modify:** `src/Service/Import/MappingHelperService.php`

### Step 1.1: Add `ImportReport` Counters to `setBrands`

Locate the `setBrands()` method. Inside the `foreach ($brands->data as $b)` loop, add `ImportReport::incCounter()` calls to track created, updated, and unchanged brands.

```php
// In: src/Service/Import/MappingHelperService.php -> setBrands()

        // ... inside foreach ($brands->data as $b) loop ...
        foreach ($brands->data as $b) {
            // ... inside if ($b->main == 0) continue; ...
            
            // ... after $duplicates[$code] = true; ...

            // Search for existing brand in the local database
            $brand = $this->topdataBrandRepository->search(
                (new Criteria())->addFilter(new EqualsFilter('code', $code))->setLimit(1),
                $this->context
            )
                ->getEntities()
                ->first();
            
            // If the brand does not exist, prepare data for creation
            if (!$brand) {
                $dataCreate[] = [
                    'code'    => $code,
                    'name'    => $b->val,
                    'enabled' => false,
                    'sort'    => (int)$b->top,
                    'wsId'    => (int)$b->id,
                ];
                // START MODIFICATION: Add counter
                ImportReport::incCounter('Brands Created');
                // END MODIFICATION
                
            // If the brand exists but has different data, prepare data for update
            } elseif (
                $brand->getName() != $b->val ||
                $brand->getSort() != $b->top ||
                $brand->getWsId() != $b->id
            ) {
                $dataUpdate[] = [
                    'id'   => $brand->getId(),
                    'name' => $b->val,
                    // 'sort' => (int)$b->top,
                    'wsId' => (int)$b->id,
                ];
                // START MODIFICATION: Add counter
                ImportReport::incCounter('Brands Updated');
                // END MODIFICATION
            } 
            // START MODIFICATION: Add else block for counter
            else {
                ImportReport::incCounter('Brands Unchanged');
            }
            // END MODIFICATION

            // ... rest of the loop ...
        }
```

### Step 1.2: Add Summary Data Helper Method

Add a new private helper method `_prepareSummaryData` to the `MappingHelperService` class. This is a direct copy of the helper from `DeviceImportService` for consistency.

```php
// In: src/Service/Import/MappingHelperService.php (at the end of the class)

    /**
     * Prepares a summary dictionary for a specific import type.
     * This ensures that all counters are present and default to 0 if not set.
     *
     * @param string $prefix The prefix for the counter keys (e.g., "Brands").
     * @return array An associative array of summary statistics.
     */
    private function _prepareSummaryData(string $prefix): array
    {
        return [
            ['Total processed', ImportReport::getCounter($prefix . ' Total Processed') ?? 0],
            ['Created',         ImportReport::getCounter($prefix . ' Created') ?? 0],
            ['Updated',         ImportReport::getCounter($prefix . ' Updated') ?? 0],
            ['Unchanged',       ImportReport::getCounter($prefix . ' Unchanged') ?? 0],
            ['DB Create batches',  ImportReport::getCounter($prefix . ' Create Batches') ?? 0],
            ['DB Update batches',  ImportReport::getCounter($prefix . ' Update Batches') ?? 0],
        ];
    }
```

### Step 1.3: Render Summary Table in `setBrands`

At the end of the `setBrands()` method, replace the existing completion log with the new table rendering logic.

**Before:**
```php
// In: src/Service/Import/MappingHelperService.php -> setBrands() at the end

        // Log the completion of the brands process
        CliLogger::writeln("\nBrands done " . CliLogger::lap() . 'sec');
        $duplicates = null;
        $brands = null;

        UtilProfiling::stopTimer();
```

**After:**
```php
// In: src/Service/Import/MappingHelperService.php -> setBrands() at the end

        // START MODIFICATION
        ImportReport::setCounter('Brands Total Processed', count($brands->data));
        ImportReport::setCounter('Brands Create Batches', (int)ceil(count($dataCreate) / 100));
        ImportReport::setCounter('Brands Update Batches', (int)ceil(count($dataUpdate) / 100));

        // Log summary using table format
        $summaryData = $this->_prepareSummaryData('Brands');
        CliLogger::getCliStyle()->table(['Metric', 'Count'], $summaryData, 'Brands Import Summary');
        // END MODIFICATION
        
        // Log the completion of the brands process
        CliLogger::writeln("\nBrands done " . CliLogger::lap() . 'sec');
        $duplicates = null;
        $brands = null;

        UtilProfiling::stopTimer();
```

## Phase 2: Implement Devices Import Summary

**Goal:** Replace the current mix of `writeln` and `dumpDict` in `setDevices` with a single, comprehensive summary table.

**File to Modify:** `src/Service/Import/DeviceImportService.php`

### Step 2.1: Replace Summary Logic in `setDevices`

Locate the end of the `setDevices()` method. Replace the entire summary block with a new block that constructs the data array and renders it as a table.

**Before:**
```php
// In: src/Service/Import/DeviceImportService.php -> setDevices() at the end

        $response = null;
        $duplicates = null;
        CliLogger::writeln('');
        $totalSecs = microtime(true) - $functionTimeStart;

        // Enhanced reporting with all counters
        CliLogger::writeln('');
        CliLogger::writeln('=== Devices Import Summary ===');
        CliLogger::writeln('Chunks processed: ' . ImportReport::getCounter('Device Chunks'));
        // ... many more writeln calls ...
        CliLogger::writeln('Total time: ' . $totalSecs . ' seconds');

        CliLogger::getCliStyle()->dumpDict([
            'created'    => $created,
            'updated'    => $updated,
            'total time' => $totalSecs,
        ], 'Devices Report');

        // $this->connection->getConfiguration()->setSQLLogger($SQLlogger);

        UtilProfiling::stopTimer();
```

**After:**
```php
// In: src/Service/Import/DeviceImportService.php -> setDevices() at the end

        $response = null;
        $duplicates = null;
        CliLogger::writeln('');
        $totalSecs = microtime(true) - $functionTimeStart;

        // START MODIFICATION
        $summaryData = [
            ['Chunks processed', ImportReport::getCounter('Device Chunks') ?? 0],
            ['Total records fetched', ImportReport::getCounter('Devices Records Fetched') ?? 0],
            ['Total records processed', ImportReport::getCounter('Devices Total Processed') ?? 0],
            ['Brand not found', ImportReport::getCounter('Devices Brand Not Found') ?? 0],
            ['Duplicates skipped', ImportReport::getCounter('Devices Duplicates Skipped') ?? 0],
            ['Database lookups', ImportReport::getCounter('Devices Database Lookups') ?? 0],
            ['Series lookups', ImportReport::getCounter('Devices Series Lookups') ?? 0],
            ['Series found', ImportReport::getCounter('Devices Series Found') ?? 0],
            ['Type lookups', ImportReport::getCounter('Devices Type Lookups') ?? 0],
            ['Type found', ImportReport::getCounter('Devices Type Found') ?? 0],
            ['Created', ImportReport::getCounter('Devices Created') ?? 0],
            ['Updated', ImportReport::getCounter('Devices Updated') ?? 0],
            ['Unchanged', ImportReport::getCounter('Devices Unchanged') ?? 0],
            ['DB Create batches', ImportReport::getCounter('Devices Create Batches') ?? 0],
            ['DB Update batches', ImportReport::getCounter('Devices Update Batches') ?? 0],
        ];

        CliLogger::getCliStyle()->table(['Metric', 'Count'], $summaryData, 'Devices Import Summary');
        // END MODIFICATION

        CliLogger::getCliStyle()->dumpDict([
            'created'    => $created,
            'updated'    => $updated,
            'total time' => number_format($totalSecs, 3) . ' sec',
        ], 'Devices Report');

        // $this->connection->getConfiguration()->setSQLLogger($SQLlogger);

        UtilProfiling::stopTimer();
```

## Phase 3: Implement Device Media Import Summary

**Goal:** Replace the list-based summary in `setDeviceMedia` with a formatted table.

**File to Modify:** `src/Service/Import/DeviceMediaImportService.php`

### Step 3.1: Replace Summary Logic in `setDeviceMedia`

At the end of the `setDeviceMedia()` method, replace the block of `writeln` calls with a new block that builds the summary data array and renders it using `CliLogger::getCliStyle()->table()`.

**Before:**
```php
// In: src/Service/Import/DeviceMediaImportService.php -> setDeviceMedia() at the end

        // ---- Final summary with all counters
        CliLogger::writeln('');
        CliLogger::writeln('=== Device Media Import Summary ===');
        CliLogger::writeln('Chunks processed: ' . ImportReport::getCounter('Device Media Chunks'));
        // ... many more writeln calls ...
        CliLogger::writeln('Errors encountered: ' . ImportReport::getCounter('Device Media Errors'));
        CliLogger::writeln('Devices Media done');

        UtilProfiling::stopTimer();
```

**After:**
```php
// In: src/Service/Import/DeviceMediaImportService.php -> setDeviceMedia() at the end

        // START MODIFICATION
        $summaryData = [
            ['Chunks processed', ImportReport::getCounter('Device Media Chunks') ?? 0],
            ['Total records fetched', ImportReport::getCounter('Device Media Records Fetched') ?? 0],
            ['Total records processed', ImportReport::getCounter('Device Media Total Processed') ?? 0],
            ['Devices found', ImportReport::getCounter('Device Media Devices Found') ?? 0],
            ['Devices skipped (not available)', ImportReport::getCounter('Device Media Devices Skipped - Not Available') ?? 0],
            ['Devices skipped (no brand)', ImportReport::getCounter('Device Media Devices Skipped - No Brand') ?? 0],
            ['Devices skipped (device not found)', ImportReport::getCounter('Device Media Devices Skipped - Device Not Found') ?? 0],
            ['Images updated', ImportReport::getCounter('Device Media Images Updated') ?? 0],
            ['Images deleted', ImportReport::getCounter('Device Media Images Deleted') ?? 0],
            ['Images skipped (no image)', ImportReport::getCounter('Device Media Images Skipped - No Image') ?? 0],
            ['Images skipped (current newer)', ImportReport::getCounter('Device Media Images Skipped - Current Newer') ?? 0],
            ['Errors encountered', ImportReport::getCounter('Device Media Errors') ?? 0],
        ];

        CliLogger::getCliStyle()->table(['Metric', 'Count'], $summaryData, 'Device Media Import Summary');
        // END MODIFICATION

        CliLogger::writeln('Devices Media done');

        UtilProfiling::stopTimer();
```

---

## Final Checklist

-   [ ] **Phase 1: Brands Summary**
    -   [ ] `ImportReport` counters added to the `setBrands` loop.
    -   [ ] `_prepareSummaryData` helper method added to `MappingHelperService`.
    -   [ ] `setBrands` method now renders a summary table at the end.
-   [ ] **Phase 2: Devices Summary**
    -   [ ] The old `writeln` summary block in `setDevices` is replaced with the new table rendering logic.
-   [ ] **Phase 3: Device Media Summary**
    -   [ ] The old `writeln` summary block in `setDeviceMedia` is replaced with the new table rendering logic.


