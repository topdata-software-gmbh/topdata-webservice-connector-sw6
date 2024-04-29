<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Product\Agregate\ProductCrossSelling;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingDefinition;

class TopdataProductCrossSellingExtensionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'topdata_product_cross_selling_extension';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return TopdataProductCrossSellingExtensionEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new FkField('product_cross_selling_id', 'productCrossSellingId', ProductCrossSellingDefinition::class),
            (new StringField('type', 'type')),

            new OneToOneAssociationField('productCrossSelling', 'product_cross_selling_id', 'id', ProductCrossSellingDefinition::class, false)
        ]);
    }
}