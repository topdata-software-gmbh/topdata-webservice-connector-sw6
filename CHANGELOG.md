# Changelog

All notable changes to this project will be documented in this file. 
The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [7.1.1] - 2025-03-18
- removed the `--start` and `--end` cli options from the import command
- choices for description import type are now configurable: NO_IMPORT, REPLACE, APPEND, PREPEND, INJECT

## [7.0.4] - 2024-11-05
### Changed
- `MediaHelperService` added (extracted from `EntityHelperService`)


## [7.0.3] - 2024-11-05
### Fixed
- `$context` variable was missing in TopdataToProductHelperService


## [7.0.2] - 2024-11-04
- removed some classes and the csv import command
- added migration for inserting default credentials if no credentials are present
- added `--print-config` option to `topdata:connector:test-connection` command


## [7.0.0] - 06/2024
- prettier output
- deprecation warnings fixed
- report generation when importing data
