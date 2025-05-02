<?php

namespace Topdata\TopdataConnectorSW6\Service\Import\MappingStrategy;


use Exception;
use Override;
use Topdata\TopdataConnectorSW6\Constants\MappingTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\MergedPluginConfigKeyConstants;
use Topdata\TopdataConnectorSW6\Service\Config\MergedPluginConfigHelperService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Service\Import\ShopwareProductService;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\UtilMappingHelper;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilFormatter;

/**
 * 03/2025 created (extracted from ProductMappingService)
 */
final class MappingStrategy_Distributor extends AbstractMappingStrategy
{

    const BATCH_SIZE = 500;

    public function __construct(
        private readonly MergedPluginConfigHelperService $mergedPluginConfigHelperService,
        private readonly TopdataToProductService         $topdataToProductService,
        private readonly TopdataWebserviceClient         $topdataWebserviceClient,
        private readonly ShopwareProductService          $shopwareProductService,
    )
    {
    }


    /**
     * ==== MAIN ====
     *
     * Maps products using the distributor mapping strategy.
     *
     * This method handles the mapping of products based on distributor data. It fetches product data from the database,
     * processes it, and inserts the mapped data into the `topdata_to_product` repository. The mapping strategy is determined
     * by the options set in `OptionConstants`.
     *
     * @throws Exception if no products are found or if the web service does not return the expected number of pages
     */
    // private function _mapDistributor(): void
    #[Override]
    public function map(): void
    {
        $dataInsert = [];

        // ---- Determine the source of product numbers based on the mapping type
        if ($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::MAPPING_TYPE) == MappingTypeConstants::DISTRIBUTOR_CUSTOM && $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_ORDERNUMBER) != '') {
            $artnos = UtilMappingHelper::convertMultiArrayBinaryIdsToHex($this->shopwareProductService->getKeysByOptionValueUnique($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_ORDERNUMBER)));
        } elseif ($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::MAPPING_TYPE) == MappingTypeConstants::DISTRIBUTOR_CUSTOM_FIELD && $this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_ORDERNUMBER) != '') {
            $artnos = $this->shopwareProductService->getKeysByCustomFieldUnique($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_ORDERNUMBER));
        } else {
            $artnos = UtilMappingHelper::convertMultiArrayBinaryIdsToHex($this->shopwareProductService->getKeysByProductNumber());
        }

        if (count($artnos) == 0) {
            throw new Exception('distributor mapping 0 products found');
        }

        $stored = 0;
        CliLogger::info(UtilFormatter::formatInteger(count($artnos)) . ' products to check ...');

        // ---- Iterate through the pages of distributor data from the web service
        for ($i = 1; ; $i++) {
            $all_artnr = $this->topdataWebserviceClient->matchMyDistributer(['page' => $i]);
            if (!isset($all_artnr->page->available_pages)) {
                throw new Exception('distributor webservice no pages');
            }
            $available_pages = $all_artnr->page->available_pages;

            // ---- Process each product in the current page
            foreach ($all_artnr->match as $prod) {
                foreach ($prod->distributors as $distri) {
                    //if((int)$s['distributor_id'] != (int)$distri->distributor_id)
                    //    continue;
                    foreach ($distri->artnrs as $artnr) {
                        $key = (string)$artnr;
                        if (isset($artnos[$key])) {
                            foreach ($artnos[$key] as $artnosValue) {
                                $stored++;
                                if (($stored % 50) == 0) {
                                    CliLogger::activity();
                                }
                                $dataInsert[] = [
                                    'topDataId'        => $prod->products_id,
                                    'productId'        => $artnosValue['id'],
                                    'productVersionId' => $artnosValue['version_id'],
                                ];
                                if (count($dataInsert) > self::BATCH_SIZE) {
                                    $this->topdataToProductService->insertMany($dataInsert);
                                    $dataInsert = [];
                                }
                            }
                        }
                    }
                }
            }

            CliLogger::activity("\ndistributor $i/$available_pages");
            CliLogger::mem();
            CliLogger::writeln('');

            if ($i >= $available_pages) {
                break;
            }
        }
        if (count($dataInsert) > 0) {
            $this->topdataToProductService->insertMany($dataInsert);
        }
        CliLogger::writeln("\n" . UtilFormatter::formatInteger($stored) . ' - stored topdata products');
        unset($artnos);
    }

}