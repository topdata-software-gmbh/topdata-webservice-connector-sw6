<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Product;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Inherited;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Topdata\TopdataConnectorSW6\Core\Content\Device\Agregate\DeviceProduct\DeviceProductDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Device\DeviceDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Product\Agregate\Alternate\ProductAlternateDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Product\Agregate\Bundled\ProductBundledDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Product\Agregate\CapacityVariant\ProductCapacityVariantDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Product\Agregate\ColorVariant\ProductColorVariantDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Product\Agregate\Related\ProductRelatedDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Product\Agregate\Similar\ProductSimilarDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Product\Agregate\Variant\ProductVariantDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\TopdataToProduct\TopdataToProductDefinition;

class ProductExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(
                'topdata',
                'id',
                'product_id',
                TopdataToProductDefinition::class,
                false
            ))->addFlags(new Inherited())
        );

        $collection->add(
            (new ManyToManyAssociationField(
                'alternate_products',
                SalesChannelProductDefinition::class,
                ProductAlternateDefinition::class,
                'product_id',
                'alternate_product_id'
            ))->addFlags(new Inherited())
        );

        $collection->add(
            (new ManyToManyAssociationField(
                'bundled_products',
                SalesChannelProductDefinition::class,
                ProductBundledDefinition::class,
                'product_id',
                'bundled_product_id'
            ))->addFlags(new Inherited())
        );

        $collection->add(
            (new ManyToManyAssociationField(
                'related_products',
                SalesChannelProductDefinition::class,
                ProductRelatedDefinition::class,
                'product_id',
                'related_product_id'
            ))->addFlags(new Inherited())
        );

        $collection->add(
            (new ManyToManyAssociationField(
                'similar_products',
                SalesChannelProductDefinition::class,
                ProductSimilarDefinition::class,
                'product_id',
                'similar_product_id'
            ))->addFlags(new Inherited())
        );

        $collection->add(
            (new ManyToManyAssociationField(
                'capacity_variant_products',
                SalesChannelProductDefinition::class,
                ProductCapacityVariantDefinition::class,
                'product_id',
                'capacity_variant_product_id'
            ))->addFlags(new Inherited())
        );

        $collection->add(
            (new ManyToManyAssociationField(
                'color_variant_products',
                SalesChannelProductDefinition::class,
                ProductColorVariantDefinition::class,
                'product_id',
                'color_variant_product_id'
            ))->addFlags(new Inherited())
        );

        $collection->add(
            (new ManyToManyAssociationField(
                'variant_products',
                SalesChannelProductDefinition::class,
                ProductVariantDefinition::class,
                'product_id',
                'variant_product_id'
            ))->addFlags(new Inherited())
        );

        $collection->add(
            (new ManyToManyAssociationField(
                'devices',
                DeviceDefinition::class,
                DeviceProductDefinition::class,
                'product_id',
                'device_id'
            ))->addFlags(new Inherited())
        );
    }

    public function getDefinitionClass(): string
    {
        return ProductDefinition::class;
    }
}
