<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\TopdataToProduct;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * 06/2025 renamed TopdataToProductDefinition --> TopdataToProductDefinition
 */
class TopdataToProductDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'topdata_to_product';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return TopdataToProductEntity::class;
    }

    public function getCollectionClass(): string
    {
        return TopdataToProductCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new IntField('top_data_id', 'topDataId'))->addFlags(new Required()),
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new Required()),
            (new ReferenceVersionField(ProductDefinition::class, 'product_version_id'))->addFlags(new Required()),
            //            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class),
            new OneToOneAssociationField('product', 'product_id', 'id', ProductDefinition::class, false),
            //            (new StringField('product_id', 'productId'))->addFlags(new PrimaryKey(), new Required()),
            //            (new StringField('product_version_id', 'productVersionId'))->addFlags(new PrimaryKey(), new Required()),
            //
            //            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new PrimaryKey(), new Required()),
            //            (new ReferenceVersionField(ProductDefinition::class, 'product_version_id'))->addFlags(new PrimaryKey(), new Required()),
            //            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class),
        ]);
    }
}
