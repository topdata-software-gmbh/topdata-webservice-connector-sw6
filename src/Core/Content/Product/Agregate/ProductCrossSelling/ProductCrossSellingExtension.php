<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Product\Agregate\ProductCrossSelling;

use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ProductCrossSellingExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            new OneToOneAssociationField('topdataExtension', 'id', 'product_cross_selling_id', TopdataProductCrossSellingExtensionDefinition::class, false)
        );
    }

    // sw6.6
    public function getDefinitionClass(): string
    {
        return ProductCrossSellingDefinition::class;
    }

    // sw6.7
    public function getEntityName(): string
    {
        return ProductCrossSellingDefinition::ENTITY_NAME;
    }
}
