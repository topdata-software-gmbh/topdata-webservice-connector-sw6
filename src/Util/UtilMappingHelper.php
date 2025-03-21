<?php

namespace Topdata\TopdataConnectorSW6\Util;

/**
 * 03/2025 created
 */
class UtilMappingHelper
{


    /**
     * Converts binary IDs in a multi-dimensional array to hexadecimal strings.
     *
     * This method iterates over a multi-dimensional array and converts the binary
     * 'id' and 'version_id' fields to their hexadecimal string representations.
     *
     * @param array $arr The input array containing binary IDs.
     * @return array The modified array with hexadecimal string IDs.
     */
    public static function convertMultiArrayBinaryIdsToHex(array $arr): array
    {
        foreach ($arr as $no => $vals) {
            foreach ($vals as $key => $val) {
                if (isset($arr[$no][$key]['id'])) {
                    $arr[$no][$key]['id'] = bin2hex($arr[$no][$key]['id']);
                }
                if (isset($arr[$no][$key]['version_id'])) {
                    $arr[$no][$key]['version_id'] = bin2hex($arr[$no][$key]['version_id']);
                }
            }
        }

        return $arr;
    }


    public static function _fixArrayBinaryIds(array $arr): array
    {
        foreach ($arr as $key => $val) {
            if (isset($arr[$key]['id'])) {
                $arr[$key]['id'] = bin2hex($arr[$key]['id']);
            }
            if (isset($arr[$key]['version_id'])) {
                $arr[$key]['version_id'] = bin2hex($arr[$key]['version_id']);
            }
        }

        return $arr;
    }


}