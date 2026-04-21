---
filename: "_ai/backlog/active/260421_1305__IMPLEMENTATION_PLAN__fix-scheduled-task-import-and-manual.md"
title: "Fix non-functional scheduled import handler and update manual"
createdAt: 2026-04-21 13:05
updatedAt: 2026-04-21 13:05
status: draft
priority: critical
tags: [shopware, scheduled-task, import, documentation, topdata]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1) Problem Statement

The scheduled import task is currently non-functional. In `ConnectorImportTaskHandler::run()`, the import execution is effectively disabled (the import command call is commented out), so the Shopware scheduled task triggers successfully but performs no import work.

Additionally, user-facing documentation does not clearly describe how automated scheduled imports run in Shopware 6.7 (scheduler + message consumer requirements), which can lead to deployments that appear broken even after code fixes.

## 2) Executive Summary

This plan restores scheduled imports by implementing a real execution path inside the scheduled task handler that invokes the import logic programmatically (instead of shelling out with `exec`).

The solution introduces a dedicated, testable service for scheduled import orchestration, ensures robust error handling and logging, keeps responsibilities separated (SOLID), and updates the manual in English and German with explicit setup/verification steps for cron and worker processes.

Final phase will generate an implementation report in `_ai/backlog/reports/260421_1305__IMPLEMENTATION_REPORT__fix-scheduled-task-import-and-manual.md`.

## 3) Project Environment Details

```text
Project: topdata-software-gmbh/topdata-webservice-connector-sw6
Plugin Type: Shopware 6 plugin
Runtime: PHP 8.1+
Core Dependency: shopware/core 6.7.*
DI Config: src/Resources/config/services.xml
Main Affected Areas:
- src/ScheduledTask/ConnectorImportTaskHandler.php
- src/DTO/ImportConfig.php
- src/Service/* (new orchestrator service)
- manual/*.md and README.md
Execution Model:
- Shopware scheduled task dispatches message
- Messenger worker executes handler
- Handler triggers connector import workflow
```

## 4) Implementation Phases

### Phase 1 — Root-cause fix in scheduled task flow

**Goal:** Ensure `ConnectorImportTaskHandler` actually performs imports when executed by message queue.

**Actions:**
- Replace inactive `run()` implementation with real import execution.
- Remove dependency on shell `exec` in handler.
- Delegate import orchestration to a dedicated service (`ScheduledImportRunnerService`) to preserve SRP.
- Add structured logging for start/success/failure with context.

**Why (SOLID):**
- **S**: Handler only handles message execution trigger; runner service handles import orchestration.
- **O**: Future scheduling modes/options can be added in runner without changing handler contract.
- **D**: Handler depends on abstraction/service via DI, not process-level side effects.

#### Planned code changes

```php
[MODIFY] src/ScheduledTask/ConnectorImportTaskHandler.php

<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Topdata\TopdataConnectorSW6\Service\ScheduledImportRunnerService;

class ConnectorImportTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ScheduledImportRunnerService $scheduledImportRunnerService,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public static function getHandledMessages(): iterable
    {
        return [ConnectorImportTask::class];
    }

    public function run(): void
    {
        $this->scheduledImportRunnerService->runFullImportForScheduledTask();
    }
}
```

```php
[NEW FILE] src/Service/ScheduledImportRunnerService.php

<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Topdata\TopdataConnectorSW6\DTO\ImportConfig;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

class ScheduledImportRunnerService
{
    public function __construct(
        private readonly ImportService $importService,
    ) {
    }

    public function runFullImportForScheduledTask(): void
    {
        CliLogger::info('Starting scheduled Topdata import (--all).');

        $config = ImportConfig::createForScheduledTaskAll();
        $this->importService->execute($config);

        CliLogger::info('Scheduled Topdata import finished.');
    }
}
```

```php
[MODIFY] src/DTO/ImportConfig.php

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

<service id="Topdata\TopdataConnectorSW6\Service\ScheduledImportRunnerService" autowire="true" />

<service id="Topdata\TopdataConnectorSW6\ScheduledTask\ConnectorImportTaskHandler" autowire="true">
    <argument type="service" id="scheduled_task.repository" />
    <argument type="service" id="logger" />
    <tag name="messenger.message_handler" />
</service>
```

---

### Phase 2 — Reliability, error handling, and observability

**Goal:** Ensure failures are visible and do not silently fail.

**Actions:**
- Wrap scheduled import execution with exception handling in runner (log exception details and rethrow).
- Add clear log messages before/after import run.
- Confirm scheduled task name and interval remain unchanged (`topdata.connector_import_task`, 24h).

**Acceptance criteria:**
- Exceptions are captured in logs with stack trace context.
- Task execution marks as failed when import throws.
- No shell execution required.

#### Planned code extension

```php
[MODIFY] src/Service/ScheduledImportRunnerService.php

public function runFullImportForScheduledTask(): void
{
    try {
        CliLogger::info('Starting scheduled Topdata import (--all).');
        $this->importService->execute(ImportConfig::createForScheduledTaskAll());
        CliLogger::info('Scheduled Topdata import finished successfully.');
    } catch (\Throwable $e) {
        CliLogger::error('Scheduled Topdata import failed: ' . $e->getMessage());
        throw $e;
    }
}
```

