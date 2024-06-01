<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Brand;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BrandEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var string
     */
    protected $code;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var bool
     */
    protected $enabled = '0';

    /**
     * @var int
     */
    protected $sort = '0';

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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
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
}
