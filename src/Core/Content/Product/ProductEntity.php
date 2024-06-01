<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Product;

use Shopware\Core\Content\Product\ProductEntity as parentProductEntity;

class ProductEntity extends parentProductEntity
{
    /**
     * @var int
     */
    protected $topDataId;

    public function getTopDataId(): ?int
    {
        return $this->topDataId;
    }

    public function setTopDataId(int $topDataId): void
    {
        $this->topDataId = $topDataId;
    }
}
