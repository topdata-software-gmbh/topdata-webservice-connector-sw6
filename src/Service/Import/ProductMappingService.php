<?php

namespace Topdata\TopdataConnectorSW6\Service\Import;

use Doctrine\DBAL\Connection;
use Topdata\TopdataConnectorSW6\Constants\MappingTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\MergedPluginConfigKeyConstants;
use Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy\AbstractMappingStrategy;
use Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy\MappingStrategy_EanOem;
use Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy\MappingStrategy_Distributor;
use Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy\MappingStrategy_ProductNumberAs;
use Topdata\TopdataConnectorSW6\Service\MergedPluginConfigHelperService;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Service class for mapping products between Topdata and Shopware 6.
 * This service handles the process of mapping products from Topdata to Shopware 6,
 * utilizing different mapping strategies based on the configured mapping type.
 * 07/2024 created (extracted from MappingHelperService).
 */
class ProductMappingService
{

    const BATCH_SIZE                    = 500;
    const BATCH_SIZE_TOPDATA_TO_PRODUCT = 99;

    /**
     * @var array already processed products
     */
    private array $setted;

    public function __construct(
        private readonly Connection                      $connection,
        private readonly MergedPluginConfigHelperService $mergedPluginConfigHelperService,
//        private readonly TopdataToProductService        $topdataToProductHelperService,
//        private readonly TopdataWebserviceClient        $topdataWebserviceClient,
//        private readonly ShopwareProductService         $shopwareProductService,
        private readonly MappingStrategy_ProductNumberAs $mappingStrategy_ProductNumberAs,
        private readonly MappingStrategy_Distributor     $mappingStrategy_Distributor,
        private readonly MappingStrategy_EanOem          $mappingStrategy_Default,
    )
    {
    }

    /**
     * TODO: remove the "TRUNCATE TABLE topdata_to_product" ... find a better way
     *
     * Maps products from Topdata to Shopware 6 based on the configured mapping type.
     * This method truncates the `topdata_to_product` table and then executes the appropriate
     * mapping strategy.
     */
    public function mapProducts(): void
    {
        UtilProfiling::startTimer();
        CliLogger::info('ProductMappingService::mapProducts() - using mapping type: ' . $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::MAPPING_TYPE));

        // ---- Clear existing mappings
        $this->connection->executeStatement('TRUNCATE TABLE topdata_to_product');

        // ---- Create the appropriate strategy based on mapping type
        $strategy = $this->_createMappingStrategy();

        // ---- Execute the strategy
        $strategy->map();
        UtilProfiling::stopTimer();
    }

    /**
     * Creates the appropriate mapping strategy based on the configured mapping type.
     *
     * @return AbstractMappingStrategy The mapping strategy to use.
     * @throws \Exception If an unknown mapping type is encountered.
     */
    private function _createMappingStrategy(): AbstractMappingStrategy
    {
        $mappingType = $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::MAPPING_TYPE);

        // FIXME? 7 Mapping Types but only 3 Mapping Strategies..
        return match ($mappingType) {

            // ---- Product Number Mapping Strategy
            MappingTypeConstants::PRODUCT_NUMBER_AS_WS_ID  => $this->mappingStrategy_ProductNumberAs,

            // ---- Distributor Mapping Strategy
            MappingTypeConstants::DISTRIBUTOR_DEFAULT,
            MappingTypeConstants::DISTRIBUTOR_CUSTOM,
            MappingTypeConstants::DISTRIBUTOR_CUSTOM_FIELD => $this->mappingStrategy_Distributor,

            // ---- Default Mapping Strategy
            MappingTypeConstants::DEFAULT,
            MappingTypeConstants::CUSTOM,
            MappingTypeConstants::CUSTOM_FIELD             => $this->mappingStrategy_Default,

            // ---- unknown mapping type --> throw exception
            default                                        => throw new \Exception('Unknown mapping type: ' . $mappingType),
        };
    }

