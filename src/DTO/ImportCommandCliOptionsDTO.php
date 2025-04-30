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
    private bool $optionAll; // --all
    private bool $optionMapping; // --mapping
    private bool $optionDevice; // --device
    private bool $optionDeviceOnly; // --device-only
    private bool $optionDeviceMedia; // --device-media
    private bool $optionDeviceSynonyms; // --device-synonyms
    private bool $optionProduct; // --product
    private bool $optionProductInformation; // --product-information
    private bool $optionProductMediaOnly; // --product-media-only
    private bool $optionProductVariations; // --product-variated
    private bool $optionExperimentalV2; // --experimental-v2, 04/2025 added
    private bool $optionProductDevice;

    public function __construct(InputInterface $input)
    {
        $this->optionAll = (bool)$input->getOption('all'); // full update with webservice
        $this->optionMapping = (bool)$input->getOption('mapping'); // Mapping all existing products to webservice
        $this->optionDevice = (bool)$input->getOption('device'); // add devices from webservice
        $this->optionDeviceOnly = (bool)$input->getOption('device-only'); // add devices from webservice (no brands/series/types are fetched)
        $this->optionDeviceMedia = (bool)$input->getOption('device-media'); // update device media data
        $this->optionDeviceSynonyms = (bool)$input->getOption('device-synonyms'); // link active devices to synonyms
        $this->optionProduct = (bool)$input->getOption('product'); // link devices to products on the store
        $this->optionProductInformation = (bool)$input->getOption('product-info'); // update product information from webservice (TopFeed plugin needed)
        $this->optionProductMediaOnly = (bool)$input->getOption('product-media-only'); // update only product media from webservice (TopFeed plugin needed)
        $this->optionProductVariations = (bool)$input->getOption('product-variated'); // Generate variated products based on color and capacity information
        $this->optionExperimentalV2 = (bool)$input->getOption('experimental-v2');
        $this->optionProductDevice = (bool)$input->getOption('product-device');
    }

    public function getOptionAll(): bool
    {
        return $this->optionAll;
    }

    public function getOptionMapping(): bool
    {
        return $this->optionMapping;
    }

    public function getOptionDevice(): bool
    {
        return $this->optionDevice;
    }

    public function getOptionDeviceOnly(): bool
    {
        return $this->optionDeviceOnly;
    }

    public function getOptionDeviceMedia(): bool
    {
        return $this->optionDeviceMedia;
    }

    public function getOptionDeviceSynonyms(): bool
    {
        return $this->optionDeviceSynonyms;
    }

    public function getOptionProduct(): bool
    {
        return $this->optionProduct;
    }

    public function getOptionProductInformation(): bool
    {
        return $this->optionProductInformation;
    }

    public function getOptionProductMediaOnly(): bool
    {
        return $this->optionProductMediaOnly;
    }

    public function getOptionProductVariations(): bool
    {
        return $this->optionProductVariations;
    }

    public function getOptionExperimentalV2(): bool
    {
        return $this->optionExperimentalV2;
    }

    public function getOptionProductDevice(): bool
    {
        return $this->optionProductDevice;
    }


    public function toDict(): array
    {
        return [
            'optionAll'                => $this->optionAll,
            'optionMapping'            => $this->optionMapping,
            'optionDevice'             => $this->optionDevice,
            'optionDeviceOnly'         => $this->optionDeviceOnly,
            'optionDeviceMedia'        => $this->optionDeviceMedia,
            'optionDeviceSynonyms'     => $this->optionDeviceSynonyms,
            'optionProduct'            => $this->optionProduct,
            'optionProductDevice'            => $this->optionProductDevice,
            'optionProductInformation' => $this->optionProductInformation,
            'optionProductMedia'       => $this->optionProductMediaOnly,
            'optionProductVariations'  => $this->optionProductVariations,
            'optionExperimentalV2'     => $this->optionExperimentalV2,
        ];
    }



}
