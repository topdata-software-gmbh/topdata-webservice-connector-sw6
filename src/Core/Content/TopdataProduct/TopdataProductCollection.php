<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\TopdataProduct;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class TopdataProductCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TopdataProductEntity::class;
    }
}