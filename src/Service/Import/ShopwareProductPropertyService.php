<?php

namespace Topdata\TopdataConnectorSW6\Service\Import;


use Doctrine\DBAL\Connection;

/**
 * 05/2025 created (extracted from ShopwareProductService)
 */
class ShopwareProductPropertyService
{

    public function __construct(
        private readonly Connection $connection,
    )
    {
    }


    /**
     * Retrieves product data based on a property group option name.
     *
     * @param string $optionName The name of the property group option.
     * @param string $newColumnName the column "name" gets renamed to the given name
     * @return array An array of product data.
     */
    public function getKeysByOptionValue(string $optionName, string $newColumnName): array
    {
        $query = $this->connection->createQueryBuilder();

        // ---- Building the query to fetch product data
        $query->select([
            'pgot.name ' . $newColumnName,
            'p.id',
            'p.version_id'
        ])
            ->from('product', 'p')
            ->innerJoin('p', 'product_property', 'pp', '(pp.product_id = p.id) AND (pp.product_version_id = p.version_id)')
            ->innerJoin('pp', 'property_group_option_translation', 'pgot', 'pgot.property_group_option_id = pp.property_group_option_id')
            ->innerJoin('pp', 'property_group_option', 'pgo', 'pgo.id = pp.property_group_option_id')
            ->innerJoin('pgo', 'property_group_translation', 'pgt', 'pgt.property_group_id = pgo.property_group_id')
            ->where('pgt.name = :option')
            ->setParameter(':option', $optionName);

        return $query->execute()->fetchAllAssociative();
    }


    /**
     * Retrieves product data based on a unique property group option name.
     *
     * @param string $optionName The name of the property group option.
     * @return array An array of product data, where the key is the option name and the value is an array of product IDs and version IDs.
     */
    public function getKeysByOptionValueUnique(string $optionName): array
    {
//        $query = $this->connection->createQueryBuilder();
//        $query->select(['pgot.name', 'p.id', 'p.version_id'])
//            ->from('product', 'p')
//            ->innerJoin('p', 'product_property', 'pp', '(pp.product_id = p.id) AND (pp.product_version_id = p.version_id)')
//            ->innerJoin('pp', 'property_group_option_translation', 'pgot', 'pgot.property_group_option_id = pp.property_group_option_id')
//            ->innerJoin('pp', 'property_group_option', 'pgo', 'pgo.id = pp.property_group_option_id')
//            ->innerJoin('pgo', 'property_group_translation', 'pgt', 'pgt.property_group_id = pgo.property_group_id')
//            ->where('pgt.name = :option')
//            ->setParameter(':option', $optionName);
//
//        $results = $query->execute()->fetchAllAssociative();

        $results = $this->getKeysByOptionValue($optionName, 'name');
        $returnArray = [];
        foreach ($results as $res) {
            $returnArray[(string)$res['name']][] = [
                'id'         => $res['id'],
                'version_id' => $res['version_id'],
            ];
        }

        return $returnArray;
    }


}