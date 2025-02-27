<?php

namespace Topdata\TopdataConnectorSW6\Constants;

/**
 * 11/2024 created
 */
class GlobalPluginConstants
{

    // TODO: add clone command:
    //    git clone https://github.com/topdata-software-gmbh/topdata-demo-data-importer-sw6.git
    //    git clone git@github.com:topdata-software-gmbh/topdata-demo-data-importer-sw6.git

    const ERROR_MESSAGE_NO_WEBSERVICE_CREDENTIALS =
        'Missing Webservice Credentials. ' . "\n" .
        'Please fill in the connection parameters in the shop administration: ' . "\n" .
        'Extensions > My Extensions > Topdata Connector > [...] > Configure' . "\n" .
        "\n" .
        'If you are using the Topdata Demo Data Plugin (https://github.com/topdata-software-gmbh/topdata-demo-data-importer-sw6.git), consider running the command `topdata:demo-data-importer:use-webservice-demo-credentials`';
}

