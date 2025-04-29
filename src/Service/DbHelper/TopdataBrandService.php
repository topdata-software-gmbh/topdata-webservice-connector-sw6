<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service\DbHelper;

use Doctrine\DBAL\Connection;

/**
 * Service class for managing Topdata brands.
 * Provides methods to retrieve enabled brands and save primary brands.
 */
class TopdataBrandService
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    private ?array $brandsByWsIdCache = null;

    /**
     * Retrieves enabled brands from the database.
     *
     * @return array An array containing all enabled brands and primary brands.
     *               The array has the following structure:
     *               [
     *                   'brands' => [brandId => brandName, ...],
     *                   'primary' => [brandId => brandName, ...],
     *                   'brandsCount' => int,
     *                   'primaryCount' => int,
     *               ]
     */
    public function getEnabledBrands(): array
    {
        $allBrands = [];
        $primaryBrands = [];
        
        // ---- Fetch enabled brands from the database
        $brands = $this->connection->createQueryBuilder()
            ->select('LOWER(HEX(id)) as id, label as name, sort')
            ->from('topdata_brand')
            ->where('is_enabled = 1')
            ->orderBy('label')
            ->execute()
            ->fetchAllAssociative();

        // ---- Process the fetched brands
        foreach ($brands as $brand) {
            $allBrands[$brand['id']] = $brand['name'];
            if ($brand['sort'] == 1) {
                $primaryBrands[$brand['id']] = $brand['name'];
            }
        }

        return [
            'brands' => $allBrands,
            'primary' => $primaryBrands,
            'brandsCount' => count($allBrands),
            'primaryCount' => count($primaryBrands),
        ];
    }

    /**
     * Saves the provided brand IDs as primary brands in the database.
     *
     * @param array|null $brandIds An array of brand IDs to be set as primary brands.
     *                             If null, all brands will be set as non-primary.
     *
     * @return bool True if the operation was successful, false if the input was invalid.
     */
    public function savePrimaryBrands(?array $brandIds): bool
    {
        if ($brandIds === null) {
            return false;
        }

        // ---- Reset all brands to non-primary
        $this->connection->executeStatement('UPDATE topdata_brand SET sort = 0');

        if ($brandIds) {
            // ---- Prepare brand IDs for the SQL query
            foreach ($brandIds as $key => $brandId) {
                if (preg_match('/^[0-9a-f]{32}$/', $brandId)) {
                    $brandIds[$key] = '0x' . $brandId;
                }
            }

            // ---- Update the specified brands as primary
            $this->connection->executeStatement(
                'UPDATE topdata_brand SET sort = 1 WHERE id IN (' . implode(',', $brandIds) . ')'
            );
        }

        return true;
    }
    /**
     * Loads all brands from the database and populates the internal cache, keyed by ws_id.
     */
    private function _loadBrandsByWsId(): void
    {
        $brands = $this->connection->createQueryBuilder()
            ->select('LOWER(HEX(id)) as id, ws_id, label, is_enabled, sort')
            ->from('topdata_brand')
            ->where('ws_id IS NOT NULL') // Ensure we only get brands with a ws_id
            ->execute()
            ->fetchAllAssociative();

        $this->brandsByWsIdCache = [];
        foreach ($brands as $brand) {
            // Ensure ws_id is treated as an integer key
            $wsId = (int) $brand['ws_id'];
            $this->brandsByWsIdCache[$wsId] = $brand;
        }
    }

    /**
     * Retrieves a specific brand by its Webservice ID (ws_id).
     * Uses an internal cache for efficiency.
     *
     * @param int $brandWsId The Webservice ID of the brand to retrieve.
     * @return array The brand data as an associative array, or an empty array if not found.
     */
    public function getBrandByWsId(int $brandWsId): array
    {
        if ($this->brandsByWsIdCache === null) {
            $this->_loadBrandsByWsId();
        }

        return $this->brandsByWsIdCache[$brandWsId] ?? [];
    }
}