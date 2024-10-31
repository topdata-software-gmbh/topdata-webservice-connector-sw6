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



}
