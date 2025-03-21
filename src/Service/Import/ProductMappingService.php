<?php

namespace Topdata\TopdataConnectorSW6\Service\Import;

use Doctrine\DBAL\Connection;
use Exception;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\MappingTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\Service\TopdataToProductHelperService;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Service\TopfeedOptionsHelperService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilFormatter;

/**
 * Service class for mapping products between Topdata and Shopware 6.
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
        private readonly Connection                    $connection,
        private readonly TopfeedOptionsHelperService   $optionsHelperService,
        private readonly TopdataToProductHelperService $topdataToProductHelperService,
        private readonly TopdataWebserviceClient       $topdataWebserviceClient,
        private readonly ShopwareProductService        $shopwareProductService,
    )
    {
    }

    /**
     * ==== MAIN ====
     *
     * This is executed if --mapping option is set.
     *
     * FIXME: `TRUNCATE topdata_to_product` should be solved in a better way
     *
     * Maps products based on the mapping type specified in the options.
     *
     * This method performs the following steps:
     * 1. Logs the start of the product mapping process.
     * 2. Truncates the `topdata_to_product` table to remove existing mappings.
     * 3. Determines the mapping type from the options and calls the appropriate mapping method.
     * 4. Returns `true` if the mapping process completes successfully.
     * 5. Catches any exceptions, logs the error, and returns `false`.
     */
    public function mapProducts(): void
    {
        CliLogger::info('ProductMappingService::mapProducts() - using mapping type: ' . $this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE));

        // FIXME: TRUNCATE topdata_to_product shoukd be solved in a better way (temp table? transaction?)
        $this->connection->executeStatement('TRUNCATE TABLE topdata_to_product');

        // ---- Determine mapping type and call appropriate method
        switch ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE)) {
            // ---- Mapping type: Product number as WS ID
            case MappingTypeConstants::PRODUCT_NUMBER_AS_WS_ID:
                $this->_mapProductNumberAsWsId();
                break;

            // ---- Mapping type: Distributor default, custom, or custom field
            case MappingTypeConstants::DISTRIBUTOR_DEFAULT:
            case MappingTypeConstants::DISTRIBUTOR_CUSTOM:
            case MappingTypeConstants::DISTRIBUTOR_CUSTOM_FIELD:
                $this->_mapDistributor();
                break;

            // ---- Mapping type: Default, custom, or custom field (default case)
            case MappingTypeConstants::DEFAULT:
            case MappingTypeConstants::CUSTOM:
            case MappingTypeConstants::CUSTOM_FIELD:
            default:
                $this->_mapDefault();
                break;
        }
    }

    /**
     * Maps products using the product number as the web service ID.
     *
     * This method retrieves product numbers and their corresponding IDs from the database,
     * then inserts the mapped data into the `topdata_to_product` table.
     */
    private function _mapProductNumberAsWsId(): void
    {
        $dataInsert = [];

        $artnos = self::_convertMultiArrayBinaryIdsToHex($this->shopwareProductService->getKeysByProductNumber());
        $currentDateTime = date('Y-m-d H:i:s');
        foreach ($artnos as $wsid => $prods) {
            foreach ($prods as $prodid) {
                if (ctype_digit((string)$wsid)) {
                    $dataInsert[] = '(' .
                        '0x' . Uuid::randomHex() . ',' .
                        "$wsid," .
                        "0x{$prodid['id']}," .
                        "0x{$prodid['version_id']}," .
                        "'$currentDateTime'" .
                        ')';
                }
                if (count($dataInsert) > self::BATCH_SIZE_TOPDATA_TO_PRODUCT) {
                    $this->connection->executeStatement('
                    INSERT INTO topdata_to_product 
                    (id, top_data_id, product_id, product_version_id, created_at) 
                    VALUES ' . implode(',', $dataInsert) . '
                ');
                    $dataInsert = [];
                    CliLogger::activity();
                }
            }
        }
        if (count($dataInsert)) {
            $this->connection->executeStatement('
                INSERT INTO topdata_to_product 
                (id, top_data_id, product_id, product_version_id, created_at) 
                VALUES ' . implode(',', $dataInsert) . '
            ');
            $dataInsert = [];
            CliLogger::activity();
        }
    }

    /**
     * Maps products using the distributor mapping strategy.
     *
     * This method handles the mapping of products based on distributor data. It fetches product data from the database,
     * processes it, and inserts the mapped data into the `topdata_to_product` repository. The mapping strategy is determined
     * by the options set in `OptionConstants`.
     *
     * @throws Exception if no products are found or if the web service does not return the expected number of pages
     */
    private function _mapDistributor(): void
    {
        $dataInsert = [];

        // ---- Determine the source of product numbers based on the mapping type
        if ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE) == MappingTypeConstants::DISTRIBUTOR_CUSTOM && $this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER) != '') {
            $artnos = self::_convertMultiArrayBinaryIdsToHex($this->shopwareProductService->getKeysByOptionValueUnique($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER)));
        } elseif ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE) == MappingTypeConstants::DISTRIBUTOR_CUSTOM_FIELD && $this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER) != '') {
            $artnos = $this->shopwareProductService->getKeysByCustomFieldUnique($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER));
        } else {
            $artnos = self::_convertMultiArrayBinaryIdsToHex($this->shopwareProductService->getKeysByProductNumber());
        }

        if (count($artnos) == 0) {
            throw new Exception('distributor mapping 0 products found');
        }

        $stored = 0;
        CliLogger::info(count($artnos) . ' products to check ...');

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
                                    $this->topdataToProductHelperService->insertMany($dataInsert);
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
            $this->topdataToProductHelperService->insertMany($dataInsert);
        }
        CliLogger::writeln("\n" . UtilFormatter::formatInteger($stored) . ' - stored topdata products');
        unset($artnos);
    }

    /**
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
    private function _mapDefault(): void
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

    /**
     * Processes EANs (European Article Numbers) by fetching data from the web service and mapping them to products.
     *
     * @param array $eanMap an associative array mapping EANs to product data
     * @param array &$this->setted A reference to an array that keeps track of already processed products
     * @param array &$dataInsert A reference to an array that accumulates data to be inserted into the repository
     *
     * @throws Exception if the web service does not return the expected number of pages
     */
    private function _processEANs(array $eanMap): void
    {
        $dataInsert = [];
        CliLogger::writeln('fetching EANs from Webservice...');
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
            CliLogger::writeln('fetched EANs page ' . $i . '/' . $available_pages);
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
     * @param array &$this->setted A reference to an array that keeps track of already processed products
     *
     * @throws Exception if the web service does not return the expected number of pages
     */
    private function _processOEMs(array $oemMap): void
    {
        $dataInsert = [];
        CliLogger::writeln('fetching OEMs from Webservice...');
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
            CliLogger::writeln('fetched OEMs page ' . $i . '/' . $available_pages);
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
     * @param array &$this->setted A reference to an array that keeps track of already processed products
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
            CliLogger::writeln('fetched PCDs page ' . $i . '/' . $available_pages);
            if ($i >= $available_pages) {
                break;
            }
        }
        $this->topdataToProductHelperService->insertMany($dataInsert);
        CliLogger::writeln("DONE. fetched " . UtilFormatter::formatInteger($total) . " PCDs from Webservice");
        ImportReport::setCounter('Fetched PCDs', $total);
    }


    private static function _fixArrayBinaryIds(array $arr): array
    {
        foreach ($arr as $key => $val) {
            if (isset($arr[$key]['id'])) {
                $arr[$key]['id'] = bin2hex($arr[$key]['id']);
            }
            if (isset($arr[$key]['version_id'])) {
                $arr[$key]['version_id'] = bin2hex($arr[$key]['version_id']);
            }
        }

        return $arr;
    }

    /**
     * Converts binary IDs in a multi-dimensional array to hexadecimal strings.
     *
     * This method iterates over a multi-dimensional array and converts the binary
     * 'id' and 'version_id' fields to their hexadecimal string representations.
     *
     * @param array $arr The input array containing binary IDs.
     * @return array The modified array with hexadecimal string IDs.
     */
    private static function _convertMultiArrayBinaryIdsToHex(array $arr): array
    {
        foreach ($arr as $no => $vals) {
            foreach ($vals as $key => $val) {
                if (isset($arr[$no][$key]['id'])) {
                    $arr[$no][$key]['id'] = bin2hex($arr[$no][$key]['id']);
                }
                if (isset($arr[$no][$key]['version_id'])) {
                    $arr[$no][$key]['version_id'] = bin2hex($arr[$no][$key]['version_id']);
                }
            }
        }

        return $arr;
    }


    /**
     * Retrieves the technical name of a custom field.
     * 03/2025 UNUSED
     * @param string $name The name of the custom field.
     * @return string|null The technical name of the custom field, or null if not found.
     */
    public function getCustomFieldTechnicalName(string $name): ?string
    {
        $rez = $this->connection
            ->prepare('SELECT name FROM custom_field'
                . ' WHERE config LIKE :term LIMIT 1');
        $rez->bindValue('term', '%":"' . $name . '"}%');
        $rez->execute();
        $result = $rez->fetchOne();

        return $result ?: null;
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
    public function _buildMaps(): array
    {
        $oems = [];
        $eans = [];

        // ---- Fetch product data based on mapping type configuration
        if ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE) == MappingTypeConstants::CUSTOM) {
            if ($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_OEM) != '') {
                $oems = self::_fixArrayBinaryIds(
                    $this->shopwareProductService->getKeysByOptionValue($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_OEM), 'manufacturer_number')
                );
            }
            if ($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_EAN) != '') {
                $eans = self::_fixArrayBinaryIds(
                    $this->shopwareProductService->getKeysByOptionValue($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_EAN), 'ean')
                );
            }
        } elseif ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE) == MappingTypeConstants::CUSTOM_FIELD) {
            if ($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_OEM) != '') {
                $oems = $this->shopwareProductService->getKeysByCustomFieldUnique($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_OEM), 'manufacturer_number');
            }
            if ($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_EAN) != '') {
                $eans = $this->shopwareProductService->getKeysByCustomFieldUnique($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_EAN), 'ean');
            }
        } else {
            $oems = self::_fixArrayBinaryIds($this->shopwareProductService->getKeysByMpn());
            $eans = self::_fixArrayBinaryIds($this->shopwareProductService->getKeysByEan());
        }

        CliLogger::info(count($oems) . ' OEMs found');
        CliLogger::info(count($eans) . ' EANs found');

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

}