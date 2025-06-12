<?php

namespace Topdata\TopdataConnectorSW6\Enum;

/**
 * 06/2025 created - it will replace the V1
 */
enum ProductRelationshipTypeEnumV2: string
{
    case ALTERNATE        = 'ALTERNATE';
    case BUNDLED          = 'BUNDLED';
    case RELATED          = 'RELATED';
    case SIMILAR          = 'SIMILAR';
    case CAPACITY_VARIANT = 'CAPACITY_VARIANT';
    case COLOR_VARIANT    = 'COLOR_VARIANT';
    case VARIANT          = 'VARIANT';
}