<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataConnectorSW6\Constants\FilterTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\Exception\WebserviceResponseException;
use Topdata\TopdataConnectorSW6\Util\UtilStringFormatting;
use Topdata\TopdataFoundationSW6\Service\ManufacturerService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Service for updating product specifications and media.
 *
 * This service handles the retrieval and updating of product specifications and media
 * from an external source, integrating them into the Shopware 6 system. It includes
 * functionalities for fetching data, processing images, and linking related products.
 */
class ProductInformationService
{
    /**
     * List of specifications to ignore during import.
     */
    const IGNORE_SPECS = [
        21  => 'Hersteller-Nr. (intern)',
        24  => 'Product Code (PCD) Intern',
        32  => 'Kurzbeschreibung',
        573 => 'Kurzbeschreibung (statisch)',
        583 => 'Beschreibung (statisch)',
        293 => 'Gattungsbegriff 1',
        294 => 'Gattungsbegriff 2',
        295 => 'Gattungsbegriff 3',
        299 => 'Originalprodukt (J/N)',
        307 => 'Hersteller-Nr. (alt)',
        308 => 'Hersteller-Nr. (Alternative)',
        311 => 'Fake Eintrag',
        340 => 'Automatisch gematched',
        341 => 'Security Code System',
        361 => 'Produktart (Überkompatibilität)',
        367 => 'Product Code (PCD) Alternative',
        368 => 'Produktcode (PCD) alt',
        371 => 'EAN/GTIN 08 (alt)',
        391 => 'MPS Ready',
        22  => 'EAN/GTIN-13 (intern)',
        23  => 'EAN/GTIN-08 (intern)',
        370 => 'EAN/GTIN 13 (alt)',
        372 => 'EAN/GTIN-13 (Alternative)',
        373 => 'EAN/GTIN-08 (Alternative)',
        26  => 'eCl@ss v6.1.0',
        28  => 'unspsc 111201',
        331 => 'eCl@ss v5.1.4',
        332 => 'eCl@ss v6.2.0',
        333 => 'eCl@ss v7.0.0',
        334 => 'eCl@ss v7.1.0',
        335 => 'eCl@ss v8.0.0',
        336 => 'eCl@ss v8.1.0',
        337 => 'eCl@ss v9.0.0',
        721 => 'eCl@ss v9.1.0',
        34  => 'Gruppe Pelikan',
        35  => 'Gruppe Carma',
        36  => 'Gruppe Reuter',
        37  => 'Gruppe Kores',
        38  => 'Gruppe DK',
        39  => 'Gruppe Pelikan (falsch)',
        40  => 'Gruppe USA (Druckwerk)',
        122 => 'Druckwerk',
        8   => 'Leergut',
        30  => 'Marketingtext',
    ];

    private Context $context;

    public function __construct(
        private readonly TopdataToProductHelperService $topdataToProductHelperService,
        private readonly OptionsHelperService          $optionsHelperService,
        private readonly ProductLinkingService         $productLinkingService,
        private readonly EntityRepository              $productRepository,
        private readonly TopdataWebserviceClient       $topdataWebserviceClient,
        private readonly ProductImportSettingsService  $productImportSettingsService,
        private readonly EntitiesHelperService         $entitiesHelperService,
        private readonly MediaHelperService            $mediaHelperService,
        private readonly LoggerInterface               $logger,
        private readonly ManufacturerService           $manufacturerService,
        private readonly Connection                    $connection,
    ){
        $this->context = Context::createDefaultContext();
    }


