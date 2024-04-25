<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Category;

use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Inherited;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Topdata\TopdataConnectorSW6\Core\Content\Category\TopdataCategoryExtension\TopdataCategoryExtensionDefinition;

class CategoryExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(
                'topdataCategoryExtension',
                'id',
                'category_id',
                TopdataCategoryExtensionDefinition::class,
                false
            ))->addFlags(new Inherited())
        );
    }

    public function getDefinitionClass(): string
    {
        return CategoryDefinition::class;
    }
}
