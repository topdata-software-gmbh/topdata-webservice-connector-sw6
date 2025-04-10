<?php

namespace Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Service\Import\ShopwareProductService;
use Topdata\TopdataConnectorSW6\Util\UtilMappingHelper;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * 03/2025 created (extracted from ProductMappingService)
 */
final class MappingStrategy_ProductNumberAs extends AbstractMappingStrategy
{
    const BATCH_SIZE = 99;

    public function __construct(
        private readonly Connection             $connection,
        private readonly ShopwareProductService $shopwareProductService
    )
    {
    }

    /**
     * ==== MAIN ====
     *
     * Maps products using the product number as the web service ID.
     *
     * This method retrieves product numbers and their corresponding IDs from the database,
     * then inserts the mapped data into the `topdata_to_product` table.
     */
    // private function _mapProductNumberAsWsId(): void

    #[\Override]
    public function map(): void
    {
        $dataInsert = [];

        $artnos = UtilMappingHelper::convertMultiArrayBinaryIdsToHex($this->shopwareProductService->getKeysByProductNumber());
        $currentDateTime = date('Y-m-d H:i:s');
        foreach ($artnos as $wsid => $prods) {
            foreach ($prods as $prodid) {
                if (ctype_digit((string)$wsid)) {
                    $dataInsert[] = '(' .
                        '0x' . Uuid::randomHex() . ',' .
                        "$wsid," .
                        "0x{$prodid['id']}," .
                        "0x{$prodid['version_id']}," .
                        "'$currentDateTime'" .
                        ')';
                }
                if (count($dataInsert) > self::BATCH_SIZE) {
                    $this->connection->executeStatement('
                    INSERT INTO topdata_to_product 
                    (id, top_data_id, product_id, product_version_id, created_at) 
                    VALUES ' . implode(',', $dataInsert) . '
                ');
                    $dataInsert = [];
                    CliLogger::activity();
                }
            }
        }
        if (count($dataInsert)) {
            $this->connection->executeStatement('
                INSERT INTO topdata_to_product 
                (id, top_data_id, product_id, product_version_id, created_at) 
                VALUES ' . implode(',', $dataInsert) . '
            ');
            $dataInsert = [];
            CliLogger::activity();
        }
    }
}