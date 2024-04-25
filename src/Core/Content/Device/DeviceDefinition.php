<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Device;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Topdata\TopdataConnectorSW6\Core\Content\Brand\BrandDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Device\Agregate\DeviceCustomer\DeviceCustomerDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Device\Agregate\DeviceProduct\DeviceProductDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\DeviceType\DeviceTypeDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Series\SeriesDefinition;

class DeviceDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'topdata_device';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return DeviceEntity::class;
    }

    public function getCollectionClass(): string
    {
        return DeviceCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('brand_id', 'brandId', BrandDefinition::class)),
            (new FkField('type_id', 'typeId', DeviceTypeDefinition::class)),
            (new FkField('series_id', 'seriesId', SeriesDefinition::class)),
            (new BoolField('is_enabled', 'enabled'))->addFlags(new Required()),
            (new BoolField('has_synonyms', 'hasSynonyms')),
            (new StringField('code', 'code'))->addFlags(new Required()),
            (new StringField('model', 'model'))->addFlags(new Required()),
            (new StringField('keywords', 'keywords'))->addFlags(new Required()),
            (new IntField('sort', 'sort'))->addFlags(new Required()),
            (new FkField('media_id', 'mediaId', MediaDefinition::class)),
            //            (new IntField('media_id', 'mediaId')),
            (new IntField('ws_id', 'wsId'))->addFlags(new Required()),
            new ManyToOneAssociationField('brand', 'brand_id', BrandDefinition::class),
            new ManyToOneAssociationField('type', 'type_id', DeviceTypeDefinition::class),
            new ManyToOneAssociationField('series', 'series_id', SeriesDefinition::class),
            new ManyToManyAssociationField('products', ProductDefinition::class, DeviceProductDefinition::class, 'device_id', 'product_id'),
            new ManyToManyAssociationField('customers', CustomerDefinition::class, DeviceCustomerDefinition::class, 'device_id', 'customer_id'),
            new ManyToOneAssociationField('media', 'media_id', MediaDefinition::class),
        ]);
    }
}
