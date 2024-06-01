<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\DeviceType;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Topdata\TopdataConnectorSW6\Core\Content\Brand\BrandDefinition;

class DeviceTypeDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'topdata_device_type';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return DeviceTypeEntity::class;
    }

    public function getCollectionClass(): string
    {
        return DeviceTypeCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('brand_id', 'brandId', BrandDefinition::class)),
            (new StringField('code', 'code'))->addFlags(new Required()),
            (new BoolField('is_enabled', 'enabled'))->addFlags(new Required()),
            (new StringField('label', 'label'))->addFlags(new Required()),
            (new IntField('sort', 'sort'))->addFlags(new Required()),
            (new IntField('ws_id', 'wsId'))->addFlags(new Required()),
            new ManyToOneAssociationField('brand', 'brand_id', BrandDefinition::class),
        ]);
    }
}
