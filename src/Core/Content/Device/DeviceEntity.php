<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Device;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Topdata\TopdataConnectorSW6\Core\Content\Brand\BrandEntity;
use Topdata\TopdataConnectorSW6\Core\Content\Series\SeriesEntity;
use Topdata\TopdataConnectorSW6\Core\Content\DeviceType\DeviceTypeEntity;
use Shopware\Core\Checkout\Customer\CustomerCollection;

class DeviceEntity extends Entity
{
    use EntityIdTrait;
    
    /**
     * @var bool
     */
    protected $enabled;
    
    /**
     * @var bool
     */
    protected $hasSynonyms;
    
    /**
     * @var bool
     */
    protected $inDeviceList;

    /**
     * @var BrandEntity
     */
    protected $brand;
    
    /**
     * @var string
     */
    protected $brandId;

    /**
     * @var string
     */
    protected $code;

    /**
     * @var DeviceTypeEntity
     */
    protected $type;

    /**
     * @var string
     */
    protected $typeId;
    
    
    /**
     * @var string
     */
    protected $model;

    /**
     * @var string
     */
    protected $keywords = '';

    /**
     * @var SeriesEntity|null
     */
    protected $series;
    
    /**
     * @var string
     */
    protected $seriesId;

    /**
     * @var boolean
     */
    protected $sort = '0';

    /**
     * @var MediaEntity|null
     */
    protected $media;

    /**
     * @var int
     */
    protected $mediaId;

    /**
     * @var ProductCollection|null
     */
    protected $products;
    
    /**
     * @var CustomerCollection|null
     */
    protected $customers;
    

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
    
    
    public function getModel(): string
    {
        return $this->model;
    }

    
    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    
    public function getEnabled(): bool
    {
        return $this->enabled;
    }
    
    
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
    
    public function getHasSynonyms(): bool
    {
        return $this->hasSynonyms;
    }
    
    
    public function setHasSynonyms(bool $hasSynonyms): void
    {
        $this->hasSynonyms = $hasSynonyms;
    }

    
    public function getKeywords(): string
    {
        return $this->keywords;
    }

    
    public function setKeywords(string $keywords): void
    {
        $this->keywords = $keywords;
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
    
    
    public function getBrandId(): ?string
    {
        return $this->brandId;
    }
    
    
    public function setBrandId(string $id): void
    {
        $this->brandId = $id;
    }
    
    
    public function getTypeId(): ?string
    {
        return $this->typeId;
    }
    
    
    public function setTypeId(string $id): void
    {
        $this->typeId = $id;
    }
    
    
    public function getSeriesId(): ?string
    {
        return $this->seriesId;
    }
    
    
    public function setSeriesId(string $id): void
    {
        $this->seriesId = $id;
    }
    
    public function getProducts(): ?ProductCollection
    {
        return $this->products;
    }

    public function setProducts(ProductCollection $products): void
    {
        $this->products = $products;
    }
    
    public function getCustomers(): ?CustomerCollection
    {
        return $this->customers;
    }

    public function setCustomers(CustomerCollection $customers): void
    {
        $this->customers = $customers;
    }
    
    public function getMedia(): ?MediaEntity
    {
        return $this->media;
    }

    public function setMedia(MediaEntity $media): void
    {
        $this->media = $media;
    }
    
    public function getBrand(): ?BrandEntity
    {
        return $this->brand;
    }
    
    public function setBrand(BrandEntity $brand): void
    {
        $this->brand = $brand;
    }
    
    public function getSeries(): ?SeriesEntity
    {
        return $this->series;
    }
    
    public function setSeries(SeriesEntity $series): void
    {
        $this->series = $series;
    }
    
    public function getType(): ?DeviceTypeEntity
    {
        return $this->type;
    }
    
    public function setType(DeviceTypeEntity $type): void
    {
        $this->type = $type;
    }
    
    public function getInDeviceList() : bool
    {
        return $this->inDeviceList;
    }
    
    public function setInDeviceList(bool $inDeviceList) : void
    {
        $this->inDeviceList = $inDeviceList;
    }
}