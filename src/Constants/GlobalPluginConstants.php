<?php

namespace Topdata\TopdataConnectorSW6\Constants;

/**
 * 11/2024 created
 */
class GlobalPluginConstants
{

    /**
     * List of specifications to ignore during import.
     * format: topId => "the name (unused)"
     */
    const IGNORE_SPECS = [
        21  => 'Hersteller-Nr. (intern)',
        24  => 'Product Code (PCD) Intern',
        32  => 'Kurzbeschreibung',
        573 => 'Kurzbeschreibung (statisch)',
        583 => 'Beschreibung (statisch)',
        293 => 'Gattungsbegriff 1',
        294 => 'Gattungsbegriff 2',
        295 => 'Gattungsbegriff 3',
        299 => 'Originalprodukt (J/N)',
        307 => 'Hersteller-Nr. (alt)',
        308 => 'Hersteller-Nr. (Alternative)',
        311 => 'Fake Eintrag',
        340 => 'Automatisch gematched',
        341 => 'Security Code System',
        361 => 'Produktart (Überkompatibilität)',
        367 => 'Product Code (PCD) Alternative',
        368 => 'Produktcode (PCD) alt',
        371 => 'EAN/GTIN 08 (alt)',
        391 => 'MPS Ready',
        22  => 'EAN/GTIN-13 (intern)',
        23  => 'EAN/GTIN-08 (intern)',
        370 => 'EAN/GTIN 13 (alt)',
        372 => 'EAN/GTIN-13 (Alternative)',
        373 => 'EAN/GTIN-08 (Alternative)',
        26  => 'eCl@ss v6.1.0',
        28  => 'unspsc 111201',
        331 => 'eCl@ss v5.1.4',
        332 => 'eCl@ss v6.2.0',
        333 => 'eCl@ss v7.0.0',
        334 => 'eCl@ss v7.1.0',
        335 => 'eCl@ss v8.0.0',
        336 => 'eCl@ss v8.1.0',
        337 => 'eCl@ss v9.0.0',
        721 => 'eCl@ss v9.1.0',
        34  => 'Gruppe Pelikan',
        35  => 'Gruppe Carma',
        36  => 'Gruppe Reuter',
        37  => 'Gruppe Kores',
        38  => 'Gruppe DK',
        39  => 'Gruppe Pelikan (falsch)',
        40  => 'Gruppe USA (Druckwerk)',
        122 => 'Druckwerk',
        8   => 'Leergut',
        30  => 'Marketingtext',
    ];



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

