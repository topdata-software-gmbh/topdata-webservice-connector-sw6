<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\DescriptionImportTypeConstant;
use Topdata\TopdataConnectorSW6\Constants\GlobalPluginConstants;
use Topdata\TopdataConnectorSW6\Constants\WebserviceFilterTypeConstants;
use Topdata\TopdataConnectorSW6\Constants\OptionConstants;
use Topdata\TopdataConnectorSW6\Exception\WebserviceResponseException;
use Topdata\TopdataConnectorSW6\Service\DbHelper\TopdataToProductService;
use Topdata\TopdataConnectorSW6\Service\Linking\ProductProductRelationshipService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataConnectorSW6\Util\UtilProfiling;
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
class ProductInformationServiceV1Slow
{
    const CHUNK_SIZE   = 50;

    private Context $context;

    public function __construct(
        private readonly TopdataToProductService           $topdataToProductHelperService,
        private readonly MergedPluginConfigHelperService   $topfeedOptionsHelperService,
        private readonly ProductProductRelationshipService $productProductRelationshipService,
        private readonly EntityRepository                  $productRepository,
        private readonly TopdataWebserviceClient           $topdataWebserviceClient,
        private readonly ProductImportSettingsService      $productImportSettingsService,
        private readonly EntitiesHelperService             $entitiesHelperService,
        private readonly MediaHelperService                $mediaHelperService,
        private readonly LoggerInterface                   $logger,
        private readonly ManufacturerService               $manufacturerService,
        private readonly Connection                        $connection,
    )
    {
        $this->context = Context::createDefaultContext();
    }


    /**
     * 04/2025 TODO: this method is way too slow .. optimize it

     * Updates product information and media.
     *
     * Fetches product data from a remote server, processes it, and updates the local database.
     * It handles both product information and media updates based on the $onlyMedia flag.
     *
     * @param bool $onlyMedia If true, only media information is updated; otherwise, all product information is updated.
     * @throws Exception If there is an error fetching data from the remote server.
     */
    public function setProductInformationV1Slow(bool $onlyMedia): void
    {
        UtilProfiling::startTimer();

        if ($onlyMedia) {
            CliLogger::section("\n\nProduct media (--product-media-only)");
        } else {
            CliLogger::section("\n\nProduct information");
        }

        // ---- Fetch the topid products
        $topid_products = $this->topdataToProductHelperService->getTopdataProductMappings(true);
        $productDataUpdate = [];
        $productDataUpdateCovers = [];
        $productDataDeleteDuplicateMedia = [];

        // ---- Split the topid products into chunks
        $batches = array_chunk(array_keys($topid_products), self::CHUNK_SIZE);
        CliLogger::lap(true);

        foreach ($batches as $idxBatch => $batch) {
            CliLogger::activity('xxx3 - Getting data from remote server part ' . ($idxBatch + 1) . '/' . count($batches) . ' (' . count($batch) . ' products)...');

            // ---- Fetch product data from the webservice
            $response = $this->topdataWebserviceClient->myProductList([
                'products' => implode(',', $batch),
                'filter'   => WebserviceFilterTypeConstants::all,
            ]);
            CliLogger::activity(CliLogger::lap() . "sec\n");

            if (!isset($response->page->available_pages)) {
                throw new WebserviceResponseException($response->error[0]->error_message . 'webservice response has no pages');
            }
            CliLogger::activity('Processing data...');

            $temp = array_slice($topid_products, $idxBatch * self::CHUNK_SIZE, self::CHUNK_SIZE);
            $currentChunkProductIds = [];
            foreach ($temp as $p) {
                $currentChunkProductIds[] = $p[0]['product_id']; // FIXME? isnt this the same as $batch?
            }

            // ---- Load product import settings for the current chunk of products
            $this->productImportSettingsService->loadProductImportSettings($currentChunkProductIds);

            // ---- Unlink products, properties, categories and images before re-linking
            if (!$onlyMedia) {
                $this->productProductRelationshipService->unlinkProducts($currentChunkProductIds);
                $this->_unlinkProperties($currentChunkProductIds);
                $this->_unlinkCategories($currentChunkProductIds);
            }
            $this->_unlinkImages($currentChunkProductIds);

            // ---- Process products
            foreach ($response->products as $product) {
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
                    $this->productProductRelationshipService->linkProducts($topid_products[$product->products_id][0], $product);
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

        UtilProfiling::stopTimer();
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

        $ids = $this->productImportSettingsService->filterProductIdsByConfig('productSpecifications', $productIds);
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

        $ids = $this->productImportSettingsService->filterProductIdsByConfig('productImages', $productIds);
        if (!count($ids)) {
            return;
        }
        $ids = $this->productImportSettingsService->filterProductIdsByConfig('productImagesDelete', $ids);
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
            || !$this->topfeedOptionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS)
            || !$this->topfeedOptionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS_DELETE)) {
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
        if (!$onlyMedia && $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productName, $productId) && $remoteProductData->short_description != '') {
            $productData['name'] = trim(substr($remoteProductData->short_description, 0, 255));
        }

