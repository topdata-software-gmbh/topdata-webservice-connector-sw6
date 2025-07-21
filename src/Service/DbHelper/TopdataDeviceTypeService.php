<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service\DbHelper;

use Doctrine\DBAL\Connection;

/**
 * Service to manage Topdata device types.
 * 03/2025 created (extracted from MappingHelperService)
 */
class TopdataDeviceTypeService
{
    private ?array $typesArray = null; // some cache

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Retrieves an array of Topdata device types.
     *
     * @param bool $forceReload If true, forces a reload of the device types from the database.
     * @return array An array of device types, indexed by their hexadecimal ID.
     */
    public function getTypesArray($forceReload = false): array
    {
        // Check if the types array is already cached or if a reload is forced.
        if ($this->typesArray === null || $forceReload) {
            $this->typesArray = [];

            // ---- Fetch device types from the database.
            $results = $this
                ->connection
                ->createQueryBuilder()
                ->select('*')
//                ->select(['id','code', 'label', 'brand_id', 'ws_id'])
                ->from('topdata_device_type')
->executeQuery()->fetchAllAssociative();

            // ---- Process the database results and format the array.
            foreach ($results as $r) {
                $this->typesArray[bin2hex($r['id'])] = $r;
                $this->typesArray[bin2hex($r['id'])]['id'] = bin2hex($r['id']);
                $this->typesArray[bin2hex($r['id'])]['brand_id'] = bin2hex($r['brand_id']);
            }
        }

        return $this->typesArray;
    }

}