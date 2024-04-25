import './page/topdata-connector-info';
// import './components/sw-connector-settings';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Module } = Shopware;

Module.register('topdata-connector', {
    type: 'plugin',
    name: 'Topdata Connector',
    title: 'topdata-connector.mainMenuItemGeneral',
    description: 'topdata-connector.descriptionTextModule',
//    version: '1.0.0',
//    targetVersion: '1.0.0',
    color: '#9AA8B5',
    icon: 'default-action-settings',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    },
    
    routes: {
        info: {
            component: 'topdata-connector-info',
            path: 'info',
            meta: {
                parentPath: 'sw.settings.index'
            }
        }
    }
    
//    navigation: [{
//        label: 'topdata-connector.mainMenuItemGeneral',
//        color: '#ff3d58',
//        path: 'topdata.connector.info',
//        icon: 'default-shopping-paper-bag-product',
//        position: 100
//    }]
});
