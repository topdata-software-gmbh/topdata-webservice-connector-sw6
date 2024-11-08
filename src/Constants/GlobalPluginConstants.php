<?php

namespace Topdata\TopdataConnectorSW6\Constants;

/**
 * 11/2024 created
 */
class GlobalPluginConstants
{
    const ERROR_MESSAGE_NO_WEBSERVICE_CREDENTIALS =
        'Missing Webservice Credentials. ' . "\n" .
        'Please fill in the connection parameters in the shop administration: ' . "\n" .
        'Extensions > My Extensions > Topdata Connector > [...] > Configure' . "\n" .
        'If you are using the Topdata Demo Data Plugin, consider running the command `topdata:demo-data-importer:use-webservice-demo-credentials`';
}
