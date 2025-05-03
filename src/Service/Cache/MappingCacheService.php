<?php

namespace Topdata\TopdataConnectorSW6\Service\Cache;

use DateTime;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
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
     * @return bool True if valid cache exists, false otherwise.
     */
    public function hasCachedMappings(): bool
    {
        // Calculate the expiry date (current time minus cache expiry hours)
        $expiryDate = new DateTime();
        $expiryDate->modify('-' . self::CACHE_EXPIRY_HOURS . ' hours');
        $expiryDateStr = $expiryDate->format('Y-m-d H:i:s');

        // Check if there are any cached mappings that are not expired
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM topdata_mapping_cache WHERE created_at > :expiryDate',
            ['expiryDate' => $expiryDateStr]
        );

        return (int)$count > 0;
    }

    /**
     * Saves mappings to the cache.
     *
     * @param array $mappings Array of mappings to save.
     * @param string $mappingType The type of mapping (EAN, OEM, PCD).
     */
    public function saveMappingsToCache(array $mappings, string $mappingType): void
    {
        if (empty($mappings)) {
            return;
        }

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
                    $this->buildBatchInsertQuery(count($batchInsert)),
                    $this->flattenBatchInsertParams($batchInsert)
                );
                $batchInsert = [];
            }
        }

        // Insert any remaining mappings
        if (!empty($batchInsert)) {
            $this->connection->executeStatement(
                $this->buildBatchInsertQuery(count($batchInsert)),
                $this->flattenBatchInsertParams($batchInsert)
            );
        }

        ImportReport::setCounter('Cached ' . $mappingType . ' mappings', count($mappings));
    }

    /**
     * Builds a batch insert query for the cache table.
     *
     * @param int $batchSize The number of records to insert.
     * @return string The SQL query.
     */
    private function buildBatchInsertQuery(int $batchSize): string
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
    private function flattenBatchInsertParams(array $batch): array
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
     */
    public function loadMappingsFromCache(): void
    {
        CliLogger::info('Loading mappings from cache...');

        // Get all cached mappings
        $cachedMappings = $this->connection->fetchAllAssociative(
            'SELECT top_data_id, product_id, product_version_id FROM topdata_mapping_cache'
        );

        if (empty($cachedMappings)) {
            CliLogger::warning('No cached mappings found.');
            return;
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

        CliLogger::info('Loaded ' . UtilFormatter::formatInteger($total) . ' mappings from cache.');
        ImportReport::setCounter('Loaded mappings from cache', $total);
    }

    /**
     * Purges all mappings from the cache.
     */
    public function purgeMappingsCache(): void
    {
        CliLogger::info('Purging mapping cache...');
        $this->connection->executeStatement('TRUNCATE TABLE topdata_mapping_cache');
        CliLogger::info('Mapping cache purged.');
    }
}
