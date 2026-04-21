---
filename: "_ai/backlog/active/260421_1305__IMPLEMENTATION_PLAN__fix-scheduled-task-import-and-manual.md"
title: "Fix scheduled import execution, add config toggle, and update manuals"
createdAt: 2026-04-21 13:05
updatedAt: 2026-04-21 13:05
status: draft
priority: critical
tags: [shopware, scheduled-task, import, documentation, topdata, configuration]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1) Problem Statement

The scheduled import task (`topdata.connector_import_task`) is currently non-functional. In `ConnectorImportTaskHandler::run()`, the import execution is effectively disabled (the code to trigger the CLI command is commented out). This means the Shopware scheduled task triggers successfully but performs no work. Additionally, triggering a CLI command from a scheduled task via `exec()` is an anti-pattern in Shopware 6 that fails on many hosting environments. 

Furthermore, if we simply fix the task to run automatically, it might execute unexpectedly upon plugin installation. We lack a mechanism to disable this automatic behavior by default. Lastly, user-facing documentation does not clearly describe the prerequisites for the scheduler (worker processes) or how to enable/disable the automatic import.

## 2) Executive Summary

This plan restores the scheduled import functionality using Shopware best practices. It removes the reliance on CLI shell execution and instead triggers the import programmatically via a new dedicated `ScheduledImportRunnerService`. 

To prevent unexpected server load, a new plugin configuration toggle (`enableScheduledImport`) is introduced, defaulting to `false`. The task handler will check this setting and gracefully abort if the user has not explicitly opted in. 

Finally, the plan includes comprehensive updates to the English and German manuals, explicitly detailing how to enable the feature in the Shopware Administration and verifying the required worker processes. An implementation report will be generated at the end.

## 3) Project Environment Details

```text
Project: topdata-software-gmbh/topdata-webservice-connector-sw6
Plugin Type: Shopware 6 plugin
Runtime: PHP 8.1+
Core Dependency: shopware/core 6.7.*
DI Config: src/Resources/config/services.xml
Main Affected Areas:
- src/Resources/config/config.xml (Plugin configuration)
- src/ScheduledTask/ConnectorImportTaskHandler.php
- src/DTO/ImportConfig.php
- src/Service/ScheduledImportRunnerService.php
- manual/*.md
Execution Model:
- Task triggered by Shopware scheduler
- Handler checks SystemConfigService for 'enableScheduledImport'
- If enabled, invokes import logic programmatically
```

## 4) Implementation Phases

### Phase 1 — Plugin Configuration Toggle

**Goal:** Introduce an admin configuration toggle so the scheduled task is disabled by default.

**Actions:**
- Add `enableScheduledImport` to `config.xml`.

```xml
[MODIFY] src/Resources/config/config.xml
(Note: Create file if it does not exist)

<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/core/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>Scheduled Import Settings</title>
        <title lang="de-DE">Einstellungen für geplanten Import</title>

        <input-field type="bool">
            <name>enableScheduledImport</name>
            <label>Enable Automatic Scheduled Import</label>
            <label lang="de-DE">Automatischen Import aktivieren</label>
            <helpText>If enabled, the import runs automatically via the Shopware Scheduled Task every 24 hours.</helpText>
            <helpText lang="de-DE">Wenn aktiviert, läuft der Import automatisch über den Shopware Scheduled Task alle 24 Stunden.</helpText>
            <defaultValue>false</defaultValue>
        </input-field>
    </card>
</config>
```

### Phase 2 — Refactor Task Handler and Orchestration

**Goal:** Ensure `ConnectorImportTaskHandler` checks the configuration and safely delegates to a dedicated programmatic runner service (SOLID: SRP, Open/Closed).

**Actions:**
- Inject `SystemConfigService` into the handler.
- Create `ScheduledImportRunnerService` to orchestrate `ImportService` without shelling out.
- Register new services.

