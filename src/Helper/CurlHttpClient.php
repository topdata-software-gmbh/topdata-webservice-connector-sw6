<?php

namespace Topdata\TopdataConnectorSW6\Helper;

use Topdata\TopdataFoundationSW6\Trait\CliStyleTrait;
use TopdataSoftwareGmbH\Util\UtilDebug;

/**
 * 11/2024 created (extracted from TopdataWebserviceClient)
 */
class CurlHttpClient
{
    const CURL_TIMEOUT = 30;  // seconds

    public function __construct()
    {
        $this->beVerboseOnCli();
    }

    use CliStyleTrait;




    /**
     * Performs a cURL request to the specified URL and returns the JSON response
     *
     * @param string $url The URL to send the request to
     * @param mixed|null $xml_data XML data to be sent (currently unused)
     * @param int $attempt Current retry attempt number, used for handling timeouts
     *
     * @return mixed Decoded JSON response from the API
     * @throws \Exception When cURL errors occur or API returns an error response
     */
    public function get(string $url, $xml_data = null, $attempt = 1)
    {
        // ---- Initialize cURL request
        $this->cliStyle->writeln("<gray>$url</gray>");

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CURL_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
        $output = curl_exec($ch);

        // ---- Handle cURL errors and retry logic
        if (curl_errno($ch)) {
            if (curl_errno($ch) == 28 && $attempt < 3) { //TIMEOUT
                $this->cliStyle->warning('cURL-ERROR: ' . curl_error($ch) . "\n" . $url . "\n" . ' ... DO RETRY ' . ($attempt + 1) . "\n");

                return $this->get($url, $xml_data, ($attempt + 1));
            }
            throw new \Exception('cURL-ERROR: ' . curl_error($ch));
        }

        // ---- Handle Bad Request responses by removing headers from output
        $header = curl_getinfo($ch);
        if ($header['http_code'] == 400) {
            $header_size = strpos($output, '{');
            $output = substr($output, $header_size);
        }

        // ---- Clean up and process response
        curl_close($ch);
        $ret = json_decode($output);
        if (isset($ret->error)) {
            throw new \Exception($ret->error[0]->error_message . ' @topdataconnector webservice error');
        }

        // UtilDebug::d($ret);

        return $ret;
    }

}