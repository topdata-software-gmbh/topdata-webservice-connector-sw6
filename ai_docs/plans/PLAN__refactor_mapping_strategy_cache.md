# Plan: Refactor MappingStrategy_EanOem for Clear Cache Separation

## Objective
Refactor the `MappingStrategy_EanOem` class to establish a clear separation between cache and non-cache paths, ensuring that all mappings are properly saved to the cache when enabled.

## Analysis
- The current implementation in `MappingStrategy_EanOem` has an issue where only the final batch of mappings is being saved to the cache
- The code mixes cache and non-cache logic, making it difficult to maintain
- The processing methods (`_processEANs`, `_processOEMs`, `_processPCDs`) are overloaded with both direct database insertion and cache collection responsibilities

## Implementation Plan

### Phase 1: Restructure the Map Method
1. **Refactor the `map()` method**
   - Keep the existing cache check logic
   - Extract the webservice mapping logic into a new private method `mapFromWebservice(bool $saveToCache)`
   - Call this method when cache is not available or needs to be refreshed

2. **Create Dedicated Processing Methods**
   - Create `processEANsFromWebservice(array $eanMap, bool $saveToCache)`
   - Create `processOEMsFromWebservice(array $oemMap, bool $saveToCache)`
   - Create `processPCDsFromWebservice(array $oemMap, bool $saveToCache)`
   - These methods will handle fetching from webservice, direct database insertion, and optional cache collection

### Phase 2: Implement Batch Processing for Cache
1. **Implement Batch Collection for Cache**
   - In each processing method, maintain a separate array for cache entries
   - Add mapping type information to cache entries
   - Collect all mappings for cache throughout the entire process
   - Save the collected mappings to cache at the end of each processing method

2. **Improve Logging and Reporting**
   - Add logging to show how many mappings are being saved to cache
   - Maintain existing progress indicators and counters

### Phase 3: Testing and Validation
1. **Test Cache Population**
   - Verify that all mappings are properly saved to the cache
   - Compare the number of mappings in the cache with the number of mappings inserted directly

2. **Test Cache Usage**
   - Verify that subsequent imports correctly use the cached mappings
   - Ensure performance improvement with cached mappings

3. **Test Cache Purging**
   - Verify that the `--purge-cache` option correctly clears the cache

## Expected Benefits
- Clear separation between cache and non-cache code paths
- Proper collection and storage of all mappings in the cache
- Improved code maintainability
- Better logging and reporting of cache operations
- Consistent behavior between cached and non-cached imports

## Potential Risks and Mitigations
- **Risk**: Increased memory usage when collecting all mappings for cache
  - **Mitigation**: Consider implementing incremental cache updates if memory becomes an issue

- **Risk**: Performance impact of additional processing
  - **Mitigation**: The performance benefit of using cache should outweigh the cost of populating it

- **Risk**: Compatibility issues with existing code
  - **Mitigation**: Maintain the same public interface and behavior
  - **Mitigation**: Comprehensive testing before deployment