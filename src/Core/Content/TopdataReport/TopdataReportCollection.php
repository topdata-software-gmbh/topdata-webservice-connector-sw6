<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\TopdataReport;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * Collection for import reports
 */
class TopdataReportCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TopdataReportEntity::class;
    }
}
