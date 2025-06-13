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
    const MAPPING_TYPE          = 'mappingType'; // aka MappingStrategy ("default" [EAN, OEM], "distributor", "product number")
    const ATTRIBUTE_OEM         = 'attributeOem'; // this is the Name of the OEM attribute in Shopware ... not sure if still needed in sw6
    const ATTRIBUTE_EAN         = 'attributeEan'; // this is the name of the EAN attribute in Shopware ... not sure if still needed in sw6
    const ATTRIBUTE_ORDERNUMBER = 'attributeOrdernumber'; // FIXME: this is not an ordernumber, but a product number


    // ---- keys from the Topfeed plugin config - used for linking/unlinking products to categories and products to products
    const PRODUCT_WAREGROUPS        = 'productWaregroups'; // allow to import product-category relations
    const PRODUCT_WAREGROUPS_DELETE = 'productWaregroupsDelete'; // allow to delete product-category relations
    const PRODUCT_WAREGROUPS_PARENT = 'productWaregroupsParent'; // something like id of the "root" category?
    const PRODUCT_COLOR_VARIANT     = 'productColorVariant';
    const PRODUCT_CAPACITY_VARIANT  = 'productCapacityVariant'; // unused?

    // moved from MergedPluginConfigHelperService to here:
    const OPTION_NAME_productName           = 'productName';
    const OPTION_NAME_productDescription    = 'productDescription';
    const OPTION_NAME_productBrand          = 'productBrand';
    const OPTION_NAME_productEan            = 'productEan';
    const OPTION_NAME_productOem            = 'productOem';
    const OPTION_NAME_productImages         = 'productImages';
    const OPTION_NAME_specReferencePCD      = 'specReferencePCD';
    const OPTION_NAME_specReferenceOEM      = 'specReferenceOEM';
    const OPTION_NAME_productSpecifications = 'productSpecifications';
    const OPTION_NAME_productImagesDelete   = 'productImagesDelete'; // not used?

    // ---- relationship options from the topfeed plugin config

    const RELATIONSHIP_OPTION_productSimilar         = 'productSimilar';
    const RELATIONSHIP_OPTION_productAlternate       = 'productAlternate';
    const RELATIONSHIO_OPTION_productRelated         = 'productRelated';
    const RELATIONSHIP_OPTION_productBundled         = 'productBundled';
    const RELATIONSHIP_OPTION_productColorVariant    = 'productColorVariant';
    const RELATIONSHIP_OPTION_productCapacityVariant = 'productCapacityVariant';
    const RELATIONSHIP_OPTION_productVariant         = 'productVariant';
    // ---- cross-selling options from the topfeed plugin config [whether cross-selling is enabled]
    const OPTION_NAME_productSimilarCross         = 'productSimilarCross';
    const OPTION_NAME_productAlternateCross       = 'productAlternateCross';
    const OPTION_NAME_productRelatedCross         = 'productRelatedCross';
    const OPTION_NAME_productBundledCross         = 'productBundledCross';
    const OPTION_NAME_productVariantColorCross    = 'productVariantColorCross';
    const OPTION_NAME_productVariantCapacityCross = 'productVariantCapacityCross';
    const OPTION_NAME_productVariantCross         = 'productVariantCross';

    // ---- display options from the topfeed plugin config
    const DISPLAY_OPTION_showAlternateProductsTab       = 'showAlternateProductsTab';
    const DISPLAY_OPTION_showBundledProductsTab         = 'showBundledProductsTab';
    const DISPLAY_OPTION_showBundlesTab                 = 'showBundlesTab';
    const DISPLAY_OPTION_showRelatedProductsTab         = 'showRelatedProductsTab';
    const DISPLAY_OPTION_showSimilarProductsTab         = 'showSimilarProductsTab';
    const DISPLAY_OPTION_showVariantProductsTab         = 'showVariantProductsTab';
    const DISPLAY_OPTION_showColorVariantProductsTab    = 'showColorVariantProductsTab';
    const DISPLAY_OPTION_showCapacityVariantProductsTab = 'showCapacityVariantProductsTab';

    // ---- list options from the topfeed plugin config
    const LIST_OPTION_listColorVariantProducts    = 'listColorVariantProducts';
    const LIST_OPTION_listCapacityVariantProducts = 'listCapacityVariantProducts';
    const LIST_OPTION_listAlternateProducts       = 'listAlternateProducts';
    const LIST_OPTION_listBundledProducts         = 'listBundledProducts';
    const LIST_OPTION_listBundles                 = 'listBundles';
    const LIST_OPTION_listRelatedProducts         = 'listRelatedProducts';
    const LIST_OPTION_listVariantProducts         = 'listVariantProducts';
}
