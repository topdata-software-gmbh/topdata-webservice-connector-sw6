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

//use Shopware\Core\Framework\Plugin\Context\InstallContext;
//use Shopware\Core\Framework\Plugin\Context\UpdateContext;
//use Shopware\Core\Framework\Plugin\Context\ActivateContext;
//use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
//use Symfony\Component\DependencyInjection\ContainerBuilder;
//use Symfony\Component\Routing\RouteCollectionBuilder;

class TopdataConnectorSW6 extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }

    public function uninstall(UninstallContext $context): void
    {
        parent::uninstall($context);

        if ($context->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);

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

        $customerFields = [];
        $temp = $connection->fetchAllAssociative('SHOW COLUMNS from `customer`');
        foreach ($temp as $field) {
            if (isset($field['Field'])) {
                $customerFields[$field['Field']] = $field['Field'];
            }
        }

        if (isset($customerFields['devices'])) {
            $connection->executeStatement('ALTER TABLE `customer` DROP `devices`');
        }

        $productFields = [];
        $temp = $connection->fetchAllAssociative('SHOW COLUMNS from `product`');
        foreach ($temp as $field) {
            if (isset($field['Field'])) {
                $productFields[$field['Field']] = $field['Field'];
            }
        }

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

        foreach ($productFieldsToDelete as $field) {
            if (isset($productFields[$field])) {
                $connection->executeStatement('ALTER TABLE `product`  DROP `' . $field . '`');
            }
        }
    }
}
