<?php

namespace Topdata\TopdataConnectorSW6\Util;

/**
 * 10/2024 created.
 */
class UtilUuid
{
    /**
     * Check if a string is a valid UUID.
     *
     * 10/2024 created (extracted from EntityHelperService)
     *
     * @param  string $uuid The string to check
     * @return bool   True if the string is a valid UUID, false otherwise
     */
    public static function isValidUuid($uuid): bool
    {
        if (!is_string($uuid)) {
            return false;
        }

        return (bool) preg_match('/^[0-9a-f]{32}$/', $uuid);
    }
}