//    /**
//     * ==== MAIN ====
//     *
//     * This is executed if --mapping option is set.
//     *
//     * FIXME: `TRUNCATE topdata_to_product` should be solved in a better way
//     *
//     * Maps products based on the mapping type specified in the options.
//     *
//     * This method performs the following steps:
//     * 1. Logs the start of the product mapping process.
//     * 2. Truncates the `topdata_to_product` table to remove existing mappings.
//     * 3. Determines the mapping type from the options and calls the appropriate mapping method.
//     * 4. Returns `true` if the mapping process completes successfully.
//     * 5. Catches any exceptions, logs the error, and returns `false`.
//     */
//    public function mapProducts(): void
//    {
//        CliLogger::info('ProductMappingService::mapProducts() - using mapping type: ' . $this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE));
//
//        // FIXME: TRUNCATE topdata_to_product shoukd be solved in a better way (temp table? transaction?)
//        $this->connection->executeStatement('TRUNCATE TABLE topdata_to_product');
//
//        // ---- Determine mapping type and call appropriate method
//        switch ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE)) {
//            // ---- Mapping type: Product number as WS ID
//            case MappingTypeConstants::PRODUCT_NUMBER_AS_WS_ID:
//                $this->_mapProductNumberAsWsId();
//                break;
//
//            // ---- Mapping type: Distributor default, custom, or custom field
//            case MappingTypeConstants::DISTRIBUTOR_DEFAULT:
//            case MappingTypeConstants::DISTRIBUTOR_CUSTOM:
//            case MappingTypeConstants::DISTRIBUTOR_CUSTOM_FIELD:
//                $this->_mapDistributor();
//                break;
//
//            // ---- Mapping type: Default, custom, or custom field (default case)
//            case MappingTypeConstants::DEFAULT:
//            case MappingTypeConstants::CUSTOM:
//            case MappingTypeConstants::CUSTOM_FIELD:
//            default:
//                $this->_mapDefault();
//                break;
//        }
//    }
//
//    /**
//     * Maps products using the product number as the web service ID.
//     *
//     * This method retrieves product numbers and their corresponding IDs from the database,
//     * then inserts the mapped data into the `topdata_to_product` table.
//     */
//    private function _mapProductNumberAsWsId(): void
//    {
//        $dataInsert = [];
//
//        $artnos =UtilMappingHelper::_convertMultiArrayBinaryIdsToHex($this->shopwareProductService->getKeysByProductNumber());
//        $currentDateTime = date('Y-m-d H:i:s');
//        foreach ($artnos as $wsid => $prods) {
//            foreach ($prods as $prodid) {
//                if (ctype_digit((string)$wsid)) {
//                    $dataInsert[] = '(' .
//                        '0x' . Uuid::randomHex() . ',' .
//                        "$wsid," .
//                        "0x{$prodid['id']}," .
//                        "0x{$prodid['version_id']}," .
//                        "'$currentDateTime'" .
//                        ')';
//                }
//                if (count($dataInsert) > self::BATCH_SIZE_TOPDATA_TO_PRODUCT) {
//                    $this->connection->executeStatement('
//                    INSERT INTO topdata_to_product
//                    (id, top_data_id, product_id, product_version_id, created_at)
//                    VALUES ' . implode(',', $dataInsert) . '
//                ');
//                    $dataInsert = [];
//                    CliLogger::activity();
//                }
//            }
//        }
//        if (count($dataInsert)) {
//            $this->connection->executeStatement('
//                INSERT INTO topdata_to_product
//                (id, top_data_id, product_id, product_version_id, created_at)
//                VALUES ' . implode(',', $dataInsert) . '
//            ');
//            $dataInsert = [];
//            CliLogger::activity();
//        }
//    }

