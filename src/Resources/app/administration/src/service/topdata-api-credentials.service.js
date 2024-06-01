const ApiService = Shopware.Classes.ApiService;

class TopdataApiCredentialsService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'topdata') {
        super(httpClient, loginService, apiEndpoint);
    }
    
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
