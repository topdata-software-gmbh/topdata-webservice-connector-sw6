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
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilMappingHelper;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilFormatter;

/**
 * Implements the default mapping strategy for products, using OEM and EAN numbers.
 * 03/2025 created (extracted from ProductMappingService)
 * 04/2025 renamed from MappingStrategy_Default to MappingStrategy_EanOem
 */
final class MappingStrategy_EanOem extends AbstractMappingStrategy
{
    const BATCH_SIZE = 500;

    private array $setted;

    public function __construct(
        private readonly MergedPluginConfigHelperService $mergedPluginConfigHelperService,
        private readonly TopdataToProductService         $topdataToProductHelperService,
        private readonly TopdataWebserviceClient         $topdataWebserviceClient,
        private readonly ShopwareProductService          $shopwareProductService,
    )
    {
    }

    /**
     * Builds mapping arrays for OEM and EAN numbers
     *
     * Creates two mapping arrays based on the configured mapping type (custom, custom field, or default).
     * Normalizes manufacturer numbers and EAN codes by removing leading zeros and formatting.
     *
     * TODO? make a DTO for the return array
     *
     * @return array[] Returns an array containing two maps:
     *                 [0] => array of OEM numbers mapped to product IDs and version IDs
     *                 [1] => array of EAN numbers mapped to product IDs and version IDs
     */
    private function _buildMaps(): array
    {
        $oems = [];
        $eans = [];

        // ---- Fetch product data based on mapping type configuration
        if ($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::MAPPING_TYPE) == MappingTypeConstants::CUSTOM) {
            if ($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_OEM) != '') {
                $oems = UtilMappingHelper::_fixArrayBinaryIds(
                    $this->shopwareProductService->getKeysByOptionValue($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_OEM), 'manufacturer_number')
                );
            }
            if ($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_EAN) != '') {
                $eans = UtilMappingHelper::_fixArrayBinaryIds(
                    $this->shopwareProductService->getKeysByOptionValue($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_EAN), 'ean')
                );
            }
        } elseif ($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::MAPPING_TYPE) == MappingTypeConstants::CUSTOM_FIELD) {
            if ($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_OEM) != '') {
                $oems = $this->shopwareProductService->getKeysByCustomFieldUnique($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_OEM), 'manufacturer_number');
            }
            if ($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_EAN) != '') {
                $eans = $this->shopwareProductService->getKeysByCustomFieldUnique($this->mergedPluginConfigHelperService->getOption(MergedPluginConfigKeyConstants::ATTRIBUTE_EAN), 'ean');
            }
        } else {
            $oems = UtilMappingHelper::_fixArrayBinaryIds($this->shopwareProductService->getKeysByMpn());
            $eans = UtilMappingHelper::_fixArrayBinaryIds($this->shopwareProductService->getKeysByEan());
        }

        CliLogger::info(UtilFormatter::formatInteger(count($oems)) . ' OEMs found');
        CliLogger::info(UtilFormatter::formatInteger(count($eans)) . ' EANs found');

        // ---- Build OEM number mapping
        $oemMap = [];
        foreach ($oems as $oem) {
            $oem['manufacturer_number'] = strtolower(ltrim(trim($oem['manufacturer_number']), '0'));
            $oemMap[(string)$oem['manufacturer_number']][$oem['id'] . '-' . $oem['version_id']] = [
                'id'         => $oem['id'],
                'version_id' => $oem['version_id'],
            ];
        }
        unset($oems);

        // ---- Build EAN number mapping
        $eanMap = [];
        foreach ($eans as $ean) {
            $ean['ean'] = preg_replace('/[^0-9]/', '', $ean['ean']);
            $ean['ean'] = ltrim(trim($ean['ean']), '0');
            $eanMap[(string)$ean['ean']][$ean['id'] . '-' . $ean['version_id']] = [
                'id'         => $ean['id'],
                'version_id' => $ean['version_id'],
            ];
        }
        unset($eans);

        return [$oemMap, $eanMap];
    }


    /**
     * Processes EANs (European Article Numbers) by fetching data from the web service and mapping them to products.
     *
     * @param array $eanMap an associative array mapping EANs to product data
     *
     * @throws Exception if the web service does not return the expected number of pages
     */
    private function _processEANs(array $eanMap): void
    {
        $dataInsert = [];
        CliLogger::title('fetching EANs from Webservice...');
        $total = 0;

        // ---- Iterate through the pages of EAN data from the web service
        for ($i = 1; ; $i++) {
            $response = $this->topdataWebserviceClient->matchMyEANs(['page' => $i]);
            $total += count($response->match);
            if (!isset($response->page->available_pages)) {
                throw new Exception('ean webservice no pages');
            }
            $available_pages = $response->page->available_pages;

            // ---- Process each product in the current page
            foreach ($response->match as $prod) {
                foreach ($prod->values as $ean) {
                    $ean = (string)$ean;
                    $ean = ltrim(trim($ean), '0');
                    if (isset($eanMap[$ean])) {
                        foreach ($eanMap[$ean] as $key => $product) {
                            if (isset($this->setted[$key])) {
                                continue;
                            }

                            $dataInsert[] = [
                                'topDataId'        => $prod->products_id,
                                'productId'        => $product['id'],
                                'productVersionId' => $product['version_id'],
                            ];
                            if (count($dataInsert) > self::BATCH_SIZE) {
                                $this->topdataToProductHelperService->insertMany($dataInsert);
                                $dataInsert = [];
                            }
                            $this->setted[$key] = true;
                        }
                    }
                }
            }
            // CliLogger::writeln('fetched EANs page ' . $i . '/' . $available_pages);
            CliLogger::progress($i, $available_pages, 'fetched EANs page');
            if ($i >= $available_pages) {
                break;
            }
        }
        $this->topdataToProductHelperService->insertMany($dataInsert);
        CliLogger::writeln("DONE. fetched " . UtilFormatter::formatInteger($total) . " EANs from Webservice");
        ImportReport::setCounter('Fetched EANs', $total);
    }

    /**
     * Processes OEMs (Original Equipment Manufacturer numbers) by fetching data from the web service and mapping them to products.
     *
     * @param array $oemMap an associative array mapping OEMs to product data
     *
     * @throws Exception if the web service does not return the expected number of pages
     */
    private function _processOEMs(array $oemMap): void
    {
        $dataInsert = [];
        CliLogger::title('fetching OEMs from Webservice...');
        $total = 0;

        // ---- Iterate through the pages of OEM data from the web service
        for ($i = 1; ; $i++) {
            $all_artnr = $this->topdataWebserviceClient->matchMyOems(['page' => $i]);
            $total += count($all_artnr->match);
            if (!isset($all_artnr->page->available_pages)) {
                throw new Exception('oem webservice no pages');
            }
            $available_pages = $all_artnr->page->available_pages;

            // ---- Process each product in the current page
            foreach ($all_artnr->match as $prod) {
                foreach ($prod->values as $oem) {
                    $oem = (string)$oem;
                    $oem = strtolower($oem);
                    if (isset($oemMap[$oem])) {
                        foreach ($oemMap[$oem] as $key => $product) {
                            if (isset($this->setted[$key])) {
                                continue;
                            }
                            $dataInsert[] = [
                                'topDataId'        => $prod->products_id,
                                'productId'        => $product['id'],
                                'productVersionId' => $product['version_id'],
                            ];
                            if (count($dataInsert) > self::BATCH_SIZE) {
                                $this->topdataToProductHelperService->insertMany($dataInsert);
                                $dataInsert = [];
                            }

                            $this->setted[$key] = true;
                        }
                    }
                }
            }
            // CliLogger::writeln('fetched OEMs page ' . $i . '/' . $available_pages);
            CliLogger::progress($i, $available_pages, "fetched OEMs page");
            if ($i >= $available_pages) {
                break;
            }
        }
        $this->topdataToProductHelperService->insertMany($dataInsert);
        CliLogger::writeln("DONE. fetched " . UtilFormatter::formatInteger($total) . " OEMs from Webservice");
        ImportReport::setCounter('Fetched OEMs', $total);
    }

    /**
     * Processes PCDs (Product Category Descriptions) by fetching data from the web service and mapping them to products.
     *
     * @param array $oemMap an associative array mapping OEMs to product data
     *
     * @throws Exception if the web service does not return the expected number of pages
     */
    private function _processPCDs(array $oemMap): void
    {
        $dataInsert = [];
        $total = 0;

        // ---- Iterate through the pages of PCD data from the web service
        for ($i = 1; ; $i++) {
            $all_artnr = $this->topdataWebserviceClient->matchMyPcds(['page' => $i]);
            $total += count($all_artnr->match);
            if (!isset($all_artnr->page->available_pages)) {
                throw new Exception('pcd webservice no pages');
            }
            $available_pages = $all_artnr->page->available_pages;

            // ---- Process each product in the current page
            foreach ($all_artnr->match as $prod) {
                foreach ($prod->values as $oem) {
                    $oem = (string)$oem;
                    $oem = strtolower($oem);
                    if (isset($oemMap[$oem])) {
                        foreach ($oemMap[$oem] as $key => $product) {
                            if (isset($this->setted[$key])) {
                                continue;
                            }
                            $dataInsert[] = [
                                'topDataId'        => $prod->products_id,
                                'productId'        => $product['id'],
                                'productVersionId' => $product['version_id'],
                            ];
                            if (count($dataInsert) > self::BATCH_SIZE) {
                                $this->topdataToProductHelperService->insertMany($dataInsert);
                                $dataInsert = [];
                            }

                            $this->setted[$key] = true;
                        }
                    }
                }
            }
            // CliLogger::writeln('fetched PCDs page ' . $i . '/' . $available_pages);
            CliLogger::progress($i, $available_pages, 'fetched PCDs page');
            if ($i >= $available_pages) {
                break;
            }
        }
        $this->topdataToProductHelperService->insertMany($dataInsert);
        CliLogger::writeln("DONE. fetched " . UtilFormatter::formatInteger($total) . " PCDs from Webservice");
        ImportReport::setCounter('Fetched PCDs', $total);
    }



    /**
     * ==== MAIN ====
     *
     * Maps products using the default mapping strategy.
     *
     * This method handles the mapping of products based on different criteria such as OEM (Original Equipment Manufacturer) numbers
     * and EAN (European Article Numbers). It supports different mapping types defined in `MappingTypeConstants` and uses options
     * from `OptionConstants` to determine the mapping strategy.
     *
     * The method fetches product data from the database, processes it, and inserts the mapped data into the `topdata_to_product` repository.
     *
     * @throws Exception if any error occurs during the mapping process
     */
    // private function _mapDefault(): void
    #[Override]
    /**
     * Executes the default mapping strategy to link Topdata products to Shopware products.
     *
     * This method orchestrates the process of building maps for OEM and EAN numbers,
     * then processes these maps to establish the links between Topdata and Shopware products.
     *
     * @throws Exception if any error occurs during the mapping process.
     */
    public function map(): void
    {
        CliLogger::section('ProductMappingService::mapDefault()');

        [$oemMap, $eanMap] = $this->_buildMaps();

        $this->setted = [];
        // ---- Process EAN mappings
        if (count($eanMap) > 0) {
            $this->_processEANs($eanMap);
        }

        // ---- Process OEM and PCD mappings
        if (count($oemMap) > 0) {
            $this->_processOEMs($oemMap);
            $this->_processPCDs($oemMap);
        }

        unset($this->setted);
        unset($oemMap);
        unset($eanMap);
    }

}