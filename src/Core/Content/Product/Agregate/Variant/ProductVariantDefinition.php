<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Product\Agregate\Variant;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\MappingEntityDefinition;

class ProductVariantDefinition extends MappingEntityDefinition
{
    public function getEntityName(): string
    {
        return 'topdata_product_to_variant';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new PrimaryKey(), new Required()),
            (new ReferenceVersionField(ProductDefinition::class, 'product_version_id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('variant_product_id', 'variantProductId', ProductDefinition::class))->addFlags(new PrimaryKey(), new Required()),
            (new ReferenceVersionField(ProductDefinition::class, 'variant_product_version_id'))->addFlags(new PrimaryKey(), new Required()),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class),
            new ManyToOneAssociationField('variant_product', 'variant_product_id', ProductDefinition::class),
            new CreatedAtField(),
        ]);
    }
}