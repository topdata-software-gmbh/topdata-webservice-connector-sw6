<?php

namespace Topdata\TopdataConnectorSW6\Service\DbHelper;

use Doctrine\DBAL\Connection;
use Exception;
use PDO;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\BatchSizeConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilStringFormatting;
use Topdata\TopdataFoundationSW6\Service\LocaleHelperService;
use Topdata\TopdataFoundationSW6\Service\ManufacturerService;

/**
 * Service class for handling Topdata device related operations.
 * 11/2024 created (extracted from MappingHelperService)
 */
class TopdataDeviceService
{

    public function __construct(
        private readonly Connection $connection,
    )
    {
    }

    /**
     * Retrieves all enabled devices from the database.
     *
     * @return array An array of associative arrays representing the enabled devices.
     */
    public function _getEnabledDevices(): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['*'])
            ->from('topdata_device')
            ->where('is_enabled = 1');

        return $query->execute()->fetchAllAssociative();
    }

    /**
     * Retrieves an array of devices based on an array of WS IDs.
     * 03/2025 extracted from MappingHelperService
     *
     * @param array $wsIds An array of WS IDs to filter devices by.
     *
     * @return array An array of devices matching the provided WS IDs.
     */
    public function getDeviceArrayByWsIdArray(array $wsIds): array
    {
        if (!count($wsIds)) {
            return [];
        }
        $ret = []; // a list of devices

        // $this->brandWsArray = []; // FIXME: why is this here?
        $queryRez = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('topdata_device')
            ->where('ws_id IN (' . implode(',', $wsIds) . ')')
            ->execute()
            ->fetchAllAssociative();

        // ---- Process the query results
        foreach ($queryRez as $device) {
            $device['id'] = bin2hex($device['id']);
            $device['brand_id'] = bin2hex($device['brand_id'] ?? '');
            $device['type_id'] = bin2hex($device['type_id'] ?? '');
            $device['series_id'] = bin2hex($device['series_id'] ?? '');
            $ret[] = $device;
        }

        return $ret;
    }

}