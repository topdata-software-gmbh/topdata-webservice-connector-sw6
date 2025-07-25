<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataConnectorSW6\Constants\MergedPluginConfigKeyConstants;
use Topdata\TopdataConnectorSW6\Service\Config\ProductImportSettingsService;
use Topdata\TopdataConnectorSW6\Util\ImportReport;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * 11/2024 created (extracted from EntitiesHelperService)
 */
class MediaHelperService
{
    const DEFAULT_MAIN_FOLDER = 'product';
    const UPLOAD_FOLDER_NAME  = 'TopData';


    private Context $context;
    private ?string $uploadFolderId = null;


    public function __construct(
        private readonly EntityRepository             $mediaRepository,
        private readonly EntityRepository             $mediaFolderRepository,
        private readonly MediaService                 $mediaService,
        private readonly ProductImportSettingsService $productImportSettingsService,
        private readonly Connection                   $connection,
    )
    {
        $this->context = Context::createDefaultContext();
    }


    /**
     * Retrieves the media ID for a given image path. If the media does not exist, it creates a new media entry.
     *
     * @param string $imagePath The path to the image file.
     * @param int $imageTimestamp The timestamp to append to the image name. Default is 0.
     * @param string $imagePrefix The prefix to prepend to the image name. Default is an empty string.
     * @param string $echoDownload The message to echo if the image needs to be downloaded. Default is an empty string.
     * @return string The media ID of the image.
     */
    public function getMediaId(string $imagePath, int $imageTimestamp = 0, string $imagePrefix = '', $echoDownload = ''): string
    {
        // Generate the image name using the provided path, timestamp, and prefix.
        $imageName = $imagePrefix . $this->generateMediaName($imagePath, $imageTimestamp);

        // Search for existing media with the generated image name.
        $existingMedia = $this->mediaRepository
            ->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('fileName', $imageName))
                    ->setLimit(1),
                $this->context
            )
            ->getEntities()
            ->first();

        // If the media exists, return its ID.
        if ($existingMedia) {
            $mediaId = $existingMedia->getId();
        } else {
            // If the media does not exist, echo the download message.
            echo $echoDownload;

            // Read the file content from the provided image path.
            $fileContent = file_get_contents($imagePath);

            // If the file content could not be read, return an empty string.
            if ($fileContent === false) {
                return '';
            }

            // Create a new media entry in the upload folder.
            $mediaId = $this->createMediaInFolder();

            // Save the file content as a new media entry and get its ID.
            $mediaId = $this->mediaService->saveFile(
                $fileContent,
                'jpg',
                'image/jpeg',
                $imageName,
                $this->context,
                null,
                $mediaId,
                false
            );
        }

        // Return the media ID.
        return $mediaId;
    }

    /**
     * Generates a media name based on the provided file path and timestamp.
     *
     * This method extracts the file name from the given path and appends the provided timestamp to it.
     *
     * @param string $path The path to the file.
     * @param int $timestamp The timestamp to append to the file name.
     * @return string The generated media name.
     */
    private function generateMediaName(string $path, int $timestamp): string
    {
        $fileName = pathinfo($path, PATHINFO_FILENAME) . '-' . $timestamp;

        return $fileName;
    }

    /**
     * Creates a new media entry in the upload folder.
     *
     * This method first checks if the upload folder ID is set. If not, it creates the upload folder.
     * Then, it generates a new media ID and creates a new media entry in the repository with the upload folder ID.
     *
     * @return string The newly created media ID.
     */
    private function createMediaInFolder(): string
    {
        if (!$this->uploadFolderId) {
            $this->createUploadFolder();
        }

        $mediaId = Uuid::randomHex();
        $this->mediaRepository->create(
            [
                [
                    'id'            => $mediaId,
                    'private'       => false,
                    'mediaFolderId' => $this->uploadFolderId,
                ],
            ],
            $this->context
        );

        return $mediaId;
    }

    /**
     * Creates the upload folder if it does not exist and sets the upload folder ID.
     *
     * This method first searches for the default folder and then checks if the upload folder
     * exists within the default folder. If the upload folder does not exist, it creates a new one.
     *
     * @return void
     */
    private function createUploadFolder(): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('media_folder.defaultFolder.entity', self::DEFAULT_MAIN_FOLDER));
        $criteria->addAssociation('defaultFolder');
        $criteria->setLimit(1);
        $defaultFolder = $this->mediaFolderRepository->search($criteria, $this->context)->first();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::UPLOAD_FOLDER_NAME));
        $criteria->addFilter(new EqualsFilter('parentId', $defaultFolder->getId()));
        $criteria->setLimit(1);
        $uploadFolder = $this
            ->mediaFolderRepository
            ->search(
                $criteria,
                $this->context
            )
            ->first();

        if ($uploadFolder) {
            $this->uploadFolderId = $uploadFolder->getId();

            return;
        }

        $this->uploadFolderId = Uuid::randomHex();
        $this->mediaFolderRepository->create(
            [
                [
                    'id'              => $this->uploadFolderId,
                    'private'         => false,
                    'name'            => self::UPLOAD_FOLDER_NAME,
                    'parentId'        => $defaultFolder->getId(),
                    'configurationId' => $defaultFolder->getConfigurationId(),
                ],
            ],
            $this->context
        );
    }


    /**
     * Unlinks images from products.
     * 05/2025 moved from ProductInformationServiceV1Slow::_unlinkImages() to MediaHelperService::unlinkImages()
     *
     * @param array $productIds Array of product IDs to unlink images from.
     */
    public function unlinkImages(array $productIds): void
    {
        if (!count($productIds)) {
            return;
        }

        $ids = '0x' . implode(',0x', $productIds);
        $this->connection->executeStatement("UPDATE product SET product_media_id = NULL, product_media_version_id = NULL WHERE id IN ($ids)");
        $this->connection->executeStatement("DELETE FROM product_media WHERE product_id IN ($ids)");
    }


    /**
     * 05/2025 extracted from ProductInformationServiceV1Slow::setProductInformationV1Slow()
     */
    public function deleteDuplicateMedia(array $productDataDeleteDuplicateMedia): void
    {
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

            $numDeleted = $this->connection->executeStatement("
                    DELETE FROM product_media 
                    WHERE (product_id IN ($productIds)) 
                        AND (media_id IN ($mediaIds)) 
                        AND(id NOT IN ($pmIds))
            ");

            ImportReport::incCounter('Deleted Duplicate Media', $numDeleted);

            CliLogger::activity();
        }
    }


}