<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;

/**
 * 03/2025 created (extracted from MappingHelperService)
 */
class TopdataSeriesService
{
    private ?array $seriesArray = null; // some cache

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function getSeriesArray($forceReload = false): array
    {
        if ($this->seriesArray === null || $forceReload) {
            $this->seriesArray = [];
            $results = $this
                ->connection
                ->createQueryBuilder()
                ->select('*')
//                ->select(['id','code', 'label', 'brand_id', 'ws_id'])
                ->from('topdata_series')
                ->execute()
                ->fetchAllAssociative();
            foreach ($results as $r) {
                $this->seriesArray[bin2hex($r['id'])] = $r;
                $this->seriesArray[bin2hex($r['id'])]['id'] = bin2hex($r['id']);
                $this->seriesArray[bin2hex($r['id'])]['brand_id'] = bin2hex($r['brand_id']);
            }
        }

        return $this->seriesArray;
    }

}
