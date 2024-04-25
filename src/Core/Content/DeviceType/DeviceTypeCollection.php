<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\DeviceType;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void             add(DeviceTypeEntity $entity)
 * @method void             set(string $key, DeviceTypeEntity $entity)
 * @method DeviceTypeEntity[]    getIterator()
 * @method DeviceTypeEntity[]    getElements()
 * @method DeviceTypeEntity|null get(string $key)
 * @method DeviceTypeEntity|null first()
 * @method DeviceTypeEntity|null last()
 */
class DeviceTypeCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return DeviceTypeEntity::class;
    }
}