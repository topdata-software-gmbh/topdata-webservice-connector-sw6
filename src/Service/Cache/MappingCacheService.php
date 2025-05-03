<?php

namespace Topdata\TopdataConnectorSW6\Service\Cache;

use DateTime;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\MappingTypeConstants;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilFormatter;

/**
 * Service for caching mapping data to improve import performance.
 *
 * This service handles caching of EAN, OEM, and PCD mappings to reduce API calls
 * to the TopData webservice during imports.
 *
 * 05/2025 created
 */
class MappingCacheService
{
    /**
     * Number of records to process in a single batch.
     */
    const BATCH_SIZE = 500;

    /**
     * Cache expiry time in hours.
     */
    const CACHE_EXPIRY_HOURS = 24;

    public function __construct(
        private readonly Connection              $connection,
        private readonly TopdataToProductService $topdataToProductService,
    )
    {
    }

    /**
     * Checks if valid cache exists for the mapping.
     *
     * @param string|null $mappingType Optional mapping type to check for specific cache
     * @return bool True if valid cache exists, false otherwise.
     */
    public function hasCachedMappings(): bool
    {
        UtilProfiling::startTimer();

        // Calculate the expiry date (current time minus cache expiry hours)
        $expiryDate = new DateTime();
        $expiryDate->modify('-' . self::CACHE_EXPIRY_HOURS . ' hours');
        $expiryDateStr = $expiryDate->format('Y-m-d H:i:s');

        // Build the query based on whether a specific mapping type was requested
        $query = 'SELECT 1 FROM topdata_mapping_cache WHERE created_at > :expiryDate LIMIT 1';
        $params = ['expiryDate' => $expiryDateStr];

        // Check if there is at least one cached mapping that is not expired
        $result = $this->connection->fetchOne($query, $params);

        UtilProfiling::stopTimer();

        return $result !== false;
    }
    /**
     * Saves mappings to the cache.
     *
     * @param array $mappings Array of mappings to save.
     * @param string $mappingType The type of mapping (EAN, OEM, PCD).
     */
    public function saveMappingsToCache(array $mappings, string $mappingType): void
    {
        UtilProfiling::startTimer();
        
        if (empty($mappings)) {
            return;
        }

        // Clear existing cache for this mapping type
        $this->purgeMappingsCacheByType($mappingType);

        $currentDateTime = date('Y-m-d H:i:s');
        $batchInsert = [];

        foreach ($mappings as $mapping) {
            $batchInsert[] = [
                'id'                 => Uuid::randomBytes(),
                'mapping_type'       => $mappingType,
                'top_data_id'        => $mapping['topDataId'],
                'product_id'         => hex2bin($mapping['productId']),
                'product_version_id' => hex2bin($mapping['productVersionId']),
                'created_at'         => $currentDateTime,
            ];

            // Insert in batches to avoid memory issues
            if (count($batchInsert) >= self::BATCH_SIZE) {
                $this->connection->executeStatement(
                    $this->_buildBatchInsertQuery(count($batchInsert)),
                    $this->_flattenBatchInsertParams($batchInsert)
                );
                $batchInsert = [];
            }
        }

        // Insert any remaining mappings
        if (!empty($batchInsert)) {
            $this->connection->executeStatement(
                $this->_buildBatchInsertQuery(count($batchInsert)),
                $this->_flattenBatchInsertParams($batchInsert)
            );
        }

        ImportReport::setCounter('Cached ' . $mappingType . ' mappings', count($mappings));
        UtilProfiling::stopTimer();
    }

    /**
     * Builds a batch insert query for the cache table.
     *
     * @param int $batchSize The number of records to insert.
     * @return string The SQL query.
     */
    private function _buildBatchInsertQuery(int $batchSize): string
    {
        $placeholders = [];
        for ($i = 0; $i < $batchSize; $i++) {
            $placeholders[] = '(:id' . $i . ', :mapping_type' . $i . ', :top_data_id' . $i . ', :product_id' . $i . ', :product_version_id' . $i . ', :created_at' . $i . ')';
        }

        return 'INSERT INTO topdata_mapping_cache (id, mapping_type, top_data_id, product_id, product_version_id, created_at) VALUES ' . implode(', ', $placeholders);
    }

