<?php

namespace Topdata\TopdataConnectorSW6\Service\Import;


use Doctrine\DBAL\Connection;

/**
 * Provides utility methods for fetching product data from Shopware 6.
 *
 * This service class encapsulates database queries to retrieve product information
 * based on various criteria such as product number, property group option,
 * manufacturer number (MPN), EAN, and custom field values.
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
     * @return array An array of product data, where the key is the product number and the value is an array of product IDs and version IDs.
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

        $ret = [];
        foreach ($results as $res) {
            $ret[(string)$res['product_number']][] = [
                'id'         => $res['id'],
                'version_id' => $res['version_id'],
            ];
        }

        return $ret;
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
        $rez = $this->connection->prepare('SELECT 
                 custom_fields, 
                 LOWER(HEX(product_id)) as `id`, 
                 LOWER(HEX(product_version_id)) as version_id
                 FROM product_translation 
        ');

        $results = $rez->execute()->fetchAllAssociative();
        $returnArray = [];

        // ---- Iterate through the results and extract custom field values
        foreach ($results as $val) {
            if (!$val['custom_fields']) {
                continue;
            }
            $cf = json_decode($val['custom_fields'], true);
            if (empty($cf[$technicalName])) {
                continue;
            }

            // ---- Build the return array based on whether a field name is provided
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