<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Brand;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void             add(BrandEntity $entity)
 * @method void             set(string $key, BrandEntity $entity)
 * @method BrandEntity[]    getIterator()
 * @method BrandEntity[]    getElements()
 * @method BrandEntity|null get(string $key)
 * @method BrandEntity|null first()
 * @method BrandEntity|null last()
 */
class BrandCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BrandEntity::class;
    }
}