/**
 * @fileoverview Service for handling API credentials and related operations for Topdata.
 * This service extends the Shopware ApiService class.
 */

/**
 * Fix for "TS2304: Cannot find name Shopware"
 * TODO: check https://developer.shopware.com/docs/guides/plugins/plugins/administration/the-shopware-object.html
 */
declare var Shopware: any;

const ApiService = Shopware.Classes.ApiService;

/**
 * Service class for Topdata API credentials.
 * @extends ApiService
 */
class TopdataApiCredentialsService extends ApiService {
    /**
     * Constructor for TopdataApiCredentialsService.
     * @param {Object} httpClient - The HTTP client for making requests.
     * @param {Object} loginService - The login service for authentication.
     * @param {string} [apiEndpoint='topdata'] - The API endpoint for Topdata.
     */
    constructor(httpClient, loginService, apiEndpoint = 'topdata') {
        super(httpClient, loginService, apiEndpoint);
    }

    /**
     * Loads the brands from the API.
     * @returns {Promise} - A promise that resolves with the API response.
     */
    loadBrands() {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .get(
                `${this.getApiBasePath()}/load-brands`,
                {
                    params: {},
                    headers: headers
                }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    /**
     * Saves the primary brands to the API.
     * @param {Array} primaryBrands - The primary brands to save.
     * @returns {Promise} - A promise that resolves with the API response.
     */
    savePrimaryBrands(primaryBrands) {
        const headers = this.getBasicHeaders();
        const payload = {
            primaryBrands
        };
        return this.httpClient
            .post(
                `${this.getApiBasePath()}/save-primary-brands`,
                payload,
                {
                    params: {},
                    headers: headers
                }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    /**
     * Tests the API credentials.
     * @returns {Promise} - A promise that resolves with the API response.
     */
    testApiCredentials() {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .get(
                `${this.getApiBasePath()}/connector-test`,
                {
                    params: {},
                    headers: headers
                }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    /**
     * Retrieves the active plugins from the API.
     * @returns {Promise} - A promise that resolves with the API response.
     */
    getActivePlugins() {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .get(
                `${this.getApiBasePath()}/connector-plugins`,
                {
                    params: {},
                    headers: headers
                }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    /**
     * Installs demo data via the API.
     * @returns {Promise} - A promise that resolves with the API response.
     */
    installDemoData() {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .get(
                `${this.getApiBasePath()}/connector-install-demodata`,
                {
                    params: {},
                    headers: headers
                }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }
}

export default TopdataApiCredentialsService;