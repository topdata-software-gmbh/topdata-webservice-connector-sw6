<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Device\Agregate\DeviceSynonym;

use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\MappingEntityDefinition;
use Topdata\TopdataConnectorSW6\Core\Content\Device\DeviceDefinition;

class DeviceSynonymDefinition extends MappingEntityDefinition
{
    public function getEntityName(): string
    {
        return 'topdata_device_to_synonym';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new FkField('device_id', 'deviceId', DeviceDefinition::class))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('synonym_id', 'synonymId', DeviceDefinition::class))->addFlags(new PrimaryKey(), new Required()),
            new ManyToOneAssociationField('device', 'device_id', DeviceDefinition::class),
            new ManyToOneAssociationField('synonym', 'synonym_id', DeviceDefinition::class),
            new CreatedAtField(),
        ]);
    }
}
