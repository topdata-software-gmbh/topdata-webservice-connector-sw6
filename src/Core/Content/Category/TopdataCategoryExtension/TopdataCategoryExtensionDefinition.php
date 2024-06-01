<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Category\TopdataCategoryExtension;

use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class TopdataCategoryExtensionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'topdata_category_extension';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return TopdataCategoryExtensionEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new FkField('category_id', 'categoryId', CategoryDefinition::class),
            new BoolField('plugin_settings', 'pluginSettings'),
            new JsonField('import_settings', 'importSettings'),

            new OneToOneAssociationField('category', 'category_id', 'id', CategoryDefinition::class, false),
        ]);
    }
}
