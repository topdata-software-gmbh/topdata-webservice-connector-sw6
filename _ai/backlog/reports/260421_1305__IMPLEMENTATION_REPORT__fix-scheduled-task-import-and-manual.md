---
filename: "_ai/backlog/reports/260421_1305__IMPLEMENTATION_REPORT__fix-scheduled-task-import-and-manual.md"
title: "Report: Fix scheduled import execution, add config toggle, and update manuals"
createdAt: 2026-04-21 13:05
updatedAt: 2026-04-21 13:05
planFile: "_ai/backlog/active/260421_1305__IMPLEMENTATION_PLAN__fix-scheduled-task-import-and-manual.md"
project: "topdata-webservice-connector-sw6"
status: completed
filesCreated: 1
filesModified: 6
filesDeleted: 0
tags: [shopware, scheduled-task, import, documentation, topdata, configuration]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The scheduled import functionality was fixed to use programmatic execution via a dedicated service instead of shell execution. A new plugin configuration setting was added to disable automatic imports by default, ensuring safe deployments.

## 2. Files Changed
- **New Files Created**:
  - `src/Service/ScheduledImportRunnerService.php`: Orchestrates the import specifically for scheduled execution.
- **Modified Files**:
  - `src/Resources/config/config.xml`: Added `enableScheduledImport` toggle.
  - `src/ScheduledTask/ConnectorImportTaskHandler.php`: Added config check and injected the runner service.
  - `src/DTO/ImportConfig.php`: Added `createForScheduledTaskAll()` factory.
  - `src/Resources/config/services.xml`: Registered the new service and updated task handler arguments.
  - `manual/10-installation.en.md`: Added UI toggle instructions and worker requirements.
  - `manual/10-installation.de.md`: Added UI toggle instructions and worker requirements.

## 3. Key Changes
- Removed non-functional `exec()` scheduling behavior in the scheduled task handler.
- Implemented `SystemConfigService` check to abort task execution gracefully if disabled.
- Decoupled scheduling trigger logic from import execution logic via `ScheduledImportRunnerService`.

## 4. Technical Decisions
- Used a plugin configuration toggle over task DB-state manipulation to provide standard Shopware UX and explicit opt-in behavior.
- Centralized exception catching inside `ScheduledImportRunnerService` to guarantee visibility in logger output.

## 5. Testing Notes
- Ran PHP syntax checks:
  - `php -l src/ScheduledTask/ConnectorImportTaskHandler.php`
  - `php -l src/Service/ScheduledImportRunnerService.php`
  - `php -l src/DTO/ImportConfig.php`
- All above checks passed with no syntax errors.
- Runtime behavior checks for enabled/disabled scheduled import are documented for execution in target Shopware environment.

## 6. Usage Examples
**Enabling in CLI (for CI/CD or devs)**:
`bin/console system:config:set TopdataConnectorSW6.config.enableScheduledImport true`

**Executing single run**:
`bin/console scheduled-task:run-single topdata.connector_import_task`

## 7. Documentation Updates
Updated English and German installation manuals with sections explaining the UI toggle and required Shopware background scheduler/consumer processes.

## 8. Next Steps
- Consider exposing specific import flags (e.g. products only) directly in plugin configuration for scheduled runs instead of hardcoding `--all`.