    /**
     * Updates product information and media.
     *
     * Fetches product data from a remote server, processes it, and updates the local database.
     * It handles both product information and media updates based on the $onlyMedia flag.
     *
     * @param bool $onlyMedia If true, only media information is updated; otherwise, all product information is updated.
     * @return bool True on success, false otherwise.
     * @throws Exception If there is an error fetching data from the remote server.
     */
    public function setProductInformation(bool $onlyMedia): bool
    {
        if ($onlyMedia) {
            CliLogger::section("\n\nProduct media (--product-media-only)");
        } else {
            CliLogger::section("\n\nProduct information");
        }

        // ---- Fetch the topid products
        $topid_products = $this->topdataToProductHelperService->getTopidProducts(true);
        $productDataUpdate = [];
        $productDataUpdateCovers = [];
        $productDataDeleteDuplicateMedia = [];

        $chunkSize = 50;

        // ---- Split the topid products into chunks
        $topids = array_chunk(array_keys($topid_products), $chunkSize);
        CliLogger::lap(true);

        foreach ($topids as $k => $prs) {
            // ---- Skip chunks based on start and end options
            if ($this->optionsHelperService->getOption(OptionConstants::START) && ($k + 1 < $this->optionsHelperService->getOption(OptionConstants::START))) {
                continue;
            }

            if ($this->optionsHelperService->getOption(OptionConstants::END) && ($k + 1 > $this->optionsHelperService->getOption(OptionConstants::END))) {
                break;
            }

            CliLogger::activity('xxx3 - Getting data from remote server part ' . ($k + 1) . '/' . count($topids) . ' (' . count($prs) . ' products)...');

            // ---- Fetch product data from the webservice
            $products = $this->topdataWebserviceClient->myProductList([
                'products' => implode(',', $prs),
                'filter'   => FilterTypeConstants::all,
            ]);
            CliLogger::activity(CliLogger::lap() . "sec\n");

            if (!isset($products->page->available_pages)) {
                throw new WebserviceResponseException($products->error[0]->error_message . 'webservice response has no pages');
            }
            CliLogger::activity('Processing data...');

            $temp = array_slice($topid_products, $k * $chunkSize, $chunkSize);
            $currentChunkProductIds = [];
            foreach ($temp as $p) {
                $currentChunkProductIds[] = $p[0]['product_id'];
            }

            // ---- Load product import settings for the current chunk of products
            $this->productImportSettingsService->loadProductImportSettings($currentChunkProductIds);

            // ---- Unlink products, properties, categories and images before re-linking
            if (!$onlyMedia) {
                $this->_unlinkProducts($currentChunkProductIds);
                $this->_unlinkProperties($currentChunkProductIds);
                $this->_unlinkCategories($currentChunkProductIds);
            }
            $this->_unlinkImages($currentChunkProductIds);

            // ---- Process products
            foreach ($products->products as $product) {
                if (!isset($topid_products[$product->products_id])) {
                    continue;
                }

                // ---- Prepare product data for update
                $productData = $this->_prepareProduct($topid_products[$product->products_id][0], $product, $onlyMedia);
                if ($productData) {
                    $productDataUpdate[] = $productData;

                    if (isset($productData['media'][0]['id'])) {
                        $productDataUpdateCovers[] = [
                            'id'      => $productData['id'],
                            'coverId' => $productData['media'][0]['id'],
                        ];
                        foreach ($productData['media'] as $tempMedia) {
                            $productDataDeleteDuplicateMedia[] = [
                                'productId' => $productData['id'],
                                'mediaId'   => $tempMedia['mediaId'],
                                'id'        => $tempMedia['id'],
                            ];
                        }
                    }
                }

                // ---- Update product data in chunks
                if (count($productDataUpdate) > 10) {
                    $this->productRepository->update($productDataUpdate, $this->context);
                    $productDataUpdate = [];
                    CliLogger::activity();

                    if (count($productDataUpdateCovers)) {
                        $this->productRepository->update($productDataUpdateCovers, $this->context);
                        CliLogger::activity();
                        $productDataUpdateCovers = [];
                    }
                }

                // ---- Link products
                if (!$onlyMedia) {
                    $this->productLinkingService->linkProducts($topid_products[$product->products_id][0], $product);
                }
            }
            CliLogger::mem();
            CliLogger::activity(' ' . CliLogger::lap() . "sec\n");
        }

        // ---- Update remaining product data
        if (count($productDataUpdate)) {
            CliLogger::activity('Updating last ' . count($productDataUpdate) . ' products...');
            $this->productRepository->update($productDataUpdate, $this->context);
            CliLogger::mem();
            CliLogger::activity(' ' . CliLogger::lap() . "sec\n");
        }

        // ---- Update remaining product covers
        if (count($productDataUpdateCovers)) {
            CliLogger::activity("\nUpdating last product covers...");
            $this->productRepository->update($productDataUpdateCovers, $this->context);
            CliLogger::activity(' ' . CliLogger::lap() . "sec\n");
        }

        // ---- Delete duplicate media
        if (count($productDataDeleteDuplicateMedia)) {
            CliLogger::activity("\nDeleting product media duplicates...");
            $chunks = array_chunk($productDataDeleteDuplicateMedia, 100);
            foreach ($chunks as $chunk) {
                $productIds = [];
                $mediaIds = [];
                $pmIds = [];
                foreach ($chunk as $el) {
                    $productIds[] = $el['productId'];
                    $mediaIds[] = $el['mediaId'];
                    $pmIds[] = $el['id'];
                }
                $productIds = '0x' . implode(', 0x', $productIds);
                $mediaIds = '0x' . implode(', 0x', $mediaIds);
                $pmIds = '0x' . implode(', 0x', $pmIds);

                $this->connection->executeStatement("
                    DELETE FROM product_media 
                    WHERE (product_id IN ($productIds)) 
                        AND (media_id IN ($mediaIds)) 
                        AND(id NOT IN ($pmIds))
                ");
                CliLogger::activity();
            }
            CliLogger::mem();
            CliLogger::activity(' ' . CliLogger::lap() . "sec\n");
        }

        CliLogger::writeln("\nProduct information done!");

        return true;
    }

    /**
     * Unlinks products from similar, alternate, related, bundled, color variant, capacity variant and variant products.
     *
     * @param array $productIds Array of product IDs to unlink.
     */
    private function _unlinkProducts(array $productIds): void
    {
        if (!count($productIds)) {
            return;
        }

        $ids = $this->filterIdsByConfig('productSimilar', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_similar WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productAlternate', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_alternate WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productRelated', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_related WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productBundled', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_bundled WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productColorVariant', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_color_variant WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productCapacityVariant', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_capacity_variant WHERE product_id IN ($ids)");
        }

        $ids = $this->filterIdsByConfig('productVariant', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("DELETE FROM topdata_product_to_variant WHERE product_id IN ($ids)");
        }
    }

    /**
     * Unlinks properties from products.
     *
     * @param array $productIds Array of product IDs to unlink properties from.
     */
    private function _unlinkProperties(array $productIds): void
    {
        if (!count($productIds)) {
            return;
        }

        $ids = $this->filterIdsByConfig('productSpecifications', $productIds);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("UPDATE product SET property_ids = NULL WHERE id IN ($ids)");
            $this->connection->executeStatement("DELETE FROM product_property WHERE product_id IN ($ids)");
        }
    }