---

### Phase 3 — Documentation and manual updates

**Goal:** Make operation requirements and verification steps explicit for users.

**Files to update:**
- `README.md`
- `manual/10-installation.en.md`
- `manual/10-installation.de.md`

**Documentation updates:**
- Add section: “Automated Import via Scheduled Task”.
- Clarify that both scheduler and message consumer must run:
  - `bin/console scheduled-task:run`
  - `bin/console messenger:consume async`
- Add initial registration command after plugin install/update:
  - `bin/console scheduled-task:register`
- Add verification commands:
  - `bin/console scheduled-task:list | grep topdata.connector_import_task`
  - `bin/console scheduled-task:run-single topdata.connector_import_task`
- Clarify fallback manual import command:
  - `bin/console topdata:connector:import --all`

#### Planned doc snippet (EN)

```md
[MODIFY] manual/10-installation.en.md

## Automated Import via Scheduled Task

The plugin provides a scheduled task (`topdata.connector_import_task`) for daily automatic imports.

### Required runtime processes

1. Scheduler process (triggers due tasks):
   `bin/console scheduled-task:run`
2. Messenger consumer (executes task handlers):
   `bin/console messenger:consume async`

### Register and verify

- Register tasks after installation/update:
  `bin/console scheduled-task:register`
- Verify task exists:
  `bin/console scheduled-task:list | grep topdata.connector_import_task`
- Trigger once for test:
  `bin/console scheduled-task:run-single topdata.connector_import_task`

For manual execution, use:
`bin/console topdata:connector:import --all`
```

#### Planned doc snippet (DE)

```md
[MODIFY] manual/10-installation.de.md

## Automatischer Import per Scheduled Task

Das Plugin stellt einen Scheduled Task (`topdata.connector_import_task`) für den täglichen automatischen Import bereit.

### Erforderliche Prozesse zur Laufzeit

1. Scheduler-Prozess (stößt fällige Tasks an):
   `bin/console scheduled-task:run`
2. Messenger-Consumer (führt Task-Handler aus):
   `bin/console messenger:consume async`

### Registrierung und Prüfung

- Tasks nach Installation/Update registrieren:
  `bin/console scheduled-task:register`
- Task prüfen:
  `bin/console scheduled-task:list | grep topdata.connector_import_task`
- Einmalig zum Test ausführen:
  `bin/console scheduled-task:run-single topdata.connector_import_task`

Für manuellen Start verwenden Sie:
`bin/console topdata:connector:import --all`
```

---

### Phase 4 — Validation and regression checks

**Goal:** Validate functionality without broad unrelated changes.

**Checks:**
1. `bin/console cache:clear`
2. `bin/console scheduled-task:register`
3. `bin/console scheduled-task:list | grep topdata.connector_import_task`
4. Start consumer and trigger single run:
   - Terminal A: `bin/console messenger:consume async -vv`
   - Terminal B: `bin/console scheduled-task:run-single topdata.connector_import_task`
5. Confirm import side effects (logs/report data and updated import entities).

**Non-goals:**
- No refactor of `Command_Import` lock/reporting unless required for this fix.
- No unrelated import logic changes.

---

### Phase 5 — Final implementation report

**Goal:** Produce delivery report after implementation.

**Output file:**
`_ai/backlog/reports/260421_1305__IMPLEMENTATION_REPORT__fix-scheduled-task-import-and-manual.md`

**Required frontmatter:**

```yaml
---
filename: "_ai/backlog/reports/260421_1305__IMPLEMENTATION_REPORT__fix-scheduled-task-import-and-manual.md"
title: "Report: Fix non-functional scheduled import handler and update manual"
createdAt: 2026-04-21 13:05
updatedAt: 2026-04-21 13:05
planFile: "_ai/backlog/active/260421_1305__IMPLEMENTATION_PLAN__fix-scheduled-task-import-and-manual.md"
project: "topdata-webservice-connector-sw6"
status: completed|partial|blocked
filesCreated: 0
filesModified: 0
filesDeleted: 0
tags: [shopware, scheduled-task, import, documentation, topdata]
documentType: IMPLEMENTATION_REPORT
---
```

**Report sections to fill:**
1. Summary
2. Files Changed
3. Key Changes
4. Technical Decisions
5. Testing Notes
6. Usage Examples (if applicable)
7. Documentation Updates
8. Next Steps (optional)

## 5) Risks and Mitigations

- **Risk:** Scheduled task still appears idle if consumer is not running.
  - **Mitigation:** Explicit manual updates and verification commands in docs.
- **Risk:** Import runtime overlaps with manual import.
  - **Mitigation:** Keep lock behavior in command path; optionally add lock in scheduled runner in follow-up.
- **Risk:** Unexpected environment differences in Shopware task transport.
  - **Mitigation:** Validate with `run-single` and `messenger:consume -vv` in staging first.

## 6) Definition of Done

- Scheduled task handler executes import logic on run.
- No shell-based `exec` is used in scheduled handler path.
- Updated docs describe scheduled setup and verification in EN + DE (+ README).
- Validation commands pass in target environment.
- Implementation report generated in `_ai/backlog/reports` with complete metadata.