```php
[MODIFY] src/ScheduledTask/ConnectorImportTaskHandler.php

<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataConnectorSW6\Service\ScheduledImportRunnerService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

class ConnectorImportTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ScheduledImportRunnerService $scheduledImportRunnerService,
        private readonly SystemConfigService $systemConfigService
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public static function getHandledMessages(): iterable
    {
        return [ConnectorImportTask::class];
    }

    public function run(): void
    {
        // Replace 'TopdataConnectorSW6' with actual plugin technical name if needed
        $isEnabled = $this->systemConfigService->getBool('TopdataConnectorSW6.config.enableScheduledImport');

        if (!$isEnabled) {
            CliLogger::info('Scheduled Topdata import is disabled in plugin configuration. Skipping execution.');
            return;
        }

        $this->scheduledImportRunnerService->runFullImportForScheduledTask();
    }
}
```

```php
[NEW FILE] src/Service/ScheduledImportRunnerService.php

<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Topdata\TopdataConnectorSW6\DTO\ImportConfig;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

class ScheduledImportRunnerService
{
    public function __construct(
        private readonly ImportService $importService
    ) {
    }

    public function runFullImportForScheduledTask(): void
    {
        try {
            CliLogger::info('Starting automatic scheduled Topdata import (--all).');
            $config = ImportConfig::createForScheduledTaskAll();
            $this->importService->execute($config);
            CliLogger::info('Automatic scheduled Topdata import finished successfully.');
        } catch (\Throwable $e) {
            CliLogger::error('Automatic scheduled Topdata import failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
```

```php
[MODIFY] src/DTO/ImportConfig.php

// Add the factory method for scheduled task configurations
public static function createForScheduledTaskAll(): self
{
    $ret = new self();
    $ret->optionAll = true;
    $ret->optionMapping = false;
    $ret->optionDevice = false;
    $ret->optionDeviceOnly = false;
    $ret->optionDeviceMedia = false;
    $ret->optionDeviceSynonyms = false;
    $ret->optionProduct = false;
    $ret->optionProductInformation = false;
    $ret->optionProductMediaOnly = false;
    $ret->optionProductVariations = false;
    $ret->optionExperimentalV2 = false;
    $ret->optionProductDevice = false;
    $ret->optionPurgeCache = false;
    $ret->baseUrl = null;

    return $ret;
}
```

```xml
[MODIFY] src/Resources/config/services.xml

<!-- Register the new service and ensure handler has correct dependencies -->
<service id="Topdata\TopdataConnectorSW6\Service\ScheduledImportRunnerService" autowire="true" />

<service id="Topdata\TopdataConnectorSW6\ScheduledTask\ConnectorImportTaskHandler" autowire="true">
    <argument type="service" id="scheduled_task.repository" />
    <argument type="service" id="logger" />
    <argument type="service" id="Topdata\TopdataConnectorSW6\Service\ScheduledImportRunnerService" />
    <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService" />
    <tag name="messenger.message_handler" />
</service>
```

### Phase 3 — Documentation and manual updates

**Goal:** Provide clear instructions to users on how to enable the feature and ensure required Shopware workers are running.

#### Document update (EN)
```md
[MODIFY] manual/10-installation.en.md

## Automated Import via Scheduled Task

The plugin provides a scheduled task (`topdata.connector_import_task`) for daily automatic imports. **By default, this is disabled to prevent unexpected server load.**

### Enabling the Scheduled Import
To activate the automatic daily import:
1. Log in to the Shopware Administration.
2. Go to **Extensions > My extensions**.
3. Open the configuration for the Topdata Connector plugin.
4. Toggle **Enable Automatic Scheduled Import** to active and save.

### Required runtime processes
Shopware requires background workers for scheduled tasks to function:
1. Scheduler process (triggers due tasks):
   `bin/console scheduled-task:run`
2. Messenger consumer (executes task handlers):
   `bin/console messenger:consume async`

For immediate manual execution, use: `bin/console topdata:connector:import --all`
```

#### Document update (DE)
```md
[MODIFY] manual/10-installation.de.md

## Automatischer Import per Scheduled Task

Das Plugin stellt einen Scheduled Task (`topdata.connector_import_task`) für den täglichen automatischen Import bereit. **Standardmäßig ist dieser deaktiviert, um unerwartete Serverauslastungen zu vermeiden.**

### Aktivierung des automatischen Imports
Um den täglichen Import zu aktivieren:
1. Loggen Sie sich in die Shopware Administration ein.
2. Navigieren Sie zu **Erweiterungen > Meine Erweiterungen**.
3. Öffnen Sie die Konfiguration für das Topdata Connector Plugin.
4. Setzen Sie **Automatischen Import aktivieren** auf aktiv und speichern Sie.

### Erforderliche Prozesse zur Laufzeit
Shopware benötigt Hintergrundprozesse (Worker), damit Scheduled Tasks ausgeführt werden:
1. Scheduler-Prozess (stößt fällige Tasks an):
   `bin/console scheduled-task:run`
2. Messenger-Consumer (führt Task-Handler aus):
   `bin/console messenger:consume async`

Für einen manuellen Start verwenden Sie: `bin/console topdata:connector:import --all`
```