//    /**
//     * Maps products using the distributor mapping strategy.
//     *
//     * This method handles the mapping of products based on distributor data. It fetches product data from the database,
//     * processes it, and inserts the mapped data into the `topdata_to_product` repository. The mapping strategy is determined
//     * by the options set in `OptionConstants`.
//     *
//     * @throws Exception if no products are found or if the web service does not return the expected number of pages
//     */
//    private function _mapDistributor(): void
//    {
//        $dataInsert = [];
//
//        // ---- Determine the source of product numbers based on the mapping type
//        if ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE) == MappingTypeConstants::DISTRIBUTOR_CUSTOM && $this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER) != '') {
//            $artnos = UtilMappingHelper::convertMultiArrayBinaryIdsToHex($this->shopwareProductService->getKeysByOptionValueUnique($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER)));
//        } elseif ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE) == MappingTypeConstants::DISTRIBUTOR_CUSTOM_FIELD && $this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER) != '') {
//            $artnos = $this->shopwareProductService->getKeysByCustomFieldUnique($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER));
//        } else {
//            $artnos = UtilMappingHelper::convertMultiArrayBinaryIdsToHex($this->shopwareProductService->getKeysByProductNumber());
//        }
//
//        if (count($artnos) == 0) {
//            throw new Exception('distributor mapping 0 products found');
//        }
//
//        $stored = 0;
//        CliLogger::info(count($artnos) . ' products to check ...');
//
//        // ---- Iterate through the pages of distributor data from the web service
//        for ($i = 1; ; $i++) {
//            $all_artnr = $this->topdataWebserviceClient->matchMyDistributer(['page' => $i]);
//            if (!isset($all_artnr->page->available_pages)) {
//                throw new Exception('distributor webservice no pages');
//            }
//            $available_pages = $all_artnr->page->available_pages;
//
//            // ---- Process each product in the current page
//            foreach ($all_artnr->match as $prod) {
//                foreach ($prod->distributors as $distri) {
//                    //if((int)$s['distributor_id'] != (int)$distri->distributor_id)
//                    //    continue;
//                    foreach ($distri->artnrs as $artnr) {
//                        $key = (string)$artnr;
//                        if (isset($artnos[$key])) {
//                            foreach ($artnos[$key] as $artnosValue) {
//                                $stored++;
//                                if (($stored % 50) == 0) {
//                                    CliLogger::activity();
//                                }
//                                $dataInsert[] = [
//                                    'topDataId'        => $prod->products_id,
//                                    'productId'        => $artnosValue['id'],
//                                    'productVersionId' => $artnosValue['version_id'],
//                                ];
//                                if (count($dataInsert) > self::BATCH_SIZE) {
//                                    $this->topdataToProductHelperService->insertMany($dataInsert);
//                                    $dataInsert = [];
//                                }
//                            }
//                        }
//                    }
//                }
//            }
//
//            CliLogger::activity("\ndistributor $i/$available_pages");
//            CliLogger::mem();
//            CliLogger::writeln('');
//
//            if ($i >= $available_pages) {
//                break;
//            }
//        }
//        if (count($dataInsert) > 0) {
//            $this->topdataToProductHelperService->insertMany($dataInsert);
//        }
//        CliLogger::writeln("\n" . UtilFormatter::formatInteger($stored) . ' - stored topdata products');
//        unset($artnos);
//    }


//    /**
//     * Retrieves the technical name of a custom field.
//     *
//     * 03/2025 UNUSED
//     *
//     * @param string $name The name of the custom field.
//     * @return string|null The technical name of the custom field, or null if not found.
//     */
//    public function getCustomFieldTechnicalName(string $name): ?string
//    {
//        $rez = $this->connection
//            ->prepare('SELECT name FROM custom_field'
//                . ' WHERE config LIKE :term LIMIT 1');
//        $rez->bindValue('term', '%":"' . $name . '"}%');
//        $rez->execute();
//        $result = $rez->fetchOne();
//
//        return $result ?: null;
//    }


}