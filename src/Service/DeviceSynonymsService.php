<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Exception;
use PDO;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\BatchSizeConstants;
use Topdata\TopdataConnectorSW6\Constants\FilterTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\Helper\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilStringFormatting;
use Topdata\TopdataFoundationSW6\Service\LocaleHelperService;
use Topdata\TopdataFoundationSW6\Service\ManufacturerService;
use Topdata\TopdataFoundationSW6\Trait\CliStyleTrait;

/**
 * 11/2024 created (extracted from MappingHelperService)
 */
class DeviceSynonymsService
{
    use CliStyleTrait;

    private TopdataWebserviceClient $topdataWebserviceClient;

    public function __construct(
        private readonly TopdataDeviceService   $topdataDeviceService,
        private readonly ProgressLoggingService $progressLoggingService,
        private readonly OptionsHelperService   $optionsHelperService,
        private readonly Connection             $connection,
    )
    {
        $this->beVerboseOnCli();
    }



    public function setDeviceSynonyms(): bool
    {
        $this->cliStyle->section("\n\nDevice synonyms");
        $availableDevices = [];
        foreach ($this->topdataDeviceService->_getEnabledDevices() as $pr) {
            $availableDevices[$pr['ws_id']] = bin2hex($pr['id']);
        }
        $chunkSize = 50;

        $chunks = array_chunk($availableDevices, $chunkSize, true);
        $this->progressLoggingService->lap(true);

        foreach ($chunks as $k => $prs) {
            if ($this->optionsHelperService->getOption(OptionConstants::START) && ($k + 1 < $this->optionsHelperService->getOption(OptionConstants::START))) {
                continue;
            }

            if ($this->optionsHelperService->getOption(OptionConstants::END) && ($k + 1 > $this->optionsHelperService->getOption(OptionConstants::END))) {
                break;
            }

            $this->progressLoggingService->activity('xxx1 - Getting data from remote server part ' . ($k + 1) . '/' . count($chunks) . '...');
            $devices = $this->topdataWebserviceClient->myProductList([
                'products' => implode(',', array_keys($prs)),
                'filter'   => FilterTypeConstants::all,
            ]);
            $this->progressLoggingService->activity($this->progressLoggingService->lap() . "sec\n");

            if (!isset($devices->page->available_pages)) {
                throw new Exception($devices->error[0]->error_message . ' webservice no pages');
            }
            //            $this->progressLoggingService->mem();
            $this->progressLoggingService->activity("\nProcessing data...");

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
                    $this->progressLoggingService->activity();
                }
            }
            $this->progressLoggingService->activity($this->progressLoggingService->lap() . 'sec ');
            $this->progressLoggingService->mem();
            $this->cliStyle->writeln('');
        }

        return true;
    }

}
