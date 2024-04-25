<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Series;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Topdata\TopdataConnectorSW6\Core\Content\Brand\BrandEntity;

class SeriesEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var string
     */
    protected $code;

    /**
     * @var string
     */
    protected $label;

    /**
     * @var bool
     */
    protected $enabled = '0';

    /**
     * @var int
     */
    protected $sort = '0';

    /**
     * @var BrandEntity
     */
    protected $brand;

    /**
     * @var string
     */
    protected $brandId;

    protected $devices;

    /**
     * @var int
     */
    protected $wsId;

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getSort(): int
    {
        return $this->sort;
    }

    public function setSort(int $sort): void
    {
        $this->sort = $sort;
    }

    public function getWsId(): int
    {
        return $this->wsId;
    }

    public function setWsId(int $wsId): void
    {
        $this->wsId = $wsId;
    }

    public function getBrand(): ?BrandEntity
    {
        return $this->brand;
    }

    public function setBrand(BrandEntity $brand): void
    {
        $this->brand = $brand;
    }

    public function getBrandId(): ?string
    {
        return $this->brandId;
    }

    public function setBrandId(string $id): void
    {
        $this->brandId = $id;
    }
}
