<?php

namespace Topdata\TopdataConnectorSW6\Service\Linking;

use Doctrine\DBAL\Connection;
use Exception;
use Psr\Log\LoggerInterface;
use Topdata\TopdataConnectorSW6\Constants\BatchSizeConstants;
use Topdata\TopdataConnectorSW6\Constants\WebserviceFilterTypeConstants;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataDeviceService;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * 04/2025 created (extracted from MappingHelperService)
 */
class ProductDeviceRelationshipServiceV1
{

    const CHUNK_SIZE = 100;

    public function __construct(
        private readonly Connection              $connection,
        private readonly TopdataToProductService $topdataToProductHelperService,
        private readonly TopdataDeviceService    $topdataDeviceService,
        private readonly TopdataWebserviceClient $topdataWebserviceClient,
    )
    {
    }


    /**
     * ==== MAIN ====
     *
     * The setProducts() method in the MappingHelperService class is responsible for linking devices to products. Here's a step-by-step breakdown of what it does:
     * It starts by disabling all devices, brands, series, and types in the database. This is done by setting the is_enabled field to 0 for each of these entities.
     * It unlinks all products by deleting all entries in the topdata_device_to_product table.
     * It retrieves all the product IDs from the topid_products array.
     * It then chunks these product IDs into groups of 100 and for each chunk, it does the following:
     * It makes a call to the remote server to get product data for the current chunk of product IDs.
     * It processes the returned product data. For each product, if it has associated devices, it adds these devices to the deviceWS array.
     * It then gets the device data for all the devices in the deviceWS array from the database.
     * For each device, it checks if the device's brand, series, and type are enabled. If not, it adds them to the respective arrays (enabledBrands, enabledSeries, enabledTypes).
     * It then checks if the device has associated products in the deviceWS array. If it does, it prepares data for inserting these associations into the topdata_device_to_product table.
     * It then inserts these associations into the topdata_device_to_product table in chunks of 30.
     * After all the associations have been inserted, it enables all the brands, series, and types that were added to the enabledBrands, enabledSeries, and enabledTypes arrays.
     * Finally, it returns true if everything went well, or false if an exception was thrown at any point.
     * This method is part of a larger process of syncing product and device data between a local database and a remote server. It ensures that the local database has up-to-date associations between products and the devices they are compatible with.
     *
     * 04/2025 moved from MappingHelperService::setProducts() to ProductDeviceRelationshipService::syncDeviceProductRelationships()
     */
    public function syncDeviceProductRelationshipsV1(): void
    {
        UtilProfiling::startTimer();

        CliLogger::getCliStyle()->yellow('Devices to products linking begin');
        CliLogger::getCliStyle()->yellow('Disabling all devices, brands, series and types, unlinking products, caching products...');
        CliLogger::lap(true);

        // ---- disable all brands
        $cntA = $this->connection->createQueryBuilder()
            ->update('topdata_brand')
            ->set('is_enabled', '0')
            ->executeStatement();

        // ---- disable all devices
        $cntB = $this->connection->createQueryBuilder()
            ->update('topdata_device')
            ->set('is_enabled', '0')
            ->executeStatement();

        // ---- disable all series
        $cntC = $this->connection->createQueryBuilder()
            ->update('topdata_series')
            ->set('is_enabled', '0')
            ->executeStatement();

        // ---- disable all device types
        $cntD = $this->connection->createQueryBuilder()
            ->update('topdata_device_type')
            ->set('is_enabled', '0')
            ->executeStatement();


        // ---- delete all device-to-product relations
        $cntE = $this->connection->createQueryBuilder()
            ->delete('topdata_device_to_product')
            ->executeStatement();

        // ---- just info
        CliLogger::getCliStyle()->dumpDict([
            'disabled brands '            => $cntA,
            'disabled devices '           => $cntB,
            'disabled series '            => $cntC,
            'disabled device types '      => $cntD,
            'unlinked device-to-product ' => $cntE,
        ]);

        $topidProducts = $this->topdataToProductHelperService->getTopdataProductMappings();

        CliLogger::activity(CliLogger::lap() . "sec\n");
        $enabledBrands = [];
        $enabledSeries = [];
        $enabledTypes = [];

        $topidsChunked = array_chunk(array_keys($topidProducts), self::CHUNK_SIZE);
        foreach ($topidsChunked as $idxChunk => $productIds) {

            // ---- fetch products from webservice
            CliLogger::writeln("Getting data from remote server part " . ($idxChunk + 1) . '/' . count($topidsChunked) . '...');
            $response = $this->topdataWebserviceClient->myProductList([
                'products' => implode(',', $productIds),
                'filter'   => WebserviceFilterTypeConstants::product_application_in,
            ]);
            CliLogger::activity(CliLogger::lap() . "sec\n");

            if (!isset($response->page->available_pages)) {
                throw new Exception($response->error[0]->error_message . 'webservice no pages');
            }
            CliLogger::mem();
            CliLogger::activity("\nProcessing data of " . count($response->products) . " products ...");
            $deviceWS = [];
            foreach ($response->products as $product) {
                if (!isset($topidProducts[$product->products_id])) {
                    continue;
                }
                if (isset($product->product_application_in->products) && count($product->product_application_in->products)) {
                    foreach ($product->product_application_in->products as $tid) {
                        foreach ($topidProducts[$product->products_id] as $tps) {
                            $deviceWS[$tid][] = $tps;
                        }
                    }
                }
            }

            //                $deviceWS = [
            //                    123 = [
            //                        ['product_id' = 00ffcc, 'product_version_id' = 00ffc2],
            //                        ['product_id' = 00ffcc, 'product_version_id' = 00ffc2]
            //                    ],
            //                    1138 = [
            //                        ['product_id' = 00afcc, 'product_version_id' = 00afc2],
            //                        ['product_id' = 00bfcc, 'product_version_id' = 00bfc2]
            //                    ]
            //                ]

            /*
             * Important!
             * There could be many devices with same ws_id!!!
             */

            $deviceIdsToEnable = array_keys($deviceWS);
            $devices = $this->topdataDeviceService->getDeviceArrayByWsIdArray($deviceIdsToEnable);
            CliLogger::activity();
            if (!count($devices)) {
                continue;
            }

            $chunkedDeviceIdsToEnable = array_chunk($deviceIdsToEnable, BatchSizeConstants::ENABLE_DEVICES);
            foreach ($chunkedDeviceIdsToEnable as $chunk) {
                $sql = 'UPDATE topdata_device SET is_enabled = 1 WHERE (is_enabled = 0) AND (ws_id IN (' . implode(',', $chunk) . '))';
                $cnt = $this->connection->executeStatement($sql);
                CliLogger::getCliStyle()->blue("Enabled $cnt devices");
                // \Topdata\TopdataFoundationSW6\Util\CliLogger::activity();
            }

            /* device_id, product_id, product_version_id, created_at */
            $insertData = [];
            $createdAt = date('Y-m-d H:i:s');

            foreach ($devices as $device) {
                if ($device['brand_id'] && !isset($enabledBrands[$device['brand_id']])) {
                    $enabledBrands[$device['brand_id']] = '0x' . $device['brand_id'];
                }

                if ($device['series_id'] && !isset($enabledSeries[$device['series_id']])) {
                    $enabledSeries[$device['series_id']] = '0x' . $device['series_id'];
                }

                if ($device['type_id'] && !isset($enabledTypes[$device['type_id']])) {
                    $enabledTypes[$device['type_id']] = '0x' . $device['type_id'];
                }

                if (isset($deviceWS[$device['ws_id']])) {
                    foreach ($deviceWS[$device['ws_id']] as $prod) {
                        $insertData[] = "(0x{$device['id']}, 0x{$prod['product_id']}, 0x{$prod['product_version_id']}, '$createdAt')";
                    }
                }
            }

            $insertDataChunks = array_chunk($insertData, 30);

            foreach ($insertDataChunks as $chunk) {
                $this->connection->executeStatement('
                        INSERT INTO topdata_device_to_product (device_id, product_id, product_version_id, created_at) VALUES ' . implode(',', $chunk) . '
                    ');
                CliLogger::activity();
            }

            CliLogger::activity(CliLogger::lap() . "sec\n");
            CliLogger::mem();
        }

        CliLogger::getCliStyle()->yellow('Activating brands, series and device types...');
        CliLogger::getCliStyle()->dumpDict([
            'enabledBrands' => count($enabledBrands),
            'enabledSeries' => count($enabledSeries),
            'enabledTypes'  => count($enabledTypes),

        ]);

        // ---- enable brands
        $ArraybrandIds = array_chunk($enabledBrands, BatchSizeConstants::ENABLE_BRANDS);
        foreach ($ArraybrandIds as $brandIds) {
            $cnt = $this->connection->executeStatement('
                    UPDATE topdata_brand SET is_enabled = 1 WHERE id IN (' . implode(',', $brandIds) . ')
                ');
            CliLogger::getCliStyle()->blue("Enabled $cnt brands");
            CliLogger::activity();
        }

        // ---- enable series
        $ArraySeriesIds = array_chunk($enabledSeries, BatchSizeConstants::ENABLE_SERIES);
        foreach ($ArraySeriesIds as $seriesIds) {
            $cnt = $this->connection->executeStatement('
                    UPDATE topdata_series SET is_enabled = 1 WHERE id IN (' . implode(',', $seriesIds) . ')
                ');
            CliLogger::getCliStyle()->blue("Enabled $cnt series");
            CliLogger::activity();
        }

        // ---- enable device types
        $ArrayTypeIds = array_chunk($enabledTypes, BatchSizeConstants::ENABLE_DEVICE_TYPES);
        foreach ($ArrayTypeIds as $typeIds) {
            $cnt = $this->connection->executeStatement('
                    UPDATE topdata_device_type SET is_enabled = 1 WHERE id IN (' . implode(',', $typeIds) . ')
                ');
            CliLogger::getCliStyle()->blue("Enabled $cnt types");
            CliLogger::activity();
        }
        CliLogger::activity(CliLogger::lap() . "sec\n");
        CliLogger::writeln('Devices to products linking done.');
        UtilProfiling::stopTimer();
    }


}
