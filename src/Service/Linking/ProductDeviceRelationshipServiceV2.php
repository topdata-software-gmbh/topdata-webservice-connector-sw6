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
 * Implements a differential update approach for device-product relationships
 * that avoids disabling all entities upfront, maintaining data consistency.
 */
class ProductDeviceRelationshipServiceV2
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
     * Synchronizes device-product relationships using a differential update approach.
     * 
     * This method implements a more robust approach for synchronizing device-to-product
     * relationships that avoids disabling all entities upfront, maintaining data consistency.
     * 
     * Unlike the original method, this implementation:
     * - Tracks active entities during processing
     * - Only deletes links for specific product IDs being processed
     * - Enables/disables entities based on their actual usage
     */
    public function syncDeviceProductRelationshipsV2(): void
    {
        UtilProfiling::startTimer();
        CliLogger::getCliStyle()->yellow('Devices to products linking begin (V2 differential approach)');
        CliLogger::lap(true);

        // Fetch mapped products
        CliLogger::getCliStyle()->info('Fetching product mappings...');
        $topidProducts = $this->topdataToProductHelperService->getTopdataProductMappings();
        if (empty($topidProducts)) {
            CliLogger::getCliStyle()->warning('No mapped products found. Skipping device-product relationship sync.');
            return;
        }

        // Extract Shopware product database IDs from the mappings
        $shopwareProductDbIds = [];
        foreach ($topidProducts as $wsId => $products) {
            foreach ($products as $product) {
                $shopwareProductDbIds[] = $product['product_id'];
            }
        }
        $shopwareProductDbIds = array_unique($shopwareProductDbIds);
        CliLogger::getCliStyle()->info(sprintf('Found %d unique Shopware product IDs to process', count($shopwareProductDbIds)));

        // Chunk the product IDs for processing
        $chunkSize = BatchSizeConstants::ENABLE_DEVICES;
        $productIdChunks = array_chunk($shopwareProductDbIds, $chunkSize);
        CliLogger::getCliStyle()->info(sprintf('Split into %d chunks of max %d products each', count($productIdChunks), $chunkSize));

        // Initialize active sets to store the database IDs of entities that should remain active
        $activeDeviceDbIds = [];
        $activeBrandDbIds = [];
        $activeSeriesDbIds = [];
        $activeTypeDbIds = [];

        // Process each chunk
        foreach ($productIdChunks as $chunkIndex => $productIdsChunk) {
            CliLogger::getCliStyle()->info(sprintf('Processing chunk %d of %d...', $chunkIndex + 1, count($productIdChunks)));
            
            // Map back to webservice IDs for this chunk
            $wsProductIdsForChunk = [];
            foreach ($topidProducts as $wsId => $products) {
                foreach ($products as $product) {
                    if (in_array($product['product_id'], $productIdsChunk)) {
                        $wsProductIdsForChunk[] = $wsId;
                    }
                }
            }
            $wsProductIdsForChunk = array_unique($wsProductIdsForChunk);
            
            // Fetch webservice links for the current chunk
            $response = $this->topdataWebserviceClient->myProductList([
                'products' => implode(',', $wsProductIdsForChunk),
                'filter'   => WebserviceFilterTypeConstants::product_application_in,
            ]);
            
            if (!isset($response->page->available_pages)) {
                CliLogger::getCliStyle()->error('Webservice error: No pages available in response');
                continue; // Skip this chunk and continue with the next one
            }
            
            // Process response to extract linked device webservice IDs
            $deviceWsIds = [];
            foreach ($response->products as $product) {
                if (!isset($topidProducts[$product->products_id])) {
                    continue;
                }
                
                if (isset($product->product_application_in->products) && count($product->product_application_in->products)) {
                    foreach ($product->product_application_in->products as $deviceWsId) {
                        $deviceWsIds[] = $deviceWsId;
                    }
                }
            }
            $deviceWsIds = array_unique($deviceWsIds);
            
            if (empty($deviceWsIds)) {
                CliLogger::getCliStyle()->info('No device links found for this chunk. Continuing...');
                
                // Delete existing links for this chunk of products
                $placeholders = implode(',', array_fill(0, count($productIdsChunk), '?'));
                $hexProductIds = array_map(function($id) {
                    return hex2bin($id);
                }, $productIdsChunk);
                
                $this->connection->executeStatement(
                    "DELETE FROM topdata_device_to_product WHERE product_id IN ($placeholders)",
                    $hexProductIds
                );
                
                continue;
            }
            
            // Fetch local device details based on the webservice IDs
            $devices = $this->topdataDeviceService->getDeviceArrayByWsIdArray($deviceWsIds);
            
            if (empty($devices)) {
                CliLogger::getCliStyle()->info('No matching devices found in database for this chunk. Continuing...');
                continue;
            }
            
            // Populate active sets with the fetched database IDs
            foreach ($devices as $device) {
                // Add device ID to active devices set
                if (!empty($device['id'])) {
                    $activeDeviceDbIds[$device['id']] = $device['id'];
                }
                
                // Add brand ID to active brands set
                if (!empty($device['brand_id'])) {
                    $activeBrandDbIds[$device['brand_id']] = $device['brand_id'];
                }
                
                // Add series ID to active series set
                if (!empty($device['series_id'])) {
                    $activeSeriesDbIds[$device['series_id']] = $device['series_id'];
                }
                
                // Add type ID to active types set
                if (!empty($device['type_id'])) {
                    $activeTypeDbIds[$device['type_id']] = $device['type_id'];
                }
            }
            
            // Delete existing links for this chunk of products
            $placeholders = implode(',', array_fill(0, count($productIdsChunk), '?'));
            $hexProductIds = array_map(function($id) {
                return hex2bin($id);
            }, $productIdsChunk);
            
            $deleteCount = $this->connection->executeStatement(
                "DELETE FROM topdata_device_to_product WHERE product_id IN ($placeholders)",
                $hexProductIds
            );
            CliLogger::getCliStyle()->info(sprintf('Deleted %d existing device-product links for this chunk', $deleteCount));
            
            // Prepare data for inserting new links
            $insertData = [];
            $createdAt = date('Y-m-d H:i:s');
            
            // Map devices to products
            $deviceProductMap = [];
            foreach ($response->products as $product) {
                if (!isset($topidProducts[$product->products_id])) {
                    continue;
                }
                
                if (isset($product->product_application_in->products) && count($product->product_application_in->products)) {
                    foreach ($product->product_application_in->products as $deviceWsId) {
                        foreach ($topidProducts[$product->products_id] as $shopwareProduct) {
                            $deviceProductMap[$deviceWsId][] = $shopwareProduct;
                        }
                    }
                }
            }
            
            // Create insert data
            foreach ($devices as $device) {
                if (isset($deviceProductMap[$device['ws_id']])) {
                    foreach ($deviceProductMap[$device['ws_id']] as $prod) {
                        $insertData[] = "(0x{$device['id']}, 0x{$prod['product_id']}, 0x{$prod['product_version_id']}, '$createdAt')";
                    }
                }
            }
            
            // Insert new links in chunks
            if (!empty($insertData)) {
                $insertDataChunks = array_chunk($insertData, 30);
                $totalInserted = 0;
                
                foreach ($insertDataChunks as $insertChunk) {
//                    $insertCount = $this->connection->executeStatement('
//                        INSERT INTO topdata_device_to_product (device_id, product_id, product_version_id, created_at)
//                        VALUES ' . implode(',', $insertChunk)
//                    );

                    // crash workaround with "ON DUPLICATE KEY UPDATE"
                    $sql = '
                        INSERT INTO topdata_device_to_product 
                            (device_id, product_id, product_version_id, created_at) 
                        VALUES ' . implode(',', $insertChunk) . '
                        ON DUPLICATE KEY UPDATE 
                            product_id = VALUES(product_id), 
                            product_version_id = VALUES(product_version_id)
                            -- updated_at = NOW() 
                    ';
                    $insertCount = $this->connection->executeStatement($sql);

                    $totalInserted += $insertCount;
                }
                
                CliLogger::getCliStyle()->info(sprintf('Inserted %d new device-product links for this chunk', $totalInserted));
            } else {
                CliLogger::getCliStyle()->info('No new device-product links to insert for this chunk');
            }
            
            CliLogger::activity(CliLogger::lap() . "sec\n");
        }
        
        // After processing all chunks, enable/disable entities based on the active sets
        CliLogger::getCliStyle()->yellow('Updating entity status (enable/disable)...');
        
        // Enable active devices
        if (!empty($activeDeviceDbIds)) {
            $deviceChunks = array_chunk(array_values($activeDeviceDbIds), BatchSizeConstants::ENABLE_DEVICES);
            foreach ($deviceChunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $hexIds = array_map(function($id) {
                    return hex2bin($id);
                }, $chunk);
                
                $enableCount = $this->connection->executeStatement(
                    "UPDATE topdata_device SET is_enabled = 1 WHERE id IN ($placeholders)",
                    $hexIds
                );
                CliLogger::getCliStyle()->info(sprintf('Enabled %d devices', $enableCount));
            }
            
            // Disable inactive devices
            $disableCount = $this->connection->executeStatement(
                "UPDATE topdata_device SET is_enabled = 0 WHERE id NOT IN (?" . str_repeat(",?", count($activeDeviceDbIds) - 1) . ")",
                array_map(function($id) {
                    return hex2bin($id);
                }, array_values($activeDeviceDbIds))
            );
            CliLogger::getCliStyle()->info(sprintf('Disabled %d devices', $disableCount));
        } else {
            // If no active devices, disable all
            $disableCount = $this->connection->executeStatement(
                "UPDATE topdata_device SET is_enabled = 0 WHERE 1=1"
            );
            CliLogger::getCliStyle()->info(sprintf('Disabled all %d devices (no active devices found)', $disableCount));
        }
        
        // Enable active brands
        if (!empty($activeBrandDbIds)) {
            $brandChunks = array_chunk(array_values($activeBrandDbIds), BatchSizeConstants::ENABLE_BRANDS);
            foreach ($brandChunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $hexIds = array_map(function($id) {
                    return hex2bin($id);
                }, $chunk);
                
                $enableCount = $this->connection->executeStatement(
                    "UPDATE topdata_brand SET is_enabled = 1 WHERE id IN ($placeholders)",
                    $hexIds
                );
                CliLogger::getCliStyle()->info(sprintf('Enabled %d brands', $enableCount));
            }
            
            // Disable inactive brands
            $disableCount = $this->connection->executeStatement(
                "UPDATE topdata_brand SET is_enabled = 0 WHERE id NOT IN (?" . str_repeat(",?", count($activeBrandDbIds) - 1) . ")",
                array_map(function($id) {
                    return hex2bin($id);
                }, array_values($activeBrandDbIds))
            );
            CliLogger::getCliStyle()->info(sprintf('Disabled %d brands', $disableCount));
        } else {
            // If no active brands, disable all
            $disableCount = $this->connection->executeStatement(
                "UPDATE topdata_brand SET is_enabled = 0 WHERE 1=1"
            );
            CliLogger::getCliStyle()->info(sprintf('Disabled all %d brands (no active brands found)', $disableCount));
        }
        
        // Enable active series
        if (!empty($activeSeriesDbIds)) {
            $seriesChunks = array_chunk(array_values($activeSeriesDbIds), BatchSizeConstants::ENABLE_SERIES);
            foreach ($seriesChunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $hexIds = array_map(function($id) {
                    return hex2bin($id);
                }, $chunk);
                
                $enableCount = $this->connection->executeStatement(
                    "UPDATE topdata_series SET is_enabled = 1 WHERE id IN ($placeholders)",
                    $hexIds
                );
                CliLogger::getCliStyle()->info(sprintf('Enabled %d series', $enableCount));
            }
            
            // Disable inactive series
            $disableCount = $this->connection->executeStatement(
                "UPDATE topdata_series SET is_enabled = 0 WHERE id NOT IN (?" . str_repeat(",?", count($activeSeriesDbIds) - 1) . ")",
                array_map(function($id) {
                    return hex2bin($id);
                }, array_values($activeSeriesDbIds))
            );
            CliLogger::getCliStyle()->info(sprintf('Disabled %d series', $disableCount));
        } else {
            // If no active series, disable all
            $disableCount = $this->connection->executeStatement(
                "UPDATE topdata_series SET is_enabled = 0 WHERE 1=1"
            );
            CliLogger::getCliStyle()->info(sprintf('Disabled all %d series (no active series found)', $disableCount));
        }
        
        // Enable active device types
        if (!empty($activeTypeDbIds)) {
            $typeChunks = array_chunk(array_values($activeTypeDbIds), BatchSizeConstants::ENABLE_DEVICE_TYPES);
            foreach ($typeChunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $hexIds = array_map(function($id) {
                    return hex2bin($id);
                }, $chunk);
                
                $enableCount = $this->connection->executeStatement(
                    "UPDATE topdata_device_type SET is_enabled = 1 WHERE id IN ($placeholders)",
                    $hexIds
                );
                CliLogger::getCliStyle()->info(sprintf('Enabled %d device types', $enableCount));
            }
            
            // Disable inactive device types
            $disableCount = $this->connection->executeStatement(
                "UPDATE topdata_device_type SET is_enabled = 0 WHERE id NOT IN (?" . str_repeat(",?", count($activeTypeDbIds) - 1) . ")",
                array_map(function($id) {
                    return hex2bin($id);
                }, array_values($activeTypeDbIds))
            );
            CliLogger::getCliStyle()->info(sprintf('Disabled %d device types', $disableCount));
        } else {
            // If no active device types, disable all
            $disableCount = $this->connection->executeStatement(
                "UPDATE topdata_device_type SET is_enabled = 0 WHERE 1=1"
            );
            CliLogger::getCliStyle()->info(sprintf('Disabled all %d device types (no active types found)', $disableCount));
        }
        
        CliLogger::getCliStyle()->success('Devices to products linking completed (V2 differential approach)');
        CliLogger::getCliStyle()->dumpDict([
            'Active devices' => count($activeDeviceDbIds),
            'Active brands' => count($activeBrandDbIds),
            'Active series' => count($activeSeriesDbIds),
            'Active device types' => count($activeTypeDbIds),
        ]);
        
        UtilProfiling::stopTimer();
    }
}