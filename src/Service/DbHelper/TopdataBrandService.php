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
}