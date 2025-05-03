# Plan: Cache EAN and OEM Mappings in Database

## Objective
Implement a database caching mechanism for EAN and OEM mappings to reduce API calls to the TopData webservice and improve import performance.

## Analysis
- The current implementation in `MappingStrategy_EanOem` fetches EAN and OEM data from the webservice on every import run
- These API calls are time-consuming and the data doesn't change frequently
- Caching this data would significantly improve performance for subsequent imports

## Implementation Plan

### Phase 1: Create Database Structure
1. **Create Migration File**
   - Create `src/Migration/Migration1715000000CreateMappingCacheTable.php`
   - Define table structure with columns:
     - `id` (BINARY(16), primary key)
     - `type` (VARCHAR(50), for distinguishing mapping types)
     - `top_data_id` (INT, the TopData product ID)
     - `product_id` (BINARY(16), Shopware product ID)
     - `product_version_id` (BINARY(16), Shopware product version ID)
     - `created_at` (DATETIME, for cache invalidation)
     - `updated_at` (DATETIME, nullable)
   - Add appropriate indexes for performance

2. **Leverage Existing CLI Option**
   - Use the existing `--experimental-v2` CLI option to enable the caching feature
   - This option is already defined in `Command_Import` and available in `ImportConfig`
   - No need to add a new configuration option to the plugin schema

3. **Add Cache Purge Option**
   - Add a new CLI option `--purge-cache` to the `Command_Import` class
   - Update `ImportConfig` to include this option
   - Implement a method in `MappingStrategy_EanOem` to purge the cache when this option is used

### Phase 2: Modify MappingStrategy_EanOem
1. **Add Cache Management Methods**
   - Add `Connection` dependency to constructor
   - Implement `hasCachedMappings()` to check if valid cache exists
   - Implement `saveMappingsToCache()` to store mappings
   - Implement `loadMappingsFromCache()` to retrieve mappings
   - Implement `purgeMappingsCache()` to clear the cache
   - Add cache expiry constant (e.g., 24 hours)

2. **Update Processing Methods**
   - Modify `_processEANs()`, `_processOEMs()`, and `_processPCDs()` to optionally return mappings instead of inserting them directly
   - Add parameter `bool $returnMappings = false` to these methods
   - When `$returnMappings` is true, collect mappings in an array instead of inserting them

3. **Update Main Map Method**
   - Modify `map()` to accept the `ImportConfig` object to check for the `--experimental-v2` flag
   - If the `--purge-cache` option is set, clear the cache before proceeding
   - If the `--experimental-v2` flag is enabled and valid cache exists, load and use cached mappings
   - Otherwise, proceed with normal mapping and save results to cache if the flag is enabled

4. **Update ProductMappingService**
   - Modify `mapProducts()` to pass the `ImportConfig` to the mapping strategy

### Phase 3: Testing and Validation
1. **Unit Tests**
   - Create tests for cache validation logic
   - Test cache expiration functionality
   - Verify correct mapping retrieval from cache
   - Test cache purging functionality

2. **Integration Tests**
   - Test full import process with `--experimental-v2` flag
   - Test cache purging with `--purge-cache` option
   - Verify performance improvement with cached mappings
   - Ensure data consistency between cached and non-cached imports

3. **Performance Benchmarking**
   - Measure and document performance improvement
   - Test with various dataset sizes

## Expected Benefits
- Reduced API calls to the TopData webservice
- Faster import process for subsequent runs
- Reduced server load and bandwidth usage
- Improved user experience due to faster imports
- Consistent with existing experimental features pattern
- Explicit control over cache through dedicated CLI option

## Potential Risks and Mitigations
- **Risk**: Cache becomes stale if product mappings change
  - **Mitigation**: Implement configurable cache expiration (default 24 hours)
  - **Mitigation**: Provide `--purge-cache` option to explicitly refresh the cache

- **Risk**: Increased database size
  - **Mitigation**: Implement cleanup for old cache entries
  - **Mitigation**: Monitor database size impact

- **Risk**: Compatibility issues with existing code
  - **Mitigation**: Only activate with explicit `--experimental-v2` flag
  - **Mitigation**: Comprehensive testing before deployment
