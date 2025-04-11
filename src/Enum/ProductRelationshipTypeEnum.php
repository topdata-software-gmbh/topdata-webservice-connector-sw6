<?php

namespace Topdata\TopdataConnectorSW6\Enum;

/**
 * 11/2024 created (extracted from MappingHelperService)
 *
 * Constants for cross-selling types.
 * 04/2025 changed from CrossSellingTypeConstant to ProductRelationshipTypeEnum
 * TODO: make the values uppercase
 */
enum ProductRelationshipTypeEnum: string
{
    case SIMILAR          = 'similar';
    case ALTERNATE        = 'alternate';
    case RELATED          = 'related';
    case BUNDLED          = 'bundled';
    case COLOR_VARIANT    = 'colorVariant';
    case CAPACITY_VARIANT = 'capacityVariant';
    case VARIANT          = 'variant';
}