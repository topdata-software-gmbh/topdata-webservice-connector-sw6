<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Brand;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class BrandDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'topdata_brand';

    
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }
    
    
    public function getEntityClass(): string
    {
        return BrandEntity::class;
    }
    
    
    public function getCollectionClass(): string
    {
        return BrandCollection::class;
    }

    
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new StringField('code', 'code'))->addFlags(new Required()),
            (new StringField('label', 'name'))->addFlags(new Required()),
            (new BoolField('is_enabled', 'enabled'))->addFlags(new Required()),
            (new IntField('sort', 'sort'))->addFlags(new Required()),
            (new IntField('ws_id', 'wsId'))->addFlags(new Required()),
            
            
        ]);
    }
}