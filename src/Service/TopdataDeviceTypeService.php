<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;

/**
 * 03/2025 created (extracted from MappingHelperService)
 */
class TopdataDeviceTypeService
{
    private ?array $typesArray = null; // some cache

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function getTypesArray($forceReload = false): array
    {
        if ($this->typesArray === null || $forceReload) {
            $this->typesArray = [];
            $results = $this
                ->connection
                ->createQueryBuilder()
                ->select('*')
//                ->select(['id','code', 'label', 'brand_id', 'ws_id'])
                ->from('topdata_device_type')
                ->execute()
                ->fetchAllAssociative();
            foreach ($results as $r) {
                $this->typesArray[bin2hex($r['id'])] = $r;
                $this->typesArray[bin2hex($r['id'])]['id'] = bin2hex($r['id']);
                $this->typesArray[bin2hex($r['id'])]['brand_id'] = bin2hex($r['brand_id']);
            }
        }

        return $this->typesArray;
    }

}
