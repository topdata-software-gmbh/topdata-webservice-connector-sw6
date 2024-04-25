import './module/info';
import './module/sw-settings/page/sw-settings-index';
import TopdataApiCredentialsService from './service/topdata-api-credentials.service';
import './component/topdata-connector-sw6/topdata-connector-test-connection';

const { Application } = Shopware;

Application.addServiceProvider('TopdataApiCredentialsService', (container) => {
    const initContainer = Application.getContainer('init');
    return new TopdataApiCredentialsService(initContainer.httpClient, container.loginService);
});
