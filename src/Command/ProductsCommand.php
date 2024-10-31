<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Command;

use Shopware\Core\Defaults;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataConnectorSW6\Service\ProductService;

/**
 * Command to import products from a CSV file into Shopware 6
 */
class ProductsCommand extends AbstractCommand
{
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
        private readonly ProductService $productService,
    )
    {
        parent::__construct();
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
        $file = $input->getOption('file');
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

        $this->lineStart = (int)($input->getOption('start') ?? 1);
        $this->lineEnd = $input->getOption('end') ? (int)$input->getOption('end') : null;
        $this->columnName = (int)$input->getOption('name');
        $this->columnNumber = (int)$input->getOption('number');
        $this->columnTopdataId = $input->getOption('wsid') ? (int)$input->getOption('wsid') : null;
        $this->columnDescription = $input->getOption('description') ? (int)$input->getOption('description') : null;
        $this->columnEAN = $input->getOption('ean') ? (int)$input->getOption('ean') : null;
        $this->columnMPN = $input->getOption('mpn') ? (int)$input->getOption('mpn') : null;
        $this->columnBrand = $input->getOption('brand') ? (int)$input->getOption('brand') : null;
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
                    $products[$values[$this->columnNumber]]['topDataId'] = (int)$values[$this->columnTopdataId];
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

        $products = $this->productService->clearExistingProductsByProductNumber($products);

        $this->cliStyle->writeln('Products not added yet: ' . count($products));

        if (count($products)) {
            $products = $this->productService->formProductsArray($products);
        } else {
            echo 'no products found';

            return 4;
        }
        $prods = array_chunk($products, 50);
        foreach ($prods as $key => $prods_chunk) {
            echo 'adding ' . ($key * 50 + count($prods_chunk)) . ' of ' . count($products) . " products...\n";
            $this->productService->createProducts($prods_chunk);
        }

        return 0;
    }

}
