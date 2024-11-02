<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Topdata\TopdataFoundationSW6\Trait\CliStyleTrait;

/**
 * 11/2024 created (extracted from MappingHelperService)
 */
class TopdataToProductHelperService
{
    use CliStyleTrait;

    /**
     * it is a map with format: [top_data_id => [product_id, product_version_id, parent_id]]
     */
    private ?array $topidProducts = null;

    public function __construct(
        private readonly Connection $connection,
    )
    {
    }


    /**
     * it populates $this->topidProducts once, unless $forceReload is true.
     */
    public function getTopidProducts(bool $forceReload = false): array
    {
        if (null === $this->topidProducts || $forceReload) {
            $rows = $this->connection->fetchAllAssociative('
                SELECT 
                    topdata_to_product.top_data_id, 
                    LOWER(HEX(topdata_to_product.product_id)) as product_id, 
                    LOWER(HEX(topdata_to_product.product_version_id)) as product_version_id, 
                    LOWER(HEX(product.parent_id)) as parent_id 
                FROM `topdata_to_product`, product 
                WHERE topdata_to_product.product_id = product.id 
                    AND topdata_to_product.product_version_id = product.version_id 
                ORDER BY topdata_to_product.top_data_id
            ');

            // ---- log to console
            $this->cliStyle->info('_fetchTopidProducts :: fetched ' . count($rows) . ' products');
            if (empty($rows)) {
                $this->cliStyle->warning('No mapped products found in database. Did you set the correct mapping in plugin config?');
            }

            // ---- build the map
            $this->topidProducts = [];
            foreach ($rows as $row) {
                $this->topidProducts[$row['top_data_id']][] = [
                    'product_id'         => $row['product_id'],
                    'product_version_id' => $row['product_version_id'],
                    'parent_id'          => $row['parent_id'],
                ];
            }
        }

        return $this->topidProducts;
    }
}