### Phase 4 — Testing and Validation

1. Run `bin/console cache:clear`.
2. Do **not** enable the config toggle yet.
3. Run `bin/console scheduled-task:run-single topdata.connector_import_task`.
4. Verify logs output: *"Scheduled Topdata import is disabled... Skipping execution."*
5. Enable toggle via Shopware Administration UI (or manipulate DB `system_config` table for testing).
6. Run task again. Verify logs output: *"Starting automatic scheduled Topdata import (--all)."* and that actual import executes.

### Phase 5 — Final Implementation Report

**Goal:** Generate the final delivery report indicating changes and testing completion.

- Create the file `_ai/backlog/reports/260421_1305__IMPLEMENTATION_REPORT__fix-scheduled-task-import-and-manual.md` matching the structure below.

```markdown
---
filename: "_ai/backlog/reports/260421_1305__IMPLEMENTATION_REPORT__fix-scheduled-task-import-and-manual.md"
title: "Report: Fix scheduled import execution, add config toggle, and update manuals"
createdAt: 2026-04-21 13:05
updatedAt: 2026-04-21 13:05
planFile: "_ai/backlog/active/260421_1305__IMPLEMENTATION_PLAN__fix-scheduled-task-import-and-manual.md"
project: "topdata-webservice-connector-sw6"
status: completed
filesCreated: 1
filesModified: 5
filesDeleted: 0
tags: [shopware, scheduled-task, import, documentation, topdata, configuration]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The scheduled import functionality was completely overhauled. It now uses programmatic execution via a dedicated service instead of faulty shell execution. A new plugin configuration setting was added to disable automatic imports by default, ensuring safe deployments.

## 2. Files Changed
- **New Files Created**:
  - `src/Service/ScheduledImportRunnerService.php`: Orchestrates the import specifically for scheduled execution.
- **Modified Files**:
  - `src/Resources/config/config.xml`: Added `enableScheduledImport` toggle.
  - `src/ScheduledTask/ConnectorImportTaskHandler.php`: Added config check and injected the runner service.
  - `src/DTO/ImportConfig.php`: Added `createForScheduledTaskAll()` factory.
  - `src/Resources/config/services.xml`: Registered new services.
  - `manual/10-installation.*.md`: Added UI toggle instructions and worker requirements.

## 3. Key Changes
- Removed commented-out, non-functional `exec()` calls in scheduled task handler.
- Implemented `SystemConfigService` check to abort task execution gracefully if disabled.
- Decoupled scheduling trigger logic from import execution logic (SRP).

## 4. Technical Decisions
- Opted for a plugin configuration toggle over manipulating task database states. This provides the standard Shopware UX and prevents the toggle state from being wiped on cache clears or plugin updates.
- Centralized exception catching inside `ScheduledImportRunnerService` to guarantee visibility in the logger when run context lacks console outputs.

## 5. Testing Notes
- Tested task execution with config set to `false` (aborts successfully).
- Tested task execution with config set to `true` (runs successfully).
- Verified memory and standard exceptions are caught and logged appropriately without stalling the messenger worker.

## 6. Usage Examples
**Enabling in CLI (for CI/CD or devs)**:
`bin/console system:config:set TopdataConnectorSW6.config.enableScheduledImport true`

**Executing single run**:
`bin/console scheduled-task:run-single topdata.connector_import_task`

## 7. Documentation Updates
Updated English and German manual sections explaining the UI toggle and the strict requirement for Shopware background messenger/scheduler processes.

## 8. Next Steps
- Consider exposing specific import flags (like 'import products only') directly in the plugin configuration for the scheduled task, rather than hardcoding `--all`.
```

