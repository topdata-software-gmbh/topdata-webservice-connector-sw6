<?php

namespace Topdata\TopdataConnectorSW6\Constants;

/**
 * TODO? rename to TopfeedOptionConstants?
 *
 * Constants for option names.
 * // TODO: make it enum
 *
 * 10/2024 created (extracted from MappingHelperService)
 * 04/2025 renamed from OptionConstants to MergedPluginConfigKeyConstants
 */
class MergedPluginConfigKeyConstants
{
    // ---- keys from the connector plugin config ... they are mapping options how to map the webservice data to the shopware data
    const MAPPING_TYPE              = 'mappingType'; // aka MappingStrategy ("default" [EAN, OEM], "distributor", "product number")
    const ATTRIBUTE_OEM             = 'attributeOem'; // this is the Name of the OEM attribute in Shopware
    const ATTRIBUTE_EAN             = 'attributeEan'; // this is the name of the EAN attribute in Shopware
    const ATTRIBUTE_ORDERNUMBER     = 'attributeOrdernumber'; // FIXME: this is not an ordernumber, but a product number


    // ---- keys from the Topfeed plugin config - used for linking/unlinking products to categories and products to products
    const PRODUCT_WAREGROUPS        = 'productWaregroups'; // allow to import product-category relations
    const PRODUCT_WAREGROUPS_DELETE = 'productWaregroupsDelete'; // allow to delete product-category relations
    const PRODUCT_WAREGROUPS_PARENT = 'productWaregroupsParent'; // something like id of the "root" category?
    const PRODUCT_COLOR_VARIANT     = 'productColorVariant';
    const PRODUCT_CAPACITY_VARIANT  = 'productCapacityVariant'; // unused?

    // moved from \Topdata\TopdataConnectorSW6\Service\Config\MergedPluginConfigHelperService to here
    const OPTION_NAME_productName                 = 'productName';
    const OPTION_NAME_productDescription          = 'productDescription';
    const OPTION_NAME_productBrand                = 'productBrand';
    const OPTION_NAME_productEan                  = 'productEan';
    const OPTION_NAME_productOem                  = 'productOem';
    const OPTION_NAME_productImages               = 'productImages';
    const OPTION_NAME_specReferencePCD            = 'specReferencePCD';
    const OPTION_NAME_specReferenceOEM            = 'specReferenceOEM';
    const OPTION_NAME_productSpecifications       = 'productSpecifications';
    const OPTION_NAME_productImagesDelete         = 'productImagesDelete'; // not used?
    const OPTION_NAME_productSimilar              = 'productSimilar';
    const OPTION_NAME_productSimilarCross         = 'productSimilarCross';
    const OPTION_NAME_productAlternate            = 'productAlternate';
    const OPTION_NAME_productAlternateCross       = 'productAlternateCross';
    const OPTION_NAME_productRelated              = 'productRelated';
    const OPTION_NAME_productRelatedCross         = 'productRelatedCross';
    const OPTION_NAME_productBundled              = 'productBundled';
    const OPTION_NAME_productBundledCross         = 'productBundledCross';
    const OPTION_NAME_productColorVariant         = 'productColorVariant';
    const OPTION_NAME_productVariantColorCross    = 'productVariantColorCross';
    const OPTION_NAME_productCapacityVariant      = 'productCapacityVariant';
    const OPTION_NAME_productVariantCapacityCross = 'productVariantCapacityCross';
    const OPTION_NAME_productVariant              = 'productVariant';
    const OPTION_NAME_productVariantCross         = 'productVariantCross';

}
