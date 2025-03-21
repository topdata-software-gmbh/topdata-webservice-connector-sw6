<?php

namespace Topdata\TopdataConnectorSW6\Service\DbHelper;

use Doctrine\DBAL\Connection;
use Exception;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\Constants\WebserviceFilterTypeConstants;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * TODO: split: Db Helper Service + Import Service
 * Service to handle device synonyms.
 * 11/2024 created (extracted from MappingHelperService)
 */
class TopdataDeviceSynonymsService
{

    public function __construct(
        private readonly TopdataWebserviceClient     $topdataWebserviceClient,
        private readonly TopdataDeviceService        $topdataDeviceService,
        private readonly Connection                  $connection,
    )
    {
    }

    /**
     * it populates the topdata_device_to_synonym table
     *
     * Sets device synonyms by fetching data from the Topdata webservice and storing it in the database.
     *
     * @return bool Returns true if the process was successful.
     * @throws Exception
     */
    public function setDeviceSynonyms(): bool
    {
        CliLogger::section("\n\nDevice synonyms");
        $availableDevices = [];
        foreach ($this->topdataDeviceService->_getEnabledDevices() as $pr) {
            $availableDevices[$pr['ws_id']] = bin2hex($pr['id']);
        }
        $chunkSize = 50;

        $chunks = array_chunk($availableDevices, $chunkSize, true);
        CliLogger::lap(true);

        foreach ($chunks as $idxChunk => $chunk) {
//            if ($this->optionsHelperService->getOption(OptionConstants::START) && ($idxChunk + 1 < $this->optionsHelperService->getOption(OptionConstants::START))) {
//                continue;
//            }
//
//            if ($this->optionsHelperService->getOption(OptionConstants::END) && ($idxChunk + 1 > $this->optionsHelperService->getOption(OptionConstants::END))) {
//                break;
//            }

            CliLogger::activity('xxx1 - Getting data from remote server part ' . ($idxChunk + 1) . '/' . count($chunks) . '...');
            $response = $this->topdataWebserviceClient->myProductList([
                'products' => implode(',', array_keys($chunk)),
                'filter'   => WebserviceFilterTypeConstants::all,
            ]);
            CliLogger::activity(CliLogger::lap() . "sec\n");

            if (!isset($response->page->available_pages)) {
                throw new Exception($response->error[0]->error_message . ' device synonym webservice no pages');
            }
            //            \Topdata\TopdataFoundationSW6\Util\CliLogger::mem();
            CliLogger::activity("\nProcessing data...");

            // ---- Delete existing synonyms for the current chunk of devices
            $this->connection->executeStatement('DELETE FROM topdata_device_to_synonym WHERE device_id IN (0x' . implode(', 0x', $chunk) . ')');

            $variantsMap = [];
            foreach ($response->products as $product) {
                if (isset($product->product_variants->products)) {
                    foreach ($product->product_variants->products as $variant) {
                        if (($variant->type == 'synonym')
                            && isset($chunk[$product->products_id])
                            && isset($availableDevices[$variant->id])
                        ) {
                            $prodId = $chunk[$product->products_id];
                            if (!isset($variantsMap[$prodId])) {
                                $variantsMap[$prodId] = [];
                            }
                            $variantsMap[$prodId][] = $availableDevices[$variant->id];
                        }
                    }
                }
            }

            $dateTime = date('Y-m-d H:i:s');
            $dataInsert = [];
            foreach ($variantsMap as $deviceId => $synonymIds) {
                foreach ($synonymIds as $synonymId) {
                    $dataInsert[] = "(0x{$deviceId}, 0x{$synonymId}, '$dateTime')";
                }
            }

            if (count($dataInsert)) {
                // ---- Chunk the insert data to avoid exceeding database limits
                $insertDataChunks = array_chunk($dataInsert, 50);
                foreach ($insertDataChunks as $dataChunk) {
                    $this->connection->executeStatement(
                        'INSERT INTO topdata_device_to_synonym (device_id, synonym_id, created_at) VALUES ' . implode(',', $dataChunk)
                    );
                    CliLogger::activity();
                }
            }
            CliLogger::activity(CliLogger::lap() . 'sec ');
            CliLogger::mem();
            CliLogger::writeln('');
        }

        return true;
    }

}