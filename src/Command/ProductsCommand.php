<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to import products from a CSV file into Shopware 6
 */
class ProductsCommand extends AbstractCommand
{
    private ?array $_manufacturers = null;
    private string $systemDefaultLocaleCode;
    private ?int $lineStart = null;
    private ?int $lineEnd = null;
    private ?int $columnNumber = null;
    private ?int $columnName = null;
    private ?int $columnTopdataId = null;
    private ?int $columnEAN = null;
    private ?int $columnMPN = null;
    private ?int $columnBrand = null;
    private ?int $columnDescription = null;
    private string $trim = '"';
    private string $divider = ';';

    public function __construct(
        private readonly EntityRepository $manufacturerRepository,
        private readonly EntityRepository $productRepository,
        private readonly Connection $connection,
        private readonly Context $context = null
    ) {
        parent::__construct();
        $this->context = $context ?? Context::createDefaultContext();
        $this->systemDefaultLocaleCode = $this->getLocaleCodeOfSystemLanguage();
    }

    /**
     * Configure the command options
     * 
     * Sets up all available command line options for the product import:
     * - Required options: file, name, number
     * - Optional options: start, end, wsid, description, ean, mpn, brand, divider, trim
     */
    protected function configure(): void
    {
        $this
            ->setName('topdata:connector:products')
            ->setDescription('Import products from a CSV file')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the CSV file')
            ->addOption('start', null, InputOption::VALUE_OPTIONAL, 'Start line number for import')
            ->addOption('end', null, InputOption::VALUE_OPTIONAL, 'End line number for import')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Column number for product name')
            ->addOption('number', null, InputOption::VALUE_REQUIRED, 'Column number for product number')
            ->addOption('wsid', null, InputOption::VALUE_OPTIONAL, 'Column number for Topdata webservice ID')
            ->addOption('description', null, InputOption::VALUE_OPTIONAL, 'Column number for product description')
            ->addOption('ean', null, InputOption::VALUE_OPTIONAL, 'Column number for EAN')
            ->addOption('mpn', null, InputOption::VALUE_OPTIONAL, 'Column number for MPN')
            ->addOption('brand', null, InputOption::VALUE_OPTIONAL, 'Column number for brand')
            ->addOption('divider', null, InputOption::VALUE_OPTIONAL, 'CSV column delimiter (default: ;)')
            ->addOption('trim', null, InputOption::VALUE_OPTIONAL, 'Character to trim from values (default: ")');
    }

    private function getLocaleCodeOfSystemLanguage(): string
    {
        return $this->connection
            ->fetchOne(
                'SELECT lo.code FROM language as la JOIN locale as lo on lo.id = la.locale_id  WHERE la.id = UNHEX(:systemLanguageId)',
                ['systemLanguageId' => Defaults::LANGUAGE_SYSTEM]
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $products = [];
        $file     = $input->getOption('file');
        if (!$file) {
            echo "add file!\n";

            return 1;
        }

        $handle = fopen($file, 'r');
        if (!$handle) {
            echo "invalid file!\n";

            return 2;
        }
        echo $file . "\n";

        $this->lineStart = (int) ($input->getOption('start') ?? 1);
        $this->lineEnd = $input->getOption('end') ? (int) $input->getOption('end') : null;
        $this->columnName = (int) $input->getOption('name');
        $this->columnNumber = (int) $input->getOption('number');
        $this->columnTopdataId = $input->getOption('wsid') ? (int) $input->getOption('wsid') : null;
        $this->columnDescription = $input->getOption('description') ? (int) $input->getOption('description') : null;
        $this->columnEAN = $input->getOption('ean') ? (int) $input->getOption('ean') : null;
        $this->columnMPN = $input->getOption('mpn') ? (int) $input->getOption('mpn') : null;
        $this->columnBrand = $input->getOption('brand') ? (int) $input->getOption('brand') : null;
        $this->divider = $input->getOption('divider') ?? ';';
        $this->trim = $input->getOption('trim') ?? '"';

        if ($handle) {
            $lineNumber = -1;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                if ($lineNumber > $this->lineEnd) {
                    break;
                }
                if ($lineNumber < $this->lineStart) {
                    continue;
                }

                $values = explode($this->divider, $line);
                foreach ($values as $key => $val) {
                    $values[$key] = trim($val, $this->trim);
                }
                $products[$values[$this->columnNumber]] = [
                    'productNumber' => $values[$this->columnNumber],
                    'name'          => $values[$this->columnName],
                ];
                if (null !== $this->columnTopdataId) {
                    $products[$values[$this->columnNumber]]['topDataId'] = (int) $values[$this->columnTopdataId];
                }
                if (null !== $this->columnDescription) {
                    $products[$values[$this->columnNumber]]['description'] = $values[$this->columnDescription];
                }
                if (null !== $this->columnEAN) {
                    $products[$values[$this->columnNumber]]['ean'] = $values[$this->columnEAN];
                }
                if (null !== $this->columnMPN) {
                    $products[$values[$this->columnNumber]]['mpn'] = $values[$this->columnMPN];
                }
                if (null !== $this->columnBrand) {
                    $products[$values[$this->columnNumber]]['brand'] = $values[$this->columnBrand];
                }
            }

            fclose($handle);
        } else {
            echo 'error opening the file';

            return 3;
        }

        $this->cliStyle->writeln('Products in file: ' . count($products));

        $products = $this->clearExistingProductsByProductNumber($products);

        $this->cliStyle->writeln('Products not added yet: ' . count($products));

        if (count($products)) {
            $products = $this->formProductsArray($products);
        } else {
            echo 'no products found';

            return 4;
        }
        $prods = array_chunk($products, 50);
        foreach ($prods as $key => $prods_chunk) {
            echo 'adding ' . ($key * 50 + count($prods_chunk)) . ' of ' . count($products) . " products...\n";
            $this->createProducts($prods_chunk);
        }

        return 0;
    }

