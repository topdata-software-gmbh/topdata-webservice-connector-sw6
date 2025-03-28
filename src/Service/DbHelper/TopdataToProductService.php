<?php

namespace Topdata\TopdataConnectorSW6\Service\DbHelper;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * 11/2024 created (extracted from MappingHelperService)
 */
class TopdataToProductService
{

    /**
     * it is a map with format: [top_data_id => [product_id, product_version_id, parent_id]]
     */
    private ?array $topidProducts = null;
    private Context $context; // default context


    public function __construct(
        private readonly Connection       $connection,
        private readonly EntityRepository $topdataToProductRepository
    )
    {
        $this->context = Context::createDefaultContext();
    }


    /**
     * it populates $this->topidProducts once, unless $forceReload is true.
     * and returns the map.
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
            \Topdata\TopdataFoundationSW6\Util\CliLogger::info('_fetchTopidProducts :: fetched ' . count($rows) . ' products');
            if (empty($rows)) {
                \Topdata\TopdataFoundationSW6\Util\CliLogger::warning('No mapped products found in database. Did you set the correct mapping in plugin config?');
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


    /**
     * 11/2024 created
     */
    public function insertMany(array $dataInsert): void
    {
        $this->topdataToProductRepository->create($dataInsert, $this->context);
    }


}
