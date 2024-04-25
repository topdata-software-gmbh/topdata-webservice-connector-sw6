<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Device\Agregate\DeviceCustomer;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\MappingEntityDefinition;
use Shopware\Core\System\User\UserDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Device\DeviceDefinition;

class DeviceCustomerDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'topdata_device_to_customer';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return DeviceCustomerEntity::class;
    }

    public function getCollectionClass(): string
    {
        return DeviceCustomerCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('device_id', 'deviceId', DeviceDefinition::class))->addFlags(new Required()),
            new FkField('customer_id', 'customerId', CustomerDefinition::class),
            new IdField('customer_extra_id', 'customerExtraId'),
            (new LongTextField('extra_info', 'extraInfo')),
            new BoolField('is_dealer_managed', 'isDealerManaged'),

            new ManyToOneAssociationField('device', 'device_id', DeviceDefinition::class),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class),
            new CreatedAtField(),
        ]);
    }
}
