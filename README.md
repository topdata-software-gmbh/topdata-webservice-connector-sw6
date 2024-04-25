# TopData Webservice Connector for Shopware 6

## About
This plugin is the base for most of the functionality in other TopData plugins for Shopware 6.
It gives possibility to import devices from TopData Webservice.

## Minimal Requirements
- Shopware 6.6.0 or higher
- PHP 8.0 or higher

## Installation
```bash
# clone the repository
cd custom/plugins
git clone -b main-sw66 https://github.com/topdata-software-gmbh/topdata-webservice-connector-sw6.git

# install the plugin
bin/console plugin:refresh
bin/console plugin:install --activate --clearCache TopdataConnectorSW6 
```

## Configuration
After installing plugin, you need to fill in API credentials to connect to TopData Webservice.

### Webservice Credentials
Settings - System - Plugins - TopdataConnector menu (... on the right) - Config

TopData will give you:

- API User-ID
- API Key
- API Salt

#### Demo Credentials

If you want to test the plugin with demo credentials, you can use the following:
 
- API User-ID: 6
- API Key: nTI9kbsniVWT13Ns
- API Salt: oateouq974fpby5t6ldf8glzo85mr9t6aebozrox


#### Testing the connection
After saving credentials you can test connection, just select TopData Plugins menu item in main menu and press "Test" button in TopData Connector block.


### Other Options

"please select your search option" - here you select how to map your products:

1. Default OEM/EAN - products are mapped using OEM and EAN fields of a product

2. Custom OEM/EAN - products are mapped using OEM and EAN fields stored as product properties, this case you sholud set name of OEM Field and EAN Field

3. distributor product number - product number in store equals to Top Data API product number

4. custom distributor product number - Webservice product number is stored in product property, you must specify name of a property in "distributor product number Field"

5. Product number is web service id - product number in your store is same as API product id on Top Data Webservice




## Console commands for work with API

### topdata:connector:test-connection

- it tests whether the connection to the TopData Webservice is working

### topdata:connector:import
   
1. `bin/console topdata:connector:import --mapping`  map store products to webservice products

2. `bin/console topdata:connector:import --device`  fetch all information about devices from webservice to local database (devices, device types, series, brands)

3. `bin/console topdata:connector:import --product`  this will connect products in store to devices in local database. Products must be mapped by console command and devices must be fetched from webservice.

4. `bin/console topdata:connector:import --device-media`  this will download device images, this process must have rights to write in website folders (You may use or chown or chmod)

5. `bin/console topdata:connector:import --product-info`  Only works with Topdata TopFeed plugin. This will fetch all product information from webservice to local database. You can select what data to fetch in Topdata TopFeed plugin settings. You need write permisions for process if you select to store product images.

6. `bin/console topdata:connector:import --device-synonyms` this will fetch device synonyms from Webservice, they will be displayed near device on device details page

`bin/console topdata:connector:import --all`  All options 1-6 are active (mapping, device, product, device-media, product-info, device-synonyms)

`bin/console topdata:connector:import --device-only` this will fetch only devices from API (good for chunked commands with --start and --end, no brands/series/types are fetched)

`bin/console topdata:connector:import --product-media-only` this will fetch only product images (good for importing product information first with disabled product media in TopFeed settings, and then download product images with this command)

Command order is important, for example --device-media (4) downloads images only for enabled devices, those devices are enabled by --product (3)

#### Additional options
`-v` key for verbose output, it shows memmory usage, data chunk numbers, time and other information

`--no-debug` keys for faster work and less memmory usage

`--start` and `--end` keys, depending on command it use chunk numbers or element counts (you can see this numbers if verbose output is enabled)


### topdata:connector:products
- a console command for import products from csv file
- example usage:
 
```bash
bin/console topdata:connector:products --file=prods2020-07-26.csv --start=1 --end=1000 --number=4 --wsid=4 --name=11 --brand=10
```

`--file`  specify filename

`--start`  start line of a file, default is 1 (first line is 0, it usually have column titles)

`--end`  end line of a file, by default file will be read until the end

`--number`  column with unique product number

`--wsid`  column with Webservice id (if csv is given from TopData it may have this column), if it is set product will be mapped to Top Data Webserivce products

`--name`  column with product name

`--brand`  column with product brand name (will be created if is not present yet)

It is recommended to limit product count with start/end, depending on server RAM. Then you can read next chunk of products in second command.

## Advices and examples

### use --start and --end options

There are more than 100.000 devices on Webservice, when you fetch devices or device media, you can use `--start` `--end` options to chunk entire process, also it is highly recommended to use `-v` key to see number of chunk where something went wrong.

When you use TopFeed plugin for additional product information it is also recommended to use `--start` / `--end` options when you have thousands of products in the store.

### Normal workflow

Step 1 
```bash
bin/console topdata:connector:import -v --mapping --no-debug
```

 Step 2
```bash
bin/console topdata:connector:import --no-debug -v --device --start=1 --end=10
bin/console topdata:connector:import --no-debug -v --device-only --start=11 --end=20
bin/console topdata:connector:import --no-debug -v --device-only --start=21
```

Step 3
```bash
# ...
```

If you download product or device images from Top Data Webservice to yours shop locale storage, don't forget to change permisions for files if command and server user are not the same user, e.g.
<!-- TODO: fix the path "." in the command -->
```bash
chown -R www-data:www-data .
```
