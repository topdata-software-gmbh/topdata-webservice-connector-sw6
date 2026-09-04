# AGENTS.md — TopData Webservice Connector (Shopware 6)

## What this repo is
- Shopware 6 plugin (`topdata/topdata-connector-sw6`, type `shopware-platform-plugin`).
- Namespace: `Topdata\TopdataConnectorSW6\` → `src/` (PSR-4).
- Requires Shopware 6.7.*, `topdata/topdata-foundation-sw6 ^1.3.0`, `ext-curl`.
- **Deprecated**, EOL July 2026. Uses the **legacy TopData Webservice V1 API** (`https://ws.topdata.de`). The `V2` terms in code/commands (`--experimental-v2`, `ProductInformationServiceV2`) refer only to internal (experimental) linking logic — **not** the webservice API.
- Plugin base class: `src/TopdataConnectorSW6.php`. CLI commands live in `src/Command/`; admins in `src/Controller/Admin/`; storefront controllers in `src/Controller/`.

## Dev / lint commands
- **Lint (PHP-CS-Fixer):** repo ships `php-cs-fixer.phar` (v3-compatible) and `.php-cs-fixer.dist.php` (scoped to `src/`, PSR-12 + custom rules).
  - Dry run: `php php-cs-fixer.phar fix --dry-run`
  - Apply: `php php-cs-fixer.phar fix [path]`
  - See `php-cs-fixer.md` for usage.
- **No PHPUnit / no test suite configured** in `composer.json` (dev deps only contain `friendsofphp/php-cs-fixer`). Do not assume `vendor/bin/phpunit` exists.
- **No CI workflow for tests/lint.** The only GitHub workflow (`.github/workflows/update-demoshops.yaml`) pings three staging demoshops on every push.
- **Composer:** no `composer.lock` is tracked (`.gitignore` excludes it). `vendor/` is gitignored. Run `composer install` inside a Shopware root with the plugin in `custom/plugins/`.
- **OpenCode config:** `.opencode/opencode.jsonc` only re-inherits a parent `AGENTS.md` via `instructions` — no project-local tools/agents.

## Key CLI commands (read `src/Command/Command_Import.php` first)
- `bin/console topdata:connector:import` — primary command; flags `--all`, `--mapping`, `--device`, `--product`, `--device-media`, `--product-info`, `--device-synonyms`, `--device-only`, `--product-media-only`, `--product-variated`, `--experimental-v2` (alias `-x`), `--product-device`, `--purge-cache`, `--base-url=URL`. **Order matters** (e.g. `--device-media` runs only for devices enabled by `--product`; see README §"Console commands").
- `bin/console topdata:connector:test-connection` (also `--print-config`).
- `bin/console topdata:connector:last-report`, `topdata:connector:set-reports-password`, `topdata:connector:check-crashed-jobs`.
- All imports use `Symfony\Component\Lock` (`topdata-connector-import`, TTL 3600 s) — concurrent runs exit gracefully.
- Recommended RAM for full import: `php -d memory_limit=2048M bin/console topdata:connector:import -v --all`.

## Scheduled task
- `ConnectorImportTask` (`src/ScheduledTask/`) runs **daily** (`getDefaultInterval() = 86400`).
- Gated by config flag `TopdataConnectorSW6.config.enableScheduledImport`; when off, handler logs and skips.
- Runs `--all` via `ScheduledImportRunnerService::runFullImportForScheduledTask()`.
- Known historical issue: in older SW 6.6 → 6.7 upgrades the handler constructor signature changed (must pass `$exceptionLogger`). See `_ai/backlog/reports/` for the RCA + fix commit (`77e8224`, `1048352`).

