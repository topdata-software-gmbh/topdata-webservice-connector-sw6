<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Product;

use Shopware\Core\Content\Product\ProductDefinition as parentProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ProductDefinition extends parentProductDefinition
{
    public function getEntityClass(): string
    {
        return ProductEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        $fieldCollection = parent::defineFields();
        $fieldCollection->add(
            new IntField('top_data_id', 'topDataId')
        );

        return $fieldCollection;
    }
}
