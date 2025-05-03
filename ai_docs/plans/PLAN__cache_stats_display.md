# Plan: Add Cache Statistics Display

## Objective

Add a method to display cache statistics in `src/Service/Cache/MappingCacheService.php` and display these statistics after the cache is populated in `src/Service/Import/MappingStrategy/MappingStrategy_EanOem.php`.

## Plan

1.  **Modify `getCacheStats` method:** Update the `getCacheStats` method in `src/Service/Cache/MappingCacheService.php` to include the `ORDER BY count DESC` clause in the SQL query for retrieving counts by mapping type.
2.  **Add stats display after caching:** In `src/Service/Import/MappingStrategy/MappingStrategy_EanOem.php`, after the call to `saveMappingsToCache` (around line 300), add code to:
    *   Call the modified `getCacheStats` method of the `MappingCacheService`.
    *   Iterate through the returned statistics and display them using `CliLogger::info`.

## Visual Representation

```mermaid
graph TD
    A[User Request: Add stats method and display after cache population] --> B{Analyze MappingCacheService.php};
    B --> C[Identify existing getCacheStats method];
    C --> D[Identify where saveMappingsToCache is called];
    D --> E{Modify getCacheStats query};
    E --> F{Add code to display stats in MappingStrategy_EanOem.php};
    F --> G[Call getCacheStats];
    G --> H[Log stats using CliLogger];
    H --> I[Task Complete];