    /**
     * Unlinks images from products.
     *
     * @param array $productIds Array of product IDs to unlink images from.
     */
    private function _unlinkImages(array $productIds): void
    {
        if (!count($productIds)) {
            return;
        }

        $ids = $this->filterIdsByConfig('productImages', $productIds);
        if (!count($ids)) {
            return;
        }
        $ids = $this->filterIdsByConfig('productImagesDelete', $ids);
        if (count($ids)) {
            $ids = '0x' . implode(',0x', $ids);
            $this->connection->executeStatement("UPDATE product SET product_media_id = NULL, product_media_version_id = NULL WHERE id IN ($ids)");
            $this->connection->executeStatement("DELETE FROM product_media WHERE product_id IN ($ids)");
        }
    }

    /**
     * Unlinks categories from products.
     *
     * @param array $productIds Array of product IDs to unlink categories from.
     */
    private function _unlinkCategories(array $productIds): void
    {
        if (!count($productIds)
            || !$this->optionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS)
            || !$this->optionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS_DELETE)) {
            return;
        }

        $idsString = '0x' . implode(',0x', $productIds);
        $this->connection->executeStatement("DELETE FROM product_category WHERE product_id IN ($idsString)");
        $this->connection->executeStatement("DELETE FROM product_category_tree WHERE product_id IN ($idsString)");
        $this->connection->executeStatement("UPDATE product SET category_tree = NULL WHERE id IN ($idsString)");
    }

    /**
     * Prepares product data for update.
     *
     * @param array $productId_versionId Array containing the product ID and version ID.
     * @param object $remoteProductData Remote product data object.
     * @param bool $onlyMedia If true, only media information is prepared; otherwise, all product information is prepared.
     * @return array Prepared product data array.
     */
    private function _prepareProduct(array $productId_versionId, $remoteProductData, $onlyMedia = false): array
    {
        $productData = [];
        $productId = $productId_versionId['product_id'];

        // ---- Prepare product name
        if (!$onlyMedia && $this->productImportSettingsService->getProductOption('productName', $productId) && $remoteProductData->short_description != '') {
            $productData['name'] = trim(substr($remoteProductData->short_description, 0, 255));
        }

        // ---- Prepare product description
        if (!$onlyMedia && $this->productImportSettingsService->getProductOption('productDescription', $productId) && $remoteProductData->short_description != '') {
            $productData['description'] = $remoteProductData->short_description;
        }

        //        $this->getOption('productLongDescription') ???
        //         $productData['description'] = $remoteProductData->short_description;

        // ---- Prepare product manufacturer
        if (!$onlyMedia && $this->productImportSettingsService->getProductOption('productBrand', $productId) && $remoteProductData->manufacturer != '') {
            $productData['manufacturerId'] = $this->manufacturerService->getManufacturerIdByName($remoteProductData->manufacturer); // fixme
        }
        // ---- Prepare product EAN
        if (!$onlyMedia && $this->productImportSettingsService->getProductOption('productEan', $productId) && count($remoteProductData->eans)) {
            $productData['ean'] = $remoteProductData->eans[0];
        }
        // ---- Prepare product OEM
        if (!$onlyMedia && $this->productImportSettingsService->getProductOption('productOem', $productId) && count($remoteProductData->oems)) {
            $productData['manufacturerNumber'] = $remoteProductData->oems[0];
        }

        // ---- Prepare product images
        if ($this->productImportSettingsService->getProductOption('productImages', $productId)) {
            if (isset($remoteProductData->images) && count($remoteProductData->images)) {
                $media = [];
                foreach ($remoteProductData->images as $k => $img) {
                    if (isset($img->big->url)) {
                        $imageUrl = $img->big->url;
                    } elseif (isset($img->normal->url)) {
                        $imageUrl = $img->normal->url;
                    } elseif (isset($img->thumb->url)) {
                        $imageUrl = $img->thumb->url;
                    } else {
                        continue;
                    }

                    if (isset($img->date)) {
                        $imageDate = strtotime(explode(' ', $img->date)[0]);
                    } else {
                        $imageDate = strtotime('2017-01-01');
                    }

                    try {
                        $echoMediaDownload = 'd';
                        $mediaId = $this->mediaHelperService->getMediaId(
                            $imageUrl,
                            $imageDate,
                            $k . '-' . $remoteProductData->products_id . '-',
                            $echoMediaDownload
                        );
                        if ($mediaId) {
                            $media[] = [
                                'id'       => Uuid::randomHex(), // $mediaId,
                                'position' => $k + 1,
                                'mediaId'  => $mediaId,
                            ];
                        }
                    } catch (Exception $e) {
                        $this->logger->error($e->getMessage());
                        CliLogger::writeln('Exception: ' . $e->getMessage());
                    }
                }
                if (count($media)) {
                    $productData['media'] = $media;
                    //                    $productData['coverId'] = $media[0]['id'];
                }
                CliLogger::activity();
            }
        }

        // ---- Prepare product reference PCD
        if (!$onlyMedia
            && $this->productImportSettingsService->getProductOption('specReferencePCD', $productId)
            && isset($remoteProductData->reference_pcds)
            && count((array)$remoteProductData->reference_pcds)
        ) {
            $propGroupName = 'Reference PCD';
            foreach ((array)$remoteProductData->reference_pcds as $propValue) {
                $propValue = trim(substr(UtilStringFormatting::formatStringNoHTML($propValue), 0, 255));
                if ($propValue == '') {
                    continue;
                }
                $propertyId = $this->entitiesHelperService->getPropertyId($propGroupName, $propValue);

                if (!isset($productData['properties'])) {
                    $productData['properties'] = [];
                }
                $productData['properties'][] = ['id' => $propertyId];
            }
            CliLogger::activity();
        }

        // ---- Prepare product reference OEM
        if (!$onlyMedia
            && $this->productImportSettingsService->getProductOption('specReferenceOEM', $productId)
            && isset($remoteProductData->reference_oems)
            && count((array)$remoteProductData->reference_oems)
        ) {
            $propGroupName = 'Reference OEM';
            foreach ((array)$remoteProductData->reference_oems as $propValue) {
                $propValue = trim(substr(UtilStringFormatting::formatStringNoHTML($propValue), 0, 255));
                if ($propValue == '') {
                    continue;
                }
                $propertyId = $this->entitiesHelperService->getPropertyId($propGroupName, $propValue);
                if (!isset($productData['properties'])) {
                    $productData['properties'] = [];
                }
                $productData['properties'][] = ['id' => $propertyId];
            }
            CliLogger::activity();
        }

        // ---- Prepare product specifications
        if (!$onlyMedia
            && $this->productImportSettingsService->getProductOption('productSpecifications', $productId)
            && isset($remoteProductData->specifications)
            && count($remoteProductData->specifications)
        ) {
            $ignoreSpecs = self::IGNORE_SPECS;
            foreach ($remoteProductData->specifications as $spec) {
                if (isset($ignoreSpecs[$spec->specification_id])) {
                    continue;
                }
                $propGroupName = trim(substr(trim(UtilStringFormatting::formatStringNoHTML($spec->specification)), 0, 255));
                if ($propGroupName == '') {
                    continue;
                }
                $propValue = trim(substr(UtilStringFormatting::formatStringNoHTML(($spec->count > 1 ? $spec->count . ' x ' : '') . $spec->attribute . (isset($spec->attribute_extension) ? ' ' . $spec->attribute_extension : '')), 0, 255));
                if ($propValue == '') {
                    continue;
                }

                $propertyId = $this->entitiesHelperService->getPropertyId($propGroupName, $propValue);
                if (!isset($productData['properties'])) {
                    $productData['properties'] = [];
                }
                $productData['properties'][] = ['id' => $propertyId];
            }
            CliLogger::activity();
        }

        // ---- Prepare product waregroups (categories)
        if (
            !$onlyMedia
            && $this->optionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS)
            && isset($remoteProductData->waregroups)
        ) {
            foreach ($remoteProductData->waregroups as $waregroupObject) {
                $categoriesChain = json_decode(json_encode($waregroupObject->waregroup_tree), true);
                $categoryId = $this->entitiesHelperService->getCategoryId($categoriesChain, (string)$this->optionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS_PARENT));
                if (!$categoryId) {
                    break;
                }
                if (!isset($productData['categories'])) {
                    $productData['categories'] = [];
                }
                $productData['categories'][] = ['id' => $categoryId];
            }
        }

        if (!count($productData)) {
            return [];
        }

        $productData['id'] = $productId;

        //\Topdata\TopdataFoundationSW6\Util\CliLogger::activity('-'.$productId_versionId['product_id'].'-');
        return $productData;
    }

    /**
     * Filters product IDs based on a given configuration option.
     *
     * @param string $optionName The name of the configuration option to check.
     * @param array $productIds An array of product IDs to filter.
     * @return array An array of product IDs that match the configuration option.
     */
    private function filterIdsByConfig(string $optionName, array $productIds): array
    {
        $returnIds = [];
        foreach ($productIds as $pid) {
            if ($this->productImportSettingsService->getProductOption($optionName, $pid)) {
                $returnIds[] = $pid;
            }
        }

        return $returnIds;
    }
}