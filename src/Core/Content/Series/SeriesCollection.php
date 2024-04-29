<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Series;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void             add(SeriesEntity $entity)
 * @method void             set(string $key, SeriesEntity $entity)
 * @method SeriesEntity[]    getIterator()
 * @method SeriesEntity[]    getElements()
 * @method SeriesEntity|null get(string $key)
 * @method SeriesEntity|null first()
 * @method SeriesEntity|null last()
 */
class SeriesCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SeriesEntity::class;
    }
}