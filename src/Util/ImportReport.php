<?php

namespace Topdata\TopdataConnectorSW6\Util;

use Exception;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilFormatter;

/**
 * 06/2024 created.
 */
class ImportReport
{
    /**
     * this is always a dict (never a list).
     */
    protected static array $counters = [];

    public static function incCounter(string $key, int $inc = 1): void
    {
        self::$counters[$key] = (self::$counters[$key] ?? 0) + $inc;
    }

    public static function setCounter(string $key, int $count): void
    {
        self::$counters[$key] = $count;
    }

    public static function getCounters(): array
    {
        return self::$counters;
    }

    public static function getCountersSorted(): array
    {
        // sort by key
        ksort(self::$counters);

        return self::$counters;
    }

    public static function getCounter(string $key): ?int
    {
        return self::$counters[$key] ?? null; // GlobalConfigConstants::NUM_ROWS__FAILED; // -2 is a magic number
    }


    /**
     * 06/2025 created
     */
    public static function dumpImportReportToCli(): void // Added void return type for clarity
    {
        $counterDescriptions = [
            'linking_v2.products.found'                => 'Total unique Shopware product IDs identified for processing.',
            'linking_v2.products.chunks'               => 'Number of chunks the product IDs were split into.',
            'linking_v2.chunks.processed'              => 'Number of chunks successfully processed.',
            'linking_v2.webservice.calls'              => 'Number of webservice calls made to fetch device links.',
            'linking_v2.webservice.device_ids_fetched' => 'Total unique device webservice IDs fetched from the webservice.',
            'linking_v2.database.devices_found'        => 'Total corresponding devices found in the local database.',
            'linking_v2.links.deleted'                 => 'Total number of existing device-product links deleted across all chunks.',
            'linking_v2.links.inserted'                => 'Total number of new device-product links inserted across all chunks.',
            'linking_v2.status.devices.enabled'        => 'Total number of devices marked as enabled.',
            'linking_v2.status.devices.disabled'       => 'Total number of devices marked as disabled.',
            'linking_v2.status.brands.enabled'         => 'Total number of brands marked as enabled.',
            'linking_v2.status.brands.disabled'        => 'Total number of brands marked as disabled.',
            'linking_v2.status.series.enabled'         => 'Total number of series marked as enabled.',
            'linking_v2.status.series.disabled'        => 'Total number of series marked as disabled.',
            'linking_v2.status.types.enabled'          => 'Total number of device types marked as enabled.',
            'linking_v2.status.types.disabled'         => 'Total number of device types marked as disabled.',
            'linking_v2.active.devices'                => 'Final count of active devices at the end of the process.',
            'linking_v2.active.brands'                 => 'Final count of active brands at the end of the process.',
            'linking_v2.active.series'                 => 'Final count of active series at the end of the process.',
            'linking_v2.active.types'                  => 'Final count of active device types at the end of the process.',
        ];

        $cliStyle = CliLogger::getCliStyle();
        $counters = ImportReport::getCountersSorted();
        $descriptions = $counterDescriptions;

        $table = new Table($cliStyle);
        $table->setHeaders(['Counter', 'Value', 'Description']);

        // START of new code
        // Create a style for right-aligning the 'Value' column.
        $rightAlignedStyle = new TableStyle();
        $rightAlignedStyle->setPadType(STR_PAD_LEFT);

        // Apply the style to the second column (index 1).
        $table->setColumnStyle(1, $rightAlignedStyle);
        // END of new code

        foreach ($counters as $key => $value) {
            $description = $descriptions[$key] ?? '';
            $table->addRow([$key, number_format($value), $description]);
        }

        $cliStyle->title('Counters Report');
        $table->render();
    }
}

