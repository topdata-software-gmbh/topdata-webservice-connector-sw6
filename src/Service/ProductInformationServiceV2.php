<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Topdata\TopdataConnectorSW6\Service\Config\MergedPluginConfigHelperService;
use Topdata\TopdataConnectorSW6\Service\Config\ProductImportSettingsService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Service\Linking\ProductProductRelationshipService;
use Topdata\TopdataFoundationSW6\Service\ManufacturerService;

/**
 * Service for updating product specifications and media.
 *
 * This service handles the retrieval and updating of product specifications and media
 * from an external source, integrating them into the Shopware 6 system. It includes
 * functionalities for fetching data, processing images, and linking related products.
 */
class ProductInformationServiceV2
{
    /**
     * 04/2025 created, WIP .. this version tries to be faster than V1
     */
    public function setProductInformationV2(): void
    {
        throw new \RuntimeException("Not implemented yet");
    }


}