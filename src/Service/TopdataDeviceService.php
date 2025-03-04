<?php

namespace Topdata\TopdataConnectorSW6\Service;

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
 * 11/2024 created (extracted from MappingHelperService)
 */
class TopdataDeviceService
{

    public function __construct(
        private readonly Connection $connection,
    )
    {
    }

    public function _getEnabledDevices(): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['*'])
            ->from('topdata_device')
            ->where('is_enabled = 1');

        return $query->execute()->fetchAllAssociative();
    }

}
