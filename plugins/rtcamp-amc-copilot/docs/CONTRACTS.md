# AMC Copilot contracts (Wave 0, frozen)

This is the one page every lane reads before writing code. It lists every interface, table, route, hook and file-ownership rule that the six parallel lanes build on. The product plan is in [phase-1-plan.md](phase-1-plan.md), and its "Locked decisions" table is not negotiable.

**Frozen** means you may not rename, retype or remove anything on this page. You *may* add (a new repository method, a new key in a response) when your lane needs it, and you must update this page in the same PR. If you think a frozen item is wrong, stop and raise it. Do not work around it.

Contents:

1. [Rules for every lane](#1-rules-for-every-lane)
2. [Lane ownership and files you may edit](#2-lane-ownership-and-files-you-may-edit)
3. [Directory layout](#3-directory-layout)
4. [Check contract](#4-check-contract)
5. [How to add a check](#5-how-to-add-a-check-step-by-step)
6. [Runner, scheduler, queue, locks, kill switch](#6-runner-scheduler-queue-locks-kill-switch)
7. [Tables and repositories](#7-tables-and-repositories)
8. [REST API `amc/v1`](#8-rest-api-amcv1)
9. [Settings (`amc_settings`)](#9-settings-amc_settings)
10. [Capabilities](#10-capabilities)
11. [CVE provider](#11-cve-provider)
12. [Reports: post type, snapshot, section blocks](#12-reports-post-type-snapshot-section-blocks)
13. [How to add a section block](#13-how-to-add-a-section-block-step-by-step)
14. [Hooks and filters](#14-hooks-and-filters)
15. [WP-CLI](#15-wp-cli)
16. [Tests and linting](#16-tests-and-linting)
17. [Deviations from the plan](#17-deviations-from-the-plan)

---

## 1. Rules for every lane

1. **Core WordPress APIs only.** No new Composer or npm runtime packages without asking.
2. **VIP-safe.** Never write files. No `debug.log`. Only the WP-Cron API. Custom tables only through `inc/Storage/`.
3. **Front-end budget.** Nothing you add may run a DB query or enqueue an asset on a front-end request. Read `amc_settings` (autoload off) only in admin, REST, cron and CLI. The capture layer (A1) buffers in memory and writes once in `shutdown`.
4. **Checks never write to the site** and never run inside a REST request.
5. **UI = WordPress core only.** `@wordpress/components`, `@wordpress/dataviews`, `@wordpress/icons`, core admin classes, core blocks and block supports. No new colours, fonts or component library. Custom CSS only under `.amc-`, in as few files as possible.
6. **Security.** Escape on output, sanitise on input, a real `permission_callback` on every route, `$wpdb->prepare()` with `%i` for table names.
7. **Conventions.** `declare( strict_types = 1 );`, full types, a docblock with `@since` on every class, PSR-4 (`AMC\Foo\Bar` lives in `inc/Foo/Bar.php`). Text domain `rtcamp-amc-copilot`. Prefix `amc_` for hooks, options, meta, cron events and tables.
8. **Tests first.** Every class you add gets a PHPUnit test in `tests/php/` that mirrors `inc/`. PHPCS, PHPStan, ESLint and PHPUnit must be green before you open a PR.
9. **Never edit another lane's files** (section 2). If you need something from another lane, use the hook or repository method listed here.

## 2. Lane ownership and files you may edit

| Lane | Scope | You may create/edit | You register through |
| --- | --- | --- | --- |
| **A1 Platform core** | Capture (errors, deprecations, 1-in-100 query sampler), ChangeLog listeners, deploy diff, Retention | `inc/Capture/**`, `inc/Storage/StorageModule.php`, `inc/Storage/Retention*.php`, `inc/Rest/ErrorsController.php`, `inc/Rest/ChangesController.php` (handler bodies), `tests/php/Capture/**`, `tests/php/Storage/Retention*` | `CaptureModule`, `StorageModule` |
| **A2 Notify** | AlertRules, throttling (once per finding, 10/hour), Mailer, Digest at 09:00, report emails *transport* | `inc/Notify/**`, `inc/Rest/NotifyController.php`, `tests/php/Notify/**` | `NotifyModule`; listen to `amc_findings_processed` |
| **B1 Simple checks** | `updates.plugins`, `updates.themes`, `updates.php`, `updates.readiness`, `inventory.*`, `detect.*`, `hosting.platform`, `users.*`, `health.*`, `perf.cache`, `perf.database` | New files in `inc/Checks/<Group>/`, `inc/Checks/Providers/LaneB1Checks.php`, `inc/Rest/UpdatesController.php`, `inc/Rest/InventoryController.php`, `tests/php/Checks/**` (own files), `tests/php/fixtures/<check>/` | `LaneB1Checks::checks()` |
| **B2 Advanced checks** | `vuln.match` + matcher + FixtureProvider, `errors.*`, `smoke.urls`, `security.*`, `ssl.certificate`, `perf.response`, `perf.slow_queries`, abilities | New files in `inc/Checks/<Group>/`, `inc/Vulnerability/Matcher.php`, `inc/Vulnerability/FixtureProvider.php`, `inc/Checks/Providers/LaneB2Checks.php`, `inc/Abilities/**`, `inc/Rest/{Vulnerabilities,Security,Performance,Smoke}Controller.php`, own tests and fixtures | `LaneB2Checks::checks()`, `AbilitiesModule` |
| **C Console** | All 12 screens and the Dashboard widget | `inc/Admin/**`, `src/js/console/**`, `src/css/console*.scss`, `tests/js/**`, `tests/php/Admin/**` | `AdminModule` |
| **D1 Reports core** | Post type capabilities, Generator, Snapshot builder, FieldCatalogue, Periods, saved fields, schedules, report emails *content*, `wp amc report generate` | `inc/Reports/*.php` (except `SnapshotSchema.php` field changes, which need the change rule in [report-snapshot.md](report-snapshot.md)), `inc/Reports/Cli/**`, `inc/Rest/ReportsController.php`, `tests/php/Reports/*.php` | `ReportsModule` |
| **D2 Report blocks** | The other 17 section blocks, the custom section block, client view, print CSS | `inc/Reports/Blocks/**` (not `AbstractSectionBlock`/`SectionData` without asking), `src/blocks/**` (not `_shared/` without asking), `src/css/report-print.scss`, `tests/php/Reports/Blocks/**` | `SectionBlocksModule`; add blocks to `ReportPostType::template()` *through D1* |

**Frozen shared files. Nobody edits these after Wave 0 without agreement:** `inc/Main.php`, `inc/Rest/RestModule.php`, `inc/Rest/AbstractController.php`, `inc/Runner/**`, `inc/Checks/{Check,Result,Finding,Context,Registry,Group,Status,Severity,Schedule}.php`, `inc/Checks/Providers/{AbstractCheckProvider,CoreChecks}.php`, `inc/Core/{Installer,Settings,Capabilities,KillSwitch}.php`, `inc/Storage/{AbstractRepository,Tables,*Repository}.php`, `inc/Vulnerability/{ProviderInterface,Component,VulnMatch,NullProvider,Providers}.php`, `inc/Reports/SnapshotSchema.php`, this page.

Need a new repository method? Add it to the repository in a **small separate PR** labelled `contracts`, tagged for Wave 0 review. Do not mix it into feature work.

Cron events you add are scheduled on the `amc_activate` action and cleared on `amc_deactivate`. Do not edit `PluginSetup`.

## 3. Directory layout

```
rtcamp-amc-copilot/
  rtcamp-amc-copilot.php   bootstrap: constants, PHP 8.1 / WP 6.9 checks, autoloader, Main
  uninstall.php            drops tables, options, capabilities (reports kept unless opted in)
  inc/                     PHP, namespace AMC\ (PSR-4)
    Main.php               lists the domain modules (frozen)
    Core/                  Assets, PluginSetup, Installer, Settings, Capabilities, KillSwitch, Logger, Encryption, Templates, Components
    Checks/                Check contract, Registry, enums; Providers/ (one per lane); <Group>/ one class per check
    Runner/                Runner, Scheduler, Lock, ProcessedFindings; Cli/ (wp amc …)
    Rest/                  amc/v1 controllers (one per owner) + AbstractController
    Storage/               Tables, AbstractRepository, one repository per table, (A1) Retention
    Capture/               (A1)
    Vulnerability/         ProviderInterface, Component, VulnMatch, NullProvider, Providers; (B2) Matcher, FixtureProvider
    Notify/                (A2)
    Reports/               ReportPostType, SnapshotSchema; (D1) Generator…; Blocks/ section block classes; Cli/
    Abilities/             (B2)
    Admin/                 ConsolePage (mount point) + (C) console wiring
    Helpers/               Util (logger(), encryption(), templates())
  src/                     JS/CSS sources (built by wp-scripts into assets/build/, gitignored)
    blocks/_shared/        editor helpers for section blocks
    blocks/section-*/      one folder per section block (block.json, index.js, edit.js)
    js/, css/              admin/console entries
  tests/php/               PHPUnit, mirrors inc/; Support/ test doubles; fixtures/
  docs/                    this page, report-snapshot.md, phase-1-plan.md
```

How this differs from the plan's layout (skeleton conventions win):

| Plan | Here | Why |
| --- | --- | --- |
| `blocks/` at the root | `src/blocks/<name>/` → built to `assets/build/blocks/<name>/` | The skeleton's wp-scripts pipeline builds `src/blocks`; the PHP class lives in `inc/Reports/Blocks/` |
| `assets/` holds built JS/CSS | `assets/build/` (gitignored, built in CI and release) | Skeleton convention |
| CLI commands in `Runner/` | `inc/Runner/Cli/` (report command in `inc/Reports/Cli/`) | One class per command (`CLICommand`) |
| REST lives "in Admin" | `inc/Rest/` | One controller per owning lane, so lanes never edit the same file |
| Modules in `inc/Modules/<Domain>.php` (skeleton) | `inc/<Domain>/<Domain>Module.php` | Keeps each lane inside its own folder |
| Playwright tests in `tests/` | `tests/e2e/` (to be added by D2/C) | Not part of Wave 0 |

## 4. Check contract

All in `AMC\Checks`. Read the docblocks; they are the detailed spec.

```php
interface Check {
    public function id(): string;          // '<group>.<name>', e.g. 'updates.plugins'. Never changes.
    public function group(): Group;        // enum
    public function label(): string;       // translated, e.g. 'Plugin updates'
    public function schedule(): Schedule;  // enum; default interval
    public function run( Context $ctx ): Result;
}
```

| Type | Kind | Values / fields |
| --- | --- | --- |
| `Group` | enum (string) | `updates`, `vulnerabilities`, `errors`, `smoke`, `security`, `performance`, `health`, `inventory`, `users`, `hosting`, `detection`; `->label()` |
| `Schedule` | enum (string) | `hourly`, `twicedaily`, `daily`, `weekly`, `event` (never scheduled, on demand only); `->seconds()` |
| `Status` | enum (string) | `pass`, `warn`, `fail`, `info`; `->label()` (Pass, Warning, Failed, Info) |
| `Severity` | enum (string) | `info`, `low`, `medium`, `high`, `critical`; `->rank()`, `->at_least( Severity )`, `->label()` |
| `Result` | readonly value object | `status`, `summary` (one line), `data` (array, JSON), `findings` (list of `Finding`); build with `Result::pass\|warn\|fail\|info( $summary, $data = [], $findings = [] )`; `complete` (bool, default true), with `->partial()` returning a copy marked incomplete for batched checks |
| `Finding` | readonly value object | `dedupe_key` (1–191 chars), `severity`, `title`, `data`; `new Finding( 'core:6.9.2', Severity::HIGH, $title, $data )` |
| `Context` | readonly value object | `blog_id`, `trigger` (`cron`\|`manual`\|`cli`), `run_id`, `now` (`DateTimeImmutable` UTC), `previous_data` (`?array`, this check's previous run data); `Context::for_tests( $previous, $now )` |
| `Registry` | service | `new Registry()`; `all()`, `get( $id )`, `has( $id )`, `in_group( Group )`, `Registry::effective_schedule( $check )` |

Rules a check must follow (also in `Check.php`):

1. Read only. Never `update_option()`, set transients, write files or make remote POSTs.
2. Never call `get_current_blog_id()` or `switch_to_blog()`. The runner has already switched. Use `$ctx->blog_id`.
3. Finish in under 20 s. Batch long work and keep a cursor in `data`, then read it back from `$ctx->previous_data`. **Every batch except the last returns `$result->partial()`**, so the runner doesn't resolve findings that belong to other batches. Accumulate the findings in `data` across batches, and on the last batch return a normal (complete) result with every finding from the whole pass. Forgetting `partial()` makes findings resolve and reopen on alternate runs, which re-sends alerts.
4. Expected problems (API down, loopback blocked, `information_schema` restricted) are **not** exceptions. Return `INFO` or `WARN` with a clear summary. A thrown exception stores the run as `error` and leaves findings untouched.
5. Every WordPress read you need to fake in a test goes through a constructor callable with a real default.
6. Document the shape of `Result::$data` in the class docblock. Console (C) and the snapshot builder (D1) read it.
7. Snapshot diffs (deploy detection, admin changes) use `SnapshotsRepository`, written by the check that owns the kind (section 7).

## 5. How to add a check (step by step)

Worked example: `inc/Checks/Updates/CoreUpdates.php` and `tests/php/Checks/Updates/CoreUpdatesTest.php`.

1. Pick the id from the plan's catalogue, e.g. `updates.plugins`. The class goes in `inc/Checks/<Group>/<Name>.php`, e.g. `inc/Checks/Updates/PluginUpdates.php`, namespace `AMC\Checks\Updates`.
2. Copy `CoreUpdates.php`. Change `id()`, `group()`, `label()` and `schedule()` to the catalogue values.
3. Constructor: one optional callable per WordPress read, with the real call as the default:
   ```php
   public function __construct( ?callable $updates_source = null ) {
       $this->updates_source = $updates_source ?? static fn (): mixed => get_site_transient( 'update_plugins' );
   }
   ```
4. In `run()`, read through the callables, build `$data`, decide the `Status`, and add a `Finding` for each issue an engineer must act on. Choose a dedupe key that changes when the issue materially changes (`plugin:<slug>:<new_version>`).
5. Write the `$data` shape in the class docblock.
6. Fixtures: `tests/php/fixtures/<check-folder>/*.php`, each returning what the WordPress read returns, plus a README table.
7. Test: copy `CoreUpdatesTest.php`. Write one test per status branch plus a metadata test, using `Context::for_tests()`.
8. Register: add `new \AMC\Checks\Updates\PluginUpdates(),` to **your lane's provider** (`inc/Checks/Providers/LaneB1Checks.php` or `LaneB2Checks.php`). Do not touch any other file.
9. Run `wp amc check run updates.plugins` on a site, then `composer lint`, `composer phpstan` and the PHPUnit suite.

## 6. Runner, scheduler, queue, locks, kill switch

All built and tested in Wave 0. Lanes consume them; they do not edit them.

- **`Runner::run( $check_id, 'cron'|'cli', $user_id = 0, $blog_id = null )`** runs a check in this process and returns the finished run row or a `WP_Error`. The errors are `amc_unknown_check` (404), `amc_locked` (409), `amc_disabled` (503) and `amc_not_claimed`.
- **`Runner::enqueue( $check_id, $user_id )`** is "Run now". It creates a `queued` run row, returns its id and schedules the `amc_process_queue` event. If the check already has a queued or running run, it returns that id instead. **It never executes the check.**
- **`Runner::run_queued( $run_id )`** executes a queued row. Only the scheduler calls it.
- Per execution: kill switch check → `switch_to_blog()` if needed → lock `check:{blog}:{id}` in `amc_locks` (10 min TTL) → build `Context` (with previous run data) → `Check::run()` → store the run → dedupe findings (seen again → `touch`, new → insert, missing → resolve, comeback → **new row**) → `do_action( 'amc_findings_processed', ProcessedFindings )` → `do_action( 'amc_run_completed', $run_row )` → unlock → restore the blog.
- **`ProcessedFindings`** (readonly): `run_id`, `check_id`, `blog_id`, `trigger`, `new`, `ongoing`, `resolved`. The last three are lists of finding rows (section 7). Notify (A2) alerts on `new`. "Alert once, again only after it resolves and comes back" holds because a comeback is a new row with `alerted_at = NULL`.
- **Scheduler.** One recurring WP-Cron event, `amc_scheduler_tick`, runs every 5 minutes (`amc_every_five_minutes`). Each tick:
  - stops if the kill switch is on;
  - upgrades the schema if needed;
  - marks `running` rows older than 10 min as `error`;
  - runs queued rows oldest first;
  - runs due checks oldest-due first.

  `due_at = last run + effective interval`. A check that missed several intervals runs **once** (catch-up once). A check that never ran is due immediately. `event` checks and checks disabled in Settings are skipped. Each tick has a 25 s budget (filter `amc_scheduler_time_budget`); anything left over runs on the next tick.
- **Lock** (`AMC\Runner\Lock`): `Lock::for_check( $id, $blog )` or `Lock::named( 'digest' )`, then `acquire()` / `release()`. Use `Lock::named()` for your own cron jobs (retention, digest, report generation).
- **KillSwitch** (`AMC\Core\KillSwitch::is_active()`): the `AMC_COPILOT_DISABLED` constant wins whenever it is defined, true or false. Otherwise the `kill_switch` setting decides. Runner, scheduler and enqueue already check it. **A1 must check it once, at flush time, in `shutdown`. A2 must check it before sending email.**

Run lifecycle (`amc_runs.status`): `queued` → `running` → `pass` | `warn` | `fail` | `info` | `error`.

"Run now" from the console:

1. `POST /amc/v1/checks/run { "check_ids": ["updates.core"] }` returns 202 `{ "runs": [ { "check_id": "updates.core", "run_id": 42 } ] }`.
2. Poll `GET /amc/v1/runs/42` every 2–3 s until `status` is not `queued` or `running`. On standard hosts the poll request itself spawns WP-Cron; on VIP, Cron Control runs it within a minute.

## 7. Tables and repositories

Created by `AMC\Core\Installer` with `dbDelta()`. The version is stored in `amc_schema_version` (autoload off; current `1`). Tables use **`$wpdb->base_prefix`**, so there is one set of tables per network, and every row carries `blog_id`. Get a table name with `Tables::name( Tables::RUNS )`. All times are UTC `Y-m-d H:i:s`. `data` columns hold JSON; repositories encode it on write and decode it to arrays on read. Repositories return associative arrays with integer columns cast to `int`. `blog_id` arguments default to the current site.

### `amc_runs`, one row per check execution, 13 months, `RunsRepository`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `blog_id` | bigint | |
| `check_id` | varchar(64) | |
| `trigger_type` | varchar(16) | `cron` \| `manual` \| `cli` (REST field name: `trigger`) |
| `status` | varchar(16) | `queued` \| `running` \| `pass` \| `warn` \| `fail` \| `info` \| `error` |
| `summary` | varchar(255) | |
| `data` | longtext JSON | `Result::$data`, or exception details for `error` |
| `user_id` | bigint | Who triggered it (0 = cron) |
| `created_at` | datetime | Queued or started |
| `started_at`, `finished_at` | datetime NULL | |
| `duration_ms` | int | |

Keys: `(blog_id, check_id, created_at)`, `(blog_id, status, created_at)`, `(created_at)`.

Methods: `create_queued( $check_id, $user_id, $blog_id )`, `create_running( $check_id, $trigger, $user_id, $blog_id )`, `claim( $id ): bool`, `finish( $id, $status, $summary, $data, $ms )`, `find( $id )`, `queued( $blog_id, $limit )`, `pending_id( $check_id, $blog_id ): int`, `fail_stale( $seconds )`, `latest( $check_id, $blog_id, $before_id = 0 )`, `latest_per_check( $blog_id )`, `last_run_times( $blog_id )`, `query( [ blog_id, check_id, status, trigger, since, until, page, per_page ] ): { items, total }`, `delete_older_than( $column, $cutoff, $limit )`.

### `amc_findings`, one row per issue occurrence, 13 months after resolved, `FindingsRepository`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `blog_id` | bigint | |
| `check_id` | varchar(64) | |
| `dedupe_key` | varchar(191) | |
| `severity` | varchar(16) | `info` \| `low` \| `medium` \| `high` \| `critical` |
| `title` | varchar(255) | |
| `data` | longtext JSON | |
| `status` | varchar(16) | `open` \| `accepted` (accepted risk) \| `muted` \| `resolved` |
| `note` | text NULL | Accepted-risk note |
| `run_id` | bigint | Last run that saw it ("the run that produced it" in the console) |
| `first_seen`, `last_seen` | datetime | |
| `resolved_at`, `alerted_at` | datetime NULL | |

Keys: `(blog_id, check_id, dedupe_key)`, `(blog_id, status, severity)`, `(run_id)`, `(resolved_at)`.

Methods: `unresolved_for_check( $check_id, $blog_id )` (keyed by `dedupe_key`), `insert_finding( Finding, $check_id, $run_id, $blog_id )`, `touch( $id, Finding, $run_id )`, `resolve( $ids )`, `set_status( $id, $status, $note )`, `mark_alerted( $ids )` (A2), `open_counts_by_severity( $blog_id )`, `find( $id )`, `query( [ blog_id, check_id, check_ids, severity, status ('unresolved' = not resolved), alerted (bool), since, run_id, page, per_page ] )`.

`accepted` and `muted` findings stay unresolved and keep `last_seen` current, but they are excluded from open counts. Notify (A2) must not alert on them.

### `amc_events`, error or deprecation signature per hour, 13 months, `EventsRepository`

Columns: `id`, `blog_id`, `type` (`fatal` \| `warning` \| `notice` \| `deprecation` \| `doing_it_wrong`), `signature` char(32), `message` text, `source` (`core`, `plugin:<slug>`, `theme:<slug>`, `mu-plugin:<file>`, `unknown`), `file`, `line`, `hour` (`Y-m-d H:00:00`), `count`, `last_seen`.

Keys: **UNIQUE `(blog_id, signature, hour)`**, `(blog_id, type, hour)`, `(hour)`.

`signature = md5( type . '|' . file . '|' . line . '|' . normalised message )`, computed by A1. `errors.*` checks (B2) use it as the finding `dedupe_key`.

Methods: `EventsRepository::hour( $ts )`, `upsert_many( $rows )` (one `INSERT … ON DUPLICATE KEY UPDATE count = count + VALUES(count)` per flush), `grouped( [ blog_id, type, since, until, limit ] )`, `counts_by_type( $since, $until, $blog_id )`, `daily_counts( $signature, $days, $blog_id )`.

### `amc_queries`, sampled slow query per hour, 90 days, `QueriesRepository`

Columns: `id`, `blog_id`, `signature` char(32) (md5 of the normalised SQL), `sql_sample`, `caller`, `hour`, `count`, `max_ms` float, `avg_ms` float.

Keys: **UNIQUE `(blog_id, signature, hour)`**, `(hour)`.

Methods: `upsert_many( $rows )` (weighted average for `avg_ms`, `GREATEST` for `max_ms`), `grouped( $since, $blog_id, $limit )`. `QueriesRepository::RETENTION_DAYS = 90`.

### `amc_changes`, code or user change, 13 months, `ChangesRepository`

Columns: `id`, `blog_id`, `type` (`plugin` \| `theme` \| `core` \| `user`), `action` (`install` \| `update` \| `activate` \| `deactivate` \| `delete` \| `role` \| `access_grant` \| `access_revoke`), `item` (slug, stylesheet, `wordpress`, user login), `item_name` (display name), `old_version`, `new_version`, `source` (`admin` \| `cli` \| `deploy` \| `auto`), `user_id`, `created_at`.

Keys: `(blog_id, created_at)`, `(blog_id, type, created_at)`, `(created_at)`.

Methods: `insert( [ type, action, item, item_name?, old_version?, new_version?, source?, user_id?, created_at?, blog_id? ] )`, `query( [ blog_id, type, action, source, item, since, until, page, per_page ] )`.

### `amc_snapshots`, inventory snapshot, 13 months, `SnapshotsRepository`

Columns: `id`, `blog_id`, `kind`, `data` JSON, `taken_at`. Key: `(blog_id, kind, taken_at)`.

Kinds:

- `plugins` (B1 `inventory.plugins` writes it; A1 diffs it for `deploy` changes)
- `themes` (B1)
- `roles` (B1 `users.roles`)
- `admins` (B2 `security.admins`)

Methods: `insert( $kind, $data, $blog_id )`, `latest( $kind, $blog_id, $before = null )`.

### `amc_locks`, `LocksRepository` (use `Runner\Lock`)

Columns: `name` varchar(191) PK, `owner`, `expires_at`.

### Retention (A1)

Run a daily cron job under `Lock::named( 'retention' )`. Call `$repo->delete_older_than( $column, $cutoff, 1000 )` in a loop until it returns less than 1000 or 20 s have passed. Columns and cut-offs:

| Table | Column | Cut-off |
| --- | --- | --- |
| runs | `created_at` | `retention_months` |
| findings | `resolved_at` | `retention_months` |
| events | `hour` | `retention_months` |
| queries | `hour` | 90 days |
| changes | `created_at` | `retention_months` |
| snapshots | `taken_at` | `retention_months` |

## 8. REST API `amc/v1`

The route map is frozen and registered by `RestModule`. Each controller has one owner, who replaces handler bodies. The owner keeps the route, method, args and response shape.

**Permissions:**

- **E** = `amc_manage`
- **R** = `amc_manage` or `amc_view_reports`
- **A** = `amc_manage` + `manage_options`

Anonymous requests get 401; logged-in users without the capability get 403.

**Status column:**

- **Live** works today.
- **Stub** returns correctly shaped placeholder data with the header `X-AMC-Stub: <lane>`.
- **501** returns `amc_not_implemented`.

| Method | Path | Perm | Owner | Status | Request | Response |
| --- | --- | --- | --- | --- | --- | --- |
| GET | `/overview` | E | W0 | Live | – | `Overview` |
| GET | `/checks` | E | W0 | Live | – | `Check[]` |
| POST | `/checks/run` | E | W0 | Live | `{ check_ids: string[] \| ["all"] }` | 202 `{ runs: [{ check_id, run_id }] }` |
| GET | `/runs` | E | W0 | Live | `check_id, status, trigger, since, until, page, per_page` | `Run[]` + `X-WP-Total`, `X-WP-TotalPages` |
| GET | `/runs/{id}` | E | W0 | Live | – | `Run` + `data` + `findings: Finding[]` (also the "Run now" poll) |
| POST | `/runs/{id}/rerun` | E | W0 | Live | – | 202 `{ run_id }` |
| GET | `/findings` | E | W0 | Live | `check_id, group, severity, status (open\|accepted\|muted\|resolved\|unresolved), since, page, per_page` | `Finding[]` + headers |
| GET | `/findings/{id}` | E | W0 | Live | – | `Finding` |
| POST | `/findings/{id}/status` | E | W0 | Live | `{ status, note? }` | `Finding` (accept risk, mute, resolve, reopen) |
| GET | `/settings` | E | W0 | Live | – | `Settings` (section 9) |
| POST | `/settings` | A | W0 | Live | partial `Settings` JSON | `Settings` |
| GET | `/access` | A | W0 | Live | – | `[{ id, login, display_name, email }]` |
| POST | `/access` | A | W0 | Live | `{ user_id }` | 201 engineer |
| DELETE | `/access/{user_id}` | A | W0 | Live | – | `{ revoked: true, user_id }`; 400 on self-revoke |
| GET | `/updates` | E | B1 | Live (generic) | – | `{ checks: { 'updates.core'\|'updates.plugins'\|'updates.themes'\|'updates.php'\|'updates.readiness': LatestResult\|null } }` |
| GET | `/inventory` | E | B1 | Live (generic) | – | `{ checks: { 'inventory.*', 'hosting.platform', 'detect.*': LatestResult\|null } }` |
| GET | `/vulnerabilities` | E | B2 | Live | `status (…\|all)` | `{ provider: { id, ready, last_error }, last_run: LatestResult\|null, findings: Finding[], counts: { open: { critical, high, medium, low, info }, accepted } }` |
| POST | `/vulnerabilities/{id}/accept` | E | B2 | Live | `{ note }` (required) | `Finding` (accept risk of a `vuln.match` finding with a note; 404 for other checks) |
| GET | `/security` | E | B2 | Live | – | `{ checks: { 'security.checksums'\|'security.config'\|'security.admins'\|'ssl.certificate': LatestResult\|null }, open_findings: Finding[] }` |
| GET | `/performance` | E | B2 | Live | `days` (1–90) | `{ checks: {perf.*}, response_trend: [{ date, avg_ms, url_count }], slow_queries: [{ signature, sql_sample, caller, count, max_ms, avg_ms, last_seen }] }` |
| GET | `/smoke` | E | B2 | Live | – | `{ last_run, urls: [{ url, required_text, is_default, last_status ('pass'\|'fail'\|'loopback_blocked'\|null), last_http_code, last_response_ms, pass_rate_30d, trend: [{ at, ms, ok }] }] }` |
| POST | `/smoke/urls` | E | B2 | Live | `{ url, required_text? }` | `{ urls: [{ url, required_text }] }` (adds an extra URL or edits its required text; engineers, unlike `POST /settings`; max 25) |
| GET | `/errors` | E | A1 | Live | `type, days` | `[{ signature, type, message, source, file, line, count, first_seen, last_seen, sparkline: int[days], finding_id, finding_status }]` |
| GET | `/changes` | E | A1 | Live | `type, action, source, since, until, page, per_page` | `[{ id, type, action, item, item_name, old_version, new_version, source, user_id, user_display_name, created_at }]` + headers |
| POST | `/notify/test` | E | A2 | 501 | `{ type: immediate\|digest }` | `{ sent: bool, recipients: string[] }` |
| GET | `/reports` | R | D1 | Live | `status (any\|draft\|publish), page, per_page` | `Report[]` (non-engineers get published only) |
| POST | `/reports/generate` | E | D1 | 501 | `{ period_type: month\|quarter\|year\|custom, start?, end? }` | 201 `Report` |
| POST | `/reports/{id}/regenerate` | E | D1 | 501 | – | `Report` (keeps engineer fields, overrides, custom sections) |
| GET | `/reports/{id}/snapshot` | R | D1 | Live | – | `{ id, taken_at, snapshot }` (drafts: engineers only) |
| GET | `/saved-fields` | E | D1 | Live | – | `{ fields: { section: { field: string\|null } }, schema: { section: string[] } }` |
| POST | `/saved-fields` | E | D1 | 501 | `{ fields }` | same as GET |

Report editing and publishing go through the block editor and core `/wp/v2/amc-reports`. CSV export is client-side, from the loaded list.

**Shapes.** All times are ISO 8601 UTC (`2026-11-13T09:00:00Z`) or `null`.

```
Run       { id, check_id, check_label, group|null, trigger, status, summary, user_id,
            created_at, started_at, finished_at, duration_ms }   (+ data, findings on GET /runs/{id})
Finding   { id, check_id, group|null, dedupe_key, severity, title, data, status, note|null,
            run_id, first_seen, last_seen, resolved_at, alerted_at }
Check     { id, label, group, group_label, schedule, default_schedule, enabled,
            last_run: Run|null, next_run_at|null }
LatestResult { run: Run, data: <check's Result::$data> }
Overview  { status, kill_switch, findings_by_severity: { critical, high, medium, low, info },
            groups: [{ group, label, status|null, last_run_at|null, checks }],
            versions: { wordpress, php, mysql, theme: { name, version, parent: {name, version}|null } },
            counts: { plugins, plugins_active, themes, pending_updates: { core, plugins, themes, total } },
            next_runs: [{ check_id, label, next_run_at }] }
Report    { id, title, status, report_id, period_type, period_start, period_end, environment,
            author: { id, name }, modified, edit_url|null, view_url|null }
```

Errors: `{ code, message, data: { status, owner? } }`. Codes you will see:

- `amc_not_found`
- `amc_unknown_check`
- `amc_locked`
- `amc_disabled`
- `amc_not_implemented`
- `amc_self_revoke`
- `amc_invalid_user`
- `amc_forbidden`
- `rest_forbidden`
- `rest_invalid_param`

**Adding a route:** add it to your own controller, add a row to the table above, and add it to `tests/php/Rest/RoutesTest.php::ROUTES`. Extend `AbstractController`, use `$this->route()` and a permission method. Never use `__return_true`.

## 9. Settings (`amc_settings`)

One option, autoload **off**, accessed through `AMC\Core\Settings`. Methods: `Settings::get()`, `get_value( $key )`, `update( $partial )` (sanitises and drops unknown keys), `check_interval( $id )`, `check_enabled( $id )`, `environment()`, `recipients()`, `loopback_password()`, `for_rest()`.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `site_type` | `content` \| `ecommerce` | `content` | Smoke default URL: latest post or latest product |
| `environment` | `''` \| `production` \| `staging` \| `development` \| `local` | `''` | `''` = `wp_get_environment_type()` |
| `contract_start` | `Y-m-d` \| `''` | `''` | Quarters and years follow it, or the calendar when empty |
| `recipients` | string[] | `[]` | Empty = every `amc_manage` user (`Settings::recipients()`) |
| `digest_time` | `HH:MM` | `09:00` | Site time |
| `immediate_alerts_per_hour` | int 0–100 | `10` | Overflow goes to the digest |
| `notify_client_on_publish` | bool | `true` | |
| `smoke_urls` | `[{ url, required_text }]` | `[]` | Extra URLs; home, login and latest post or product are always added by `smoke.urls` |
| `loopback_auth` | `{ username, password }` | empty | Password stored encrypted. Needs `AMC_COPILOT_ENCRYPTION_KEY` in wp-config; without it a password is not saved. REST returns `{ username, has_password, encryption_available }` |
| `kill_switch` | bool | `false` | The `AMC_COPILOT_DISABLED` constant overrides it. REST adds `kill_switch_locked` |
| `checks` | `{ <check_id>: { enabled: bool, interval: ''\|hourly\|twicedaily\|daily\|weekly } }` | `{}` | Per-check overrides |
| `retention_months` | int 1–60 | `13` | |
| `disclaimers` | `{ backups, hosting, domains, accessibility }` (HTML) | `''` each | `''` = the block's built-in text |

Other options:

- `amc_saved_fields` (D1, see [report-snapshot.md](report-snapshot.md))
- `amc_schema_version`
- `amc_version`
- `amc_delete_reports_on_uninstall` (D1 UI)

## 10. Capabilities

`AMC\Core\Capabilities`:

| Capability | Kind | Granted to | Allows |
| --- | --- | --- | --- |
| `Capabilities::MANAGE` = `amc_manage` | **User** capability | Engineers, via `grant()` | Console, runs, settings, reports (edit and publish) |
| `Capabilities::VIEW_REPORTS` = `amc_view_reports` | **Role** capability | `administrator`, on activation | Published reports only |

- `grant( $user_id, $actor_id, $source )` and `revoke( … )` return `true` or a `WP_Error` and write an `amc_changes` row (`user` / `access_grant` or `access_revoke`).
- `can_manage_access( $user_id )` requires `amc_manage` + `manage_options`.
- `engineers()` lists the users who hold `amc_manage`.
- `on_activation()` and `remove_all()` handle install and uninstall.
- The activating user gets `amc_manage` if nobody has it yet.
- `ReportPostType::map_caps()` grants every `*_amc_reports` primitive to `amc_manage`. D1 adds read access for `amc_view_reports`.

## 11. CVE provider

`AMC\Vulnerability`. The plan's `Match` class is named **`VulnMatch`**, because `match` is reserved in PHP 8.

```php
interface ProviderInterface {
    public function id(): string;               // 'rtcamp-cve'
    public function is_ready(): bool;
    /** @param list<Component> $components  @return list<VulnMatch> */
    public function match( array $components ): array;
    public function last_error(): ?string;      // shown on the Vulnerabilities screen; never throw
}
new Component( 'core'|'plugin'|'theme', $slug, $version, $name );
new VulnMatch( $type, $slug, $reference, $title, Severity, ?$cvss, $affected_range, ?$fixed_in, $url, ?$published_at );
Providers::current();   // applies the 'amc_vulnerability_provider' filter, falls back to NullProvider
```

- `vuln.match` (B2) uses the dedupe key `"{type}:{slug}:{installed_version}:{reference}"`, with Finding data `VulnMatch::to_array() + installed_version`.
- Severity `high` or `critical` triggers an immediate alert.
- Provider errors go into the run `data` (`provider_error`) and never fail other checks.

## 12. Reports: post type, snapshot, section blocks

- **Post type `amc_report`** (`ReportPostType`):
  - private, shown in admin and REST (`rest_base` `amc-reports`);
  - supports title, editor, revisions, author, custom-fields;
  - capability type `amc_report` with `map_meta_cap`;
  - meta: `_amc_period_type`, `_amc_period_start`, `_amc_period_end`, `_amc_environment`, `_amc_snapshot` (JSON string), `_amc_snapshot_taken_at`, `_amc_report_id`;
  - template = locked section blocks, `template_lock` false (custom sections can go anywhere).
- **Snapshot format:** [report-snapshot.md](report-snapshot.md) and `SnapshotSchema` (sections, fields, sources, types; `empty_snapshot()`, `for_post()`, `saved_fields()`, `fields_by_source()`).
- **Section blocks** are dynamic. PHP renders them from snapshot + attributes (`AbstractSectionBlock`). Every one has the same four attributes: `lock`, `hidden`, `overrides`, `engineer`. Exemplar: Overview.

## 13. How to add a section block (step by step)

Worked example: `amc/section-overview`. Files:

- `inc/Reports/Blocks/OverviewSection.php`
- `src/blocks/section-overview/{block.json,index.js,edit.js}`
- `tests/php/Reports/Blocks/OverviewSectionTest.php`

1. Find the section key and its fields in `SnapshotSchema::sections()`, e.g. `ssl` → block `amc/section-ssl`, slug `ssl`. Keys with `_` become `-` in the block name (`plugins_themes` → `amc/section-plugins-themes`).
2. **PHP:** copy `OverviewSection.php` to `inc/Reports/Blocks/SslSection.php`. Set `section_key()` to `'ssl'`. In `render_section( SectionData $data )`:
   - build rows with `$data->label( $f )`, `$this->format_value( $data->value( $f ), $data->type( $f ) )` for auto and saved fields, and `$data->engineer( $f )` for engineer fields;
   - return `$this->key_value_table( $rows )`, or core table, list or paragraph markup for lists;
   - escape everything: `format_value()` and `engineer()` already return safe HTML; anything else goes through `esc_html()` or `wp_kses_post()`.
3. **JS:** copy `src/blocks/section-overview/` to `src/blocks/section-ssl/`.
   - In `block.json`, change `name`, `title` and `description` only. Keep `attributes`, `supports`, `usesContext`, `editorScript` and `editorStyle` exactly as they are.
   - In `edit.js`, set `SECTION = 'ssl'` and list `FIELDS` (`key`, translated `label`, `source`, optional `type`) in the same order as the PHP.
   - Use `SectionShell` + `FieldRow` from `../_shared`. Nothing else.
4. **Register:** add `SslSection::class` to `SectionBlocksModule::get_classes()`. Ask D1 to add `[ 'amc/section-ssl', $locked ]` to `ReportPostType::template()` in report order.
5. **Test:** copy `OverviewSectionTest.php`. Test the snapshot values in core table markup, that overrides win, that engineer HTML is sanitised, and that a hidden section renders `''`.
6. Run `npm run build:blocks`, `npm run lint:js`, `composer lint`, PHPUnit. Insert the block on a draft report to check the editor and the published view match.

Rules:

- Core blocks and supports only.
- Tables use core Table markup: `<figure class="wp-block-table"><table class="has-fixed-layout">`.
- Headings use `<h2 class="wp-block-heading">`.
- Fixed disclaimers come from `Settings::get_value( 'disclaimers' )[ key ]`, with the block's built-in text as the fallback.
- Print CSS lives in one file under 5 KB (D2).

## 14. Hooks and filters

| Hook | Type | Fired by | Args | Used by |
| --- | --- | --- | --- | --- |
| `amc_register_checks` | filter | `Registry::all()` | `Check[] $checks` | Check providers (one per lane) |
| `amc_vulnerability_provider` | filter | `Providers::current()` | `ProviderInterface $provider` | rtCamp CVE plugin, B2 FixtureProvider in tests |
| `amc_findings_processed` | action | `Runner` after every successful run | `ProcessedFindings $processed` | A2 Notify |
| `amc_run_completed` | action | `Runner` after any run (including `error`) | `array $run_row` | C (optional), A2 |
| `amc_scheduler_time_budget` | filter | `Scheduler::tick()` | `int $seconds` (25) | Hosts with long cron windows |
| `amc_activate` | action | Plugin activation | – | Lanes scheduling their own cron events |
| `amc_deactivate` | action | Deactivation and uninstall | – | Lanes clearing their cron events |
| `amc_scheduler_tick` | cron | Every 5 min | – | Scheduler |
| `amc_process_queue` | cron (single) | `Runner::enqueue()` | – | Scheduler |

Cron events lanes will add (names reserved):

- `amc_retention` (A1, daily)
- `amc_digest` (A2, daily at `digest_time`)
- `amc_report_monthly`, `amc_report_quarterly`, `amc_report_annual` (D1)

## 15. WP-CLI

| Command | Owner | Notes |
| --- | --- | --- |
| `wp amc check list [--format=table\|json\|csv\|yaml\|ids]` | W0 | id, group, effective schedule, enabled, last status, last run |
| `wp amc check run <id>... [--all] [--format=…]` | W0 | Runs synchronously with trigger `cli`; exit code 1 if any run errored |
| `wp amc access grant <user>` / `revoke <user>` | W0 | ID, login or email; logged with source `cli` |
| `wp amc report generate [--period=month\|quarter\|year\|custom] [--start=] [--end=]` | D1 | Stub (errors until D1 implements it) |

## 16. Tests and linting

| What | Command (from the plugin folder) |
| --- | --- |
| PHPCS (rtCampWP: WPCS + VIP + Slevomat) | `composer lint` (fix: `composer format`) |
| PHPStan level 5 | `composer phpstan` |
| PHPUnit (wp-env) | `npm run test:php` (needs Docker) |
| PHPUnit without Docker | Create an empty DB, write a `wp-tests-config.php` (DB_HOST may be `localhost:/path/to/mysqld.sock`), then `WP_PHPUNIT__TESTS_CONFIG=/path/wp-tests-config.php vendor/bin/phpunit`. Add `WP_MULTISITE=1` for multisite |
| ESLint / Stylelint / package.json | `npm run lint` |
| Build | `npm run build:dev` or `npm run build:prod` |

Test helpers:

- `AMC\Tests\TestCase`: settings reset per test, `register_checks( ...$checks )`, `fixture( 'folder/name' )`.
- `AMC\Tests\Support\FakeCheck`: a configurable check for runner, scheduler and REST tests.
- Custom tables are created once in `tests/bootstrap.php`. Each test runs in a rolled-back transaction.
- WP's test suite unregisters meta keys after each test, so re-register them in a test that needs them.

## 17. Deviations from the plan

| Plan says | Wave 0 does | Why |
| --- | --- | --- |
| Runner, locks, catch-up, kill switch, settings and the Runs page exist in amc-auto-pilot | Built from scratch here (Runs page REST only; UI is lane C) | They did not exist |
| `Match` value object | `VulnMatch` | `match` is a reserved word in PHP 8 |
| `ProviderInterface` has 3 methods | Adds `last_error(): ?string` | The plan requires provider errors to be shown and logged without throwing |
| `Check` has 4 methods | Adds `label()` | The console needs a human name per check |
| `amc_runs.trigger` | Column `trigger_type`; REST still says `trigger` | `TRIGGER` is a reserved word in MySQL |
| Run statuses pass/warn/fail/info | Adds `queued`, `running`, `error` | The "Run now" queue is the runs table itself; `error` = the check threw |
| `amc_findings` key columns | Adds `status`, `note`, `run_id` | Accepted risk with a note, mute, "show the run that produced it" |
| `amc_changes` actions | Adds `access_grant`, `access_revoke`; adds `item_name` | The plan requires access changes in the change log; removed plugins still need a name in reports |
| `amc_runs` `started_at` | Adds `created_at`, `finished_at`, `user_id` | Queue ordering and "who ran it" |
| Tables per site | `base_prefix` tables shared by the network, keyed by `blog_id` | The network view needs no migration and one query covers all sites |
| `blocks/`, `assets/` at the root | `src/blocks/`, `assets/build/` | Skeleton build pipeline (section 3) |
| PHP 8.1+ | Header `Requires PHP: 8.1`, PHPCompatibility checks 8.1. `rtcamp/wp-primitives` declares `php >=8.2` but its code passes PHPCompatibility for 8.1, so `config.platform.php` stays 8.2 for dependency resolution and `platform-check` is off | Locked decision; the dependency constraint should be lowered upstream |
| One cron event per check | One 5-minute tick plus the queue event | Per-check overrides without rescheduling; catch-up once falls out of `last run + interval` |
| Loopback password in Settings | Stored only when `AMC_COPILOT_ENCRYPTION_KEY` is defined | The skeleton's Encryptor deliberately has no salt fallback; never store it in plain text |
