<?php

namespace Topdata\TopdataConnectorSW6\Constants;

/**
 * Constants for mapping types.
 *
 * 10/2024 created (extracted from MappingHelperService)
 * 05/2025 updated with cache mapping types
 */
class MappingTypeConstants
{
    // these are the options given to the user to choose from in the plugin config
    const PRODUCT_NUMBER_AS_WS_ID  = 'productNumberAsWsId';
    const DISTRIBUTOR_DEFAULT      = 'distributorDefault';
    const DISTRIBUTOR_CUSTOM       = 'distributorCustom';
    const DISTRIBUTOR_CUSTOM_FIELD = 'distributorCustomField';
    const DEFAULT                  = 'default';
    const CUSTOM                   = 'custom';
    const CUSTOM_FIELD             = 'customField';
    
    // Cache mapping types
    const EAN            = 'EAN';
    const OEM            = 'OEM';
    const PCD            = 'PCD';
}
