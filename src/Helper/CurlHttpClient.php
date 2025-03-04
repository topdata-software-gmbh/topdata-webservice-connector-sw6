<?php

namespace Topdata\TopdataConnectorSW6\Helper;

use Topdata\TopdataFoundationSW6\Trait\CliStyleTrait;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use TopdataSoftwareGmbH\Util\UtilDebug;

/**
 * 11/2024 created (extracted from TopdataWebserviceClient)
 */
class CurlHttpClient
{
    const CURL_TIMEOUT = 30;  // seconds

    public function __construct(
        private readonly int $initialDelayMs = 1000,
        private readonly int $maxRetries = 3,
        private readonly float $backoffMultiplier = 2.0
    )
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

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CURL_TIMEOUT);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
            $output = curl_exec($ch);

            // ---- Handle cURL errors
            if (curl_errno($ch)) {
                throw new \Exception('cURL-ERROR: ' . curl_error($ch));
            }

            // ---- Handle HTTP status codes
            $header = curl_getinfo($ch);
            if ($header['http_code'] != 200) {
                throw new \Exception('HTTP Error: ' . $header['http_code']);
            }

            // ---- Handle Bad Request responses by removing headers from output
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

            $this->cliStyle->writeln('<gray>' . substr($output, 0, 180) . '...</gray>');

            return $ret;
        } catch (\Exception $e) {
            // ---- it failed ... try again until maxRetries is reached
            if ($attempt >= $this->maxRetries) {
                throw $e;
            }
            $delayMs = $this->initialDelayMs * pow($this->backoffMultiplier, $attempt - 1);
            CliLogger::warning("Request failed ... attempt ".($attempt+1)."/{$this->maxRetries} in {$delayMs}ms ...");
            usleep($delayMs * 1000);
            
            return $this->get($url, $xml_data, $attempt + 1);
        }
    }

}
