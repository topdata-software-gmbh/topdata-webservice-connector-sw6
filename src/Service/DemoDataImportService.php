<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Shopware\Core\Framework\Context;

/**
 * TODO: move this into a new Plugin TopdataDemoDataImportSW6
 *
 * 10/2024 created (extracted from ProductService)
 */
class DemoDataImportService
{
    private ?int $columnNumber = null;
    private ?int $columnName = null;
    private ?int $columnEAN = null;
    private ?int $columnMPN = null;
    private string $divider;
    private string $trim;

    public function __construct(
        private readonly ProductService $productService
    )
    {
    }

    /**
     * 10/2024 extracted from ProductService
     *
     * Install demo data from file
     *
     * @param string $filename
     * @return array
     */
    public function installDemoData(string $filename = 'demo.csv'): array
    {
        $this->divider = ';';
        $this->trim = '"';
        if (!$filename) {
            return [
                'success'        => false,
                'additionalInfo' => 'file with demo data not found!',
            ];
        }

        $file = dirname(__FILE__) . '/../DemoData/' . $filename;
        $handle = fopen($file, 'r');
        if (!$handle) {
            return [
                'success'        => false,
                'additionalInfo' => 'file with demo not accessible!',
            ];
        }

        $line = fgets($handle);
        if ($line === false) {
            return [
                'success'        => false,
                'additionalInfo' => 'file is empty!',
            ];
        }

        $values = explode($this->divider, $line);
        foreach ($values as $key => $val) {
            $val = trim($val);
            if ($val === 'article_no') {
                $this->columnNumber = $key;
            }
            if ($val === 'short_desc') {
                $this->columnName = $key;
            }
            if ($val === 'ean') {
                $this->columnEAN = $key;
            }
            if ($val === 'oem') {
                $this->columnMPN = $key;
            }
        }

        if (is_null($this->columnNumber)) {
            return [
                'success'        => false,
                'additionalInfo' => 'article_no column not exists!',
            ];
        }

        if (is_null($this->columnName)) {
            return [
                'success'        => false,
                'additionalInfo' => 'short_desc column not exists!',
            ];
        }

        if (is_null($this->columnEAN)) {
            return [
                'success'        => false,
                'additionalInfo' => 'ean column not exists!',
            ];
        }

        if (is_null($this->columnMPN)) {
            return [
                'success'        => false,
                'additionalInfo' => 'oem column not exists!',
            ];
        }

        $products = [];

        while (($line = fgets($handle)) !== false) {
            $values = explode($this->divider, $line);
            foreach ($values as $key => $val) {
                $values[$key] = trim($val, $this->trim);
            }
            $products[$values[$this->columnNumber]] = [
                'productNumber' => $values[$this->columnNumber],
                'name'          => $values[$this->columnName],
                'ean'           => $values[$this->columnEAN],
                'mpn'           => $values[$this->columnMPN],
            ];
        }

        fclose($handle);

        $products = $this->productService->clearExistingProductsByProductNumber($products);
        if (count($products)) {
            $products = $this->productService->formProductsArray($products, 100000.0);
        } else {
            return [
                'success'        => true,
                'additionalInfo' => 'Nothing to add',
            ];
        }

        $this->productService->createProducts($products);

        return [
            'success'        => true,
            'additionalInfo' => count($products) . ' products has been added',
        ];
    }
}
