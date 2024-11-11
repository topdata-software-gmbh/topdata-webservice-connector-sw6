<?php
/**
 * @author    Christoph Muskalla <muskalla@cm-s.eu>
 * @copyright 2019 CMS (http://www.cm-s.eu)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Topdata\TopdataConnectorSW6\Helper;

use Topdata\TopdataFoundationSW6\Trait\CliStyleTrait;

/**
 * A simple http client for the topdata webservice.
 */
class TopdataWebserviceClient
{
    use CliStyleTrait;

    const API_VERSION                 = '108';
    const CURL_TIMEOUT                = 30;  // seconds

    private $apiVersion = self::API_VERSION;
    private string $baseUrl; // without trailing slash

    public function __construct(
        string $baseUrl,
        private readonly string $apiUsername, // aka userId
        private readonly string $apiKey,
        private readonly string $apiSalt,
        private readonly string $apiLanguage
    )
    {
        $this->beVerboseOnCli();
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    private function getParams(): string
    {
        return 'uid=' . $this->apiUsername . '&security_key=' . $this->apiSalt . '&password=' . $this->apiKey . '&version=' . $this->apiVersion . '&language=' . $this->apiLanguage . '&filter=all';
    }

    /**
     * @param null $xml_data
     * @return bool|mixed
     * @throws \Exception
     */
    public function getCURLResponse(string $url, $xml_data = null, $attempt = 1)
    {
        $this->cliStyle->writeln("fetching $url");
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CURL_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
        $output = curl_exec($ch);

        if (curl_errno($ch)) {
            if (curl_errno($ch) == 28 && $attempt < 3) { //TIMEOUT
                $this->cliStyle->warning( 'cURL-ERROR: ' . curl_error($ch) . "\n" . $url . "\n" . ' ... DO RETRY ' . ($attempt + 1) . "\n");

                return $this->getCURLResponse($url, $xml_data, ($attempt + 1));
            }
            throw new \Exception('cURL-ERROR: ' . curl_error($ch));
        }
        // Dirty Hack for Curl Bad Request 400
        $header = curl_getinfo($ch);
        if ($header['http_code'] == 400) {
            $header_size = strpos($output, '{');
            $output = substr($output, $header_size);
        }
        // ----------------------------------------------------
        curl_close($ch);
        $json = json_decode($output);
        if (isset($json->error)) {
            throw new \Exception($json->error[0]->error_message . ' @topdataconnector webservice error');
        }

        return $json;
    }

    /**
     * 11/2024 unused?
     */
    public function getFinder($finder, $step, $params = [])
    {
        $p = [];
        foreach ($params as $k => $v) {
            $p[] = $k . '=' . rawurlencode($v);
        }
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/finder/' . $finder . '/' . $step . '?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    public function product($id, $params = [])
    {
        $p = [];
        foreach ($params as $k => $v) {
            $p[] = $k . '=' . rawurlencode($v);
        }
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/product/' . $id . '?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    /**
     * 11/2024 unused?
     */
    public function myProducts($params = [])
    {
        $p = [];
        foreach ($params as $k => $v) {
            $p[] = $k . '=' . rawurlencode($v);
        }
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/my_products?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    /**
     * 11/2024 unused?
     */
    public function myProductsOfWaregroup($waregroupId)
    {
        if (!is_int($waregroupId)) {
            return false;
        }

        $p = [];
        $p[] = $this->getParams();
        $url = $this->baseUrl . "/waregroup/$waregroupId?" . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    /**
     * 11/2024 unused?
     */
    public function myDistributorProducts($params = [])
    {
        $p = [];
        foreach ($params as $k => $v) {
            $p[] = $k . '=' . rawurlencode($v);
        }
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/distributor_products?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    /**
     * 11/2024 unused?
     */
    public function myWaregroups($params = [])
    {
        $p = [];
        foreach ($params as $k => $v) {
            $p[] = $k . '=' . rawurlencode($v);
        }
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/waregroups?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    // FIXME: it should be named "fetchMyOEMs" ?
    public function matchMyOems($params = [])
    {
        $p = [];
        foreach ($params as $k => $v) {
            $p[] = $k . '=' . rawurlencode($v);
        }
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/match/oem?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    // FIXME: it should be named "fetchMyPCDs" ?
    public function matchMyPcds($params = [])
    {
        $p = [];
        foreach ($params as $k => $v) {
            $p[] = $k . '=' . rawurlencode($v);
        }
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/match/pcd?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    // FIXME: it should be named "fetchMyEANs" ?
    public function matchMyEANs($params = [])
    {
        $p = [];
        foreach ($params as $k => $v) {
            $p[] = $k . '=' . rawurlencode($v);
        }
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/match/ean?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    // FIXME: it should be named "fetchMyDistributors" ?
    public function matchMyDistributer($params = [])
    {
        $p = [];
        foreach ($params as $k => $v) {
            $p[] = $k . '=' . rawurlencode($v);
        }
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/match/distributor?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    /**
     * Retrieves the list of products based on the provided parameters.
     *
     * This method constructs a URL with the given parameters and sends a GET request
     * to the Topdata webservice to retrieve the product list.
     *
     * @param array $params An associative array of parameters to be included in the request.
     *                      Each key-value pair will be URL-encoded and appended to the URL.
     * @return bool|mixed The response from the Topdata webservice, decoded from JSON format.
     *                    Returns false if the request fails.
     * @throws \Exception If there is an error during the cURL request or if the webservice returns an error.
     */
    public function myProductList($params = [])
    {
        $p = [];
        foreach ($params as $k => $v) {
            $p[] = $k . '=' . rawurlencode($v);
        }
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/product_list?' . implode('&', $p);

        //debug($url);
        return $this->getCURLResponse($url);
    }


    /**
     * @return bool|mixed
     * @throws \Exception
     */
    public function getBrands()
    {
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/finder/ink_toner/brands?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    public function getModelTypeByBrandId($brandId = false)
    {
        $p[] = $this->getParams();
        if ($brandId) {
            $p['brand_id'] = $brandId;
        }
        $url = $this->baseUrl . '/finder/ink_toner/devicetypes?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    public function getModelSeriesByBrandId($brandId = false)
    {
        $p[] = $this->getParams();
        if ($brandId) {
            $p['brand_id'] = $brandId;
        }
        $url = $this->baseUrl . '/finder/ink_toner/modelseries?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    public function getModelsBySeriesId($brandId, $seriesId)
    {
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/finder/ink_toner/models?brand_id=' . $brandId . '&modelserie_id=' . $seriesId . '&' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    public function getModels($limit = 500, $start = 0)
    {
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/finder/ink_toner/models?limit=' . $limit . '&start=' . $start . '&' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    public function getModelsByBrandId($brandId)
    {
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/finder/ink_toner/models?brand_id=' . $brandId . '&' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    public function getUserInfo()
    {
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/user/user_info?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }

    /**
     * 11/2024 unused?
     */
    public function productAccessories($id, $params = [])
    {
        $p = [];
        if (count($params) != 0) {
            foreach ($params as $k => $v) {
                $p[] = $k . '=' . rawurlencode($v);
            }
        }
        $p[] = $this->getParams();
        $url = $this->baseUrl . '/product_accessories/' . (int)$id . '?' . implode('&', $p);

        return $this->getCURLResponse($url);
    }
}
