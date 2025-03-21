<?php

namespace Topdata\TopdataConnectorSW6\Service\Import;


use Doctrine\DBAL\Connection;

/**
 * basically just utility methods for fetching data from shopware's product table
 *
 * 03/2025 created (extracted from ProductMappingService)
 */
class ShopwareProductService
{


    public function __construct(
        private readonly Connection $connection,
    )
    {
    }

    /**
     * Retrieves product data based on the product number.
     *
     * 03/2025 renamed from getKeysByOrdernumber to _getKeysByProductNumber
     *
     * @return array An array of product data.
     */
    public function getKeysByProductNumber(): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'p.product_number',
            'p.id',
            'p.version_id'
        ])->from('product', 'p');

        $results = $query->execute()->fetchAllAssociative();
        $returnArray = [];
        foreach ($results as $res) {
            $returnArray[(string)$res['product_number']][] = [
                'id'         => $res['id'],
                'version_id' => $res['version_id'],
            ];
        }

        return $returnArray;
    }



    /**
     * Retrieves product data based on a property group option name.
     *
     * @param string $optionName The name of the property group option.
     * @param string $colName The name of the column to retrieve (default: 'name').
     * @return array An array of product data.
     */
    public function getKeysByOptionValue(string $optionName, string $colName = 'name'): array
    {
        $query = $this->connection->createQueryBuilder();

        //        $query->select(['val.value', 'det.id'])
        //            ->from('s_filter_articles', 'art')
        //            ->innerJoin('art', 's_articles_details','det', 'det.articleID = art.articleID')
        //            ->innerJoin('art', 's_filter_values','val', 'art.valueID = val.id')
        //            ->innerJoin('val', 's_filter_options', 'opt', 'opt.id = val.optionID')
        //            ->where('opt.name = :option')
        //            ->setParameter(':option', $optionName)
        //        ;

        $query->select(['pgot.name ' . $colName, 'p.id', 'p.version_id'])
            ->from('product', 'p')
            ->innerJoin('p', 'product_property', 'pp', '(pp.product_id = p.id) AND (pp.product_version_id = p.version_id)')
            ->innerJoin('pp', 'property_group_option_translation', 'pgot', 'pgot.property_group_option_id = pp.property_group_option_id')
            ->innerJoin('pp', 'property_group_option', 'pgo', 'pgo.id = pp.property_group_option_id')
            ->innerJoin('pgo', 'property_group_translation', 'pgt', 'pgt.property_group_id = pgo.property_group_id')
            ->where('pgt.name = :option')
            ->setParameter(':option', $optionName);
        //print_r($query->getSQL());die();
        $returnArray = $query->execute()->fetchAllAssociative();

        //        foreach ($returnArray as $key=>$val) {
        //            $returnArray[$key] = [
        //                $colName => $val[$colName],
        //                'id' => bin2hex($val['id']),
        //                'version_id' => bin2hex($val['version_id']),
        //            ];
        //        }
        return $returnArray;
    }


    /**
     * Retrieves product data based on a unique property group option name.
     *
     * @param string $optionName The name of the property group option.
     * @return array An array of product data.
     */
    public function getKeysByOptionValueUnique($optionName)
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['pgot.name', 'p.id', 'p.version_id'])
            ->from('product', 'p')
            ->innerJoin('p', 'product_property', 'pp', '(pp.product_id = p.id) AND (pp.product_version_id = p.version_id)')
            ->innerJoin('pp', 'property_group_option_translation', 'pgot', 'pgot.property_group_option_id = pp.property_group_option_id')
            ->innerJoin('pp', 'property_group_option', 'pgo', 'pgo.id = pp.property_group_option_id')
            ->innerJoin('pgo', 'property_group_translation', 'pgt', 'pgt.property_group_id = pgo.property_group_id')
            ->where('pgt.name = :option')
            ->setParameter(':option', $optionName);

        $results = $query->execute()->fetchAllAssociative();
        $returnArray = [];
        foreach ($results as $res) {
            $returnArray[(string)$res['name']][] = [
                'id'         => $res['id'],
                'version_id' => $res['version_id'],
            ];
        }

        return $returnArray;
    }


    /**
     * Retrieves product data based on the manufacturer number (MPN).
     * 03/2025 renamed from getKeysBySuppliernumber to getKeysByMpn
     * @return array An array of product data.
     */
    public function getKeysByMpn()
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['p.manufacturer_number', 'p.id', 'p.version_id'])
            ->from('product', 'p')
            ->where('(p.manufacturer_number != \'\') AND (p.manufacturer_number IS NOT NULL)');

        return $query->execute()->fetchAllAssociative();
    }

    
    /**
     * Retrieves product data based on the EAN.
     *
     * @return array An array of product data.
     */
    public function getKeysByEan()
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['p.ean', 'p.id', 'p.version_id'])
            ->from('product', 'p')
            ->where('(p.ean != \'\') AND (p.ean IS NOT NULL)');

        return $query->execute()->fetchAllAssociative();
    }



    /**
     * Retrieves product data based on a unique custom field value.
     *
     * @param string $technicalName The technical name of the custom field.
     * @param string|null $fieldName The name of the field (optional).
     * @return array An array of product data.
     */
    public function getKeysByCustomFieldUnique(string $technicalName, ?string $fieldName = null)
    {
        //$technicalName = $this->getCustomFieldTechnicalName($optionName);
        $rez = $this->connection
            ->prepare('SELECT '
                . ' custom_fields, '
                . ' LOWER(HEX(product_id)) as `id`, '
                . ' LOWER(HEX(product_version_id)) as version_id'
                . ' FROM product_translation ');
        $rez->execute();
        $results = $rez->fetchAllAssociative();
        $returnArray = [];
        foreach ($results as $val) {
            if (!$val['custom_fields']) {
                continue;
            }
            $cf = json_decode($val['custom_fields'], true);
            if (empty($cf[$technicalName])) {
                continue;
            }

            if (!empty($fieldName)) {
                $returnArray[] = [
                    $fieldName   => (string)$cf[$technicalName],
                    'id'         => $val['id'],
                    'version_id' => $val['version_id'],
                ];
            } else {
                $returnArray[(string)$cf[$technicalName]][] = [
                    'id'         => $val['id'],
                    'version_id' => $val['version_id'],
                ];
            }
        }

        return $returnArray;
    }



}