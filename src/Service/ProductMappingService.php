<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\MappingTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataFoundationSW6\Helper\CliStyle;
use Topdata\TopdataConnectorSW6\Helper\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\ImportReport;

/**
 * 10/2024 created (extracted from MappingHelperService).
 */
class ProductMappingService
{
    private bool $verbose;
    private Context $context;
    private CliStyle $cliStyle;
    private TopdataWebserviceClient $topdataWebserviceClient;

    public function __construct(
        private readonly LoggerInterface        $logger,
        private readonly Connection             $connection,
        private readonly EntityRepository       $topdataToProductRepository,
        private readonly OptionsHelperService   $optionsHelperService,
        private readonly ProgressLoggingService $progressLoggingService,
    )
    {
        $this->context = Context::createDefaultContext();
    }

    public function mapProducts(): bool
    {
        $this->cliStyle->info('ProductMappingService::mapProducts() - using mapping type: ' . $this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE));

        try {
            $this->connection->executeStatement('
                TRUNCATE TABLE topdata_to_product;
            ');
            switch ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE)) {
                case MappingTypeConstants::PRODUCT_NUMBER_AS_WS_ID:
                    $this->mapProductNumberAsWsId();
                    break;

                case MappingTypeConstants::DISTRIBUTOR_DEFAULT:
                case MappingTypeConstants::DISTRIBUTOR_CUSTOM:
                case MappingTypeConstants::DISTRIBUTOR_CUSTOM_FIELD:
                    $this->mapDistributor();
                    break;

                case MappingTypeConstants::DEFAULT:
                case MappingTypeConstants::CUSTOM:
                case MappingTypeConstants::CUSTOM_FIELD:
                default:
                    $this->mapDefault();
                    break;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $this->progressLoggingService->writeln("\nException abgefangen: " . $e->getMessage() . '');
        }

