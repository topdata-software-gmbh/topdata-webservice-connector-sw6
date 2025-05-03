<?php

namespace Topdata\TopdataConnectorSW6\Service\DbHelper;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilFormatter;

/**
 * Service class for managing the mapping between Topdata IDs and Shopware product IDs.
 * It handles fetching and storing product mappings in the database.
 * 11/2024 created (extracted from MappingHelperService)
 */
class TopdataToProductService
{

    /**
     * @var array|null it is a map with format: [top_data_id => [product_id, product_version_id, parent_id]]
     */
    private ?array $topdataProductMappings = null;
    private Context $context; // default context


    public function __construct(
        private readonly Connection       $connection,
        private readonly EntityRepository $topdataToProductRepository
    )
    {
        $this->context = Context::createDefaultContext();
    }


    /**
     * Retrieves the mapping between Topdata IDs and product IDs.
     * The mapping is fetched from the database and cached in memory for subsequent calls.
     *
     * @param bool $forceReload If true, forces a reload of the mapping from the database, otherwise the cached version is returned if available.
     * @return array An array representing the mapping, where keys are Topdata IDs and values are arrays containing product_id, product_version_id, and parent_id.
     *
     * 04/2025 renamed from getTopidProducts() to getTopdataProductMappings()
     */
    public function getTopdataProductMappings(bool $forceReload = false): array
    {
        if ($this->topdataProductMappings === null || $forceReload) {
            // ---- fetch from db
            $rows = $this->connection->fetchAllAssociative('
                SELECT 
                    topdata_to_product.top_data_id, 
                    LOWER(HEX(topdata_to_product.product_id))          AS product_id, 
                    LOWER(HEX(topdata_to_product.product_version_id))  AS product_version_id, 
                    LOWER(HEX(product.parent_id))                      AS parent_id 
                FROM `topdata_to_product`
                INNER JOIN product ON 
                    topdata_to_product.product_id = product.id AND 
                    topdata_to_product.product_version_id = product.version_id 
                ORDER BY topdata_to_product.top_data_id
            ');

            // ---- log to console
            CliLogger::info('getTopdataProductMappings - fetched ' . UtilFormatter::formatInteger(count($rows)) . ' mappings from database');
            if (empty($rows)) {
                CliLogger::warning('No mapped products found in database. Did you set the correct mapping in plugin config?');
            }

            // ---- build the map
            $this->topdataProductMappings = [];
            foreach ($rows as $row) {
                $this->topdataProductMappings[$row['top_data_id']][] = [
                    'product_id'         => $row['product_id'],
                    'product_version_id' => $row['product_version_id'],
                    'parent_id'          => $row['parent_id'],
                ];
            }
        }

        return $this->topdataProductMappings;
    }


    /**
     * Inserts multiple Topdata to product mappings into the database.
     * 11/2024 created
     *
     * @param array $dataInsert An array of data to insert into the topdata_to_product table.
     */
    public function insertMany(array $dataInsert): void
    {
        $this->topdataToProductRepository->create($dataInsert, $this->context);
    }

    public function deleteAll()
    {
        $this->connection->executeStatement('TRUNCATE TABLE topdata_to_product');
    }


}