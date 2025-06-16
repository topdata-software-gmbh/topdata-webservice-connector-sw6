<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\TopdataToProduct;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * 06/2025 rebamed TopdataToProductEntity --> TopdataToProductEntity
 */
class TopdataToProductEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var int
     */
    protected $topDataId;

    /**
     * @var string
     */
    protected $productId;

    /**
     * @var string
     */
    protected $productVersionId;

    /**
     * @var ?ProductEntity
     */
    protected $product;

    public function getTopDataId(): int
    {
        return $this->topDataId;
    }

    public function setTopDataId(int $topDataId): void
    {
        $this->topDataId = $topDataId;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function setProductId(string $productId): void
    {
        $this->productId = $productId;
    }

    public function getProductVersionId(): string
    {
        return $this->productVersionId;
    }

    public function setProductVersionId(string $productVersionId): void
    {
        $this->productVersionId = $productVersionId;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $product): void
    {
        $this->product = $product;
    }
}
