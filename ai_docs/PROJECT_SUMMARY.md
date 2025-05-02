# Project Summary: TopData Webservice Connector for Shopware 6

## Brief Description and Purpose

The TopData Webservice Connector for Shopware 6 is a plugin designed to integrate a Shopware 6 online store with the TopData Webservice. Its primary purpose is to enable the import of device information from the TopData Webservice into the Shopware store. This plugin serves as a foundational component for other TopData plugins that extend its functionality.

## Key Aspects

### Architecture

The plugin follows a standard Shopware 6 plugin architecture, utilizing:
-   **Core Content Extensions:** Extending Shopware's core entities like Brand, Category, Customer, Device, Product, and Series to store TopData-specific information.
-   **Services:** A comprehensive set of services handle various aspects of the integration, including configuration checking, connection testing, data import (devices, media, products, synonyms), data mapping, database interactions, and product linking.
-   **Console Commands:** Provides CLI commands for initiating and managing import processes and testing the webservice connection.
-   **Scheduled Tasks:** Includes scheduled tasks for automated imports.
-   **API Controllers:** Provides an administrative API endpoint.
-   **Migrations:** Manages database schema changes required by the plugin.
-   **Dependency Injection:** Configuration is managed via XML service definitions.

### Implementation Details

-   **Webservice Communication:** Uses a dedicated webservice client (`TopdataWebserviceClient`) and an HTTP client (`CurlHttpClient`) to interact with the TopData API.
-   **Data Import:** Implements various import services (`DeviceImportService`, `DeviceMediaImportService`, etc.) to fetch and process data from the webservice.
-   **Data Mapping:** Includes mapping strategies (`MappingStrategy_Distributor`, `MappingStrategy_EanOem`, etc.) to link Shopware products with TopData devices based on different criteria.
-   **Product Linking:** Services like `ProductDeviceRelationshipServiceV2` handle the creation of relationships between Shopware products and imported devices.
-   **Error Handling:** Custom exceptions (`WebserviceRequestException`, `MissingPluginConfigurationException`, etc.) are used to manage specific error conditions.
-   **Configuration:** Plugin configuration is managed through the Shopware admin interface and accessed via a merged configuration helper service.

### Coding Standards and Conventions

The project adheres to specific coding standards and conventions, as indicated by the presence of:
-   `.php-cs-fixer.dist.php` and `.php-cs-fixer.php` for PHP code style fixing.
-   `CONVENTIONS.md` detailing project-specific conventions.

### Versioning

Versioning is managed through `VERSIONING.md` and changes are documented in `CHANGELOG.md`.

### Documentation

Includes user manuals in German and English (`manual/`) and internal AI-related documentation (`ai_docs/`).

## Minimal Requirements

-   Shopware 6.6.0 or higher
-   PHP 8.1 or higher