<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;

class TopdataBrandService
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function getEnabledBrands(): array
    {
        $allBrands = [];
        $primaryBrands = [];
        
        $brands = $this->connection->createQueryBuilder()
            ->select('LOWER(HEX(id)) as id, label as name, sort')
            ->from('topdata_brand')
            ->where('is_enabled = 1')
            ->orderBy('label')
            ->execute()
            ->fetchAllAssociative();

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

    public function savePrimaryBrands(?array $brandIds): bool
    {
        if ($brandIds === null) {
            return false;
        }

        $this->connection->executeStatement('UPDATE topdata_brand SET sort = 0');

        if ($brandIds) {
            foreach ($brandIds as $key => $brandId) {
                if (preg_match('/^[0-9a-f]{32}$/', $brandId)) {
                    $brandIds[$key] = '0x' . $brandId;
                }
            }

            $this->connection->executeStatement(
                'UPDATE topdata_brand SET sort = 1 WHERE id IN (' . implode(',', $brandIds) . ')'
            );
        }

        return true;
    }
}
