# Checklist: Refactoring Device-Product Linking (V2)

Based on: `ai_docs/plan/PLAN__refactor_device_linking_v2.md`

## Phase 1: Create New Service Structure

- [ ] 1. **Create New Service File:** `src/Service/Linking/ProductDeviceRelationshipServiceV2.php`
- [ ] 2. **Define Class & Dependencies:**
    - [ ] Define class `ProductDeviceRelationshipServiceV2`.
    - [ ] Copy `__construct` from `ProductDeviceRelationshipService`.
- [ ] 3. **Define Public Method Stub:** Create `public function syncDeviceProductRelationshipsV2(): void`.
- [ ] 4. **Register Service:** Add `ProductDeviceRelationshipServiceV2` to `src/Resources/config/services.xml`.

## Phase 2: Implement Differential Update Logic in New Service

- [ ] 1. **Implement `syncDeviceProductRelationshipsV2()`:**
    - [ ] Fetch mapped products (`getTopdataProductMappings`).
    - [ ] Chunk product IDs.
    - [ ] Initialize active entity ID sets (`$activeDeviceDbIds`, `$activeBrandDbIds`, `$activeSeriesDbIds`, `$activeTypeDbIds`).
    - [ ] Loop through product chunks:
        - [ ] Fetch product-device links from webservice.
        - [ ] Process response for linked device `ws_id`s.
        - [ ] Fetch local device details (`getDeviceArrayByWsIdArray`).
        - [ ] Add fetched DB IDs to active sets.
        - [ ] Identify Shopware Product DB IDs for the chunk.
        - [ ] **Delete** existing links for these specific Product DB IDs.
        - [ ] **Insert** new links for the chunk.
    - [ ] After loop:
        - [ ] **Enable Active Entities** (Brand, Series, Type, Device) using `UPDATE ... WHERE id IN (...)`.
        - [ ] **Disable Inactive Entities** (Brand, Series, Type, Device) using `UPDATE ... WHERE id NOT IN (...)`.
    - [ ] Integrate `CliLogger` and `UtilProfiling`.

## Phase 3: Integrate New Service

- [ ] 1. **Inject New Service:** Add `ProductDeviceRelationshipServiceV2` dependency to `ImportService` constructor (`src/Service/ImportService.php`).
- [ ] 2. **Conditional Logic in `ImportService`:**
    - [ ] Modify `_handleProductOperations` in `ImportService.php`.
    - [ ] Replace `syncDeviceProductRelationships()` call with conditional logic checking `--experimental-v2` flag.