    /**
     * Flattens batch insert parameters for use with the query.
     *
     * @param array $batch The batch of records to insert.
     * @return array The flattened parameters.
     */
    private function _flattenBatchInsertParams(array $batch): array
    {
        $params = [];
        foreach ($batch as $i => $record) {
            foreach ($record as $key => $value) {
                $params[$key . $i] = $value;
            }
        }
        return $params;
    }

    /**
     * Loads mappings from the cache and inserts them into the topdata_to_product table.
     * 
     * @param string|null $mappingType Optional mapping type to load specific cache
     * @return int Number of mappings loaded
     */
    public function loadMappingsFromCache(?string $mappingType = null): int
    {
        UtilProfiling::startTimer();
        CliLogger::info('Loading mappings from cache...');

        // Build the query based on whether a specific mapping type was requested
        $query = 'SELECT top_data_id, product_id, product_version_id FROM topdata_mapping_cache';
        $params = [];
        
        if ($mappingType !== null) {
            $query .= ' WHERE mapping_type = :mappingType';
            $params['mappingType'] = $mappingType;
        }

        // Get cached mappings
        $cachedMappings = $this->connection->fetchAllAssociative($query, $params);

        if (empty($cachedMappings)) {
            CliLogger::warning('No cached mappings found' . ($mappingType ? ' for type ' . $mappingType : '') . '.');
            UtilProfiling::stopTimer();
            return 0;
        }

        $batchInsert = [];
        $total = 0;

        foreach ($cachedMappings as $mapping) {
            $batchInsert[] = [
                'topDataId'        => (int)$mapping['top_data_id'],
                'productId'        => bin2hex($mapping['product_id']),
                'productVersionId' => bin2hex($mapping['product_version_id']),
            ];

            $total++;

            if (count($batchInsert) >= self::BATCH_SIZE) {
                $this->topdataToProductService->insertMany($batchInsert);
                $batchInsert = [];
            }
        }

        // Insert any remaining mappings
        if (!empty($batchInsert)) {
            $this->topdataToProductService->insertMany($batchInsert);
        }

        CliLogger::info('Loaded ' . UtilFormatter::formatInteger($total) . ' mappings from cache' . 
            ($mappingType ? ' for type ' . $mappingType : '') . '.');
        ImportReport::setCounter('Loaded mappings from cache' . ($mappingType ? ' (' . $mappingType . ')' : ''), $total);
        
        UtilProfiling::stopTimer();
        return $total;
    }

    /**
     * Purges all mappings from the cache.
     */
    public function purgeMappingsCache(): void
    {
        UtilProfiling::startTimer();
        CliLogger::info('Purging mapping cache...');
        $this->connection->executeStatement('TRUNCATE TABLE topdata_mapping_cache');
        CliLogger::info('Mapping cache purged.');
        UtilProfiling::stopTimer();
    }
    
    /**
     * Purges mappings of a specific type from the cache.
     * 
     * @param string $mappingType The type of mapping to purge.
     */
    public function purgeMappingsCacheByType(string $mappingType): void
    {
        UtilProfiling::startTimer();
        CliLogger::info('Purging ' . $mappingType . ' mapping cache...');
        $this->connection->executeStatement(
            'DELETE FROM topdata_mapping_cache WHERE mapping_type = :mappingType',
            ['mappingType' => $mappingType]
        );
        CliLogger::info($mappingType . ' mapping cache purged.');
        UtilProfiling::stopTimer();
    }
    
    /**
     * Gets statistics about the cache.
     * 
     * @return array Cache statistics
     */
    public function getCacheStats(): array
    {
        $stats = [];
        
        // Get total count
        $stats['total'] = (int)$this->connection->fetchOne('SELECT COUNT(*) FROM topdata_mapping_cache');
        
        // Get counts by mapping type
        $typeStats = $this->connection->fetchAllAssociative(
            'SELECT mapping_type, COUNT(*) as count FROM topdata_mapping_cache GROUP BY mapping_type'
        );
        
        foreach ($typeStats as $typeStat) {
            $stats['by_type'][$typeStat['mapping_type']] = (int)$typeStat['count'];
        }
        
        // Get age of oldest and newest cache entries
        if ($stats['total'] > 0) {
            $stats['oldest'] = $this->connection->fetchOne('SELECT MIN(created_at) FROM topdata_mapping_cache');
            $stats['newest'] = $this->connection->fetchOne('SELECT MAX(created_at) FROM topdata_mapping_cache');
        }
        
        return $stats;
    }
}
