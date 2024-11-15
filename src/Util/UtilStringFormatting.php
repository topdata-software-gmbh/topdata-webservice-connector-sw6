<?php

namespace Topdata\TopdataConnectorSW6\Util;


use Topdata\TopdataConnectorSW6\Service\MappingHelperService;

/**
 * 11/2024 created (extracted from MappingHelperService)
 */
class UtilStringFormatting
{

    public static function getWordsFromString(string $string): array
    {
        $rez = [];
        $string = str_replace(['-', '/', '+', '&', '.', ','], ' ', $string);
        $words = explode(' ', $string);
        foreach ($words as $word) {
            if (trim($word)) {
                $rez[] = trim($word);
            }
        }

        return $rez;
    }


    public static function firstLetters(string $string): string
    {
        $rez = '';
        foreach (self::getWordsFromString($string) as $word) {
            $rez .= mb_substr($word, 0, 1);
        }

        return $rez;
    }


    public static function formatStringNoHTML($string)
    {
        return self::formatString(strip_tags((string)$string));
    }


    public static function formatString($string)
    {
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', (string)$string));
    }

    /**
     * 06/2024 made it static.
     */
    public static function formCode(string $label): string
    {
        $replacement = [
            ' ' => '-',
        ];

        return strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', str_replace(array_keys($replacement), array_values($replacement), $label)));
    }


}