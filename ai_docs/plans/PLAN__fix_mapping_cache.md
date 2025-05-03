# Plan: Refactor Mapping Cache to Store Webservice Values

## Objective
Modify the mapping cache implementation to store the actual mapping values returned from the webservice instead of Shopware product IDs, enabling more flexible and accurate product matching when loading from cache.

## Current Implementation Analysis
- The current mapping cache stores Shopware product IDs directly
- This approach tightly couples the cache to specific Shopware products
- If product identifiers change in Shopware, the cache becomes invalid even though the webservice mappings are still valid
- The cache cannot be reused across different Shopware instances or after product data changes

## Proposed Changes

### 1. Update Database Schema
- Modify `Migration1746267946CreateMappingCacheTable.php` to:
  - Replace `product_id` and `product_version_id` columns with a `mapping_value` column
  - This column will store the actual identifier value (EAN, OEM, PCD) from the webservice
  - Ensure appropriate indexes for performance

### 2. Modify MappingCacheService
- Update `saveMappingsToCache()` method to:
  - Store `topDataId` and `mapping_value` pairs instead of product associations
  - Maintain mapping type information for proper categorization
- Refactor `loadMappingsFromCache()` method to:
  - Load cached mapping values
  - Dynamically find matching Shopware products at runtime
  - Implement product lookup logic based on mapping type
- Add helper methods:
  - `findMatchingProducts()` to match cached values to Shopware products
  - `getEanMap()`, `getOemMap()`, `getPcdMap()` to retrieve current product identifiers

### 3. Update MappingStrategy_EanOem
- Modify `_processIdentifiers()` method to:
  - Store original webservice values alongside normalized versions
  - Include these values when creating mapping records
- Update `saveToCacheV2()` method to:
  - Extract only `topDataId` and `value` for caching
  - Exclude Shopware-specific product IDs from cache storage
- Ensure `flattenMappings()` and `persistMappingsToDatabase()` methods handle the new structure

### 4. Testing Strategy
- Unit tests:
  - Verify cache storage correctly preserves webservice values
  - Confirm cache loading properly matches to current Shopware products
- Integration tests:
  - Test full import cycle with cache enabled
  - Verify cache hit/miss scenarios
  - Test with modified product data to ensure dynamic matching works

## Benefits
- **Decoupling**: Cache becomes independent of Shopware product IDs
- **Flexibility**: Cache remains valid even if Shopware product identifiers change
- **Reusability**: Cache could potentially be shared across environments
- **Accuracy**: Ensures mappings reflect the actual webservice data
- **Performance**: Still provides performance benefits by avoiding webservice calls

## Implementation Phases

### Phase 1: Schema Changes
1. Create migration to modify the `topdata_mapping_cache` table
2. Update database schema in development environment
3. Verify schema changes

### Phase 2: Service Implementation
1. Update `MappingCacheService` methods
2. Implement product lookup logic
3. Modify `MappingStrategy_EanOem` to work with the new structure

### Phase 3: Testing and Validation
1. Develop unit tests for the new functionality
2. Test cache population and retrieval
3. Verify performance metrics
4. Ensure backward compatibility with existing import flows

## Potential Risks and Mitigations
- **Risk**: Performance impact of dynamic product lookup
  - **Mitigation**: Optimize queries and use efficient indexing
- **Risk**: Data inconsistency during transition
  - **Mitigation**: Implement cache purge option for clean transition
- **Risk**: Compatibility with other components
  - **Mitigation**: Ensure interface consistency and thorough testing


