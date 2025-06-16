<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\TopdataToProduct;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class TopdataToProductCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TopdataToProductEntity::class;
    }
}
