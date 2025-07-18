<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Customer;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Inherited;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Topdata\TopdataConnectorSW6\Core\Content\Device\Agregate\DeviceCustomer\DeviceCustomerDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Device\DeviceDefinition;

class CustomerExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new ManyToManyAssociationField(
                'devices',
                DeviceDefinition::class,
                DeviceCustomerDefinition::class,
                'customer_id',
                'device_id'
            ))->addFlags(new Inherited())
        );
    }

    // sw6.6
    public function getDefinitionClass(): string
    {
        return CustomerDefinition::class;
    }

    // sw6.7
    public function getEntityName(): string
    {
        return CustomerDefinition::ENTITY_NAME;
    }

}
