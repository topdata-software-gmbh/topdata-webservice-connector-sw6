# Refactoring Brand Lookup Logic Checklist

- [x] **Step 1: Enhance `TopdataBrandService`**
    - [x] Add private property `$brandsByWsIdCache`.
    - [x] Create private helper method `_loadBrandsByWsId()`.
    - [x] Create public method `getBrandByWsId()`.
- [x] **Step 2: Refactor `DeviceImportService`**
    - [x] Add `TopdataBrandService` dependency injection.
    - [x] Remove `brandWsArray` property.
    - [x] Remove `_getBrandByWsIdArray` method.
    - [x] Update method calls in `setDeviceTypes`, `setSeries`, `setDevices`, `setDeviceMedia` to use `TopdataBrandService::getBrandByWsId()`.