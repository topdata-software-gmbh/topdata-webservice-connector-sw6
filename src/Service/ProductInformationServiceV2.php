<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\DescriptionImportTypeConstant;
use Topdata\TopdataConnectorSW6\Constants\WebserviceFilterTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\Exception\WebserviceResponseException;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Service\Linking\ProductProductRelationshipService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataConnectorSW6\Util\UtilStringFormatting;
use Topdata\TopdataFoundationSW6\Service\ManufacturerService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Service for updating product specifications and media.
 *
 * This service handles the retrieval and updating of product specifications and media
 * from an external source, integrating them into the Shopware 6 system. It includes
 * functionalities for fetching data, processing images, and linking related products.
 */
class ProductInformationServiceV2
{
    const CHUNK_SIZE   = 50;

    private Context $context;

    public function __construct(
        private readonly TopdataToProductService           $topdataToProductHelperService,
        private readonly MergedPluginConfigHelperService   $topfeedOptionsHelperService,
        private readonly ProductProductRelationshipService $productProductRelationshipService,
        private readonly EntityRepository                  $productRepository,
        private readonly TopdataWebserviceClient           $topdataWebserviceClient,
        private readonly ProductImportSettingsService      $productImportSettingsService,
        private readonly EntitiesHelperService             $entitiesHelperService,
        private readonly MediaHelperService                $mediaHelperService,
        private readonly LoggerInterface                   $logger,
        private readonly ManufacturerService               $manufacturerService,
        private readonly Connection                        $connection,
    )
    {
        $this->context = Context::createDefaultContext();
    }



    /**
     * 04/2025 created, WIP .. this version tries to be faster than V1
     */
    public function setProductInformationV2(): void
    {
        throw new \RuntimeException("Not implemented yet");
    }


}