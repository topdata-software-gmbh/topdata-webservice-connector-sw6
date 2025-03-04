<?php
/**
 * @author    Christoph Muskalla <muskalla@cm-s.eu>
 * @copyright 2019 CMS (http://www.cm-s.eu)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Topdata\TopdataConnectorSW6\Helper;

use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * A simple http client for the topdata webservice with a retry mechanism (exponential backoff).
 */
class TopdataWebserviceClient
{

    const API_VERSION = '108';

    private $apiVersion = self::API_VERSION;
    private string $baseUrl;
    private CurlHttpClient $curlHttpClient;

    public function __construct(
        string                  $baseUrl,
        private readonly string $apiUid,
        private readonly string $apiPassword,
        private readonly string $apiSecurityKey,
        private readonly string $apiLanguage,
    )
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->curlHttpClient = new CurlHttpClient();
    }


    /**
     * Sends an HTTP GET request to a specified endpoint with optional query parameters.
     *
     * @param string $endpoint API endpoint to call (e.g., '/my_products').
     * @param array $params Optional associative array of query parameters.
     * @return mixed Response from the API.
     * @throws \Exception
     */
    private function httpGet(string $endpoint, array $params = []): mixed
    {
        // Combine common parameters with any additional ones
        $params = array_merge($params, [
            'uid'          => $this->apiUid,
            'security_key' => $this->apiSecurityKey,
            'password'     => $this->apiPassword,
            'version'      => $this->apiVersion,
            'language'     => $this->apiLanguage,
            'filter'       => 'all'
        ]);
        $url = $this->baseUrl . $endpoint . '?' . http_build_query($params);

        return $this->curlHttpClient->get($url);
    }


    public function getFinder(string $finder, string $step, array $params = []): mixed
    {
        $endpoint = "/finder/$finder/$step";
        return $this->httpGet($endpoint, $params);
    }


    public function product($id, array $params = []): mixed
    {
        $endpoint = "/product/$id";
        return $this->httpGet($endpoint, $params);
    }


    public function myProducts(array $params = []): mixed
    {
        return $this->httpGet('/my_products', $params);
    }


    public function myProductsOfWaregroup(int $waregroupId): mixed
    {
        if ($waregroupId <= 0) {
            return false;
        }

        return $this->httpGet("/waregroup/$waregroupId");
    }

    public function myDistributorProducts(array $params = []): mixed
    {
        return $this->httpGet('/distributor_products', $params);
    }

    public function myWaregroups(array $params = []): mixed
    {
        return $this->httpGet('/waregroups', $params);
    }

    public function matchMyOems(array $params = []): mixed
    {
        return $this->httpGet('/match/oem', $params);
    }

    public function matchMyPcds(array $params = []): mixed
    {
        return $this->httpGet('/match/pcd', $params);
    }

    public function matchMyEANs(array $params = []): mixed
    {
        return $this->httpGet('/match/ean', $params);
    }

    public function matchMyDistributer(array $params = []): mixed
    {
        return $this->httpGet('/match/distributor', $params);
    }

    public function myProductList(array $params = []): mixed
    {
        return $this->httpGet('/product_list', $params);
    }

    public function getBrands(): mixed
    {
        return $this->httpGet('/finder/ink_toner/brands');
    }

    public function getModelTypeByBrandId(int|string|false $brandId = false): mixed
    {
        $params = $brandId ? ['brand_id' => $brandId] : [];
        return $this->httpGet('/finder/ink_toner/devicetypes', $params);
    }

    public function getModelSeriesByBrandId(int|string|false $brandId = false): mixed
    {
        $params = $brandId ? ['brand_id' => $brandId] : [];
        return $this->httpGet('/finder/ink_toner/modelseries', $params);
    }

    public function getModelsBySeriesId(int|string $brandId, int|string $seriesId): mixed
    {
        $params = ['brand_id' => $brandId, 'modelserie_id' => $seriesId];
        return $this->httpGet('/finder/ink_toner/models', $params);
    }

    public function getModels(int $limit = 500, int $start = 0): mixed
    {
        $params = ['limit' => $limit, 'start' => $start];
        return $this->httpGet('/finder/ink_toner/models', $params);
    }

    public function getModelsByBrandId(int|string $brandId): mixed
    {
        return $this->httpGet('/finder/ink_toner/models', ['brand_id' => $brandId]);
    }

    public function getUserInfo(): mixed
    {
        return $this->httpGet('/user/user_info');
    }

    public function productAccessories(int|string $id, array $params = []): mixed
    {
        return $this->httpGet("/product_accessories/$id", $params);
    }
}
