<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Exception;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataConnectorSW6\Constants\FilterTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\Service\TopdataWebserviceClient;

/**
 * 11/2024 created (extracted from MappingHelperService)
 */
class DeviceSynonymsService
{


    public function __construct(
        private readonly TopdataWebserviceClient $topdataWebserviceClient,
        private readonly TopdataDeviceService   $topdataDeviceService,
        private readonly OptionsHelperService   $optionsHelperService,
        private readonly Connection             $connection,
    )
    {
    }


    public function setDeviceSynonyms(): bool
    {
        \Topdata\TopdataFoundationSW6\Util\CliLogger::section("\n\nDevice synonyms");
        $availableDevices = [];
        foreach ($this->topdataDeviceService->_getEnabledDevices() as $pr) {
            $availableDevices[$pr['ws_id']] = bin2hex($pr['id']);
        }
        $chunkSize = 50;

        $chunks = array_chunk($availableDevices, $chunkSize, true);
        \Topdata\TopdataFoundationSW6\Util\CliLogger::lap(true);

        foreach ($chunks as $k => $prs) {
            if ($this->optionsHelperService->getOption(OptionConstants::START) && ($k + 1 < $this->optionsHelperService->getOption(OptionConstants::START))) {
                continue;
            }

            if ($this->optionsHelperService->getOption(OptionConstants::END) && ($k + 1 > $this->optionsHelperService->getOption(OptionConstants::END))) {
                break;
            }

            \Topdata\TopdataFoundationSW6\Util\CliLogger::activity('xxx1 - Getting data from remote server part ' . ($k + 1) . '/' . count($chunks) . '...');
            $devices = $this->topdataWebserviceClient->myProductList([
                'products' => implode(',', array_keys($prs)),
                'filter'   => FilterTypeConstants::all,
            ]);
            \Topdata\TopdataFoundationSW6\Util\CliLogger::activity(\Topdata\TopdataFoundationSW6\Util\CliLogger::lap() . "sec\n");

            if (!isset($devices->page->available_pages)) {
                throw new Exception($devices->error[0]->error_message . ' device synonym webservice no pages');
            }
            //            \Topdata\TopdataFoundationSW6\Util\CliLogger::mem();
            \Topdata\TopdataFoundationSW6\Util\CliLogger::activity("\nProcessing data...");

            $this->connection->executeStatement('DELETE FROM topdata_device_to_synonym WHERE device_id IN (0x' . implode(', 0x', $prs) . ')');

            $variantsMap = [];
            foreach ($devices->products as $product) {
                if (isset($product->product_variants->products)) {
                    foreach ($product->product_variants->products as $variant) {
                        if (($variant->type == 'synonym')
                            && isset($prs[$product->products_id])
                            && isset($availableDevices[$variant->id])
                        ) {
                            $prodId = $prs[$product->products_id];
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
                $insertDataChunks = array_chunk($dataInsert, 50);
                foreach ($insertDataChunks as $dataChunk) {
                    $this->connection->executeStatement(
                        'INSERT INTO topdata_device_to_synonym (device_id, synonym_id, created_at) VALUES ' . implode(',', $dataChunk)
                    );
                    \Topdata\TopdataFoundationSW6\Util\CliLogger::activity();
                }
            }
            \Topdata\TopdataFoundationSW6\Util\CliLogger::activity(\Topdata\TopdataFoundationSW6\Util\CliLogger::lap() . 'sec ');
            \Topdata\TopdataFoundationSW6\Util\CliLogger::mem();
            \Topdata\TopdataFoundationSW6\Util\CliLogger::writeln('');
        }

        return true;
    }

}