        return false;
    }

    /**
     * TopID from the webservice is used as shopware product number
     */
    private function mapProductNumberAsWsId(): void
    {
        $dataInsert = [];

        $artnos = $this->_convertMultiArrayBinaryIdsToHex(
            $this->getKeysByOrdernumber()
        );
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
                if (count($dataInsert) > 99) {
                    $this->connection->executeStatement('
                    INSERT INTO topdata_to_product 
                    (id, top_data_id, product_id, product_version_id, created_at) 
                    VALUES ' . implode(',', $dataInsert) . '
                ');
                    $dataInsert = [];
                    $this->progressLoggingService->activity();
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
            $this->progressLoggingService->activity();
        }
    }

    private function mapDistributor(): void
    {
        $dataInsert = [];

        if ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE) == MappingTypeConstants::DISTRIBUTOR_CUSTOM && $this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER) != '') {
            $artnos = $this->_convertMultiArrayBinaryIdsToHex($this->getKeysByOptionValueUnique($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER)));
        } elseif ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE) == MappingTypeConstants::DISTRIBUTOR_CUSTOM_FIELD && $this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER) != '') {
            $artnos = $this->getKeysByCustomFieldUnique($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_ORDERNUMBER));
        } else {
            $artnos = $this->_convertMultiArrayBinaryIdsToHex($this->getKeysByOrdernumber());
        }

        if (count($artnos) == 0) {
            throw new \Exception('distributor mapping 0 products found');
        }

        $stored = 0;
        $this->cliStyle->info(count($artnos) . ' products to check ...');
        for ($i = 1; ; $i++) {
            $all_artnr = $this->topdataWebserviceClient->matchMyDistributer(['page' => $i]);
            if (!isset($all_artnr->page->available_pages)) {
                throw new \Exception('distributor webservice no pages');
            }
            $available_pages = $all_artnr->page->available_pages;
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
                                    $this->progressLoggingService->activity();
                                }
                                $dataInsert[] = [
                                    'topDataId'        => $prod->products_id,
                                    'productId'        => $artnosValue['id'],
                                    'productVersionId' => $artnosValue['version_id'],
                                ];
                                if (count($dataInsert) > 500) {
                                    $this->topdataToProductRepository->create($dataInsert, $this->context);
                                    $dataInsert = [];
                                }
                            }
                        }
                    }
                }
            }

            $this->progressLoggingService->activity("\ndistributor $i/$available_pages");
            $this->progressLoggingService->mem();
            $this->progressLoggingService->writeln('');

            if ($i >= $available_pages) {
                break;
            }
        }
        if (count($dataInsert) > 0) {
            $this->topdataToProductRepository->create($dataInsert, $this->context);
            $dataInsert = [];
        }
        $this->progressLoggingService->writeln("\n" . $stored . ' - stored topdata products');
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
     * @throws \Exception if any error occurs during the mapping process
     */
    private function mapDefault(): void
    {
        $this->cliStyle->section('ProductMappingService::mapDefault()');
        $dataInsert = [];

        $oems = [];
        $eans = [];
        // Determine the mapping type and fetch the corresponding product data
        if ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE) == MappingTypeConstants::CUSTOM) {
            if ($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_OEM) != '') {
                $oems = $this->fixArrayBinaryIds(
                    $this->getKeysByOptionValue($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_OEM), 'manufacturer_number')
                );
            }
            if ($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_EAN) != '') {
                $eans = $this->fixArrayBinaryIds(
                    $this->getKeysByOptionValue($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_EAN), 'ean')
                );
            }
        } elseif ($this->optionsHelperService->getOption(OptionConstants::MAPPING_TYPE) == MappingTypeConstants::CUSTOM_FIELD) {
            if ($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_OEM) != '') {
                $oems = $this->getKeysByCustomFieldUnique($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_OEM), 'manufacturer_number');
            }
            if ($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_EAN) != '') {
                $eans = $this->getKeysByCustomFieldUnique($this->optionsHelperService->getOption(OptionConstants::ATTRIBUTE_EAN), 'ean');
            }
        } else {
            $oems = $this->fixArrayBinaryIds($this->getKeysBySuppliernumber());
            $eans = $this->fixArrayBinaryIds($this->getKeysByEan());
        }

        // Map OEM numbers to product data
        $oemMap = [];
        foreach ($oems as $oem) {
            $oem['manufacturer_number'] = strtolower(ltrim(trim($oem['manufacturer_number']), '0'));
            $oemMap[(string)$oem['manufacturer_number']][$oem['id'] . '-' . $oem['version_id']] = [
                'id'         => $oem['id'],
                'version_id' => $oem['version_id'],
            ];
        }
        unset($oems);

        // Map EAN numbers to product data
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

        $setted = [];
        // Process EAN mappings
        if (count($eanMap) > 0) {
            $this->processEANs($eanMap, $setted, $dataInsert);
        }

        // Process OEM and PCD mappings
        if (count($oemMap) > 0) {
            $this->processOEMs($oemMap, $setted, $dataInsert);
            $this->processPCDs($oemMap, $setted, $dataInsert);
        }

        // Insert the mapped data into the repository
        if (count($dataInsert) > 0) {
            $this->topdataToProductRepository->create($dataInsert, $this->context);
            $dataInsert = [];
        }
        unset($setted);
        unset($oemMap);
        unset($eanMap);
    }

    /**
     * Processes EANs (European Article Numbers) by fetching data from the web service and mapping them to products.
     *
     * @param array $eanMap an associative array mapping EANs to product data
     * @param array &$setted A reference to an array that keeps track of already processed products
     * @param array &$dataInsert A reference to an array that accumulates data to be inserted into the repository
     *
     * @throws \Exception if the web service does not return the expected number of pages
     */
    private function processEANs(array $eanMap, array &$setted, array &$dataInsert): void
    {
        $this->progressLoggingService->writeln('fetching EANs from Webservice...');
        $total = 0;
        for ($i = 1; ; $i++) {
            $all_artnr = $this->topdataWebserviceClient->matchMyEANs(['page' => $i]);
            $total += count($all_artnr->match);
            if (!isset($all_artnr->page->available_pages)) {
                throw new \Exception('ean webservice no pages');
            }
            $available_pages = $all_artnr->page->available_pages;
            foreach ($all_artnr->match as $prod) {
                foreach ($prod->values as $ean) {
                    $ean = (string)$ean;
                    $ean = ltrim(trim($ean), '0');
                    if (isset($eanMap[$ean])) {
                        foreach ($eanMap[$ean] as $key => $product) {
                            if (isset($setted[$key])) {
                                continue;
                            }

                            $dataInsert[] = [
                                'topDataId'        => $prod->products_id,
                                'productId'        => $product['id'],
                                'productVersionId' => $product['version_id'],
                            ];
                            if (count($dataInsert) > 500) {
                                $this->topdataToProductRepository->create($dataInsert, $this->context);
                                $dataInsert = [];
                            }
                            $setted[$key] = true;
                        }
                    }
                }
            }
            $this->progressLoggingService->writeln('fetched EANs page ' . $i . '/' . $available_pages);
            if ($i >= $available_pages) {
                break;
            }
        }
        $this->progressLoggingService->writeln("DONE. fetched {$total} EANs from Webservice");
        ImportReport::setCounter('Fetched EANs', $total);
    }

    /**
     * Processes OEMs (Original Equipment Manufacturer numbers) by fetching data from the web service and mapping them to products.
     *
     * @param array $oemMap an associative array mapping OEMs to product data
     * @param array &$setted A reference to an array that keeps track of already processed products
     * @param array &$dataInsert A reference to an array that accumulates data to be inserted into the repository
     *
     * @throws \Exception if the web service does not return the expected number of pages
     */
    private function processOEMs(array $oemMap, array &$setted, array &$dataInsert): void
    {
        $this->progressLoggingService->writeln('fetching OEMs from Webservice...');
        $total = 0;
        for ($i = 1; ; $i++) {
            $all_artnr = $this->topdataWebserviceClient->matchMyOems(['page' => $i]);
            $total += count($all_artnr->match);
            if (!isset($all_artnr->page->available_pages)) {
                throw new \Exception('oem webservice no pages');
            }
            $available_pages = $all_artnr->page->available_pages;
            foreach ($all_artnr->match as $prod) {
                foreach ($prod->values as $oem) {
                    $oem = (string)$oem;
                    $oem = strtolower($oem);
                    if (isset($oemMap[$oem])) {
                        foreach ($oemMap[$oem] as $key => $product) {
                            if (isset($setted[$key])) {
                                continue;
                            }
                            $dataInsert[] = [
                                'topDataId'        => $prod->products_id,
                                'productId'        => $product['id'],
                                'productVersionId' => $product['version_id'],
                            ];
                            if (count($dataInsert) > 500) {
                                $this->topdataToProductRepository->create($dataInsert, $this->context);
                                $dataInsert = [];
                            }

                            $setted[$key] = true;
                        }
                    }
                }
            }
            $this->progressLoggingService->writeln('fetched OEMs page ' . $i . '/' . $available_pages);
            if ($i >= $available_pages) {
                break;
            }
        }
        $this->progressLoggingService->writeln("DONE. fetched {$total} OEMs from Webservice");
        ImportReport::setCounter('Fetched OEMs', $total);
    }

    /**
     * Processes PCDs (Product Category Descriptions) by fetching data from the web service and mapping them to products.
     *
     * @param array $oemMap an associative array mapping OEMs to product data
     * @param array &$setted A reference to an array that keeps track of already processed products
     * @param array &$dataInsert A reference to an array that accumulates data to be inserted into the repository
     *
     * @throws \Exception if the web service does not return the expected number of pages
     */
    private function processPCDs(array $oemMap, array &$setted, array &$dataInsert): void
    {
        $total = 0;
        for ($i = 1; ; $i++) {
            $all_artnr = $this->topdataWebserviceClient->matchMyPcds(['page' => $i]);
            $total += count($all_artnr->match);
            if (!isset($all_artnr->page->available_pages)) {
                throw new \Exception('pcd webservice no pages');
            }
            $available_pages = $all_artnr->page->available_pages;
            foreach ($all_artnr->match as $prod) {
                foreach ($prod->values as $oem) {
                    $oem = (string)$oem;
                    $oem = strtolower($oem);
                    if (isset($oemMap[$oem])) {
                        foreach ($oemMap[$oem] as $key => $product) {
                            if (isset($setted[$key])) {
                                continue;
                            }
                            $dataInsert[] = [
                                'topDataId'        => $prod->products_id,
                                'productId'        => $product['id'],
                                'productVersionId' => $product['version_id'],
                            ];
                            if (count($dataInsert) > 500) {
                                $this->topdataToProductRepository->create($dataInsert, $this->context);
                                $dataInsert = [];
                            }

                            $setted[$key] = true;
                        }
                    }
                }
            }
            $this->progressLoggingService->writeln('fetched PCDs page ' . $i . '/' . $available_pages);
            if ($i >= $available_pages) {
                break;
            }
        }
        $this->progressLoggingService->writeln("DONE. fetched {$total} PCDs from Webservice");
        ImportReport::setCounter('Fetched PCDs', $total);
    }

    private function getKeysByOptionValue(string $optionName, string $colName = 'name'): array
    {
        $query = $this->connection->createQueryBuilder();

        //        $query->select(['val.value', 'det.id'])
        //            ->from('s_filter_articles', 'art')
        //            ->innerJoin('art', 's_articles_details','det', 'det.articleID = art.articleID')
        //            ->innerJoin('art', 's_filter_values','val', 'art.valueID = val.id')
        //            ->innerJoin('val', 's_filter_options', 'opt', 'opt.id = val.optionID')
        //            ->where('opt.name = :option')
        //            ->setParameter(':option', $optionName)
        //        ;

        $query->select(['pgot.name ' . $colName, 'p.id', 'p.version_id'])
            ->from('product', 'p')
            ->innerJoin('p', 'product_property', 'pp', '(pp.product_id = p.id) AND (pp.product_version_id = p.version_id)')
            ->innerJoin('pp', 'property_group_option_translation', 'pgot', 'pgot.property_group_option_id = pp.property_group_option_id')
            ->innerJoin('pp', 'property_group_option', 'pgo', 'pgo.id = pp.property_group_option_id')
            ->innerJoin('pgo', 'property_group_translation', 'pgt', 'pgt.property_group_id = pgo.property_group_id')
            ->where('pgt.name = :option')
            ->setParameter(':option', $optionName);
        //print_r($query->getSQL());die();
        $returnArray = $query->execute()->fetchAllAssociative();

        //        foreach ($returnArray as $key=>$val) {
        //            $returnArray[$key] = [
        //                $colName => $val[$colName],
        //                'id' => bin2hex($val['id']),
        //                'version_id' => bin2hex($val['version_id']),
        //            ];
        //        }
        return $returnArray;
    }

    private function fixArrayBinaryIds(array $arr): array
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
    private function _convertMultiArrayBinaryIdsToHex(array $arr): array
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
     * FIXME? ordernumber?
     */
    private function getKeysByOrdernumber(): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['p.product_number', 'p.id', 'p.version_id'])
            ->from('product', 'p');

        $results = $query->execute()->fetchAllAssociative();
        $returnArray = [];
        foreach ($results as $res) {
            $returnArray[(string)$res['product_number']][] = [
                'id'         => $res['id'],
                'version_id' => $res['version_id'],
            ];
        }

        return $returnArray;
    }

    private function getKeysByOptionValueUnique($optionName)
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['pgot.name', 'p.id', 'p.version_id'])
            ->from('product', 'p')
            ->innerJoin('p', 'product_property', 'pp', '(pp.product_id = p.id) AND (pp.product_version_id = p.version_id)')
            ->innerJoin('pp', 'property_group_option_translation', 'pgot', 'pgot.property_group_option_id = pp.property_group_option_id')
            ->innerJoin('pp', 'property_group_option', 'pgo', 'pgo.id = pp.property_group_option_id')
            ->innerJoin('pgo', 'property_group_translation', 'pgt', 'pgt.property_group_id = pgo.property_group_id')
            ->where('pgt.name = :option')
            ->setParameter(':option', $optionName);

        $results = $query->execute()->fetchAllAssociative();
        $returnArray = [];
        foreach ($results as $res) {
            $returnArray[(string)$res['name']][] = [
                'id'         => $res['id'],
                'version_id' => $res['version_id'],
            ];
        }

        return $returnArray;
    }

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

    public function getKeysByCustomFieldUnique(string $technicalName, ?string $fieldName = null)
    {
        //$technicalName = $this->getCustomFieldTechnicalName($optionName);
        $rez = $this->connection
            ->prepare('SELECT '
                . ' custom_fields, '
                . ' LOWER(HEX(product_id)) as `id`, '
                . ' LOWER(HEX(product_version_id)) as version_id'
                . ' FROM product_translation ');
        $rez->execute();
        $results = $rez->fetchAllAssociative();
        $returnArray = [];
        foreach ($results as $val) {
            if (!$val['custom_fields']) {
                continue;
            }
            $cf = json_decode($val['custom_fields'], true);
            if (empty($cf[$technicalName])) {
                continue;
            }

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

    /**
     * Gets product id and variant_id by MANUFACTURER NUMBER.
     */
    private function getKeysBySuppliernumber()
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['p.manufacturer_number', 'p.id', 'p.version_id'])
            ->from('product', 'p')
            ->where('(p.manufacturer_number != \'\') AND (p.manufacturer_number IS NOT NULL)');

        return $query->execute()->fetchAllAssociative();
    }

    private function getKeysByEan()
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['p.ean', 'p.id', 'p.version_id'])
            ->from('product', 'p')
            ->where('(p.ean != \'\') AND (p.ean IS NOT NULL)');

        return $query->execute()->fetchAllAssociative();
    }

    public function setCliStyle(CliStyle $cliStyle): void
    {
        $this->cliStyle = $cliStyle;
    }

    public function setTopdataWebserviceClient(TopdataWebserviceClient $topdataWebserviceClient): void
    {
        $this->topdataWebserviceClient = $topdataWebserviceClient;
    }
}