## Architecture notes
- **Entities** (Shopware DAL, in `src/Core/Content/`): `Device`, `DeviceType`, `Brand`, `Series`, `Category`, `Product` (extension), `TopdataToProduct`, `TopdataReport`, `Customer` (`DeviceToCustomer`). Migrations in `src/Migration/` — current highest timestamp `1754987352` (`AddCustomFieldsForTagging`, adds the `topdata_connector_is_imported_media` custom field used to keep manually-uploaded media safe during `--product-info`/`--all`).
- **Services:** `TopdataWebserviceClient` (HTTP + retry, `API_VERSION = '108'`), `ImportService`, `MediaHelperService` (extracted from `EntityHelperService` in 7.0.4), `EntitiesHelperService`, `ProgressLoggingService`, `TopdataReportService`, `ProductInformationServiceV1Slow` / `ProductInformationServiceV2`.
- **DTO/Enum:** `src/DTO/ImportConfig.php`, `src/Enum/ProductRelationshipTypeEnumV1.php`, `ProductRelationshipTypeEnumV2.php`.
- **DI:** `src/Resources/config/services.xml`; routes in `src/Resources/config/routes.xml`; admin/plugin config in `src/Resources/config/config.xml` (bilingual DE/EN/NL labels — keep parity when adding fields).
- **Helpers:** `src/Helper/CurlHttpClient.php`; utils in `src/Util/`.
- Sub-namespace `Topdata\TopdataFoundationSW6\` is provided by the required `topdata/topdata-foundation-sw6` package (e.g. `AbstractTopdataCommand`, `TopdataJobTypeConstants`, `CliLogger`, `UtilThrowable`) — not part of this repo.

## Repo-specific conventions
- **CLI command class naming:** `Command_<Name>.php` (e.g. `Command_Import.php`), not `ImportCommand.php`. Keep this style for new commands.
- **Coding style** is enforced by `.php-cs-fixer.dist.php`: PSR-12, short arrays, single quotes, blank line before `return`, `declare(strict_types=1)` at the top of every file (see `src/Command/Command_Import.php:3`), `declare_equal_normalize` with no space, **no closing `?>` tag**.
- **Bilingual labels** in `config.xml` (DE mandatory, NL where it already exists). Preserve them when adding config fields.
- **Deprecated/stub options** may linger in code with `// TODO: remove this option` comments (e.g. `--device-only` in `Command_Import.php:78`). Do not reintroduce removed flags like `--start`/`--end` (removed in 7.1.1, see CHANGELOG).
- **Credentials are loaded from `system_config`** (`TopdataConnectorSW6.config.*`), never from `.env` directly. Demo creds (uid=6) are documented in README + `config.xml`.

## Performance / operational gotchas
- `--device-media` and any media download needs write perms on the web root — see README "Non-Destructive Image Updates". After import, often `chown -R www-data:www-data .` is needed if CLI user ≠ web user.
- `media.file_name` has **no DB index** in Shopware core → single-image updates do a full table scan. Recommended mitigation documented in README: `CREATE INDEX IX__file_name ON media (file_name(255));`.
- Since 8.2.0, image unlinking is **non-destructive**: only media tagged with custom field `topdata_connector_is_imported_media` is removed (preserves user-uploaded images). Don't regress this behavior.
- Bulk product updates suppress SEO URL generation (commit `91d9e00`) to avoid duplicate-key errors — don't re-enable blindly.

## Reference docs already in this repo
- `README.md` — install, config, full import flag reference, performance note.
- `manual/` — bilingual user manual (DE + EN, numbered sections).
- `CHANGELOG.md` — Keep-a-Changelog format; update on version bumps.
- `VERSIONING.md` — branch → Shopware major mapping (only SW 6.4/6.5/6.6 listed; this checkout targets 6.7 — branch scheme may be stale).
- `ai_docs/` — project notes, plans, reports, conventions (`ai_docs/CONVENTIONS.md`, `ai_docs/PROJECT_SUMMARY.md`).
- `_ai/backlog/active/` and `_ai/backlog/reports/` — implementation plans + reports (e.g. the scheduled-task RCA). Read these before changing scheduled-task or import behavior.
- `.github/workflows/update-demoshops.yaml` — push to `main` automatically triggers plugin updates on three staging demoshops; expect side-effects on push.