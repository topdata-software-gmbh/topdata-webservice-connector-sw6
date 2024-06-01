<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Device\Agregate\DeviceCustomer;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void              add(DeviceEntity $entity)
 * @method void              set(string $key, DeviceEntity $entity)
 * @method DeviceEntity[]    getIterator()
 * @method DeviceEntity[]    getElements()
 * @method DeviceEntity|null get(string $key)
 * @method DeviceEntity|null first()
 * @method DeviceEntity|null last()
 */
class DeviceCustomerCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return DeviceCustomerEntity::class;
    }
}