    private function getTaxId(): string
    {
        /*
        $result = $this->connection->fetchColumn('
            SELECT LOWER(HEX(COALESCE(
                (SELECT `id` FROM `tax` WHERE tax_rate = "19.00" LIMIT 1),
                (SELECT `id` FROM `tax`  LIMIT 1)
            )))
        ');
        */

        $result = $this->connection->executeQuery('
            SELECT LOWER(HEX(COALESCE(
                (SELECT `id` FROM `tax` WHERE tax_rate = "19.00" LIMIT 1),
	            (SELECT `id` FROM `tax`  LIMIT 1)
            )))
        ')->fetchOne();

        if (!$result) {
            throw new \RuntimeException('No tax found, please make sure that basic data is available by running the migrations.');
        }

        return (string) $result;
    }

    private function getStorefrontSalesChannel(): string
    {
        /*
        $result = $this->connection->fetchColumn('
            SELECT LOWER(HEX(`id`))
            FROM `sales_channel`
            WHERE `type_id` = :storefront_type
            ORDER BY `created_at` ASC
        ', ['storefront_type' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]);
        */

        $result = $this->connection->executeQuery('
            SELECT LOWER(HEX(`id`))
            FROM `sales_channel`
            WHERE `type_id` = ' . Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT) . '
            ORDER BY `created_at` ASC            
        ')->fetchOne();
        if (!$result) {
            throw new \RuntimeException('No sale channel found.');
        }

        return (string) $result;
    }

    private function getManufacturersArray(): void
    {
        $criteria      = new Criteria();
        $manufacturers = $this->manufacturerRepository->search($criteria, $this->context)->getEntities();
        $ret           = [];
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
            $this->manufacturerRepository->create([
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

    private function formProductsArray(array $input, float $price = 1.0): array
    {
        $output                 = [];
        $taxId                  = $this->getTaxId();
        $storefrontSalesChannel = $this->getStorefrontSalesChannel();
        $priceTax               = $price * (1.19);
        foreach ($input as $in) {
            $prod = [
                'id'            => Uuid::randomHex(),
                'productNumber' => $in['productNumber'],
                'active'        => true,
                'taxId'         => $taxId,
                'stock'         => 10,
                'shippingFree'  => false,
                'purchasePrice' => $priceTax,
                //                    'releaseDate' => new \DateTimeImmutable(),
                'displayInListing' => true,
                'name'             => [
                    $this->systemDefaultLocaleCode => $in['name'],
                ],
                'price' => [[
                    'net'        => $price,
                    'gross'      => $priceTax,
                    'linked'     => true,
                    'currencyId' => Defaults::CURRENCY,
                ]],
                'visibilities' => [
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

    private function createProducts(array $products): void
    {
        $this->productRepository->create($products, $this->context);
    }

    private function clearExistingProductsByProductNumber(array $products): array
    {
        $rezProducts    = $products;
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
        $this->trim    = '"';
        if (!$filename) {
            return [
                'success'        => false,
                'additionalInfo' => 'file with demo data not found!',
            ];
        }

        $file   = dirname(__FILE__) . '/../DemoData/' . $filename;
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
