<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\Indexer\InheritanceIndexer;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
//use Shopware\Core\Framework\DataAbstractionLayer\Indexing\MessageQueue\IndexerMessageSender;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Topdata\TopdataControlCenterSW6\DependencyInjection\TopConfigServiceCompilerPass;

//use Shopware\Core\Framework\Plugin\Context\InstallContext;
//use Shopware\Core\Framework\Plugin\Context\UpdateContext;
//use Shopware\Core\Framework\Plugin\Context\ActivateContext;
//use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
//use Symfony\Component\DependencyInjection\ContainerBuilder;
//use Symfony\Component\Routing\RouteCollectionBuilder;

class TopdataConnectorSW6 extends Plugin
{
    const MAPPINGS = [];

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // ---- register the plugin in Topdata Configration Center's TopConfigService
        if(class_exists(TopConfigServiceCompilerPass::class)) {
            $container->addCompilerPass(new TopConfigServiceCompilerPass(__CLASS__, self::MAPPINGS));
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

    // ---- Drop plugin-related tables if they exist
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

    // ---- Fetch and store customer table fields
    $customerFields = [];
    $temp           = $connection->fetchAllAssociative('SHOW COLUMNS from `customer`');
    foreach ($temp as $field) {
        if (isset($field['Field'])) {
            $customerFields[$field['Field']] = $field['Field'];
        }
    }

    // ---- Drop `devices` field from `customer` table if it exists
    if (isset($customerFields['devices'])) {
        $connection->executeStatement('ALTER TABLE `customer` DROP `devices`');
    }

    // ---- Fetch and store product table fields
    $productFields = [];
    $temp          = $connection->fetchAllAssociative('SHOW COLUMNS from `product`');
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
}
