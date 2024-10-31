<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\DTO\CsvConfiguration;
use Topdata\TopdataConnectorSW6\Service\ProductCsvReader;

/**
 * 10/2024 created (extracted from "ProductsCommand")
 */
class ProductService
{
    private ?array $_manufacturers = null;
    private string $systemDefaultLocaleCode;
    private Context $context;


    public function __construct(
        private readonly EntityRepository    $productRepository,
        private readonly EntityRepository    $productManufacturerRepository,
        private readonly Connection          $connection,
        private readonly ProductCsvReader    $productCsvReader,
        private readonly LocaleHelperService $localeHelperService,
    )
    {
        $this->context = Context::createDefaultContext();
        $this->systemDefaultLocaleCode = $this->localeHelperService->getLocaleCodeOfSystemLanguage();
    }


    private function getTaxId(): string
    {
        $result = $this->connection->executeQuery('
            SELECT LOWER(HEX(COALESCE(
                (SELECT `id` FROM `tax` WHERE tax_rate = "19.00" LIMIT 1),
                (SELECT `id` FROM `tax`  LIMIT 1)
            )))
        ')->fetchOne();

        if (!$result) {
            throw new \RuntimeException('No tax found, please make sure that basic data is available by running the migrations.');
        }

        return (string)$result;
    }

    private function getStorefrontSalesChannel(): string
    {
        $result = $this->connection->executeQuery('
            SELECT LOWER(HEX(`id`))
            FROM `sales_channel`
            WHERE `type_id` = 0x' . Defaults::SALES_CHANNEL_TYPE_STOREFRONT . '
            ORDER BY `created_at` ASC            
        ')->fetchOne();

        if (!$result) {
            throw new \RuntimeException('No sale channel found.');
        }

        return (string)$result;
    }

    private function getManufacturersArray(): void
    {
        $criteria = new Criteria();
        $manufacturers = $this->productManufacturerRepository->search($criteria, $this->context)->getEntities();
        $ret = [];
        foreach ($manufacturers as $manufacturer) {
            $ret[$manufacturer->getName()] = $manufacturer->getId();
        }
        $this->_manufacturers = $ret;
    }

    public function getManufacturerIdByName(string $manufacturerName): string
    {
        if ($this->_manufacturers === null) {
            $this->getManufacturersArray();
        }

        if (isset($this->_manufacturers[$manufacturerName])) {
            $manufacturerId = $this->_manufacturers[$manufacturerName];
        } else {
            $manufacturerId = Uuid::randomHex();
            $this->productManufacturerRepository->create([
                [
                    'id'   => $manufacturerId,
                    'name' => [
                        $this->systemDefaultLocaleCode => $manufacturerName,
                    ],
                ],
            ], $this->context);
            $this->_manufacturers[$manufacturerName] = $manufacturerId;
        }

        return $manufacturerId;
    }


    public function parseProductsFromCsv(string $filePath, CsvConfiguration $config): array
    {
        return $this->productCsvReader->readProducts($filePath, $config);
    }

    public function formProductsArray(array $input, float $price = 1.0): array
    {
        $output = [];
        $taxId = $this->getTaxId();
        $storefrontSalesChannel = $this->getStorefrontSalesChannel();
        $priceTax = $price * (1.19);

        foreach ($input as $in) {
            $prod = [
                'id'               => Uuid::randomHex(),
                'productNumber'    => $in['productNumber'],
                'active'           => true,
                'taxId'            => $taxId,
                'stock'            => 10,
                'shippingFree'     => false,
                'purchasePrice'    => $priceTax,
                'displayInListing' => true,
                'name'             => [
                    $this->systemDefaultLocaleCode => $in['name'],
                ],
                'price'            => [[
                    'net'        => $price,
                    'gross'      => $priceTax,
                    'linked'     => true,
                    'currencyId' => Defaults::CURRENCY,
                ]],
                'visibilities'     => [
                    [
                        'salesChannelId' => $storefrontSalesChannel,
                        'visibility'     => ProductVisibilityDefinition::VISIBILITY_ALL,
                    ],
                ],
            ];

            if (isset($in['description'])) {
                $prod['description'] = [
                    $this->systemDefaultLocaleCode => $in['description'],
                ];
            }

            if (isset($in['brand'])) {
                $prod['manufacturer'] = [
                    'id' => $this->getManufacturerIdByName($in['brand']),
                ];
            }

            if (isset($in['mpn'])) {
                $prod['manufacturerNumber'] = $in['mpn'];
            }

            if (isset($in['ean'])) {
                $prod['ean'] = $in['ean'];
            }

            if (isset($in['topDataId'])) {
                $prod['topdata'] = [
                    'topDataId' => $in['topDataId'],
                ];
            }

            $output[] = $prod;
        }

        return $output;
    }

    public function createProducts(array $products): void
    {
        $this->productRepository->create($products, $this->context);
    }

    public function clearExistingProductsByProductNumber(array $products): array
    {
        $rezProducts = $products;
        $product_arrays = array_chunk($products, 50, true);
        foreach ($product_arrays as $prods) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('productNumber', array_keys($prods)));
            $foundedProducts = $this->productRepository->search($criteria, $this->context)->getEntities();
            foreach ($foundedProducts as $foundedProd) {
                unset($rezProducts[$foundedProd->getProductNumber()]);
            }
        }

        return $rezProducts;
    }


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

        $products = $this->clearExistingProductsByProductNumber($products);
        if (count($products)) {
            $products = $this->formProductsArray($products, 100000.0);
        } else {
            return [
                'success'        => true,
                'additionalInfo' => 'Nothing to add',
            ];
        }

        $this->createProducts($products);

        return [
            'success'        => true,
            'additionalInfo' => count($products) . ' products has been added',
        ];
    }

}
