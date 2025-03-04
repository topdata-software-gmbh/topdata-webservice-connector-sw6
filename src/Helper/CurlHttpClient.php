<?php

namespace Topdata\TopdataConnectorSW6\Helper;

use Exception;
use Topdata\TopdataConnectorSW6\Exception\WebserviceRequestException;
use Topdata\TopdataConnectorSW6\Exception\WebserviceResponseException;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * A simple cURL client for making HTTP requests with exponential backoff retry strategy.
 * This one is only used for the webservice calls, it validates the JSON response and
 * throws an exception if the response is invalid.
 *
 * TODO: use custom exception class/es
 *
 * 11/2024 created (extracted from TopdataWebserviceClient)
 */
class CurlHttpClient
{
    const CURL_TIMEOUT                         = 30;  // seconds
    const EXPONENTIAL_BACKOFF_INITIAL_DELAY_MS = 1000; // ms, for exponential backoff
    const EXPONENTIAL_BACKOFF_MAX_RETRIES      = 5;
    const EXPONENTIAL_BACKOFF_MULTIPLIER       = 2.0;

    public function __construct(
        private int   $timeoutSeconds = self::CURL_TIMEOUT,
        private int   $initialDelayMs = self::EXPONENTIAL_BACKOFF_INITIAL_DELAY_MS,
        private int   $maxRetries = self::EXPONENTIAL_BACKOFF_MAX_RETRIES,
        private float $backoffMultiplier = self::EXPONENTIAL_BACKOFF_MULTIPLIER,
    )
    {
    }



    /**
     * Performs a cURL request to the specified URL and returns the decoded JSON response
     *
     * @param string $url The URL to send the request to
     * @param mixed|null $xml_data XML data to be sent (currently unused)
     * @param int $attempt Current retry attempt number, used for handling timeouts
     *
     * @return mixed Decoded JSON response from the API
     * @throws Exception When cURL errors occur or API returns an error response
     */
    public function get(string $url, $xml_data = null, $attempt = 1)
    {
        // ---- Initialize cURL request
        CliLogger::writeln("<gray>$url</gray>");

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeoutSeconds);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
            $output = curl_exec($ch);

            // ---- Handle cURL errors
            if (curl_errno($ch)) {
                throw new WebserviceRequestException('cURL-ERROR: ' . curl_error($ch));
            }

            // ---- Handle HTTP status codes
            $header = curl_getinfo($ch);
            if ($header['http_code'] != 200) {
                throw new WebserviceRequestException('HTTP Error: ' . $header['http_code']);
            }

            // ---- Handle Bad Request responses by removing headers from output
            if ($header['http_code'] == 400) {
                $header_size = strpos($output, '{');
                $output = substr($output, $header_size);
            }

            // ---- Clean up and process response
            curl_close($ch);
            $ret = json_decode($output);

            // check if the response is valid JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new WebserviceResponseException('Invalid JSON response: ' . json_last_error_msg());
            }

            // check if the webservice returned an error
            if (isset($ret->error)) {
                throw new WebserviceResponseException($ret->error[0]->error_message . ' @topdataconnector webservice error');
            }

            CliLogger::writeln('<gray>' . substr($output, 0, 180) . '...</gray>');

            return $ret;
        } catch (Exception $e) {
            // ---- it failed ... try again until maxRetries is reached
            if ($attempt >= $this->maxRetries) {
                throw $e;
            }
            $delayMs = $this->initialDelayMs * pow($this->backoffMultiplier, $attempt - 1);
            CliLogger::warning("Request failed ... attempt " . ($attempt + 1) . "/{$this->maxRetries} in {$delayMs}ms ...");
            usleep($delayMs * 1000);

            return $this->get($url, $xml_data, $attempt + 1);
        }
    }

}