        // ---- Prepare product description
        $descriptionImportType = $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productDescription, $productId);
        if (!$onlyMedia && $descriptionImportType && ($descriptionImportType !== DescriptionImportTypeConstant::NO_IMPORT) && $remoteProductData->short_description != '') {
            $newDescription = $this->_renderDescription($descriptionImportType, $productId, $remoteProductData->short_description);
            if ($newDescription !== null) {
                $productData['description'] = $newDescription;
            }
        }

        // ---- Prepare product manufacturer
        if (!$onlyMedia && $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productBrand, $productId) && $remoteProductData->manufacturer != '') {
            $productData['manufacturerId'] = $this->manufacturerService->getManufacturerIdByName($remoteProductData->manufacturer); // fixme
        }
        // ---- Prepare product EAN
        if (!$onlyMedia && $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productEan, $productId) && count($remoteProductData->eans)) {
            $productData['ean'] = $remoteProductData->eans[0];
        }
        // ---- Prepare product OEM
        if (!$onlyMedia && $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productOem, $productId) && count($remoteProductData->oems)) {
            $productData['manufacturerNumber'] = $remoteProductData->oems[0];
        }

        // ---- Prepare product images
        if ($this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productImages, $productId)) {
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
            && $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_specReferencePCD, $productId)
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
            && $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_specReferenceOEM, $productId)
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
            && $this->productImportSettingsService->isProductOptionEnabled(ProductImportSettingsService::OPTION_NAME_productSpecifications, $productId)
            && isset($remoteProductData->specifications)
            && count($remoteProductData->specifications)
        ) {
            $ignoreSpecs = GlobalPluginConstants::IGNORE_SPECS;
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
            && $this->topfeedOptionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS)
            && isset($remoteProductData->waregroups)
        ) {
            foreach ($remoteProductData->waregroups as $waregroupObject) {
                $categoriesChain = json_decode(json_encode($waregroupObject->waregroup_tree), true);
                $categoryId = $this->entitiesHelperService->getCategoryId($categoriesChain, (string)$this->topfeedOptionsHelperService->getOption(OptionConstants::PRODUCT_WAREGROUPS_PARENT));
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
     * 03/2025 created
     */
    private function _renderDescription(?string $descriptionImportType, string $productId, $descriptionFromWebservice): ?string
    {
        if (empty($descriptionFromWebservice)) {
            return null;
        }

        if ($descriptionImportType === DescriptionImportTypeConstant::REPLACE) {
            return $descriptionFromWebservice;
        }

        if ($descriptionImportType === DescriptionImportTypeConstant::NO_IMPORT) {
            return null;
        }

        // -- fetch original description from DB
        $criteria = new Criteria([$productId]);
        /** @var ProductEntity $product */
        $product = $this->productRepository->search($criteria, $this->context)->first();
        if (!$product) {
            return null;
        }
        $originalDescription = $product->getDescription();

        // -- append
        if ($descriptionImportType === DescriptionImportTypeConstant::APPEND) {
            return $originalDescription . ' ' . $descriptionFromWebservice; // fixme: will not work if running the 2nd time
        }

        // -- prepend
        if ($descriptionImportType === DescriptionImportTypeConstant::PREPEND) {
            return $descriptionFromWebservice . ' ' . $originalDescription; // fixme: will not work if running the 2nd time
        }

        // -- inject
        if ($descriptionImportType === DescriptionImportTypeConstant::INJECT) {
            $regex = '@<!--\s*TOPDATA_DESCRIPTION_BEGIN\s*-->(.*)<!--\s*TOPDATA_DESCRIPTION_END\s*-->@si'; // si stands for case insensitive and multiline
            $replacement = '<!-- TOPDATA_DESCRIPTION_BEGIN -->' . $descriptionFromWebservice . '<!-- TOPDATA_DESCRIPTION_END -->';
            return preg_replace($regex, $replacement, $originalDescription);
        }

        return $descriptionFromWebservice;
    }
}