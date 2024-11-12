<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\DTO;

use Symfony\Component\Console\Input\InputInterface;

/**
 * 10/2024 created.
 *
 * it just holds the cli options for the `topdata:connector:import` command under different names
 * we use a dto for easy access to the cli options and easier code navigation
 */
class ImportCommandCliOptionsDTO
{
    private bool $isServiceAll;
    private bool $isServiceMapping;
    private bool $isServiceDevice;
    private bool $isServiceDeviceOnly;
    private bool $isServiceDeviceMedia;
    private bool $isServiceDeviceSynonyms;
    private bool $isServiceProduct;
    private bool $isServiceProductInformation;
    private bool $isServiceProductMediaOnly; // --product-media-only
    private bool $isProductVariations;

    public function __construct(InputInterface $input)
    {
        $this->isServiceAll                = (bool) $input->getOption('all'); // full update with webservice
        $this->isServiceMapping            = (bool) $input->getOption('mapping'); // Mapping all existing products to webservice
        $this->isServiceDevice             = (bool) $input->getOption('device'); // add devices from webservice
        $this->isServiceDeviceOnly         = (bool) $input->getOption('device-only'); // add devices from webservice (no brands/series/types are fetched)
        $this->isServiceDeviceMedia        = (bool) $input->getOption('device-media'); // update device media data
        $this->isServiceDeviceSynonyms     = (bool) $input->getOption('device-synonyms'); // link active devices to synonyms
        $this->isServiceProduct            = (bool) $input->getOption('product'); // link devices to products on the store
        $this->isServiceProductInformation = (bool) $input->getOption('product-info'); // update product information from webservice (TopFeed plugin needed)
        $this->isServiceProductMediaOnly   = (bool) $input->getOption('product-media-only'); // update only product media from webservice (TopFeed plugin needed)
        $this->isProductVariations         = (bool) $input->getOption('product-variated'); // Generate variated products based on color and capacity information
    }

    public function isServiceAll(): bool
    {
        return $this->isServiceAll;
    }

    public function isServiceMapping(): bool
    {
        return $this->isServiceMapping;
    }

    public function isServiceDevice(): bool
    {
        return $this->isServiceDevice;
    }

    public function isServiceDeviceOnly(): bool
    {
        return $this->isServiceDeviceOnly;
    }

    public function isServiceDeviceMedia(): bool
    {
        return $this->isServiceDeviceMedia;
    }

    public function isServiceDeviceSynonyms(): bool
    {
        return $this->isServiceDeviceSynonyms;
    }

    public function isServiceProduct(): bool
    {
        return $this->isServiceProduct;
    }

    public function isServiceProductInformation(): bool
    {
        return $this->isServiceProductInformation;
    }

    public function isServiceProductMediaOnly(): bool
    {
        return $this->isServiceProductMediaOnly;
    }

    public function isProductVariations(): bool
    {
        return $this->isProductVariations;
    }

    public function toDict(): array
    {
        return [
            'isServiceAll'                => $this->isServiceAll,
            'isServiceMapping'            => $this->isServiceMapping,
            'isServiceDevice'             => $this->isServiceDevice,
            'isServiceDeviceOnly'         => $this->isServiceDeviceOnly,
            'isServiceDeviceMedia'        => $this->isServiceDeviceMedia,
            'isServiceDeviceSynonyms'     => $this->isServiceDeviceSynonyms,
            'isServiceProduct'            => $this->isServiceProduct,
            'isServiceProductInformation' => $this->isServiceProductInformation,
            'isServiceProductMedia'       => $this->isServiceProductMediaOnly,
            'isProductVariations'         => $this->isProductVariations,
        ];
    }
}
