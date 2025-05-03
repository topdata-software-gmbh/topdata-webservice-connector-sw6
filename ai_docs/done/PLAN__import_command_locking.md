# Plan: Integrate Symfony Lock Component into `topdata:connector:import` Command

**Objective:** Prevent concurrent execution of the `topdata:connector:import` command by utilizing the Symfony Lock component.

**Analysis:**
- The command `src/Command/Command_Import.php` is a standard Symfony command.
- The service definition in `src/Resources/config/services.xml` uses `autowire="true"`, simplifying dependency injection.

**Refined Plan:**

1.  **Modify `src/Command/Command_Import.php`:**
    *   Add `use Symfony\Component\Lock\LockFactory;` and `use Symfony\Component\Lock\LockInterface;`.
    *   Add a private property `$lock` of type `?LockInterface` to hold the lock object.
    *   Add a `LockFactory $lockFactory` parameter to the `__construct` method. Autowiring will handle the injection.
    *   In the `execute` method:
        *   Create the lock: `$this->lock = $this->lockFactory->createLock('topdata-connector-import', 3600);` (1-hour TTL).
        *   Attempt lock acquisition: `if (!$this->lock->acquire()) { ... }`. If it fails, log a message and exit gracefully.
        *   Wrap the core command logic (lines 95-126 in the original file) in a `try...finally` block.
        *   Release the lock in the `finally` block: `if ($this->lock) { $this->lock->release(); }`.

2.  **No changes needed in `src/Resources/config/services.xml`** due to autowiring.

**Mermaid Diagram of the `execute` flow:**

```mermaid
sequenceDiagram
    participant CLI as Command Line
    participant Command as Command_Import
    participant LockFactory
    participant Lock as LockInterface
    participant ImportService
    participant ReportService

    CLI->>Command: execute()
    Command->>LockFactory: createLock('topdata-connector-import', 3600)
    LockFactory-->>Command: returns Lock
    Command->>Lock: acquire()
    alt Lock Acquired (returns true)
        Lock-->>Command: true
        Command->>ReportService: newJobReport()
        Command->>ImportService: execute(importConfig)
        ImportService-->>Command: completes
        Command->>ReportService: markAsSucceeded()
        Command->>Lock: release() # In finally block
        Lock-->>Command: released
        Command-->>CLI: Command::SUCCESS
    else Lock Not Acquired (returns false)
        Lock-->>Command: false
        Command->>CLI: Log "Already running"
        Command-->>CLI: Command::SUCCESS (or specific code)
    else Exception during Import
        Lock-->>Command: true
        Command->>ReportService: newJobReport()
        Command->>ImportService: execute(importConfig)
        ImportService-->>Command: throws Exception
        Command->>ReportService: markAsFailed()
        Command->>Lock: release() # In finally block
        Lock-->>Command: released
        Command-->>CLI: throws Exception
    end