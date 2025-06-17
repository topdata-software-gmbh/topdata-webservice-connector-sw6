# Optimization Plan: Product Relationship Processing

## Problem Statement
The current implementation of product relationship processing in `ProductProductRelationshipServiceV1` is inefficient due to:
- Individual INSERT statements for each relationship
- High number of database roundtrips
- Lack of transaction batching
- Small chunk size (30 records)

This results in slow performance when processing product relationships.

## Proposed Solution
Implement bulk INSERT operations with transaction handling to reduce database roundtrips and improve performance.

```mermaid
graph LR
    A[Current Implementation] --> B[Individual INSERTs]
    B --> C[High DB Roundtrips]
    C --> D[Slow Processing]
    
    E[Optimized Solution] --> F[Bulk INSERTs]
    F --> G[Transaction Handling]
    G --> H[Large Batches]
    H --> I[Faster Processing]
```

## Implementation Steps

### 1. Refactor Relationship Collection
Modify `linkProducts()` to collect all relationships before processing:
```php
public function linkProducts(array $productId_versionId, $remoteProductData): void
{
    $dateTime = date('Y-m-d H:i:s');
    $allRelationships = [
        'similar' => $this->_findSimilarProducts($remoteProductData),
        'alternate' => $this->_findAlternateProducts($remoteProductData),
        'related' => $this->_findRelatedProducts($remoteProductData),
        'bundled' => $this->findBundledProducts($remoteProductData),
        'color_variant' => $this->_findColorVariantProducts($remoteProductData),
        'capacity_variant' => $this->_findCapacityVariantProducts($remoteProductData),
        'variant' => $this->_findVariantProducts($remoteProductData),
    ];
    
    // ... rest of implementation ...
}
```

### 2. Implement Bulk Processing
Create new `_processBulkRelationships()` method:
```php
private function _processBulkRelationships(
    array $productId_versionId,
    array $allRelationships,
    string $dateTime
): void {
    $this->connection->beginTransaction();
    
    try {
        foreach ($allRelationships as $type => $products) {
            if (empty($products)) continue;
            
            $tableName = $this->getTableForType($type);
            $idColumnPrefix = $this->getIdColumnPrefix($type);
            
            $values = [];
            foreach ($products as $tempProd) {
                $values[] = [
                    hex2bin($productId_versionId['product_id']),
                    hex2bin($productId_versionId['product_version_id']),
                    hex2bin($tempProd['product_id']),
                    hex2bin($tempProd['product_version_id']),
                    $dateTime
                ];
            }
            
            $this->connection->insert(
                $tableName,
                $values,
                [
                    'product_id' => Types::BINARY,
                    'product_version_id' => Types::BINARY,
                    "{$idColumnPrefix}_product_id" => Types::BINARY,
                    "{$idColumnPrefix}_product_version_id" => Types::BINARY,
                    'created_at' => Types::STRING
                ]
            );
        }
        
        $this->connection->commit();
    } catch (\Exception $e) {
        $this->connection->rollBack();
        throw $e;
    }
}
```

### 3. Add Helper Methods
```php
private function getTableForType(string $type): string
{
    $map = [
        'similar' => 'topdata_product_to_similar',
        'alternate' => 'topdata_product_to_alternate',
        'related' => 'topdata_product_to_related',
        'bundled' => 'topdata_product_to_bundled',
        'color_variant' => 'topdata_product_to_color_variant',
        'capacity_variant' => 'topdata_product_to_capacity_variant',
        'variant' => 'topdata_product_to_variant',
    ];
    return $map[$type] ?? '';
}

private function getIdColumnPrefix(string $type): string
{
    $map = [
        'similar' => 'similar_product',
        'alternate' => 'alternate_product',
        'related' => 'related_product',
        'bundled' => 'bundled_product',
        'color_variant' => 'color_variant_product',
        'capacity_variant' => 'capacity_variant_product',
        'variant' => 'variant_product',
    ];
    return $map[$type] ?? '';
}
```

### 4. Update Constants
```php
const BULK_INSERT_SIZE = 500; // Optimal batch size for MySQL
const USE_TRANSACTIONS = true;
```

### 5. Preserve Cross-Selling Logic
Keep the existing `_addProductCrossSelling()` method unchanged.

## Expected Benefits
- **10-100x performance improvement** for relationship processing
- Reduced database load
- More efficient resource utilization
- Scalable solution for large product catalogs

## Risk Mitigation
1. Maintain existing functionality through interface preservation
2. Comprehensive error handling with transactions
3. Detailed logging for debugging
4. Fallback to original implementation through feature flag

## Next Steps
1. Implement the changes in `ProductProductRelationshipServiceV1.php`
2. Test with large product datasets
3. Monitor performance metrics
4. Deploy to staging environment for validation