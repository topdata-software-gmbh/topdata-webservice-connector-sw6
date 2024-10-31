<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service\Product;

use RuntimeException;
use Topdata\TopdataConnectorSW6\DTO\CsvConfiguration;

/**
 * Service for reading products from CSV files
 */
class ProductCsvReader
{
    /**
     * Read products from a CSV file
     *
     * @throws RuntimeException if file cannot be read or is invalid
     */
    public function readProducts(string $filePath, CsvConfiguration $config): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException('File not found: ' . $filePath);
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new RuntimeException('Could not open file: ' . $filePath);
        }

        try {
            return $this->processFile($handle, $config);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @throws RuntimeException if required columns are missing
     */
    private function processFile($handle, CsvConfiguration $config): array
    {
        $products = [];
        $lineNumber = 0;
        $mapping = $config->getColumnMapping();

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;

            if ($lineNumber < $config->getStartLine()) {
                continue;
            }

            if ($config->getEndLine() !== null && $lineNumber > $config->getEndLine()) {
                break;
            }

            $values = array_map(
                fn($val) => trim($val, $config->getEnclosure()),
                explode($config->getDelimiter(), $line)
            );

            if (!isset($values[$mapping['number']]) || !isset($values[$mapping['name']])) {
                continue;
            }

            $products[$values[$mapping['number']]] = $this->mapRowToProduct($values, $mapping);
        }

        return $products;
    }

    private function mapRowToProduct(array $values, array $mapping): array
    {
        $product = [
            'productNumber' => $values[$mapping['number']],
            'name'          => $values[$mapping['name']],
        ];

        // Optional fields
        $optionalFields = [
            'wsid'        => 'topDataId',
            'description' => 'description',
            'ean'         => 'ean',
            'mpn'         => 'mpn',
            'brand'       => 'brand'
        ];

        foreach ($optionalFields as $csvField => $productField) {
            if (isset($mapping[$csvField]) && isset($values[$mapping[$csvField]])) {
                $product[$productField] = $values[$mapping[$csvField]];
            }
        }

        return $product;
    }
}
