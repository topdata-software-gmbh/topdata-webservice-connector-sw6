<?php

namespace Topdata\TopdataConnectorSW6\Constants;

/**
 * Constants for option names.
 *
 * 10/2024 created (extracted from MappingHelperService)
 */
class OptionConstants
{
    const MAPPING_TYPE              = 'mappingType';
    const ATTRIBUTE_OEM             = 'attributeOem';
    const ATTRIBUTE_EAN             = 'attributeEan';
    const ATTRIBUTE_ORDERNUMBER     = 'attributeOrdernumber';
    const START                     = 'start'; // TODO: remove --> belongs to ImportCommandCliOptionsDTO
    const END                       = 'end'; // TODO: remove --> belongs to ImportCommandCliOptionsDTO
    const PRODUCT_WAREGROUPS        = 'productWaregroups'; // unused?
    const PRODUCT_WAREGROUPS_DELETE = 'productWaregroupsDelete'; // unused?
    const PRODUCT_WAREGROUPS_PARENT = 'productWaregroupsParent'; // unused?
    const PRODUCT_COLOR_VARIANT     = 'productColorVariant';
    const PRODUCT_CAPACITY_VARIANT  = 'productCapacityVariant';
}
