# Implementation Plan: Add Progress Bar to Device Import

## Project Context
The user wants to enhance the command-line interface (CLI) for the `topdata:connector:import --device` command. Currently, the device media import (`--device-media`) displays a progress bar, providing a good user experience. The goal is to implement a similar progress bar for the device import process to make it more informative and consistent.

The target logic is within the `setDevices()` method of the `DeviceImportService` class. Since the total number of devices is not known in advance, the progress bar will display the progress for each chunk of devices being processed.

## Final Deliverable
The AI agent will provide the complete, modified code for the file `src/Service/Import/DeviceImportService.php`.

---

## Phase 1: Analysis and Preparation

### Step 1.1: Locate Target File and Method
- **File:** `src/Service/Import/DeviceImportService.php`
- **Method:** `public function setDevices(): void`

### Step 1.2: Identify Reference Implementation
- **File:** `src/Service/Import/DeviceMediaImportService.php`
- **Method:** `public function setDeviceMedia(): void`
- **Key Code Snippet for Reference:** `CliLogger::progressBar($numDevicesProcessed, $numDevicesTotal, 'device_media');`
- **Note:** The `setDevices` method fetches devices in chunks, and the total number of devices is unknown at the start. Therefore, the progress bar will be implemented *per chunk*, not for the overall process. The total for the progress bar will be the number of records in the current chunk.

### Step 1.3: Identify the Progress Bar Utility
- The progress bar is provided by the `TopdataFoundationSW6` plugin.
- **Class:** `Topdata\TopdataFoundationSW6\Util\CliLogger`
- **Method:** `public static function progressBar(int $current, int $total, string $label = ''): void`

---

## Phase 2: Code Implementation in `DeviceImportService::setDevices()`

### Step 2.1: Initialize a Counter for the Current Chunk
Inside the `while ($repeat)` loop, but before the `foreach ($response->data as $s)` loop, initialize a counter for the records processed within the current chunk.

- **File:** `src/Service/Import/DeviceImportService.php`
- **Location:** Inside `setDevices()`, after the line `$recordsInChunk = count($response->data);`.
- **Action:** Add the following line to initialize the counter:
  ```php
  $processedInChunk = 0;
  ```

### Step 2.2: Remove Redundant Progress Indicators
The current implementation uses `CliLogger::activity()` to print dots, pluses, and asterisks. These will be replaced by the progress bar for a cleaner output.

- **File:** `src/Service/Import/DeviceImportService.php`
- **Location:** Inside `setDevices()`.
- **Action:** Locate and remove the following lines:
    - `CliLogger::activity("Processing Device Chunk $chunkNumber ($recordsInChunk records)");` (This will be replaced by the progress bar's label).
    - `CliLogger::activity('+');` (two occurrences)
    - `CliLogger::activity('*');` (one occurrence)

### Step 2.3: Add the Progress Bar Logic
Inside the `foreach ($response->data as $s)` loop, increment the counter and call the `CliLogger::progressBar()` method. This should be one of the last actions inside the loop to accurately reflect that a device record has been processed.

- **File:** `src/Service/Import/DeviceImportService.php`
- **Location:** At the end of the `foreach ($response->data as $s)` loop.
- **Action:** Add the following lines of code:
  ```php
  $processedInChunk++;
  CliLogger::progressBar($processedInChunk, $recordsInChunk, "Processing chunk $chunkNumber");
  ```

---

## Phase 3: Final Review and Verification

### Step 3.1: Review the Modified `setDevices()` Method
- Ensure the `$processedInChunk` counter is correctly initialized before the `foreach` loop.
- Confirm that the `CliLogger::progressBar()` call is present at the end of the `foreach` loop.
- Verify that the old `CliLogger::activity()` calls have been removed.
- Check that the parameters passed to `progressBar()` are correct: `($processedInChunk, $recordsInChunk, "Processing chunk $chunkNumber")`.

### Step 3.2: Verify Expected Output
Imagine running the command `bin/console topdata:connector:import --device`. The expected output in the console for the "Devices" section should now look like this for each chunk processed:

```
Devices
================

Devices begin (Chunk size is 5000 devices)
[memory usage]

Getting device chunk 1 from remote server...[time]sec

Processing chunk 1: [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 5000/5000 (100%)
```
The progress bar should fill up from 0% to 100% for each chunk of devices fetched from the webservice.

This plan provides a clear, step-by-step path to achieving the desired functionality. The AI agent should now proceed with the implementation.



