<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Topdata\TopdataFoundationSW6\DependencyInjection\TopConfigRegistryCompilerPass;


class TopdataConnectorSW6 extends Plugin
{
    const MAPPINGS = [
        'apiBaseUrl'     => 'topdataWebservice.baseUrl',
        'apiUid'         => 'topdataWebservice.credentials.uid',
        'apiPassword'    => 'topdataWebservice.credentials.password',
        'apiSecurityKey' => 'topdataWebservice.credentials.securityKey',
        'apiLanguage'    => 'topdataWebservice.language',
        'mappingType'    => 'import.mappingType',
    ];

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // ---- register the plugin in Topdata Configration Center's TopConfigRegistry
        if (class_exists(TopConfigRegistryCompilerPass::class)) {
            $container->addCompilerPass(new TopConfigRegistryCompilerPass(__CLASS__, self::MAPPINGS));
        }


    }

    /**
     * Uninstalls the plugin and removes all related database tables and fields.
     *
     * @param UninstallContext $context The context of the uninstallation process.
     */
    public function uninstall(UninstallContext $context): void
    {
        parent::uninstall($context);

        // ---- Check if user data should be kept
        if ($context->keepUserData()) {
            return;
        }

        // ---- Get the database connection
        $connection = $this->container->get(Connection::class);

        $this->_dropPluginRelatedTables($connection);
        $this->_removeColumnsFromCustomerTable($connection);
        $this->_removeColumnsFromProductTable($connection);

    }

    /**
     * 05/2025 TODO: I think this is not needed anymore (maybe some artefact from the sw5 version?)
     */
    private function _removeColumnsFromProductTable($connection): void
    {
        // ---- Fetch and store product table fields
        $productFields = [];
        $temp = $connection->fetchAllAssociative('SHOW COLUMNS from `product`');
        foreach ($temp as $field) {
            if (isset($field['Field'])) {
                $productFields[$field['Field']] = $field['Field'];
            }
        }

        // ---- List of product fields to delete
        $productFieldsToDelete = [
            'devices',
            'topdata',
            'alternate_products',
            'similar_products',
            'related_products',
            'bundled_products',
            'variant_products',
            'capacity_variant_products',
            'color_variant_products',
        ];

        // ---- Drop specified fields from `product` table if they exist
        foreach ($productFieldsToDelete as $field) {
            if (isset($productFields[$field])) {
                $connection->executeStatement('ALTER TABLE `product`  DROP `' . $field . '`');
            }
        }
    }

    /**
     * 05/2025 // TODO: probably not needed anymore (probably some artefact from the sw5 version?)
     */
    private function _removeColumnsFromCustomerTable($connection): void
    {
        // ---- Fetch and store customer table fields
        $customerFields = [];
        $temp = $connection->fetchAllAssociative('SHOW COLUMNS from `customer`');
        foreach ($temp as $field) {
            if (isset($field['Field'])) {
                $customerFields[$field['Field']] = $field['Field'];
            }
        }

        // ---- Drop `devices` field from `customer` table if it exists
        if (isset($customerFields['devices'])) {
            $connection->executeStatement('ALTER TABLE `customer` DROP `devices`');
        }
    }

    /**
     * Drop plugin-related tables if they exist
     *
     * 05/2025 created (extracted from uninstall)
     */
    private function _dropPluginRelatedTables($connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_brand`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_device`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_device_to_product`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_device_to_customer`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_device_to_synonym`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_device_type`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_series`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_to_product`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_product_to_alternate`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_product_to_similar`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_product_to_related`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_product_to_bundled`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_product_to_color_variant`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_product_to_capacity_variant`');
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_product_to_variant`');
    }
}
