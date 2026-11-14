# AMC Copilot: blueprint

This is the single-file guide to the AMC Copilot plugin (`rtcamp-amc-copilot`, version 0.1.0, namespace `AMC\`, option and table prefix `amc_`). It is written from the code at commit `f63995b` on branch `docs/amc-blueprint`. Where this file and the code disagree, the code wins and this file needs fixing. Where the code cannot answer a question, the text says **Unclear from code**.

The three documents it builds on are still the reference for intent and for frozen contracts:

- `docs/CONTRACTS.md`: the frozen Wave 0 contract (check interface, tables, REST routes, hooks, lane ownership).
- `docs/phase-1-plan.md`: the product plan and its locked decisions.
- `docs/report-snapshot.md`: the report snapshot format, with `inc/Reports/SnapshotSchema.php` as the source of truth.

Paths are relative to the plugin folder unless they are absolute.

---

## 1. What the app is

AMC Copilot is a WordPress plugin that puts every recurring AMC maintenance check for one site on one wp-admin screen, sends email alerts for the events that cannot wait for the next maintenance cycle, and generates editable Website Maintenance Reports from the collected data. Each check is a small read-only class that inspects the site and returns a status, a summary, structured data and zero or more findings. Everything else (scheduling, storage, alerts, the console and reports) reads the stored results, so the console, the emails and the reports never run a check themselves and always agree with each other.

**Who uses it**

- **Engineers** hold the per-user capability `amc_manage`. They get the React console (AMC Copilot menu), the Dashboard widget, the block editor for reports, and can generate, edit and publish reports. Access is granted individually, not by role, so an engineer keeps their normal role. See `inc/Core/Capabilities.php`.
- **Client administrators** hold `amc_view_reports`, which is added to the `administrator` role on activation. They see only published reports, under the "Maintenance Reports" menu. They cannot open the console or drafts. See `inc/Reports/ClientView.php` and `inc/Reports/ReportPostType.php::map_caps()`.

**Phase 1 scope (what is in the code)**

- 32 checks in 11 groups (the plan's text says "31" but its own catalogue and the code both list 32; see section 4).
- 13 console screens: Overview, Updates, Vulnerabilities, Security, Errors, Smoke checks, Performance, Inventory, Runs, Changes, Reports, Settings and Setup (the setup wizard is standalone, with no navigation).
- Email: immediate alerts, a daily digest at 09:00 site time, and report emails (draft ready, published).
- Reports: monthly, quarterly, annual and custom periods; 18 section blocks plus a custom section block; the browser prints them to PDF.
- A CVE provider interface with a `NullProvider` default. rtCamp's real provider is not in this repository.
- Abilities API registration of every check (read-only).
- Out of scope in Phase 1 (per the plan): applying updates or any other change to the site.

### Known gaps and documentation drift

These were found while writing this file. Each one is a fact about the code today, and each is worth fixing or deciding on.

1. **Check count.** The plan says "31 checks"; its catalogue lists 32, and so does the code. Use 32.
2. **Stale console notes.** `docs/console.md` says Smoke and the Performance trend are "stub" data from B2. The controllers (`SmokeController`, `PerformanceController`) return live data, and no `X-AMC-Stub` header or 501 response remains in `inc/Rest`. The same file says `src/js/console/console.scss` is under 2 KB; it is about 12 KB.
3. **Smoke URL saving.** The console adds and edits extra smoke URLs through `POST /amc/v1/settings` (`src/js/console/api/index.js::saveSmokeUrl`). The dedicated `POST /amc/v1/smoke/urls` route exists and works, but the console does not call it.
4. **`changelog_url` is never produced.** `CheckData.js` gives an "Open changelog" action to rows that have a `changelog_url`. No PHP code writes that key; the plugin update rows carry `changelog_excerpt` only.
5. **Snapshots are written on every run.** `PluginInventory`, `ThemeInventory` and `Roles` insert an `amc_snapshots` row on every run, with no change detection. The inventory checks run weekly, so their snapshots are effectively weekly, but `users.roles` runs daily, so the `roles` kind grows daily. Only `AdminSnapshot` writes on change.
6. **`amc_version` is written only on activation.** `PluginSetup::activate()` is the only writer. WordPress does not run the activation hook on an update, so `amc_version` can be stale after an upgrade.
7. **Uninstall and the throttle option.** The throttle option `amc_notify_sent_times` is not in the explicit delete list in `uninstall.php`. It is cleared by the `amc_deactivate` listener `Throttle::reset()`, which is run during deactivation. `uninstall.php` also calls `do_action( 'amc_deactivate' )`, but the plugin file is not loaded in the uninstall context, so no listeners are attached there.
8. **Engineer lookup is capped at 200.** `Capabilities::engineers()` uses `'number' => 200`. The recipient list, the uninstall capability removal and the setup suggestions all use it, so a site with more than 200 engineers would lose or miss some of them.
9. **Checksum alerts are broader than the plan.** `AlertRules` routes every `security.checksums` finding to immediate, including the MEDIUM "missing file" findings. The plan says immediate alerts are for a *modified* core file.
10. **Carry-forward is not implemented on the server.** The custom section block saves a `carryForward` attribute (`src/blocks/custom-section/block.json`, declared again in `inc/Reports/Blocks/CustomSectionBlock.php`), but nothing acts on it: `Reports\Generator` builds a new report from the section template and never reads the previous report's blocks. So ticking "carry forward" currently has no effect; a custom section has to be added again in the next report. This is a gap to fix, not a documentation question.
11. **Multisite super admins.** The plan says super admins get `amc_manage` across the network by default. No code does this; `is_super_admin` is not referenced.
12. **Checks inside a REST request.** `CONTRACTS.md` says checks never run inside a REST request. `GET /amc/v1/setup` runs `hosting.platform` in-process when no stored run exists (`inc/Admin/SetupDefaults.php::detect_platform()`). The result is read-only and not stored.
13. **Empty placeholder.** `src/js/admin.js` is 239 bytes of comments and no code. `Assets::register_admin_assets()` registers it on every admin page without enqueuing it.
14. **Uninstall on multisite.** `uninstall.php` deletes options for the site it runs on. It does not loop over subsites. WordPress runs `uninstall.php` once, in the context of the site it is triggered from (the main site for a network uninstall); it does not loop over subsites. So on a network, `amc_*` options and reports on the other subsites survive uninstall. The tables use `$wpdb->base_prefix` and are shared, so they are dropped for every site.

---

## 2. Architecture at a glance

### Boot sequence

1. **`rtcamp-amc-copilot.php`** loads. It defines `AMC_COPILOT_FILE`, `AMC_COPILOT_VERSION` (`0.1.0`), `AMC_COPILOT_PATH` and `AMC_COPILOT_URL`. It checks `MIN_PHP` (8.1) and `MIN_WP` (6.9). If either fails it shows an admin notice and stops. The `Requires PHP`, `Requires at least` and `Tested up to` headers match these constants.
2. It requires **`inc/Autoloader.php`**. `Autoloader::autoload()` requires `AMC_COPILOT_PATH . 'vendor/autoload.php'`. If the file is missing, it shows an admin notice and the plugin does not load.
3. Composer's autoload (`composer.json`) maps `AMC\` to `inc/` (PSR-4), `AMC\Tests\` to `tests/php/`, and loads `inc/Compat/php82-polyfills.php` as a `files` entry.
4. It calls **`Main::get_instance()`**. `Main` uses the framework's `Singleton` and `Loader` traits from `rtcamp/wp-primitives`, and its constructor calls `$this->load( self::CLASSES )`. The framework is in the gitignored `vendor/` (`vendor/rtcamp/wp-primitives/inc/Contracts/Traits/Loader.php`). `load()` instantiates each class once (duplicates are skipped), calls `register_hooks()` on every `Registrable` unless it is a `ConditionallyRegistrable` whose `can_register()` returns false, and keeps every `Shareable` instance in a container so `Main::get_instance()->get_shared( Class::class )` can return it. `AbstractModule::register_hooks()` simply calls `load( $this->get_classes() )`, so modules nest the same way.

### Modules (`inc/Main.php::CLASSES`, in load order)

`inc/Main.php` is frozen. Each domain has one module that its owning lane edits.

| Entry | What it does |
| --- | --- |
| `Core\Assets` | Extends the framework's `AssetLoader` with the `rtcamp-amc-copilot-` handle prefix. Registers the admin script and style and the editor assets (only if built). In `local` environments with HMR on, enqueues the BrowserSync client. |
| `Core\PluginSetup` | Activation, deactivation and the text domain (section 15). |
| `Core\Components`, `Core\Templates`, `Core\Encryption`, `Core\Logger` | Framework services. Encryption protects the loopback password and needs `AMC_COPILOT_ENCRYPTION_KEY`. The logger is silent unless `WP_DEBUG`. |
| `Core\CoreModule` | Registers the `amc_settings` option (`Core\Settings`) and hooks `Installer::maybe_upgrade()` on `admin_init`. |
| `Checks\ChecksModule` | Loads three providers: `CoreChecks` (1 check), `LaneB1Checks` (20 checks) and `LaneB2Checks` (11 checks, plus the `FixtureProvider` hook). Each provider adds its checks to the `amc_register_checks` filter. |
| `Runner\RunnerModule` | Loads `Scheduler`: the 5-minute tick and the queue drain (section 5). |
| `Runner\Cli\CliModule` | Registers the `wp amc` commands when WP-CLI is running (section 5.7). |
| `Rest\RestModule` | Registers 17 `amc/v1` controllers. The route table is in section 8. |
| `Capture\CaptureModule` | `ErrorCapture`, `DeprecationCapture`, `QuerySampler` and `ChangeLog` (section 6). |
| `Storage\StorageModule` | `Retention`, the daily cleanup job (section 3). |
| `Notify\NotifyModule` | `Alerts` (immediate email) and `Digest` (the daily email) (section 7). |
| `Abilities\AbilitiesModule` | `AbilitiesRegistrar`, which registers each check and two report actions as WordPress abilities (section 12). |
| `Admin\AdminModule` | `ConsolePage`, `ConsoleAssets`, `DashboardWidget`, `SetupRedirect` and `SetupNotice` (section 9). |
| `Reports\ReportsModule` | `ReportPostType`, `Generator`, `ReportSchedules`, `ReportPublishing`, `ReportEmails` and `ClientView` (section 10). |
| `Reports\Blocks\SectionBlocksModule` | The 18 section block classes and the custom section block (section 10.6). |

### Data flow

```
  Triggers                                Engine                                     Storage (base_prefix tables)
  --------                                ------                                     ----------------------------
  WP-Cron amc_scheduler_tick (5 min) -->  Scheduler::tick()
                                          - fail_stale (running > 10 min -> error)
  WP-Cron amc_process_queue ----------->  - process_queue(): queued rows, oldest first
                                          - due checks: last run + interval
                                                    |
  WP-CLI: wp amc check run ------------>            |
                                                    v
  Console "Run now": POST /checks/run --> Runner::enqueue()  -- writes amc_runs (queued) --> amc_runs
                                          then Scheduler::kick() (single event)
                                                    |
                                                    v
                                          Runner::run() / run_queued()
                                          KillSwitch -> Lock "check:{blog}:{id}" (amc_locks)
                                                    |
                                                    v
                                          Check::run(Context)   read only, never writes
                                                    |  Result: status, summary, data, findings
                                                    v
                                          amc_runs.finish()
                                          FindingsRepository: insert | touch | resolve   --> amc_findings
                                                    |
                              +---------------------+----------------------+
                              v                                            v
                  do_action amc_findings_processed            do_action amc_run_completed
                              |                                            |
             Alerts (immediate) -> Mailer -> wp_mail          DashboardWidget: flush transient
             Digest (cron 09:00) -> Mailer -> wp_mail

  REST amc/v1 reads amc_runs, amc_findings, amc_events, amc_changes, amc_queries, amc_settings
          -> React console (13 screens). The console never calls a check directly.
```

```
  Generator                                        Storage and outputs
  ---------                                        -------------------
  REST POST /reports/generate                      amc_report draft (status pending)
  amc_report_monthly | quarterly | annual   -->    + template: 18 section blocks, meta
  wp amc report generate (builds in-process)            |
                                                        v
  cron amc_report_build (queue_build)  -->  SnapshotBuilder::build()
                                              - refresh(): Runner::run() x10 FRESH_CHECKS
                                              - amc_changes, amc_events, amc_findings (period)
                                              - amc_runs (latest results), SiteFacts (live reads)
                                              - SavedFields::for_new_report()
                                                        |
                                                        v  JSON snapshot
                                          post meta _amc_snapshot, _amc_build_status = ready
                                                        |   first time only: amc_report_draft_ready -> email
                                                        v
  Block editor: section blocks read _amc_snapshot through the core REST entity
  Publish (transition to publish): SavedFields::write_back -> amc_report_published -> client emails once
  Client view (wp-admin page, read-only, browser print to PDF): PHP renders the same section blocks
```

### Request paths

| Path | Runs checks? | Writes | Notes |
| --- | --- | --- | --- |
| Front-end request | No | Capture buffer, flushed once in `shutdown` (`Capture\Buffer::flush`) | Nothing else. No assets, no option reads beyond the kill switch on flush. |
| wp-admin (console, Dashboard, client view) | No | Via REST or CLI only | Schema self-heal on `admin_init` (`CoreModule`). |
| REST `amc/v1` | No, except `GET /setup` (section 1, gap 12) | Settings, findings status, runs queued, access changes, saved fields | Every route has a capability `permission_callback`. |
| WP-Cron (`amc_*` events) | Yes | Runs, findings, events, snapshots, emails, reports | Each check is locked per site. |
| WP-CLI | Yes, in-process | Same as cron | `check run` uses trigger `cli`. |

---

## 3. Database

Tables are created with `dbDelta()` in `inc/Core/Installer.php::install()` (line 47), using the statements in `Installer::schema()` (line 92). They use `$wpdb->base_prefix`, so there is one set per network. Every table except `amc_locks` has a `blog_id` column (default 1). `Tables::name()` in `inc/Storage/Tables.php` builds names. All `DATETIME` values are UTC, `Y-m-d H:i:s`. JSON is stored in `longtext` and decoded by `AbstractRepository::hydrate()`.

The table list is in `Tables::ALL`, in creation order. Retention is in `inc/Storage/Retention.php`, and it runs as the `amc_retention` cron event (section 5.4).

### 3.1 `amc_runs`: one row per check execution (also the "Run now" queue)

**Purpose.** Records every check execution. A row with status `queued` is also the "Run now" queue entry. Its `data` holds the `Result::$data` or the exception details. Readers use the latest result-status row per check to show each screen's data.

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | bigint unsigned, AUTO_INCREMENT, PK | Run id. Shown as "run" in the console. |
| `blog_id` | bigint unsigned, default 1 | Site the check ran on. |
| `check_id` | varchar(64) | Check id, e.g. `updates.plugins`. |
| `trigger_type` | varchar(16), default `cron` | `cron`, `manual` or `cli`. Exposed in REST as `trigger`. A queued run is always written as `manual`. |
| `status` | varchar(16), default `queued` | `queued`, `running`, `pass`, `warn`, `fail`, `info` or `error`. |
| `summary` | varchar(255) | One-line summary (`Result::$summary`). |
| `data` | longtext NULL | JSON of `Result::$data`, or exception details for `error`. |
| `user_id` | bigint unsigned, default 0 | Who queued or ran it. 0 for cron. |
| `created_at` | datetime | When queued or started. |
| `started_at` | datetime NULL | Set when claimed or started. |
| `finished_at` | datetime NULL | Set on `finish()`. |
| `duration_ms` | int unsigned, default 0 | Wall time of `Check::run()`. |

**Indexes.** `KEY blog_check_created (blog_id, check_id, created_at)`, `KEY blog_status_created (blog_id, status, created_at)`, `KEY created_at (created_at)`.

**Written by.** `Runner\Runner` (`create_queued` from `enqueue()`, `create_running` from `run()`, `claim` in `run_queued()`, `finish` after every run); `Runner\Scheduler::tick()` through `fail_stale()` (sets `error` on `running` rows older than 600 s); `RunsRepository::finish()` for an unknown check in the queue.

**Read by.** `Runner\Scheduler` (`queued()`, `last_run_times()`, `fail_stale()`); `Runner\Runner` (`latest()` for the previous data, `pending_id()` to avoid duplicate queues, `find()`); `Rest\OverviewController`, `ChecksController`, `RunsController`, `UpdatesController`, `InventoryController`, `SecurityController`, `PerformanceController`, `SmokeController`, `VulnerabilitiesController` (through `AbstractController::latest_results()`); `Admin\SetupDefaults` (latest hosting and SSL data); `Reports\SnapshotBuilder` (`latest()` and `query()`); `Abilities\CheckAbility`; `Rest\AbstractController::format_run()`.

**Retention.** `created_at` older than `retention_months` (default 13).

### 3.2 `amc_findings`: one row per issue occurrence

**Purpose.** An issue that an engineer must act on, keyed by `(check_id, dedupe_key)`. A finding that resolves and comes back is a **new row** (see section 5.2).

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | bigint unsigned, PK | Finding id. |
| `blog_id` | bigint unsigned, default 1 | |
| `check_id` | varchar(64) | Check that raised it. |
| `dedupe_key` | varchar(191) | Stable identity within a check (section 5.2). |
| `severity` | varchar(16), default `info` | `info`, `low`, `medium`, `high` or `critical`. |
| `title` | varchar(255) | Human title, shown in the console and in emails. |
| `data` | longtext NULL | JSON, from `Finding::$data`. |
| `status` | varchar(16), default `open` | `open`, `accepted` (accepted risk), `muted` or `resolved`. |
| `note` | text NULL | Note for an accepted risk, or any status change that carries one. |
| `run_id` | bigint unsigned, default 0 | The last run that saw this finding. The console links to it. |
| `first_seen`, `last_seen` | datetime | First appearance and last run that saw it. |
| `resolved_at` | datetime NULL | Set when resolved. Also cleared when a finding is reopened. |
| `alerted_at` | datetime NULL | Set when an immediate alert or a digest included this finding. |

**Indexes.** `KEY blog_check_key (blog_id, check_id, dedupe_key)`, `KEY blog_status_severity (blog_id, status, severity)`, `KEY run_id (run_id)`, `KEY resolved_at (resolved_at)`.

**Written by.** `Runner\Runner::process_findings()` (insert, touch and resolve); `FindingsRepository::set_status()`, from `Rest\FindingsController::set_status()`, `Rest\ErrorsController::set_status()` and `Rest\VulnerabilitiesController::accept()`; `FindingsRepository::mark_alerted()`, from `Notify\Alerts` and `Notify\Digest`.

**Read by.** `Runner\Runner` (`unresolved_for_check()`); `Rest\OverviewController` (`open_counts_by_severity()`); `Rest\FindingsController`; `Rest\RunsController::get_run()` (findings of one run); `Rest\SecurityController` (open findings of its four checks); `Rest\VulnerabilitiesController`; `Rest\ErrorsController` (joined by `dedupe_key` = event signature); `Reports\SnapshotBuilder::vulnerabilities()` (`vuln.match`, overlapping the period); `Notify\Alerts` and `Notify\Digest`; `Abilities\CheckAbility`.

**Retention.** `resolved_at` older than `retention_months`. Open findings are never deleted (`Storage\Retention::targets()`).

### 3.3 `amc_events`: captured error or deprecation, aggregated per signature per hour

**Purpose.** PHP errors, warnings, notices, deprecations and `doing_it_wrong` calls. Each distinct signature is one row per UTC hour, with a count. A noisy warning on every page load still writes one row per hour.

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `blog_id` | bigint unsigned, default 1 | |
| `type` | varchar(20) | `fatal`, `warning`, `notice`, `deprecation` or `doing_it_wrong`. |
| `signature` | char(32) | `md5( type \| relative file \| line \| normalised message )` (`inc/Capture/Signature.php::of()`). Numbers become `N`, paths become `<path>`, hex becomes `N`. |
| `message` | text NULL | The message (first sample, capped at 2,000 characters on write). |
| `source` | varchar(191) | `core`, `plugin:<slug>`, `theme:<slug>`, `mu-plugin:<file>` or `unknown` (`Signature::source()`). |
| `file` | varchar(255) | File relative to the WordPress root, or to the parent of `wp-content`. |
| `line` | int unsigned, default 0 | |
| `hour` | datetime | UTC hour bucket (`Y-m-d H:00:00`). |
| `count` | int unsigned, default 0 | Occurrences in that hour. |
| `last_seen` | datetime | Last occurrence in that hour. |

**Indexes.** `UNIQUE KEY blog_signature_hour (blog_id, signature, hour)`, `KEY blog_type_hour (blog_id, type, hour)`, `KEY hour (hour)`.

**Written by.** `Capture\Buffer::write_events()` through `EventsRepository::upsert_many()`, which runs `INSERT ... ON DUPLICATE KEY UPDATE count = count + VALUES(count)`. Flushed once per request in `shutdown`. A fatal error is drained immediately (`ErrorCapture::record_fatal()`).

**Read by.** `Checks\Errors\AbstractEventsCheck` (through `EventsRepository::grouped()`, for `errors.fatal`, `errors.warnings` and `errors.deprecations`); `Rest\ErrorsController` (`grouped()`, `daily_counts()` for the 30-day sparkline); `Reports\SnapshotBuilder` (`grouped()` and `counts_by_type()` for the period).

**Retention.** `hour` older than `retention_months`.

### 3.4 `amc_queries`: sampled slow query, aggregated per signature per hour

**Purpose.** Queries over 100 ms, sampled on 1 request in 100 (section 6). Sampling keeps the production cost low.

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `blog_id` | bigint unsigned, default 1 | |
| `signature` | char(32) | `md5( normalised SQL \| caller )`. The SQL normaliser replaces string and numeric literals with `?` and collapses `IN (?, ?)` lists. |
| `sql_sample` | text NULL | A normalised sample of the SQL (2,000 characters at most on write). |
| `caller` | varchar(255), default `''` | The last three non-`wpdb` and non-filter frames of the call stack, joined by commas. |
| `hour` | datetime | UTC hour bucket. |
| `count` | int unsigned, default 0 | Sampled occurrences in that hour. |
| `max_ms` | float, default 0 | Slowest sampled execution. |
| `avg_ms` | float, default 0 | Weighted average. |

**Indexes.** `UNIQUE KEY blog_signature_hour (blog_id, signature, hour)`, `KEY hour (hour)`.

**Written by.** `Capture\Buffer::write_queries()` through `QueriesRepository::upsert_many()`, with a weighted average for `avg_ms` and `GREATEST` for `max_ms`.

**Read by.** `Checks\Performance\SlowQueries` (through `QueriesRepository::grouped()`, last 24 hours); `Rest\PerformanceController` (`grouped()`, the slow-query table, over the chosen number of days).

**Retention.** `hour` older than 90 days (`QueriesRepository::RETENTION_DAYS`), not `retention_months`.

### 3.5 `amc_changes`: a code or user change

**Purpose.** The change log: plugin and theme installs, updates, activations, deactivations and deletions; core updates; user creation, deletion and role changes; engineer access grants and revocations; and code changes found by the deploy diff.

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `blog_id` | bigint unsigned, default 1 | |
| `type` | varchar(16) | `plugin`, `theme`, `core` or `user`. |
| `action` | varchar(20) | `install`, `update`, `activate`, `deactivate`, `delete`, `role`, `access_grant` or `access_revoke`. For users, `install` means registered and `delete` means removed. |
| `item` | varchar(191), default `''` | Plugin slug (the directory, or the file name for single-file plugins), theme stylesheet, `wordpress` for core, or user login. |
| `item_name` | varchar(255), default `''` | Display name, kept so a removed plugin still has a name in reports. |
| `old_version`, `new_version` | varchar(64), default `''` | Versions, or the role list for users. |
| `source` | varchar(16), default `admin` | `admin`, `cli`, `deploy` or `auto` (see section 6). |
| `user_id` | bigint unsigned, default 0 | Acting user. 0 for system and deploy rows. |
| `created_at` | datetime | UTC. |

**Indexes.** `KEY blog_created (blog_id, created_at)`, `KEY blog_type_created (blog_id, type, created_at)`, `KEY created_at (created_at)`.

**Written by.** `Capture\ChangeLog::record()` (all upgrader, activation, deletion, theme, core and user hooks); `Core\Capabilities::log_change()` (access grant and revoke); `Capture\DeployDiff::write()` (source `deploy`).

**Read by.** `Rest\ChangesController::list_changes()`; `Reports\SnapshotBuilder` (`period_changes()`, `all_core_updates()`, and the user rows for user management); `Capture\DeployDiff::write()` (its own duplicate check); `Checks\Users\UserChanges` (`type = user`).

**Retention.** `created_at` older than `retention_months`.

### 3.6 `amc_snapshots`: inventory baseline

**Purpose.** A stored copy of inventory data, used as the baseline for diffing on the next run. A row is an inventory snapshot. It is not the same as a report snapshot (section 16).

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `blog_id` | bigint unsigned, default 1 | |
| `kind` | varchar(32) | `plugins`, `themes`, `roles` or `admins`. |
| `data` | longtext NULL | JSON of the snapshot. |
| `taken_at` | datetime | UTC. |

**Indexes.** `KEY blog_kind_taken (blog_id, kind, taken_at)`, `KEY taken_at (taken_at)`.

**Written by.** `Checks\Inventory\PluginInventory` (`plugins`, every run); `Checks\Inventory\ThemeInventory` (`themes`, every run); `Checks\Users\Roles` (`roles`, every run); `Checks\Security\AdminSnapshot` (`admins`, on the first run and whenever the list changes).

**Read by.** `PluginInventory` (previous `plugins` snapshot, for the deploy diff); `AdminSnapshot` (latest `admins`); `Roles` (latest `roles`). Nothing reads `themes`; **it is written only** (`SnapshotsRepository::latest()` is never called with `themes`).

**Retention.** `taken_at` older than `retention_months`.

### 3.7 `amc_locks`: running job lock

**Purpose.** Stops two processes running the same job at once. Lock rows are deleted on release, and an expired row is replaced the next time the same name is acquired.

| Column | Type | Meaning |
| --- | --- | --- |
| `name` | varchar(191), PK | Lock name, including the blog id for check locks (section 5.5). |
| `owner` | varchar(64) | UUID of the holder. |
| `expires_at` | datetime | After this, the lock counts as free. |

**Indexes.** `KEY expires_at (expires_at)`.

**Written by and read by.** `Storage\LocksRepository::acquire()` and `release()`, through `Runner\Lock`. `LocksRepository::is_locked()` exists but **nothing calls it**.

**Retention.** None. Released rows are deleted. Expired rows are only removed when the same name is acquired again, so a crashed holder leaves a row behind until then.

### 3.8 Options (all with autoload off)

Every option the plugin writes is non-autoloaded (checked by `grep` for `update_option` and `add_option`). Reads use `get_option`, which is cheap on admin and cron requests. Settings are never read on a front-end request.

| Option | Written by | Read by | Contents |
| --- | --- | --- | --- |
| `amc_settings` | `Core\Installer::install()` (`add_option`, defaults); `Core\Settings::update()` (from `POST /settings`, `POST /smoke/urls`, the setup wizard) | `Core\Settings::get()`, and everything that reads a setting | The full settings array. Keys in section 3.8.1. |
| `amc_schema_version` | `Installer::install()` | `Installer::maybe_upgrade()` | The schema version string, `'1'` (`Installer::SCHEMA_VERSION`). |
| `amc_version` | `Core\PluginSetup::activate()` only | Nothing | The plugin version at last activation (gap 6). |
| `amc_saved_fields` | `Reports\SavedFields::update()` (from `POST /saved-fields` and on publish via `write_back()`) | `SavedFields::stored()`, `all()`, `for_new_report()` | `{ section: { field: string } }` for the saved report fields. |
| `amc_delete_reports_on_uninstall` | `Rest\SettingsController::update_settings()` (set to `1`, or deleted) | `SettingsController::get_settings()`, `uninstall.php` | Whether uninstall deletes `amc_report` posts. |
| `amc_setup_completed` | `Admin\SetupState::complete()` | `SetupState::completed_at()` | `{ completed_at (UTC), user_id }`. |
| `amc_notify_sent_times` | `Notify\Throttle::acquire()` | `Throttle::recent()` | A list of Unix times of recent immediate emails (sliding one-hour window). Deleted by `Throttle::reset()`. |

#### 3.8.1 `amc_settings` keys

Defaults come from `Core\Settings::defaults()` (line 109). The sanitiser is `Core\Settings::sanitize()` (line 364), which drops unknown keys and falls back to defaults for invalid values.

| Key | Type | Default | Meaning and source |
| --- | --- | --- | --- |
| `site_type` | `content` or `ecommerce` | `content` | Which latest item the smoke check adds (post or product). |
| `environment` | `''` or `production`, `staging`, `development`, `local` | `''` | Environment label on reports. `''` uses `wp_get_environment_type()`. |
| `contract_start` | `Y-m-d` or `''` | `''` | Quarters and years follow it. Empty means the calendar. |
| `recipients` | string list | `[]` | Alert and draft recipients. Empty means every `amc_manage` user. |
| `digest_time` | `HH:MM` | `09:00` | Digest time, site timezone. |
| `immediate_alerts_per_hour` | int 0 to 100 | `10` | Cap on immediate emails per hour. Overflow goes to the digest. |
| `notify_client_on_publish` | bool | `true` | Email client administrators when a report is published. |
| `report_publish_recipients` | email list, at most 25 | `[]` | Extra recipients for the published email. |
| `smoke_urls` | `[ { url, required_text } ]` | `[]` | Extra smoke URLs (the default URLs are always added by the check). |
| `loopback_auth` | `{ username, password }` | empty | Basic-auth credentials for URLs on this host. The password is encrypted, and only stored when `AMC_COPILOT_ENCRYPTION_KEY` is defined. |
| `kill_switch` | bool | `false` | The `AMC_COPILOT_DISABLED` constant overrides it (section 5.6). |
| `checks` | `{ check_id: { enabled, interval } }` | `{}` | Per-check overrides. `interval` is `''`, `hourly`, `twicedaily`, `daily` or `weekly`. |
| `retention_months` | int 1 to 60 | `13` | Retention for most tables. |
| `disclaimers` | `{ backups, hosting, domains, accessibility }` (HTML) | `''` each | Per-site disclaimer text for section blocks. Empty means the block's built-in text. |

`GET /settings` adds `loopback_auth.has_password`, `loopback_auth.encryption_available`, `kill_switch_locked` and `delete_reports_on_uninstall` (`Settings::for_rest()`).

### 3.9 Transients

| Transient | Set by | Lifetime | Notes |
| --- | --- | --- | --- |
| `amc_dashboard_overview_{blog_id}` | `Admin\DashboardWidget::overview()` | 300 s | The cached overview for the Dashboard widget. Deleted on `amc_run_completed` (`DashboardWidget::flush()`). It is not deleted on uninstall, but it expires on its own. |
| `amc_activation_redirect` | `Admin\SetupRedirect::queue()` (on `amc_activate`) | 60 s | The activating user's id. The next admin page load sends that user to the setup wizard, once. Deleted on uninstall. |

Core transients the plugin **reads but never sets**: `update_core`, `update_plugins` and `update_themes`. WordPress fills them from api.wordpress.org. They are read by the Overview counts (`Rest/OverviewController::counts()`) and by the Updates checks.

### 3.10 Post type and post meta

**Post type `amc_report`** (`inc/Reports/ReportPostType.php`, `SLUG` line 53). Settings in `get_custom_options()` (line 157):

- Not public, not publicly queryable, excluded from search, no archive and no rewrite. `show_ui` and `show_in_menu` are true.
- `show_in_rest` is true, with `rest_base` `amc-reports` and controller `Reports\ReportRestController`.
- `capability_type` `['amc_report', 'amc_reports']`, `map_meta_cap` true, and the `read` capability is `read_amc_reports`.
- Supports title, editor, revisions, author and custom-fields.
- `template` is built by `ReportPostType::template()` (line 190): one locked block per entry in `SnapshotSchema::sections()`, in report order. `template_lock` is false, so custom sections can go anywhere.

**Meta** (`ReportPostType::META`, line 58; registered in `after_register()` with `show_in_rest` true and `auth_callback` requiring `amc_manage`):

| Key | Type | Meaning |
| --- | --- | --- |
| `_amc_period_type` | string | `month`, `quarter`, `year` or `custom`. |
| `_amc_period_start`, `_amc_period_end` | string | `Y-m-d`, site time. |
| `_amc_environment` | string | Environment at generation. |
| `_amc_report_id` | string | Report id, e.g. `AMC-NWT-2026-10` (section 10.2). |
| `_amc_snapshot` | string (JSON) | The snapshot (section 10.5). |
| `_amc_snapshot_taken_at` | string | UTC time of the last snapshot. Empty until the first build. |
| `_amc_build_status` | string | `pending`, `ready` or `failed` (`BUILD_PENDING`, `BUILD_READY`, `BUILD_FAILED`). |

Two more meta keys are not registered for REST:

- `_amc_published_email_sent` (`Reports\ReportEmails::SENT_META`): set once with `add_post_meta( …, true )`, so a report is announced to clients once.

Each section block also stores its own attributes in the post content (`lock`, `hidden`, `overrides`, `engineer`; section 10.6).

### 3.11 User meta

| Key | Written by | Read by | Meaning |
| --- | --- | --- | --- |
| `amc_setup_notice_dismissed` | `Admin\SetupState::dismiss()` (`POST /setup/dismiss`) | `SetupState::is_dismissed()`, `SetupNotice` | The user closed the setup notice. Deleted for all users on uninstall. |
| `{prefix}capabilities` (core) | WordPress, through `WP_User::add_cap()` | `Capabilities::engineers()` (a `LIKE` on the serialised value, limit 200) | Holds `amc_manage` for engineers. |

### 3.12 Capabilities

| Capability | Kind | Granted to | Allows | Defined in |
| --- | --- | --- | --- | --- |
| `amc_manage` | User capability (never a role) | Engineers, individually, via `Capabilities::grant()` | Console, runs, settings, reports (edit and publish), Dashboard widget, abilities | `Core\Capabilities::MANAGE` |
| `amc_view_reports` | Role capability | `administrator`, on activation | Read published reports only | `Core\Capabilities::VIEW_REPORTS` |
| `read_amc_reports` | Derived, not stored | Anyone with `amc_manage` or `amc_view_reports` (the `user_has_cap` filter) | The post type's `read` capability. Core's `read` is not used. | `ReportPostType::map_caps()` |
| `edit_amc_reports`, `edit_others_amc_reports`, `edit_private_amc_reports`, `edit_published_amc_reports`, `publish_amc_reports`, `read_private_amc_reports`, `delete_amc_reports`, `delete_others_amc_reports`, `delete_private_amc_reports`, `delete_published_amc_reports` | Derived | Holders of `amc_manage` | Editing and publishing reports | `ReportPostType::map_caps()` (line 237) |
| `manage_options` | Core | Administrators | Needed on top of `amc_manage` for settings, access and setup | Core |

### 3.13 Core options and data the plugin reads

Read only, never written, unless noted:

- `active_plugins`, `active_sitewide_plugins`, `stylesheet`, `template`, `admin_email`, `date_format`, `timezone_string` and `gmt_offset` (through `wp_timezone()`).
- `get_plugins()`, `wp_get_themes()`, `get_mu_plugins()`, `_get_dropins()`, `get_core_checksums()`, `_get_cron_array()`, `count_users()`, `get_users()`.
- Site Health tests (`WP_Site_Health`, through `Support\CoreSiteHealth`).
- `$wpdb` table sizes from `information_schema`, or `SHOW TABLE STATUS` when that is restricted.

---

## 4. Checks

### 4.1 The contract in brief

Every check implements `AMC\Checks\Check` (`inc/Checks/Check.php`): `id()`, `group()`, `label()`, `schedule()` and `run( Context $ctx ): Result`. The value objects are:

- `Result` (`Result.php`): `status` (`Status`: `pass`, `warn`, `fail`, `info`), `summary`, `data`, `findings`, `complete` (default true). Built with `Result::pass|warn|fail|info()`. `partial()` returns an incomplete copy, so the runner does not resolve findings that belong to other batches.
- `Finding` (`Finding.php`): `dedupe_key` (1 to 191 characters), `severity` (`Severity`), `title`, `data`.
- `Context` (`Context.php`): `blog_id`, `trigger` (`cron`, `manual` or `cli`), `run_id`, `now` (UTC), `previous_data` (this check's previous run data, or null).
- Enums: `Group` (`updates`, `vulnerabilities`, `errors`, `smoke`, `security`, `performance`, `health`, `inventory`, `users`, `hosting`, `detection`); `Schedule` (`hourly`, `twicedaily`, `daily`, `weekly`, `event`, with seconds 3600, 43200, 86400, 604800 and 0); `Severity` (`info`, `low`, `medium`, `high`, `critical`).
- Rules: read only, no `update_option()`, no transients, no files, no remote POSTs; never call `get_current_blog_id()` or `switch_to_blog()`; finish within about 20 seconds (batch with a cursor in `data` and return `partial()` on every batch except the last); expected problems return `INFO` or `WARN`, not exceptions.

### 4.2 Registration

`Registry::all()` (`inc/Checks/Registry.php`, line 53) applies the `amc_register_checks` filter, rejects duplicates and bad ids (`_doing_it_wrong`), and sorts by id. Each provider class extends `AbstractCheckProvider`, which adds its checks through that filter.

- `Providers\CoreChecks`: `updates.core`.
- `Providers\LaneB1Checks`: 20 checks (updates, inventory, detection, hosting, users, health, performance).
- `Providers\LaneB2Checks`: 11 checks (vulnerabilities, errors, smoke, security, performance) and `FixtureProvider::register_hooks()` (section 11).

`Registry::effective_schedule()` applies the per-check interval override from `amc_settings` before the default.

### 4.3 The catalogue: identity and inputs

All 32 checks. "Schedule" is the default, before overrides. "Event" means the check is never scheduled and runs only on demand.

| Check id | Class (`inc/Checks/…`) | Group | Schedule | Reads |
| --- | --- | --- | --- | --- |
| `updates.core` | `Updates/CoreUpdates.php` | updates | twicedaily | `update_core` site transient (no remote call). |
| `updates.plugins` | `Updates/PluginUpdates.php` | updates | twicedaily | `update_plugins` transient, `get_plugins()`, `active_plugins`, and `plugins_api()` for changelog excerpts (remote, capped per run). |
| `updates.themes` | `Updates/ThemeUpdates.php` | updates | twicedaily | `update_themes` transient, installed themes, `themes_api()` for changelog excerpts. |
| `updates.php` | `Updates/PhpUpdates.php` | updates | daily | `phpversion()`, `wp_check_php_version()` (Serve Happy API, cached by WordPress), and each active plugin's `Requires PHP`. |
| `updates.readiness` | `Updates/Readiness.php` | updates | weekly | Each active plugin's "Tested up to" from WordPress.org update data, then the plugin header, then `readme.txt`. |
| `vuln.match` | `Vulnerabilities/VulnMatchCheck.php` | vulnerabilities | twicedaily | The inventory (core, plugins, themes) sent to the provider from `amc_vulnerability_provider` (section 11). |
| `inventory.plugins` | `Inventory/PluginInventory.php` | inventory | weekly | `get_plugins()`, active and network-active lists, `plugins_api( 'plugin_information' )` for each plugin (WordPress.org metadata), and the `plugins` snapshot for the deploy diff. |
| `inventory.themes` | `Inventory/ThemeInventory.php` | inventory | weekly | `wp_get_themes()`, `stylesheet` and `template` options. |
| `inventory.mu_dropins` | `Inventory/MuDropins.php` | inventory | weekly | `get_mu_plugins()`, `_get_dropins()`. |
| `inventory.editor` | `Inventory/Editor.php` | inventory | weekly | Classic Editor plugin state, and `use_block_editor_for_post_type()` for each public post type. |
| `errors.fatal` | `Errors/FatalErrors.php` | errors | hourly | `amc_events` of type `fatal` since the previous run. |
| `errors.warnings` | `Errors/Warnings.php` | errors | hourly | `amc_events` of types `warning` and `notice`. |
| `errors.deprecations` | `Errors/Deprecations.php` | errors | hourly | `amc_events` of types `deprecation` and `doing_it_wrong`. |
| `smoke.urls` | `Smoke/SmokeUrls.php` | smoke | hourly | `wp_remote_get()` to home, login, the latest post or product, and `smoke_urls`. Judged by `Support\SmokeRule`. |
| `security.checksums` | `Security/CoreChecksums.php` | security | daily | `get_core_checksums()` for the installed version and locale, compared with files on disk. `wp-content/` is skipped. |
| `security.config` | `Security/ConfigCheck.php` | security | daily | `DISALLOW_FILE_EDIT`, `WP_DEBUG`, `WP_DEBUG_DISPLAY`, the salts, the `admin` user, XML-RPC, and a request to the uploads folder for a directory listing. Judged by `Support\SecurityConfig`. Secret values are never stored. |
| `security.admins` | `Security/AdminSnapshot.php` | security | daily | Users with the administrator role, compared with the latest `admins` snapshot. |
| `ssl.certificate` | `Security/SslCertificate.php` | security | daily | A TLS connection to the host with `capture_peer_cert` (verification off on purpose), parsed by `Support\CertParse`. |
| `perf.response` | `Performance/ResponseTime.php` | performance | hourly | No requests of its own. Reads the history and daily buckets that the latest `smoke.urls` run stored. |
| `perf.database` | `Performance/DatabaseCheck.php` | performance | daily | Table sizes from `information_schema` (fallback `SHOW TABLE STATUS`), and the autoloaded options size. |
| `perf.cache` | `Performance/CacheCheck.php` | performance | daily | Site Health's persistent object cache and page cache tests (about three loopback requests each), and the page cache detail (reflection, or the home page headers). |
| `perf.slow_queries` | `Performance/SlowQueries.php` | performance | hourly | `amc_queries` for the last 24 hours, grouped by caller. |
| `health.site_health` | `Health/SiteHealth.php` | health | daily | Site Health tests run as core's own scheduled check runs them (`Support\CoreSiteHealth`). The page cache test is left to `perf.cache`. |
| `health.cron` | `Health/CronHealth.php` | health | hourly | `_get_cron_array()`, to find events more than one hour overdue. |
| `users.roles` | `Users/Roles.php` | users | daily | `count_users()`, role capability sets, compared with the latest `roles` snapshot. |
| `users.changes` | `Users/UserChanges.php` | users | event | `amc_changes` rows of type `user`. |
| `hosting.platform` | `Hosting/Platform.php` | hosting | weekly | `Support\HostDetect`: host constants, `$_SERVER`, database version, OS, memory limit, and disk space where readable. |
| `detect.cdn` | `Detection/CdnDetect.php` | detection | weekly | Response headers of the home page (`Support\HeaderDetect`). |
| `detect.forms` | `Detection/FormsDetect.php` | detection | weekly | Active form plugins and add-ons, and form counts where the plugin has an API (`Support\PluginDetect`). |
| `detect.mailer` | `Detection/MailerDetect.php` | detection | weekly | Active mail or SMTP plugins, and callbacks on `phpmailer_init`. |
| `detect.analytics` | `Detection/AnalyticsDetect.php` | detection | weekly | GTM, GA4 and Universal Analytics IDs in the home page HTML (`Support\HtmlDetect`). |
| `detect.accessibility` | `Detection/AccessibilityDetect.php` | detection | weekly | Known accessibility overlay scripts in the home page HTML, and a page whose slug contains "accessibility". |

The count is 5 updates, 1 vulnerability, 4 inventory, 3 errors, 1 smoke, 4 security, 4 performance, 2 health, 2 users, 1 hosting and 5 detection: 32.

### 4.4 The catalogue: stored data, findings, and where each result shows

The `data` shapes are from each class docblock. Only the main keys are listed here. Findings show the severity and the dedupe key. "Alert" is the route from `Notify\AlertRules` (section 7). The last two columns say which screen and which report section show the result. Where a section draws on a live WordPress read or on the change log rather than on this check's data, the table says so.

| Check id | Stored `data` (main keys) | Findings (severity; dedupe key) | Alert route | Console screen | Report section (source) |
| --- | --- | --- | --- | --- | --- |
| `updates.core` | `current_version`, `latest_version`, `update_available`, `update_type` (none, minor, major), `last_checked`, `offers[]` | Major: MEDIUM. Minor (security): HIGH. Key `core:<latest>` | Digest | Updates | None directly. CMS version comes from `amc_changes` and `SiteFacts`. |
| `updates.plugins` | `plugins[]` (name, version, `new_version`, `tested_up_to`, `requires_php`, `changelog_excerpt`, `update_source`), `total`, `pending`, `no_update_source[]`, `cursor` | MEDIUM per plugin. Key `plugin:<file>:<new_version>` | Digest | Updates | None directly. Plugin update lists come from `amc_changes`. |
| `updates.themes` | `themes[]`, `total`, `pending`, `active` | MEDIUM per theme. Key `theme:<stylesheet>:<new_version>` | Digest | Updates | None directly. |
| `updates.php` | `installed`, `recommended`, `is_acceptable`, `blocking_plugins[]` | MEDIUM (version). Key `php:<installed>`. HIGH (plugin needs newer PHP). Key `php-plugin:<file>` | Digest | Updates | Hosting, `php_recommendation`, from `SiteFacts`. |
| `updates.readiness` | `installed`, `next_major`, `plugins[]` (`tested_up_to`, `ready`), `ready`, `not_ready` | LOW per plugin. Key `readiness:<file>:<tested_up_to>` | None | Updates (readiness matrix) | None. |
| `vuln.match` | `provider`, `provider_ready`, `provider_error`, `components`, `matches[]` (`VulnMatch::to_array()` plus `installed_version`), `counts` | The match's severity. Key `{type}:{slug}:{installed}:{reference}`, hashed if longer than 191 | Immediate if HIGH or CRITICAL, otherwise digest | Vulnerabilities | Security: `vulnerabilities` (period findings). |
| `inventory.plugins` | `plugins[]` (`wp_org`, `closed`, `last_updated`, `abandoned`), `total`, `active`, `network_active`, `closed`, `abandoned`, `changes` | MEDIUM per closed or abandoned plugin (over 2 years). Keys `plugin:<file>:closed` and `:abandoned` | Digest | Inventory | Plugins and themes: the `inactive` list is live (`SiteFacts`). Lists come from `amc_changes`. |
| `inventory.themes` | `themes[]`, `total`, `active` | None | None | Inventory | Theme section uses live `SiteFacts::active_theme()`. |
| `inventory.mu_dropins` | `mu_plugins[]`, `dropins[]`, `total` | None | None | Inventory | None. |
| `inventory.editor` | `classic_editor`, `classic_editor_active`, `post_types{}`, `editor` (block, classic or mixed) | None | None | Inventory | CMS `editor_type` is live (`SiteFacts::editor_type()`). |
| `errors.fatal` | `checked_at`, `since`, `events`, `signatures`, `items[]` (`key`, `source`, `file`, `line`, `count`, `message`, `first_seen`, `last_seen`), `truncated` | HIGH per fatal signature. Key is the signature | Immediate for every new finding | Errors | PHP errors: counts come from `amc_events` directly. |
| `errors.warnings` | Same shape | LOW per source. Key `source:<source>` | Digest | Errors | PHP errors: `warning_count` from `amc_events`. |
| `errors.deprecations` | Same shape | LOW per caller. Key `caller:<md5(source\|file)>` | Digest | Errors | PHP errors: `deprecation_count` from `amc_events`. |
| `smoke.urls` | `checked_at`, `complete`, `cursor`, `counts`, `urls[]` (`url`, `status` pass, fail, loopback_blocked or null, `http_code`, `response_ms`, `consecutive_failures`, `history[]` of 24, `daily{}` of 35 days) | HIGH per URL after two failures in a row. Key `smoke:<md5(url)>` | Immediate | Smoke | Smoke checks: runs in the period (`amc_runs`). |
| `security.checksums` | `version`, `locale`, `total`, `cursor`, `checked`, `done`, `found[]`, `modified`, `missing`, `error` | HIGH for a modified file. MEDIUM for a missing file, LOW for optional files (`readme.html`, `license.txt`, `wp-config-sample.php`). Key `core-file:<path>` | Immediate, for any finding (gap 9) | Security | Security: `core_checksums` (latest run). |
| `security.config` | `items[]` (`key`, `status`, `code`, `title`, `detail`), `counts` | MEDIUM for a fail, LOW for a warn. Key `config:<key>` | Digest | Security | Security: `config_checks` (latest run). |
| `security.admins` | `admins[]`, `count`, `baseline`, `snapshot_at`, `changes[]` (`added`, `removed`, `role_changed`) | HIGH (added). MEDIUM (role changed). LOW (removed). Key `admin:<change>:<user_id>` | Immediate if added, otherwise digest | Security | None. User management uses live counts and `amc_changes`. |
| `ssl.certificate` | `host`, `https`, `available`, `reason`, `issuer`, `subject_cn`, `serial`, `sans[]`, `valid_from`, `valid_to`, `days_left`, `expired`, `expiring_soon` | Only when expired or at most 14 days left. CRITICAL (expired or at most 3 days). HIGH otherwise. Key `ssl:<serial>` | Immediate at 14 days or fewer | Security | SSL: `https`, `issuer`, `expires_at`, `days_left` (latest run). |
| `perf.response` | `checked_at`, `threshold_pct` (20), `avg_ms`, `urls[]`, `trend[]` (30 days) | MEDIUM when 50% or more slower, otherwise LOW (digest). Key `slow:<md5(url)>` | Digest | Performance | Performance: `response_trend` from `perf.response` runs in the period. |
| `perf.database` | `source`, `tables[]`, `total_mb`, `autoload_kb`, `autoload_over` | MEDIUM when autoload is over 800 KB. Key `autoload:<kb>` | Digest | Performance | Performance: `database_size_bytes`, `autoload_size_bytes` (latest run). |
| `perf.cache` | `persistent_object_cache`, `page_cache_hit`, `page_cache_status`, `object_cache_status`, `response_time_ms`, `advanced_cache_present` | None (PASS or INFO) | None | Performance | Caching and CDN: `page_cache`, `object_cache` (latest run). |
| `perf.slow_queries` | `since`, `queries`, `callers[]` (`caller`, `count`, `max_ms`, `avg_ms`, `sample`) | LOW, or MEDIUM from 1,000 ms. Key `caller:<md5(caller)>` | Digest | Performance | None. |
| `health.site_health` | `tests[]` (`id`, `label`, `status`, `critical`), `critical`, `recommended`, `good`, `errors` | MEDIUM per critical test. LOW per recommended test. Keys `site-health:<id>` and `:recommended` | Digest | Overview (group status) | None. |
| `health.cron` | `overdue[]` (`hook`, `scheduled`, `overdue_seconds`), `total_events` | MEDIUM per overdue hook. Key `cron:<hook>` | Digest | Overview (group status) | None. |
| `users.roles` | `counts`, `total`, `roles[]`, `changes[]` (`role`, `added`, `removed`) | MEDIUM when capabilities change. Key `role:<role>:caps` | Digest | Overview (group status) | User management uses live counts. |
| `users.changes` | `total`, `items[]`, `counts` | None | None | Overview (group status) | User management: `users_added` and `users_removed` come from `amc_changes` directly. |
| `hosting.platform` | `host`, `web_server`, `evidence[]`, `php_version`, `db_version`, `os`, `memory_limit`, `disk_total`, `disk_free` | None | None | Inventory; Setup suggestions | Hosting: `host`, `server_type`, `web_server`, `mysql_version`, `php_version`, `memory_limit` (latest run, with live fallback). |
| `detect.cdn` | `url`, `provider`, `evidence[]`, `page_cache_hit`, `status_code` | None | None | Inventory | Caching and CDN: `cdn` (latest run). |
| `detect.forms` | `plugin`, `active`, `addons[]`, `form_count` | None | None | Inventory | Forms: `form_plugins`, `integrations` (latest run). |
| `detect.mailer` | `plugin`, `active`, `addons[]`, `phpmailer_init_callbacks` | None (INFO when no mailer plugin) | None | Inventory | Forms: `sending_method` (latest run). |
| `detect.analytics` | `url`, `gtm[]`, `ga4[]`, `ua[]`, `accessibility_tools[]`, `status_code` | None | None | Inventory | Analytics: `gtm_ids`, `ga_ids` (latest run). |
| `detect.accessibility` | `url`, `tools[]`, `statement_pages[]`, `status_code` | None | None | Inventory | Accessibility: `third_party_tool`, `statement_page` (latest run). |

Notes on the table:

- The report section reads the **latest** run of each check at build time, regardless of the period, through `SnapshotBuilder::latest_data()`. The builder runs the ten inventory and detection checks fresh first (section 10.3). Section 10.4 gives the full map.
- `errors.*` checks are built on `AbstractEventsCheck` (`inc/Checks/Errors/AbstractEventsCheck.php`), which sets group `errors` and schedule hourly. The three subclasses supply the types, the grouping key, the severity and the title.

---

## 5. Runner, scheduling, locks and the kill switch

### 5.1 Run lifecycle

`Runner\Runner` (`inc/Runner/Runner.php`) has three entry points.

1. **`run( $check_id, 'cron'|'cli', $user_id, $blog_id )`** (line 101). Runs a check now, in this process, and returns the finished run row or a `WP_Error`. Used by the scheduler, the CLI and the report build.
2. **`enqueue( $check_id, $user_id, $blog_id )`** (line 127). "Run now". It never runs the check. If a `queued` or `running` row already exists for that check, it returns that row's id. Otherwise it creates a `queued` row (trigger `manual`) and calls `Scheduler::kick()` to schedule the queue drain. It returns 503 `amc_disabled` when the kill switch is on.
3. **`run_queued( $run_id )`** (line 157). Runs a queued row. Only the scheduler's queue drain calls it. It atomically claims the row (`status` from `queued` to `running`, with `started_at`), and returns 409 `amc_not_queued` if that fails.

Each execution (`execute()`, line 191) follows these steps:

1. Kill switch check. If on, return 503 `amc_disabled`.
2. Acquire the lock `check:{blog_id}:{check_id}` in `amc_locks` (TTL 600 s). If it is held, return 409 `amc_locked`.
3. Create or claim the run row. If that fails, return 409 `amc_not_claimed`.
4. Read the previous result for this check (`RunsRepository::latest()` before this run).
5. Build the `Context`, with `previous_data` set to the previous run's data.
6. `Check::run()`. A thrown exception stores the run as `error` (with class, message, file and line in `data`), leaves findings untouched, and returns.
7. `RunsRepository::finish()` stores the status, summary, data and duration.
8. `process_findings()` (section 5.2).
9. `do_action( 'amc_findings_processed', ProcessedFindings )`.
10. `do_action( 'amc_run_completed', $row )` (also for `error` runs).
11. Release the lock.

Multisite: `with_blog()` (line 336) calls `switch_to_blog()` when the run is for another site, and restores afterwards. On a single site, nothing switches.

The run's `trigger` value is `cron` (scheduler), `manual` (console "Run now", rerun, and every queued run) or `cli` (`wp amc check run`).

### 5.2 Findings: deduplication and resolution

`process_findings()` (line 265) takes the check's unresolved findings (`FindingsRepository::unresolved_for_check()`, keyed by `dedupe_key`), and compares them with the new `Result::$findings`.

- **Seen before, still open** (key matches an unresolved row): `touch()` updates the severity, title, data, `run_id` and `last_seen`. `alerted_at` is not changed, so the alert is not sent again. The finding goes into `ProcessedFindings::$ongoing`.
- **New key**: `insert_finding()` creates a row with status `open`, `first_seen` and `last_seen` set to now. It goes into `$new`.
- **Not seen this time** and the result is complete: `resolve()` sets `status` to `resolved` and `resolved_at` to now. It goes into `$resolved`.
- **Not seen, but the result is partial**: nothing is resolved. This is how batched checks avoid resolving findings from batches that have not run yet.
- **Comeback**: a resolved finding that reappears is not in the unresolved map, so it is inserted as a **new row** with `alerted_at` null. That is why an alert fires again only after a resolve.
- **Accepted and muted** findings stay unresolved, so they keep matching and keep `last_seen` current. They are excluded from the open counts and from alerts (`AlertRules::route()` returns `none` for any status other than `open`).
- **Duplicate keys** in one result: the first one wins.

`ProcessedFindings` (`inc/Runner/ProcessedFindings.php`) is a readonly value object: `run_id`, `check_id`, `blog_id`, `trigger`, `new`, `ongoing`, `resolved`, each a list of finding rows.

### 5.3 The scheduler tick

`Runner\Scheduler` (`inc/Runner/Scheduler.php`) owns one recurring WP-Cron event, `amc_scheduler_tick`, on a custom interval `amc_every_five_minutes` (300 s). The first tick is 60 s after activation. `Scheduler::schedule()` also runs on `init` in admin, cron and CLI requests, so a missing event heals itself.

`tick()` (line 146) runs these steps in order:

1. If the kill switch is on, stop.
2. `Installer::maybe_upgrade()` (a schema check).
3. `RunsRepository::fail_stale( 600 )`: any `running` row started more than 600 seconds ago becomes `error`, with the summary "Run did not finish (timed out or PHP stopped)."
4. `process_queue()`: runs queued rows, oldest first (`created_at`, then `id`), until the time budget (default 25 s, filter `amc_scheduler_time_budget`) runs out. If it runs out, it calls `kick()` to schedule another drain.
5. `due_checks()`: for each registered check, the due time is the last run's time plus the effective interval (`last_run_times()`). A check that has never run is due immediately. Checks with schedule `event` and checks disabled in settings are skipped. Due checks are sorted by due time, oldest first. A check that missed several intervals runs **once** (catch-up once). Each due check is run with trigger `cron` until the budget runs out.

`process_queue()` returns the number of queued runs it completed. `next_runs()` gives the next run time for each check, used by the Overview and the checks list.

### 5.4 Cron events

| Hook | Kind | Schedule | Registered in | Purpose |
| --- | --- | --- | --- | --- |
| `amc_scheduler_tick` | Recurring, `amc_every_five_minutes` | Every 300 s; first at activation plus 60 s | `Runner/Scheduler.php` (line 102) | Runs queued rows and due checks (section 5.3). |
| `amc_process_queue` | Single | Now (`Scheduler::kick()`) | `Runner/Scheduler.php` (line 119) | Drains the queue after "Run now". |
| `amc_retention` | Recurring, `daily` | Next 03:00 UTC | `Storage/Retention.php` (line 108) | Deletes expired rows (section 3). A follow-up single event, five minutes later, is set when the 20-second budget runs out. |
| `amc_digest` | Single, rescheduled after each run | `digest_time` (default 09:00), site time | `Notify/Digest.php` (line 118) | Sends the daily digest (section 7.3). |
| `amc_report_monthly` | Single, rescheduled after each run | 1st of each month, 06:00 site time | `Reports/ReportSchedules.php` (line 107) | Generates the previous month's report. |
| `amc_report_quarterly` | Single, rescheduled | 1st day of each contract quarter, 06:15 | `Reports/ReportSchedules.php` | Generates the previous quarter's report. |
| `amc_report_annual` | Single, rescheduled | Contract anniversary, 06:30 | `Reports/ReportSchedules.php` | Generates the previous 12 months' report. |
| `amc_report_build` | Single, one per report (argument: post id) | Now (`Generator::queue_build()`) | `Reports/Generator.php` (line 356) | Builds a queued report's snapshot. |

Each report event carries its scheduled timestamp as its argument. When it fires, it generates the report and schedules the next one, so there is never a drifting recurring interval. `Periods::next_run()` works in site time, so the hour stays fixed across daylight saving changes.

The events are scheduled on `amc_activate` and cleared on `amc_deactivate`. The report and digest events also self-heal on `init` in admin, cron and CLI requests.

### 5.5 Locks

`Runner\Lock` (`inc/Runner/Lock.php`) wraps `LocksRepository`. `Lock::for_check( $id, $blog )` makes the name `check:{blog}:{id}`. `Lock::named( $job, $ttl )` makes `job:{job}`. Acquire inserts with `INSERT IGNORE` after deleting an expired row of the same name. Release deletes the row only when the owner matches.

| Lock name | TTL | Taken by |
| --- | --- | --- |
| `check:{blog_id}:{check_id}` | 600 s | `Runner::execute()`, for every check run |
| `job:retention` | 600 s | `Retention::run()` |
| `job:digest` | 600 s | `Digest::run()` |
| `job:report-create:{report_id}` | 120 s | `Generator::create()` |
| `job:report-build:{post_id}` | 600 s | `Generator::build()` |
| `job:report-schedule:{type}` | 300 s | `ReportSchedules::run()` |

### 5.6 The kill switch

`Core\KillSwitch::is_active()` (`inc/Core/KillSwitch.php`, line 42). The constant `AMC_COPILOT_DISABLED` wins whenever it is defined, whether true or false. Otherwise the `kill_switch` setting decides. The REST response reports `kill_switch_locked` when the constant is set, so the console can show the switch as read only.

Checkpoints where the switch is read:

| Where | Effect when on |
| --- | --- |
| `Scheduler::tick()` and `process_queue()` | Nothing runs. |
| `Runner::enqueue()` | Returns 503 `amc_disabled`. |
| `Runner::execute()` | Returns 503 `amc_disabled`. |
| `Storage\Retention::run()` | No cleanup. |
| `Notify\Digest::run()` | No digest. |
| `Notify\Alerts::alert()` | No immediate alerts. |
| `Notify\Mailer::send_parts()` | No email is sent. |
| `Rest\NotifyController::send_test()` | Returns 503 `amc_disabled`. |
| `Capture\Buffer::drain()` | Buffered capture data is discarded (once per flush, not per hook). |
| `Capture\ChangeLog::record()` | No change rows. |
| `Reports\ReportSchedules::run()` | No scheduled generation. |
| `Reports\Generator::run_queued_build()` | No build. |

### 5.7 WP-CLI

Registered by `Runner\Cli\CliModule` only when `WP_CLI` is defined. Each command class implements `CLICommand`.

| Command | Class | Notes |
| --- | --- | --- |
| `wp amc check list [--format=table\|json\|csv\|yaml\|ids]` | `CheckListCommand` | Columns: id, group, schedule, enabled, last status, last run. |
| `wp amc check run <id>... [--all] [--format=…]` | `CheckRunCommand` | Runs synchronously with trigger `cli`. Exit code 1 when any run errored. |
| `wp amc access grant <user>` / `revoke <user>` | `AccessGrantCommand`, `AccessRevokeCommand` | User by id, login or email. Logged to `amc_changes` with source `cli`. |
| `wp amc report generate [--period=…] [--start=] [--end=]` | `Reports\Cli\ReportGenerateCommand` | Builds the draft and its snapshot in this process. Prints the existing report instead of making a second one for the same period. |

---

## 6. Capture layer and change log

The capture layer runs on every request. It must stay cheap (the plan's budget is under 2 ms at the 95th percentile on the front end). Everything is buffered in memory and written once, in `shutdown`, by `Capture\Buffer`.

### 6.1 Hooks and what they write

| Hook or mechanism | Listener | Writes |
| --- | --- | --- |
| `set_error_handler` (chained to the previous handler) | `ErrorCapture::handle()` (`inc/Capture/ErrorCapture.php`, line 122) | `amc_events`. The error type comes from `ErrorCapture::TYPES` (E_WARNING and similar become `warning`, E_NOTICE becomes `notice`, E_DEPRECATED becomes `deprecation`). |
| `register_shutdown_function` and `wp_php_error_message` | `ErrorCapture::on_shutdown()` and `on_fatal_screen()` | A `fatal` row from `error_get_last()`. The fatal is drained at once. |
| `deprecated_function_run`, `deprecated_argument_run`, `deprecated_hook_run`, `deprecated_file_included`, `deprecated_class_run` | `DeprecationCapture` (`inc/Capture/DeprecationCapture.php`, line 48) | `amc_events`, type `deprecation`. |
| `doing_it_wrong_run` | `DeprecationCapture::on_doing_it_wrong()` | `amc_events`, type `doing_it_wrong`. |
| `query` and `log_query_custom_data` (1 request in 100) | `QuerySampler` (`inc/Capture/QuerySampler.php`, line 109) | `amc_queries`, for queries of 100 ms or more. |
| `upgrader_pre_install` (filter) | `ChangeLog::on_pre_install()` | Nothing. It stores the "before" state for the update. |
| `upgrader_process_complete` | `ChangeLog::on_upgrade_complete()` | `amc_changes`: plugin or theme `update` and `install`. |
| `activated_plugin`, `deactivated_plugin` | `ChangeLog` | `amc_changes`: plugin `activate` and `deactivate`. |
| `delete_plugin` (before) and `deleted_plugin` (after, only when deleted) | `ChangeLog` | `amc_changes`: plugin `delete`, with the name and version kept. |
| `switch_theme` | `ChangeLog::on_switch_theme()` | `amc_changes`: theme `activate`. |
| `_core_updated_successfully` | `ChangeLog::on_core_updated()` | `amc_changes`: core `update`. |
| `user_register`, `delete_user`, `set_user_role` | `ChangeLog` | `amc_changes`: user `install`, `delete` and `role`. |
| `Core\Capabilities::grant()` and `revoke()` (called from the REST routes, CLI and setup) | Direct call | `amc_changes`: user `access_grant` and `access_revoke`. |
| Deploy diff (from `inventory.plugins`) | `Capture\DeployDiff::record()` | `amc_changes`: plugin `install`, `update` and `delete` with source `deploy`. |
| `shutdown` (priority 999) | `Buffer::flush()` | `amc_events` and `amc_queries`, one upsert each. |

### 6.2 The buffer

`Capture\Buffer` (`inc/Capture/Buffer.php`) keeps events and queries in memory, keyed by blog, type and location (events) or blog, caller and SQL (queries). It holds at most `MAX_ENTRIES` (200) distinct entries. Overflow is counted in `dropped` and reported through the `amc_capture_events_dropped` action. Repeats of the same key only increment a counter.

`drain()` (line 220) empties the buffer first. Then it checks `KillSwitch::is_active()` once, and writes only if the switch is off. It suppresses `$wpdb` errors during the write, so a failing capture never shows an error to the visitor.

`Buffer::disabled_by_constant()` skips all capture hooks when the kill switch is locked on by the constant (`KillSwitch::is_locked_by_constant() && is_active()`).

### 6.3 The query sampler

`QuerySampler` rolls a die once per request (`mt_rand( 1, rate )`) at `register_hooks()`. Only a roll of 1 attaches the `query` filter, so 1 request in 100 is timed (filter `amc_capture_query_sample_rate`, default 100; 0 turns sampling off). The threshold is 100 ms (filter `amc_capture_slow_query_ms`). Timing uses `hrtime()` around each query. Callers are shortened to the last three non-`wpdb` frames, and `SAVEQUERIES` is never turned on.

### 6.4 The change log: sources

`ChangeLog::source()` (line 362) sets the `source` column:

- `auto`: when the upgrader skin is `Automatic_Upgrader_Skin` (background and automatic updates).
- `cli`: when `WP_CLI` is defined.
- `admin`: anything else (the wp-admin screens).
- `deploy`: set by `DeployDiff`, for changes that fired no hook (git deploys and file copies).
- `cli` or `admin` for `Capabilities::grant()` and `revoke()`, depending on the caller.

`ChangeLog::record_update()` skips a row when the old and new versions are the same.

### 6.5 The deploy diff

`inventory.plugins` (`PluginInventory`) compares this run's plugin list with the stored `plugins` snapshot (section 3.6). `DeployDiff::record( 'plugin', $previous, $current, $since )` (`inc/Capture/DeployDiff.php`, line 66) writes an `install`, `update` or `delete` row, with source `deploy`, for each difference. A difference is skipped when a row with the same item, action and new version was already logged within the last two days. That is how the diff avoids repeating what the upgrader hooks already recorded. No baseline means nothing is logged.

### 6.6 Helpers

`Capture\Signature` (`inc/Capture/Signature.php`) holds the normalisers used for event and query signatures (`message()`, `of()`, `sql()`), the file and source helpers (`relative_file()`, `source()`), and the path roots used to attribute files to plugins, themes, mu-plugins or core.

---

## 7. Notifications

Email is sent with `wp_mail()` through `Notify\Mailer`, so the site's mail plugin or host mail is used. Every message has an HTML part and a plain-text part (`AltBody`). Subjects start with `[AMC][site name]`.

### 7.1 Alert routing

`Notify\AlertRules::table()` (`inc/Notify/AlertRules.php`, line 46) maps each check id to `immediate`, `digest`, `none` or a callable that decides per finding. The `amc_alert_rules` filter can change the table. `AlertRules::route()` (line 119) returns `none` for any finding whose status is not `open`.

| Check | Route | Rule |
| --- | --- | --- |
| `updates.core`, `updates.plugins`, `updates.themes`, `updates.php` | Digest | Any finding. |
| `updates.readiness` | None | |
| `vuln.match` | Immediate when severity is HIGH or CRITICAL, otherwise digest | `route_vulnerability()`. |
| `inventory.plugins` | Digest | Closed or abandoned plugins. |
| `inventory.themes`, `inventory.mu_dropins`, `inventory.editor` | None | |
| `errors.fatal` | Immediate | Every new fatal signature. |
| `errors.warnings`, `errors.deprecations` | Digest | New sources and callers. |
| `smoke.urls` | Immediate | The check raises a finding only after two failures in a row. |
| `security.checksums` | Immediate | Every finding, including missing files (gap 9). |
| `security.config` | Digest | |
| `security.admins` | Immediate when `added`, otherwise digest | `route_admin_change()`. |
| `ssl.certificate` | Immediate at 14 days or fewer (`days_left`), otherwise digest | `route_certificate()`. |
| `perf.response`, `perf.database`, `perf.slow_queries`, `health.site_health`, `health.cron`, `users.roles` | Digest | |
| `perf.cache`, `users.changes`, `hosting.platform`, `detect.*` | None | |

`AlertRules::alerting_checks()` lists the checks with a route other than `none`. The digest uses that list.

### 7.2 The immediate alert path

`Notify\Alerts` (`inc/Notify/Alerts.php`) listens on `amc_findings_processed` (line 66).

1. If there are no new findings, or the kill switch is on, stop.
2. Keep the new findings whose route is `immediate` and that have not yet been alerted (`alerted_at` is null).
3. Get the recipients with `Settings::recipients()`. The list is `recipients`, or every user with `amc_manage` when the list is empty.
4. `Throttle::acquire()`: if the hourly limit is used up, stop. The findings are **not** marked alerted, so the next digest reports them as "held back".
5. `Mailer::send_immediate()`. On success, `mark_alerted()`.

`Notify\Throttle` (`inc/Notify/Throttle.php`) keeps a sliding one-hour window of send times in `amc_notify_sent_times`. The limit is `immediate_alerts_per_hour` (default 10). `reset()` deletes the option, and runs on `amc_deactivate`.

### 7.3 The daily digest

`Notify\Digest` (`inc/Notify/Digest.php`) is a single `amc_digest` event, scheduled for the next `digest_time` in site time. After each run, `on_cron()` schedules the next one, so DST changes are handled. The event is rescheduled when the digest time or the site timezone changes.

`run()` (line 192) takes the lock `job:digest`, builds the digest and sends it to the recipients. It marks the reported findings as alerted. When there is nothing to report, it sends nothing.

`build()` (line 230) collects three sections:

- **Immediate alerts held back by the hourly limit**: open findings with an immediate route that were never alerted.
- **New in the last 24 hours**: open findings with a digest route and `first_seen` in the window.
- **Still open**: the other open findings with a route other than `none`.

Each section lists up to 25 items, most severe first, with a count of the rest. The subject is "Daily digest: N new findings" (or "1 new finding"). The intro says how many are new and how many are still open. The digest links to the console.

`Digest::send_sample()` sends a sample digest for `POST /notify/test`.

### 7.4 Mailer and templates

`Notify\Mailer` (`inc/Notify/Mailer.php`) is the only place that calls `wp_mail()` for alerts and reports.

- `send_parts()` (line 92) checks the recipients and the kill switch, adds the plain-text `AltBody` through `phpmailer_init` for the duration of one send, and removes it afterwards.
- `send_immediate()` (line 146): one finding gives the subject "{Severity label}: {title}" and the heading "New alert". Several give "N new alerts".
- `EmailRenderer` (`inc/Notify/EmailRenderer.php`) builds the HTML and text from a structure of heading, intro, sections, paragraphs and an action link. It uses the `Core\Brand` colours inline, so the email does not depend on any stylesheet. The header reads "rtCamp · AMC Copilot". All HTML is escaped.
- `Mailer::finding_url()` builds the deep link for each item, `admin.php?page=amc-copilot&screen=findings&finding=ID`. Sites can filter it with `amc_notify_finding_url`. The console maps the link to the right screen (section 9.3).

### 7.5 Report emails

- **Draft ready.** `Generator::build()` fires `amc_report_draft_ready` once, after the first snapshot of a new report. `ReportEmails::on_draft_ready()` calls `Mailer::send_report_draft_ready()`. The email goes to the engineer recipients, and says the draft of the named report is ready, with a link to review it in the editor.
- **Published.** `ReportPublishing::on_transition()` fires `amc_report_published` when a report moves to `publish`. `ReportEmails::on_published()` first adds `_amc_published_email_sent` with `add_post_meta( …, true )`, which is the lock. If that fails, the email has already gone. If sending fails, the meta is removed so the next publish can retry. The recipients are `report_publish_recipients` plus, when `notify_client_on_publish` is true, the client administrators (administrators who have `amc_view_reports` and not `amc_manage`). The filter `amc_report_published_recipients` can change them. Each recipient gets its own send.

### 7.6 What each email contains

| Email | Subject | Contents |
| --- | --- | --- |
| Immediate alert | "{Severity}: {title}", or "N new alerts" | Heading "New alert", an intro, and a table of findings: severity, title (linked to the console), and the check's label. Button to the console. |
| Daily digest | "Daily digest: N new findings" | Heading "Daily digest", counts of new and still open, and up to 25 items per section (held back, new, still open). Button to the console. |
| Draft ready | "Report draft ready: {report title}" | The report title, a sentence saying the draft is ready for review, and a button to edit the draft. |
| Published | "[AMC][site] …" with the report id or title | The report title, the period, and a link to the client view. |
| Test (`POST /notify/test`) | As for the type | A sample alert or a sample digest, sent to the recipients. |

---

## 8. REST API

The namespace is `amc/v1`. Routes are registered by the controllers in `inc/Rest/`, which `Rest\RestModule` loads (`inc/Rest/RestModule.php`, line 28). `Rest\AbstractController::route()` (line 60) registers each route with a real `permission_callback`. No route uses `__return_true`.

**Permissions:**

- `manage`: `current_user_can( 'amc_manage' )` (`AbstractController::manage()`, line 76).
- `view_reports`: `amc_manage` or `amc_view_reports`.
- `manage_access`: `amc_manage` and `manage_options` (`Core\Capabilities::can_manage_access()`).
- `manage_settings` (`SettingsController`): `amc_manage` and `manage_options`.
- `manage_setup` (`SetupController`): `amc_manage` and `manage_options`.

Anonymous requests get 401; logged-in users without the capability get 403. All the responses below are JSON. Times are ISO 8601 UTC, for example `2026-11-13T09:00:00Z`.

| Method | Path | Permission | Controller (`inc/Rest/…`, method) | Returns | Data source |
| --- | --- | --- | --- | --- | --- |
| GET | `/overview` | manage | `OverviewController::get_overview()` | `status`, `kill_switch`, `findings_by_severity`, `groups[]`, `versions`, `counts`, `next_runs[]` | `amc_runs` (latest result per check), `amc_findings` (open counts), `update_*` site transients, `Registry`, `Scheduler::next_runs()` |
| GET | `/checks` | manage | `ChecksController::list_checks()` | `Check[]` with `schedule`, `default_schedule`, `enabled`, `last_run`, `next_run_at` | `Registry`, `amc_settings` (overrides), `amc_runs` |
| POST | `/checks/run` | manage | `ChecksController::run_checks()` | 202 `{ runs: [{ check_id, run_id }] }` | `Runner::enqueue()` (writes `amc_runs`); `"all"` expands to every check |
| GET | `/runs` | manage | `RunsController::list_runs()` | `Run[]` with `X-WP-Total`, `X-WP-TotalPages`. Filters: `check_id`, `status`, `trigger`, `since`, `until`, `page`, `per_page` | `amc_runs` (`RunsRepository::query()`) |
| GET | `/runs/{id}` | manage | `RunsController::get_run()` | `Run` with `data` and `findings[]` (up to 500 of that run) | `amc_runs`, `amc_findings` by `run_id`. 404 when the run belongs to another site |
| POST | `/runs/{id}/rerun` | manage | `RunsController::rerun()` | 202 `{ run_id }` | `Runner::enqueue()` for the same check |
| GET | `/findings` | manage | `FindingsController::list_findings()` | `Finding[]` with headers. Filters: `check_id`, `group` (expanded to its check ids), `severity`, `status` (including `unresolved`), `since`, `page`, `per_page` | `amc_findings` |
| GET | `/findings/{id}` | manage | `FindingsController::get_finding()` | `Finding` | `amc_findings` |
| POST | `/findings/{id}/status` | manage | `FindingsController::set_status()` | `Finding`. Body: `status` (`open`, `accepted`, `muted`, `resolved`), optional `note` | `FindingsRepository::set_status()` |
| GET | `/settings` | manage | `SettingsController::get_settings()` | `Settings` (section 3.8.1) plus `delete_reports_on_uninstall` | `amc_settings`, `amc_delete_reports_on_uninstall` |
| POST | `/settings` | manage and `manage_options` | `SettingsController::update_settings()` | `Settings`. Validates `report_publish_recipients` (400 `amc_invalid_email` with `data.invalid`, or 400 `amc_too_many_recipients`) | `Settings::update()`; toggles the uninstall option |
| GET | `/access` | manage and `manage_options` | `AccessController::list_engineers()` | `[{ id, login, display_name, email }]` | `Capabilities::engineers()` |
| POST | `/access` | manage and `manage_options` | `AccessController::grant()` | 201 engineer. Body: `user_id` | `Capabilities::grant()` (writes `amc_changes` `access_grant`) |
| DELETE | `/access/{user_id}` | manage and `manage_options` | `AccessController::revoke()` | `{ revoked: true, user_id }`. 400 `amc_self_revoke` on self | `Capabilities::revoke()` (writes `amc_changes` `access_revoke`) |
| GET | `/updates` | manage | `UpdatesController::get_screen()` | `{ checks: { 'updates.core', 'updates.plugins', 'updates.themes', 'updates.php', 'updates.readiness': { run, data } or null }, csv: { columns, rows } }` | Latest runs of those five checks. The CSV is built from the plugin and theme rows |
| GET | `/inventory` | manage | `InventoryController::get_screen()` | `{ checks: {…}, csv }` for `inventory.*` (4), `hosting.platform` and `detect.*` (5) | Latest runs of those ten checks |
| GET | `/vulnerabilities` | manage | `VulnerabilitiesController::get_screen()` | `{ provider: { id, ready, last_error }, last_run, findings[], counts: { open: { critical, high, medium, low, info }, accepted } }`. `status` default `all` | `Providers::current()`, latest `vuln.match` run, `amc_findings` with `check_id` `vuln.match` |
| POST | `/vulnerabilities/{id}/accept` | manage | `VulnerabilitiesController::accept()` | `Finding`. Body: `note` (required). 404 for any other check | `FindingsRepository::set_status( 'accepted' )`. **The console does not call this route** (gap 3 and section 9) |
| GET | `/security` | manage | `SecurityController::get_screen()` | `{ checks: { 'security.checksums', 'security.config', 'security.admins', 'ssl.certificate' }, open_findings[] }` (up to 200) | Latest runs of those four checks, unresolved findings for them |
| GET | `/performance` | manage | `PerformanceController::get_screen()` | `{ checks: { 'perf.response', 'perf.database', 'perf.cache', 'perf.slow_queries' }, response_trend[], slow_queries[] }`. `days` 1 to 90, default 30 | Latest `perf.*` runs; `perf.response` `data.trend` filtered to the window; `QueriesRepository::grouped()` for the window |
| GET | `/smoke` | manage | `SmokeController::get_screen()` | `{ last_run, urls: [{ url, required_text, is_default, last_status, last_http_code, last_response_ms, pass_rate_30d, trend[] }] }` | Latest `smoke.urls` run data, and the `smoke_urls` setting |
| POST | `/smoke/urls` | manage | `SmokeController::save_url()` | `{ urls }`. Adds or edits an extra URL (maximum 25). **The console does not use this route** (it posts `smoke_urls` to `POST /settings`) | `Settings::update( smoke_urls )` |
| GET | `/errors` | manage | `ErrorsController::list_errors()` | `[{ signature, type, message, source, file, line, count, first_seen, last_seen, sparkline[], finding_id, finding_status }]`. `type`, `days` 1 to 90 (default 30) | `EventsRepository::grouped()` (100 rows), `daily_counts()`, linked `errors.*` findings by `dedupe_key` |
| POST | `/errors/{signature}/status` | manage | `ErrorsController::set_status()` | Finding. Body: `status` (`open`, `muted`, `resolved`). 404 when no unresolved `errors.*` finding has that signature | `FindingsRepository::set_status()` |
| GET | `/changes` | manage | `ChangesController::list_changes()` | `[{ id, type, action, item, item_name, old_version, new_version, source, user_id, user_display_name, created_at }]` with headers. Filters: `type`, `action`, `source`, `since`, `until`, `page`, `per_page` | `amc_changes`, `get_userdata()` |
| POST | `/notify/test` | manage | `NotifyController::send_test()` | `{ sent, recipients[] }`. Body: `type` (`immediate` or `digest`). 503 `amc_disabled` when the kill switch is on | `Mailer::send_immediate()` or `Digest::send_sample()` |
| GET | `/reports` | view_reports | `ReportsController::list_reports()` | `Report[]` with headers. `status` (`any`, `draft`, `publish`). Non-managers get published reports only | `WP_Query` on `amc_report` |
| POST | `/reports/generate` | manage | `ReportsController::generate()` | 201 `Report`, build queued (`build_status` `pending`). Body: `period_type` (`month`, `quarter`, `year`, `custom`), `start`, `end` (custom needs both). 409 `amc_report_exists` when the period already has a report | `Generator::resolve_period()`, `create()`, `queue_build()` |
| POST | `/reports/{id}/regenerate` | manage | `ReportsController::regenerate()` | `Report`, build queued. 409 `amc_report_published` for a published report | `Generator::guard_regenerable()`, `queue_build()` |
| GET | `/reports/{id}/snapshot` | view_reports | `ReportsController::get_snapshot()` | `{ id, taken_at, snapshot }`. Drafts for managers only. Managers also get `saved_fields` (previous and changes) | `SnapshotSchema::for_post()`, `SavedFields::review()` |
| GET | `/saved-fields` | manage | `ReportsController::get_saved_fields()` | `{ fields, schema }` | `SavedFields::all()`, `FieldCatalogue::saved()` |
| POST | `/saved-fields` | manage | `ReportsController::update_saved_fields()` | Same as GET. Body: `fields` (partial, `null` clears a field) | `SavedFields::update()` |
| GET | `/setup` | manage and `manage_options` | `SetupController::get_setup()` | `{ completed, completed_at, notice_dismissed, detected: { environment, site_name, home_url, primary_domain, admin_email, engineer_emails, host, ssl, suggestions } }` | `SetupState`, `SetupDefaults` (latest `hosting.platform` and `ssl.certificate` runs, or a live hosting check; gap 12) |
| POST | `/setup/complete` | manage and `manage_options` | `SetupController::complete()` | `{ completed, completed_at, notice_dismissed }` | `SetupState::complete()` |
| POST | `/setup/dismiss` | manage and `manage_options` | `SetupController::dismiss()` | Same as complete. Hides the setup notice for the current user | `SetupState::dismiss()` (user meta) |

Core routes that the plugin depends on:

| Method | Path | Controller | Notes |
| --- | --- | --- | --- |
| GET, POST, … | `/wp/v2/amc-reports` and `/wp/v2/amc-reports/{id}` | `Reports\ReportRestController` (extends `WP_REST_Posts_Controller`) | Every read requires `read_amc_reports`, so core does not serve published reports to anonymous requests. The console publishes with `POST …/{id}` and `status: publish`. The editor reads `_amc_snapshot` through this route. |
| GET | `/wp/v2/users` | Core | Candidate engineers in Settings and Setup. |

Error codes the plugin returns: `amc_not_found`, `amc_unknown_check`, `amc_locked`, `amc_disabled`, `amc_not_claimed`, `amc_not_queued`, `amc_self_revoke`, `amc_invalid_user`, `amc_invalid_email`, `amc_too_many_recipients`, `amc_report_exists`, `amc_report_published`, `amc_invalid_period`, `amc_build_failed`, `amc_forbidden`, `rest_forbidden` and `rest_invalid_param`. The response body is `{ code, message, data: { status, … } }`.

---

## 9. UI surfaces and their data

### 9.1 The console shell

- **Menu.** `Admin\ConsolePage` (`inc/Admin/ConsolePage.php`) adds a top-level **AMC Copilot** menu with capability `amc_manage`, icon `dashicons-shield`, slug `amc-copilot`. The hook suffix is `toplevel_page_amc-copilot`. It prints the mount point `#amc-console-root`.
- **Enqueue.** `Admin\ConsoleAssets::enqueue()` (`inc/Admin/ConsoleAssets.php`, line 72) runs only on that hook suffix, only for `amc_manage`, and only when the build exists. It enqueues `assets/build/js/console.js` and `assets/build/css/console.css` (depending on `wp-components`), loads the brand fonts, sets script translations, and prints `window.amcCopilot = { restNonce, useMocks }` before the script. `useMocks` is always false in PHP. A developer can change it with the `amc_console_config` filter.
- **Routing.** `?page=amc-copilot&screen=<slug>`, with no router library. `src/js/console/constants.js` lists the 13 screens and the sections. `src/js/console/app.js` renders the navigation and the screen. Setup is standalone (`STANDALONE_SCREENS`), so it has no navigation.
- **Other query arguments.** `&run=<id>` opens a run's raw data on the Runs screen (`screens/Runs.js` reads it with `useLocation()`). `&finding=<id>` opens the finding modal on any screen (section 9.3).
- **Data access.** `src/js/console/api/index.js` is the only module that calls REST. It uses `@wordpress/api-fetch` with the `amc/v1` namespace. It returns the mock fixtures instead when `useMocks` is on.
- **Polling.** "Run now" queues the run and polls `GET /runs/{id}` every 2.5 s until the status is no longer `queued` or `running`. With more than three runs, it fetches `GET /runs?per_page=500` once instead (`getRunStatuses()`).

### 9.2 Screen-by-screen mapping

| Surface | URL | React file | REST calls (from `api/index.js`) | PHP controller | Underlying source |
| --- | --- | --- | --- | --- | --- |
| Overview | `screen=overview` | `screens/Overview.js` | `getOverview` → `GET /overview`. Run all: `RunChecksButton` (`getChecks`, then `runCheck('all')`) | `OverviewController::get_overview()` | Latest run per check (`amc_runs`), open findings by severity (`amc_findings`), `update_*` transients, `Registry`, schedule times |
| Updates | `screen=updates` | `screens/Updates.js` | `getUpdates` → `GET /updates`. Run check and polling: `RunNowButton` | `UpdatesController::get_screen()` | Latest runs of `updates.*`; CSV from the plugin and theme rows |
| Vulnerabilities | `screen=vulnerabilities` | `screens/Vulnerabilities.js` | `getVulnerabilities` → `GET /vulnerabilities?status=`. Accept risk: `acceptRisk` → `POST /findings/{id}/status` with `accepted` and a note | `VulnerabilitiesController::get_screen()` | `Providers::current()`, latest `vuln.match` run, `vuln.match` findings |
| Security | `screen=security` | `screens/Security.js` | `getSecurity` → `GET /security` | `SecurityController::get_screen()` | Latest `security.*` runs and `ssl.certificate`; unresolved findings of those four checks |
| Errors | `screen=errors` | `screens/Errors.js` | `getEvents` → `GET /errors`, plus `GET /findings?check_id=errors.*&status=unresolved` (joined in JS for finding and run ids). Resolve: `resolveEvent` → `POST /findings/{id}/status` `resolved`. Mute: `muteSignature` → `muted` or `open` | `ErrorsController::list_errors()`, `set_status()` | `amc_events` grouped by signature, daily counts for the sparkline, `errors.*` findings |
| Smoke checks | `screen=smoke` | `screens/Smoke.js` | `getSmoke` → `GET /smoke`. Add or edit URL: `saveSmokeUrl` → `getSettings` then `saveSettings` (`POST /settings` with `smoke_urls`). Run: `RunNowButton` | `SmokeController::get_screen()` | Latest `smoke.urls` run data and `smoke_urls` setting |
| Performance | `screen=performance` | `screens/Performance.js` | `getPerformance` → `GET /performance?days=` | `PerformanceController::get_screen()` | Latest `perf.*` runs; `perf.response` trend; slow queries from `amc_queries` |
| Inventory | `screen=inventory` | `screens/Inventory.js` | `getInventory` → `GET /inventory`. CSV export is client-side | `InventoryController::get_screen()` | Latest `inventory.*`, `hosting.platform` and `detect.*` runs |
| Runs | `screen=runs` (and `&run=<id>`) | `screens/Runs.js`, `components/FindingModal.js` | `getRuns` → `GET /runs`; `getRun` → `GET /runs/{id}`; rerun: `rerunRun` → `POST /runs/{id}/rerun`; poll: `getRunStatuses` | `RunsController` | `amc_runs` (including `data`), `amc_findings` by `run_id` |
| Changes | `screen=changes` | `screens/Changes.js` | `getChanges` → `GET /changes` (filters: type, source, since, until) | `ChangesController::list_changes()` | `amc_changes` |
| Reports | `screen=reports` | `screens/Reports.js` | `getReports` → `GET /reports`. `generateReport` → `POST /reports/generate`. `publishReport` → `POST /wp/v2/amc-reports/{id}` with `status: publish`. `getSavedFields` and `saveSavedFields` (`/saved-fields`). Edit opens `edit_url`; View opens `view_url` (null when not visible) | `ReportsController`, `ReportRestController` | `amc_report` posts and meta, `amc_saved_fields` option |
| Settings | `screen=settings` | `screens/Settings.js` | `getSettings` and `saveSettings` → `GET` and `POST /settings`. `getChecks` → `GET /checks` (schedule table). `getEngineers`, `grantAccess`, `revokeAccess` → `/access`. `getCandidateUsers` → `GET /wp/v2/users`. `getSavedFields` and `saveSavedFields` | `SettingsController`, `AccessController`, `ChecksController`, `ReportsController` | `amc_settings`, `amc_changes` (access rows), users with `amc_manage`, `amc_saved_fields` |
| Setup (wizard) | `screen=setup` | `screens/Setup.js` | `getSetup` → `GET /setup`. `saveSettings`, `getSavedFields`, `saveSavedFields`. On finish: `runCheck('all')` (when chosen) and `completeSetup` → `POST /setup/complete` | `SetupController`, `SettingsController`, `ReportsController` | `SetupDefaults` (site name, domain, admin email, engineer emails, detected host, SSL issuer), `amc_setup_completed` |

The Setup wizard has seven steps (`screens/Setup.js`): Welcome, Client and site, Hosting and backups, Domains, SSL and accessibility, Notifications, Report schedule, and Review. The Review step can queue all checks when the engineer chooses to.

Console behaviour that applies to every screen (from `docs/console.md` and the code):

- Status is never colour alone. `components/StatusBadge.js` shows an icon and a text label (Pass, Warning, Failed, Info).
- Every list is a `DataViews` table with search, filters, sorting, paging and CSV export. Cells starting with `=`, `+`, `@` are prefixed so spreadsheets do not run them.
- Check data is rendered generically by `components/CheckData.js`: scalars as a description list (formatted for dates, `_bytes`, `_ms`, `_pct`), arrays of objects as a table, nested objects as sections, and deeper levels as JSON. Dedicated columns come later.
- A route that returns 501 shows a "not available yet" notice. **No route returns 501 now** (section 1, gap 2).

### 9.3 The finding modal and the deep link

- `components/FindingModal.js` opens on `&finding=<id>` over any screen. It calls `getFinding` (`GET /findings/{id}`) and `getRun` (`GET /runs/{id}`, to show the run that produced it) and offers the status actions.
- Alert emails link to `?page=amc-copilot&screen=findings&finding=<id>`. There is no "findings" screen. `components/FindingRedirect.js` fetches the finding, replaces the URL with the screen for its check group, and keeps `finding=<id>` so the modal opens. The mapping is in `src/js/console/utils/findingScreen.js`: `vuln.*` goes to Vulnerabilities, `errors.*` to Errors, `smoke.*` to Smoke checks, `security.*` and `ssl.*` to Security, `perf.*` to Performance, `updates.*` to Updates, `inventory.*`, `detect.*` and `hosting.*` to Inventory, and `users.*` and `health.*` to Overview.

### 9.4 Dashboard widget

- **Where.** The WordPress Dashboard, shown only to users with `amc_manage` (`Admin\DashboardWidget::register()`, `inc/Admin/DashboardWidget.php`).
- **Data.** One internal REST dispatch, `rest_do_request( GET /amc/v1/overview )`, cached in the transient `amc_dashboard_overview_{blog_id}` for 300 seconds. The cache is deleted on `amc_run_completed`.
- **Shows.** The overall status (icon and text), a table of open findings by severity, the time since the last run, and a button to the console. A notice shows when the kill switch is on.
- **Styles.** `Brand::enqueue_fonts()` and `css/admin` (section 14).

### 9.5 Setup notice and redirect

- **Notice.** `Admin\SetupNotice` shows a dismissible info notice on the Plugins screen and the console, until the wizard is finished or the user has closed it. It is not shown on the wizard screen itself, and only to users who can run the wizard (`SetupState::can_run()`: `amc_manage` and `manage_options`).
- **Dismiss.** `src/js/setup-notice.js` posts to `POST /amc/v1/setup/dismiss` when the core close button is clicked. It calls `apiFetch()` without a nonce of its own; that works because WordPress core attaches its nonce middleware to the `wp-api-fetch` script in wp-admin, so the request carries `X-WP-Nonce` and passes the route's `amc_manage` check.
- **Redirect.** `Admin\SetupRedirect`: on activation, the activating user is stored in the transient `amc_activation_redirect` for 60 seconds. On the next `admin_init`, that user is redirected once to `?page=amc-copilot&screen=setup`. Bulk activation, network admin, cron, AJAX, REST and WP-CLI requests do not redirect.

### 9.6 Client "Maintenance Reports" list and report view

- **Menus.** `Reports\ClientView::register_menu()` (`inc/Reports/ClientView.php`, line 159). Users with `amc_manage` get a "Client view" submenu under the report post type (slug `amc-reports`). Users with only `amc_view_reports` (client administrators) get a top-level "Maintenance Reports" menu with the same slug.
- **List.** `render_list()` (line 276) runs a `WP_Query` for published reports only.
- **Report view.** `render_report()` (line 321) checks `ClientView::visible_report()`: a published report for anyone with `read_amc_reports`, or a draft only for `amc_manage` users who can edit it, marked as a draft preview. It renders the post content's section blocks (`render_blocks()`, line 407), so the view matches the editor. The cover shows the rtCamp logo, the report title, the site name and host, and a facts row (period, report id, prepared by, issued); the footer reads "Prepared by rtCamp for <site>. Confidential." with the report id. The toolbar (screen only, `amc-no-print`) has **All reports**, **Edit report** (only for users who can edit the post), **Download PDF**, and a note saying to choose "Save as PDF" in the print dialog. The button sets the document title to the report id and calls `window.print()`. **There is no server-side PDF.**
- **Hooks.** `amc_report_view_enqueue` (print stylesheet and block styles), `amc_report_view_toolbar`, `amc_report_view_content` (filter) and `amc_report_logo_url` (filter, default `assets/images/rtcamp-logo.svg`).
- **Styles.** `src/css/report-view.scss` (screen) and `src/css/report-print.scss` (print, `@page { size: a4 }`).
- **Indexing.** REST responses for `/wp/v2/amc-reports` and `/amc/v1/reports` get `X-Robots-Tag: noindex, nofollow` (`ReportPostType::noindex_headers()`). Reports are never shown on the front end.

### 9.7 Report block editor

- **Where.** The report's edit screen (`post.php?post=<id>&action=edit`). Each section is a block: `src/blocks/section-<slug>/` with `block.json`, `index.js` and `edit.js`. The shared helpers are in `src/blocks/_shared/`.
- **Data.** `_shared/use-snapshot-section.js` reads `_amc_snapshot` from the core entity record for the post (`core` data store, `getEditedEntityRecord`), which the REST route exposes because the meta has `show_in_rest`. It takes `sections[<section>]` from the JSON.
- **Attributes.** Each block stores `lock` (move and remove, both true), `hidden`, `overrides` (field to value, for auto and saved fields) and `engineer` (field to HTML, for engineer fields). These live in the post content.
- **Engineer fields.** Edited inline with `RichText` (`_shared/engineer-field.js`), allowing bold, italic and links.
- **Rendering.** The PHP block class renders the same data on the published view (section 10.6), so the editor and the view match.

### 9.8 Email surfaces

Email is covered in section 7 (routing, contents and recipients). The console does not show the emails, but the `POST /notify/test` route sends samples from the Settings screen.

---

## 10. Reports pipeline

### 10.1 Periods and schedules

`Reports\Period` (`inc/Reports/Period.php`) is a value object: type (`month`, `quarter`, `year` or `custom`), start, end and label. `Reports\Periods` (`inc/Reports/Periods.php`) does the date maths in site time.

| Type | Covers | Scheduled at (site time) | Notes |
| --- | --- | --- | --- |
| `month` | The previous calendar month | 1st of each month, 06:00 (`MONTHLY_RUN_MINUTE` 0) | `Periods::previous_month()` |
| `quarter` | The previous quarter, following the contract start | 1st day of each contract quarter, 06:15 (`QUARTERLY_RUN_MINUTE` 15) | Anchored to `contract_start`, or the calendar when it is empty |
| `year` | The previous 12 months, following the contract start | Contract anniversary, 06:30 (`ANNUAL_RUN_MINUTE` 30) | Calendar year when no contract start is set |
| `custom` | Any date range the engineer chooses | On demand | `Periods::custom()`. Needs both dates |

The run hour is `RUN_HOUR` 6. `Periods::next_run()` gives the next instant, and `is_due()` says whether a run is due. The `custom` type gets a label such as "1 Oct 2026 – 31 Oct 2026".

Quarterly, annual and custom periods longer than 62 days also get `totals` and `monthly` (section 10.5).

### 10.2 Report IDs

`Period::report_id( $site_code )` (`inc/Reports/Period.php`, line 137) builds `AMC-<code>-<suffix>`:

- `month`: `AMC-<code>-YYYY-MM`.
- `quarter`: `AMC-<code>-YYYY-Qn`.
- `year`: `AMC-<code>-YYYY` (or `YYYY-YY` across a year boundary).
- `custom`: `AMC-<code>-YYYYMMDD-YYYYMMDD`.

`Generator::site_code()` (line 152) derives `<code>` from the site name: the initials of up to six words, or the first five characters of a single word, falling back to the host name and then to `SITE`. The result is upper-cased and stripped to letters and digits. The `amc_report_site_code` filter can override it. Report ids are unique per site: `Generator::find_existing()` checks `_amc_report_id` before a new report is created.

### 10.3 Lifecycle

1. **Start.** One of three things: `POST /reports/generate` (the console), a scheduled event (`amc_report_monthly`, `amc_report_quarterly`, `amc_report_annual`), or `wp amc report generate`. The REST route and the schedule both call `Generator::resolve_period()` (`inc/Reports/Generator.php`, line 93), then `create()` (line 226).
2. **Create.** `create()` takes the lock `job:report-create:{report_id}`, checks for an existing report with that id (409 `amc_report_exists`), and inserts a **draft** `amc_report`. The post's content is the template: one comment-delimited block per section (`Generator::content()`). Meta is set: period, environment, report id, `_amc_build_status` `pending`, and an empty snapshot from `SnapshotSchema::empty_snapshot()` with period and site filled in. The title is "Website Maintenance Report: <period label>".
3. **Queue.** `queue_build()` (line 356) sets status `pending` and schedules `amc_report_build` for now, with the post id. The REST route returns 201 at this point. The build runs in the next WP-Cron run. The CLI builds in-process instead (`generate()`, line 325).
4. **Build.** `build()` (line 261) takes `job:report-build:{post_id}`, then calls `SnapshotBuilder::build()` (section 10.4), then `store_snapshot()` (line 516), which writes `_amc_snapshot`, `_amc_snapshot_taken_at` and status `ready`. On a build error the status becomes `failed` and the error is logged. The first successful build fires `amc_report_draft_ready` (section 7.5).
5. **Edit.** The engineer edits the draft in the block editor. Engineer fields, hidden sections, overrides and custom sections are kept in the post content and survive a regenerate.
6. **Regenerate.** `POST /reports/{id}/regenerate` rebuilds the snapshot (section 10.3, step 3 and 4). It is refused for a published report (409 `amc_report_published`). Auto fields are refreshed, and engineer fields and custom sections are kept.
7. **Publish.** Moving the post to `publish` (in the editor, or `POST /wp/v2/amc-reports/{id}`) fires `ReportPublishing::on_transition()` (`inc/Reports/ReportPublishing.php`, line 56). It runs `SavedFields::write_back()` (copies the changed saved values back to `amc_saved_fields`), then `amc_report_published`, which sends the client emails once (section 7.5).

### 10.4 SnapshotBuilder: where each section comes from

`Reports\SnapshotBuilder::build()` (`inc/Reports/SnapshotBuilder.php`, line 161) does the following:

1. `refresh()` (line 240) runs the ten `FRESH_CHECKS` synchronously through `Runner::run()`, with trigger `cron`: `inventory.plugins`, `inventory.themes`, `inventory.mu_dropins`, `inventory.editor`, `hosting.platform`, `detect.cdn`, `detect.forms`, `detect.mailer`, `detect.analytics` and `detect.accessibility`. This makes the plugin list in the change log complete, because `inventory.plugins` logs deploy-time changes before the change log is read. Any check that is not registered is recorded as `skipped`.
2. Reads the period's change rows (`amc_changes`), events (`amc_events`) and vulnerability findings (`amc_findings`).
3. Builds each section (table below).
4. Fills saved fields with `SavedFields::for_new_report()` (section 10.7).
5. For long periods, adds `totals` and `monthly`.
6. Applies the `amc_report_snapshot` filter, and returns the array.

`latest_data( $check_id )` reads the **latest** result-status run of a check, whatever its date. Only the periodic lists (smoke, response trend, changes, events and vulnerabilities) are restricted to the period.

| Section | Source in code | Notes |
| --- | --- | --- |
| `overview` | `SiteFacts` (home URL, site name), author from `default_author()`, the report date | |
| `cms` | `amc_changes` (type `core`, action `update`) for the version at the period start and end; `SiteFacts::editor_type()`, `is_multisite()` | Editor type is live, not from `inventory.editor` |
| `theme` | `SiteFacts::active_theme()` (live), parent theme | |
| `plugins_themes` | `amc_changes` rows of type `plugin` and `theme` in the period, split into `updated`, `added` and `removed` (row for row, with source); `SiteFacts::plugins()` and `themes_installed()` (live) for `counts` and the `inactive` list | The lists are the change log, not the inventory check |
| `user_management` | `SiteFacts::user_counts()`, `users_registered()` (period), `administrators()` (live); `amc_changes` type `user`: `install` gives `users_added`, `delete` gives `users_removed` | |
| `hosting` | Latest `hosting.platform` data (`host`, `server_type`/`os`, `web_server`/`server_software`, `mysql_version`/`db_version`, `php_version`, `memory_limit`), falling back to `SiteFacts`. `php_recommendation` from `SiteFacts` | `host` is null when not detectable |
| `domains` | Host of the home URL (live) | |
| `ssl` | Latest `ssl.certificate` run (`issuer`, `expires_at` or `valid_to`, `days_left`). `https` is from the home URL | |
| `caching_cdn` | Latest `perf.cache` run (`page_cache`, `object_cache`), latest `detect.cdn` run (`cdn`) | |
| `security` | `vulnerabilities`: `vuln.match` findings that overlap the period (`SnapshotBuilder::vulnerabilities()`). `core_checksums`: latest `security.checksums` run. `config_checks`: latest `security.config` run | Each vulnerability has `status`, `fixed_in`, `first_seen`, `resolved_at` |
| `forms` | Latest `detect.forms` run (`form_plugins`, `integrations`) and `detect.mailer` run (`sending_method`) | |
| `analytics` | Latest `detect.analytics` run (`gtm_ids`, `ga_ids`), with GA4 and UA merged in | |
| `accessibility` | Latest `detect.accessibility` run (`third_party_tool`, `statement_page`) | |
| `php_errors` | `amc_events` for the period: counts by type (fatal; warning and notice; deprecation and doing_it_wrong) and the top signatures (at most 10) | Counts from `EventsRepository::counts_by_type()` |
| `smoke_checks` | Every `smoke.urls` run in the period: runs, pass rate and average response per URL. `loopback_blocked` results are excluded | |
| `performance` | Latest `perf.database` run (`database_size_bytes` or `total_size_bytes`; `autoload_size_bytes`, with a `SiteFacts` fallback). `response_trend` from the `perf.response` runs in the period | Weekly points for monthly reports, monthly points for longer ones |
| `engineer_notes` | Nothing (engineer only) | |

### 10.5 Snapshot format and field sources

The snapshot is stored in `_amc_snapshot` as JSON. The format is frozen (`docs/report-snapshot.md`; the class is `inc/Reports/SnapshotSchema.php`, with `VERSION = 1` at line 67).

```json
{
  "schema_version": 1,
  "taken_at": "2026-11-01T06:00:00Z",
  "period": { "type": "month", "start": "2026-10-01", "end": "2026-10-31" },
  "site": { "url": "https://example.com", "name": "Example", "blog_id": 1, "environment": "production" },
  "sections": { "overview": { … }, "cms": { … }, … },
  "totals": { … },
  "monthly": [ { "label": "…", "start": "…", "avg_response_ms": 0, "database_size_bytes": 0, "autoload_size_bytes": 0, … } ]
}
```

`totals` and `monthly` appear only for quarterly, annual and custom periods over 62 days. Monthly entries carry the month's performance figures, and a month with no matching run holds `null` ("No data"), never zero.

Every field has a **source**, set in `SnapshotSchema::field()`:

- **auto**: from the snapshot (`SnapshotBuilder`). About 60% of fields.
- **saved**: carried forward from `amc_saved_fields`, or from the latest published report for the same environment when the saved value is empty (`SavedFields::for_new_report()`). About 20%.
- **engineer**: typed in the editor per report. Never in the snapshot. Empty at generation.

The value a block shows is the first that exists: `overrides[field]` (set by the engineer), then `sections[section][field]` in the snapshot, then `null`, which renders as "—". An overridden field shows an "Edited" badge in the editor only.

The field catalogue (keys, sources and types) is in `SnapshotSchema::sections()` (line 85). The section keys are: `overview`, `cms`, `theme`, `plugins_themes`, `database_backups`, `user_management`, `hosting`, `domains`, `ssl`, `caching_cdn`, `security`, `forms`, `analytics`, `accessibility`, `php_errors`, `smoke_checks`, `performance` and `engineer_notes`. Adding a field is allowed. Renaming, retyping or removing one needs a `VERSION` bump and a migration (`docs/report-snapshot.md`).

Field summary (auto, saved, engineer), from the schema:

| Section | Auto | Saved | Engineer |
| --- | --- | --- | --- |
| overview | `website_url`, `developer_name`, `report_date` | `client_name`, `maintenance_schedule` | `hours_used` |
| cms | `wp_version_old`, `wp_version_new`, `editor_type`, `is_multisite` | `websites_covered` | |
| theme | `active_theme`, `parent_theme` | `theme_origin` | |
| plugins_themes | `counts`, `updated`, `added`, `removed`, `inactive` | | `change_reasons` |
| database_backups | | `backup_method`, `backup_schedule`, `backup_retention` | `backups_working`, `latest_backup` |
| user_management | `total_users`, `users_per_role`, `administrators`, `users_added`, `users_removed` | | |
| hosting | `host`, `server_type`, `web_server`, `mysql_version`, `php_version`, `memory_limit`, `php_recommendation` | `hosting_type`, `control_panel`, `server_backup_frequency`, `server_backup_retention`, `redundancy`, `account_owners`, `hosting_users` | `disk_space`, `server_backups_running`, `latest_server_backup` |
| domains | `primary_domain` | `domain_expiry`, `auto_renewal`, `subdomains` | |
| ssl | `https`, `issuer`, `expires_at`, `days_left` | `ssl_type`, `ssl_auto_renewal` | |
| caching_cdn | `page_cache`, `object_cache`, `cdn` | | `configured_correctly` |
| security | `vulnerabilities`, `core_checksums`, `config_checks` | | `remediation_notes`, `malware_scan` |
| forms | `form_plugins`, `sending_method`, `integrations` | | `entries_saving`, `notifications_confirmed` |
| analytics | `gtm_ids`, `ga_ids` | | `tag_assistant` |
| accessibility | `third_party_tool`, `statement_page` | `defined_standard` | `wave_result` |
| php_errors | `fatal_count`, `warning_count`, `deprecation_count`, `top_signatures` | | |
| smoke_checks | `urls` | | |
| performance | `response_trend`, `database_size_bytes`, `autoload_size_bytes` | | |
| engineer_notes | | | `note` |

The disclaimers for backups, hosting, domains and accessibility are part of their blocks and can be changed per site in Settings (`disclaimers`).

### 10.6 Section blocks

- **PHP.** `Reports\Blocks\AbstractSectionBlock` (`inc/Reports/Blocks/AbstractSectionBlock.php`) is the base. Each of the 18 subclasses (one per section) defines `section_key()` and `render_section( SectionData $data )`. The class list is `SectionBlocksModule::get_classes()`. The block name is `amc/section-<slug>`, with underscores turned into hyphens (for example `amc/section-plugins-themes`). The block's build folder is `assets/build/blocks/section-<slug>`.
- **Rendering.** `render()` (line 119) returns nothing when `hidden` is set. Otherwise it loads the snapshot for the post, and returns `<section class="amc-section amc-section--<slug>"><h2 class="wp-block-heading">{title}</h2>…</section>`. `SectionData` (`inc/Reports/Blocks/SectionData.php`) resolves each value through the precedence in section 10.5. `engineer()` returns `wp_kses_post()` output.
- **Attributes.** All section blocks share `lock` (default move and remove true), `hidden`, `overrides` and `engineer` (section 10.5).
- **Custom section.** `Reports\Blocks\CustomSectionBlock` (`amc/custom-section`) has a title, free rich text, and a `carryForward` attribute. The editor's "Carry forward" toggle sets it, but no PHP code reads it (section 1, gap 10).
- **Template.** The 18 section blocks are locked in the template, so an engineer can hide a section but cannot remove or reorder it. Custom sections can be inserted between them.

### 10.7 Saved fields

`Reports\SavedFields` (`inc/Reports/SavedFields.php`) stores `amc_saved_fields` (`{ section: { field: string } }`, at most 2,000 characters per value, autoload off). It is the carry-forward store.

- `for_new_report( $environment )`: returns the stored values. For any field that is empty, it takes the value from the latest published report with the same environment (`latest_published()`), through `effective_values()`.
- `write_back( $post_id )` (on publish): finds the saved fields the engineer changed in the report and saves them. `SavedFields::changes_for_post()` (`inc/Reports/SavedFields.php`, line 278) compares this report's effective values (`effective_values()`: carried-forward values plus the report's overrides) with the previous report's values (`previous_for_post()`), after `normalise()`, and returns `{ section, field, label, previous, current }` for each difference. `review()` returns the same list to the editor before publish.
- `review( $post_id )`: the previous values and the changes, shown to engineers in the snapshot response.
- `update( $changes )`, from `POST /saved-fields`: a partial update. `null` clears a field. Unknown keys are ignored.

The console's Settings screen and the Setup wizard both edit saved fields through `/saved-fields`.

### 10.8 Publishing, client view and PDF

- A published report is visible to client administrators through the Maintenance Reports menu (section 9.6). The view is read only and uses the same blocks as the editor.
- Save as PDF is the browser's print dialog. `src/css/report-print.scss` sets A4 page size, page breaks, the footer and the hiding of wp-admin chrome. The file is 2.3 KB, under the 5 KB limit in the plan.
- Reports are never on the front end and never indexed (section 9.6).

---

## 11. Vulnerability (CVE) provider

The vulnerability lane is built around one interface, so rtCamp's real provider can be plugged in later without changing the checks.

**Interface** (`inc/Vulnerability/ProviderInterface.php`, frozen):

- `id(): string` (for example `rtcamp-cve`, or `null` and `fixture` for the bundled ones).
- `is_ready(): bool`.
- `match( list<Component> ): list<VulnMatch>`.
- `last_error(): ?string` (added in Wave 0; shown on the Vulnerabilities screen; never throws).

**Value objects.** `Component( type, slug, version, name )` (type `core`, `plugin` or `theme`). `VulnMatch( component_type, component_slug, reference, title, Severity, cvss, affected_range, fixed_in, url, published_at )`. `VulnMatch::to_array()` is what the finding stores.

**Selection.** `Providers::current()` (`inc/Vulnerability/Providers.php`, line 35) applies the filter `amc_vulnerability_provider` to a `NullProvider` default. A value that is not a `ProviderInterface` is ignored.

- `NullProvider` (`id()` is `null`, not ready, no matches, no error). This is the default, so `vuln.match` reports INFO with "provider not ready" and nothing is judged.
- `FixtureProvider` (`inc/Vulnerability/FixtureProvider.php`) is for tests and development. It is registered on every site, but does nothing unless `AMC_COPILOT_FIXTURE_PROVIDER` is defined or the filter `amc_vulnerability_use_fixture` returns true. It is hooked at priority 20, so it overrides a provider set earlier. A string constant is a path to a PHP file that returns the entries. Otherwise the entries come from the filter `amc_vulnerability_fixture_data`. Each entry has `type`, `slug`, `affected` (a range), `fixed_in`, `reference`, `title`, `severity`, `cvss`, `url` and `published_at`. Range syntax is in `FixtureProvider::in_range()` (line 206): `*` or empty matches everything, and comma-separated constraints with `<`, `<=`, `>`, `>=`, `=` and `==` are compared with `version_compare()`.

**Matching.** `Vulnerability\Matcher` (`inc/Vulnerability/Matcher.php`) builds the component list from the installed core (`wordpress`), plugins (by directory slug) and themes (`installed_components()`), and calls the provider only when it is ready. A thrown exception or a `last_error()` is recorded as the outcome's `error`. Matches for components that are not installed are dropped, so a provider cannot invent components.

**Check.** `vuln.match` (`inc/Checks/Vulnerabilities/VulnMatchCheck.php`, group `vulnerabilities`, schedule twice daily) turns each match into a finding. The dedupe key is `{type}:{slug}:{installed_version}:{reference}`, so updating the component makes the match disappear and the finding resolve. Status: provider not ready gives INFO and partial; a provider error gives WARN and partial; no matches gives PASS; any HIGH or CRITICAL match gives FAIL; other matches give WARN. A partial result never resolves findings.

**Where matches appear.**

- The Vulnerabilities screen (`GET /vulnerabilities`), with the provider state, the last run, the findings, and counts by severity.
- The Overview's open findings by severity (all checks, including `vuln.match`).
- The report's Security section, as the period's vulnerabilities (`vulnerabilities`).
- Alerts: HIGH and CRITICAL matches are immediate, the rest go to the digest (section 7.1).
- Not on the Security screen: `SecurityController`'s open findings list covers only the four security and SSL checks.

**Accepted risk.** The console uses `POST /findings/{id}/status` with `accepted` and a required note (`acceptRisk()`). `POST /vulnerabilities/{id}/accept` does the same, and it is the route the contract names. Accepted findings keep matching, but they are not alerted and not counted as open.

---

## 12. Abilities API

`Abilities\AbilitiesModule` loads `AbilitiesRegistrar` (`inc/Abilities/AbilitiesRegistrar.php`). It registers the WordPress 6.9 Abilities API category `amc-copilot`, with the label "AMC Copilot" and a read-only description, and then one ability per item below. Each ability is read-only: its annotations are `readonly: true`, `destructive: false` and `idempotent: true`, and `show_in_rest` is true (`Abilities\ReadOnlyMeta`). Permission for every ability is `amc_manage`.

| Ability name | Source class | Input | Output |
| --- | --- | --- | --- |
| `amc-copilot/<check-id with dots turned into hyphens>` (32 abilities, for example `amc-copilot/updates-plugins`) | `CheckAbility` (one per registered check) | None | `{ check: { id, label, group, schedule }, run (latest run with data, or null), findings[] (unresolved findings of that check) }` |
| `amc-copilot/reports` | `ReportsAbility` | `status` (`any`, `draft`, `publish`), `page`, `per_page` | The `GET /amc/v1/reports` response, through `rest_do_request()` |
| `amc-copilot/report-snapshot` | `ReportSnapshotAbility` | `id` (report post id) | The `GET /amc/v1/reports/{id}/snapshot` response, through `rest_do_request()` |

The check abilities read the same data as the REST routes (`RunsRepository::latest()` and `FindingsRepository::unresolved_for_check()`). The two report abilities re-use the REST controllers, so their permissions and drafts rules apply.

---

## 13. Security model

### 13.1 Capability matrix

| Surface or route | Check | Capability |
| --- | --- | --- |
| Console (page, enqueue), Dashboard widget, abilities, all `amc/v1` routes not listed below | `manage` | `amc_manage` |
| `POST /settings`, `GET`, `POST`, `DELETE /access` | `manage_settings`, `manage_access` | `amc_manage` and `manage_options` |
| `GET /setup`, `POST /setup/complete`, `POST /setup/dismiss`, and the setup notice | `manage_setup` | `amc_manage` and `manage_options` |
| `GET /reports`, `GET /reports/{id}/snapshot` | `view_reports` | `amc_manage` or `amc_view_reports`. Drafts and saved-field review are for `amc_manage` only |
| Core `/wp/v2/amc-reports` (every read) | `ReportRestController::can_read_reports()` | `read_amc_reports` (derived from either capability) |
| Client view page | `ClientView::render()` | `read_amc_reports`. Drafts need `amc_manage` and the edit capability |
| Report edit and publish | `map_caps()` | `*_amc_reports`, granted to `amc_manage` |
| Saved fields (`GET`, `POST`) | `manage` | `amc_manage` |

### 13.2 Nonces and authentication

- REST requests use WordPress cookie authentication with the `wp_rest` nonce. The console sends the nonce through `apiFetch.createNonceMiddleware()`, with `window.amcCopilot.restNonce` created by `wp_create_nonce( 'wp_rest' )` in `ConsoleAssets::config()`.
- The plugin has no `admin-post` handlers, form nonces or AJAX endpoints. Every write goes through REST (or WP-CLI), so `wp_verify_nonce` is not needed. The setup notice's dismiss call is a REST request (section 9.5).

### 13.3 Input

- REST argument schemas (`type`, `enum`, `minimum`, `maximum`, `required`, `pattern`, `minLength`, `maxLength`, `format`) validate on every route.
- Sanitisers: `sanitize_textarea_field` for notes, `esc_url_raw` for smoke URLs, `sanitize_text_field` for required text, and `sanitize_textarea_field` for the accepted-risk note.
- `Settings::sanitize()` drops unknown keys, validates the enums and the time format, uses `sanitize_email()` and `is_email()` for addresses, `esc_url_raw()` for URLs, `sanitize_text_field()` for text, and `wp_kses_post()` for disclaimers.
- `SavedFields::sanitize()` caps each value at 2,000 characters.
- Read-only query arguments in PHP (for example the setup notice's `screen`, and the bulk activation checks in `SetupRedirect`) are read with `sanitize_key( wp_unslash() )` and carry phpcs ignore comments.

### 13.4 Output

- PHP output uses `esc_html`, `esc_attr`, `esc_url` and `wp_kses_post` (section blocks, the Dashboard widget, the client view).
- Engineer fields are HTML from the editor and pass through `wp_kses_post()` in `SectionData::engineer()`.
- Email HTML escapes every value (`EmailRenderer`), and the plain-text part is passed through `wp_strip_all_tags()`.
- The console renders text through React, which escapes by default.

### 13.5 Secrets and personal data

- The loopback password is stored encrypted (`Core\Encryption`) and only when `AMC_COPILOT_ENCRYPTION_KEY` is defined in `wp-config.php`. Without the key, the password is not stored, and `GET /settings` reports `encryption_available: false`. Basic-auth credentials are sent only to URLs on the site's own host.
- `security.config` stores a verdict for each salt, never the salt value (`ConfigCheck` docblock).
- The SSL check turns off certificate verification on purpose, so that an expired or mismatched certificate is read and reported rather than refused. It stores only the certificate's facts.
- Capture stores normalised SQL (literals replaced) and argument-free backtraces.
- `amc_changes` records code changes (`Capture\ChangeLog`, `Capture\DeployDiff`), user changes (`Checks\Users\UserChanges`) and console access grants and revocations (`Core\Capabilities`, type `user`, action `access_grant` / `access_revoke`). Settings changes and report publishing are **not** recorded there; publishing is visible only through the post's own status, date and revisions.

### 13.6 Multisite

- Every row has `blog_id`. Repositories default to the current site, and `RunsController` and `FindingsController` return 404 when a row belongs to another site (`get_current_blog_id() !== $row['blog_id']`).
- `Runner::with_blog()` switches to the target blog for a run, and restores afterwards. Checks never call `switch_to_blog()` (`Check.php` rule 2). `Storage\AbstractRepository::blog_id()` falls back to `get_current_blog_id()`.
- Tables use `base_prefix`, so there is one set per network.
- Super admins do not get `amc_manage` automatically (section 1, gap 11).

---

## 14. Build and front-end assets

### 14.1 Toolchain

- **Node** `^24.22.1 || >=26.12.1` (`package.json` `engines`). **PHP** 8.1 or later, Composer, PSR-4 `AMC\` to `inc/`.
- **Build** with `@wordpress/scripts` and two custom webpack configurations: `webpack.config.js` (scripts and styles) and `webpack.blocks.config.js` (blocks, wrapping `wp-scripts`). A BrowserSync HMR server runs in the local environment when `ENABLE_HMR` is on (`.env.local`).
- **Output** goes to `assets/build/` (gitignored, built in CI and at release). It is **not present in this worktree** (`ls assets` shows only `fonts/` and `images/`), so the bundle contents cannot be checked here.

### 14.2 npm scripts that matter

| Script | Runs |
| --- | --- |
| `build:dev`, `build:prod` | Every `build:*` script (except dev and prod), with `NODE_ENV` set |
| `build:assets` | `wp-scripts build --config ./webpack.config.js` (JS and CSS entries) |
| `build:blocks` | `wp-scripts build` over `src/blocks/` into `assets/build/blocks/` |
| `start`, `start:assets`, `start:blocks` | Watch mode, with HMR for blocks |
| `lint` | CSS (stylelint), JS (ESLint), PHP (`vendor/bin/phpcs`) and `package.json` |
| `test:js` | Jest (`tests/js/jest.config.js`) |
| `test:php` | PHPUnit under `wp-env` (`.wp-env.tests.json`) |
| `test:e2e` | Playwright (`playwright.config.js`, `tests/e2e/`) |
| `init`, `sync-ai`, `create:block`, `release:*` | Project setup, AI tooling sync, block scaffolding and release tooling |

Composer scripts (from the plugin folder): `composer lint` (PHPCS), `composer format` (phpcbf), `composer phpstan` (level 5).

### 14.3 Entry points

**Scripts** (`webpack.config.js`, `scripts`). Every `.js`, `.jsx`, `.ts` or `.tsx` file under `src/js` becomes an entry (folders `modules` and `console` are excluded from the walk), and `console` is added as `src/js/console/index.js`. Output is `assets/build/js/<name>.js`.

| Entry | Source | Enqueued by |
| --- | --- | --- |
| `console` | `src/js/console/index.js` (the whole app) | `Admin\ConsoleAssets`, on the console page only, for `amc_manage` |
| `setup-notice` | `src/js/setup-notice.js` | `Admin\SetupNotice`, only where the notice shows |
| `admin` | `src/js/admin.js` (a placeholder, 239 bytes) | Registered by `Core\Assets` on every admin page, **not enqueued** |

**Styles** (`src/css/*.scss`, underscore-prefixed partials excluded). Output is `assets/build/css/<name>.css`.

| Entry | Source | Enqueued by |
| --- | --- | --- |
| `admin` | `src/css/admin.scss` (uses `brand`) | The Dashboard widget (`amc-admin-brand`), and the client view's list page |
| `report-view` | `src/css/report-view.scss` (uses `brand`) | The client view (screen) |
| `report-print` | `src/css/report-print.scss` (A4 print rules) | The client view (print) |
| `console` | `src/js/console/console.scss` (imports DataViews and `brand`; about 12 KB, layout only) | `ConsoleAssets` with `wp-components` |

**Blocks** (`src/blocks/section-*`, `custom-section`). Built by `build:blocks` into `assets/build/blocks/`. Each block's PHP class registers it from there. `_shared/` is for helpers, not a block.

### 14.4 Brand stylesheet and fonts

- `src/css/_brand.scss` mirrors the constants in `inc/Core/Brand.php`: ink `#1c1c1c`, muted `#565656`, rule `#e5e3dc`, cream `#f9f8f2`, and the rtCamp red `#c63c51` (accents only, never status). The body font is Reddit Sans, with a system fallback stack.
- `Core\Brand::font_face_css()` inlines the `@font-face` rules for `assets/fonts/reddit-sans-latin.woff2` and `reddit-mono-latin.woff2` (SIL OFL 1.1), so wp-admin makes no third-party request. `Brand::enqueue_fonts()` adds them, and is called by the console, the Dashboard widget, the report editor and the client view.
- PHP email uses the same constants inline (`EmailRenderer`), because email cannot use a stylesheet.

### 14.5 What enqueues on which screen

| Screen | Scripts | Styles |
| --- | --- | --- |
| Console (`toplevel_page_amc-copilot`, `amc_manage`) | `console` (with `window.amcCopilot`, translations) | Brand fonts; `console`, with `wp-components` |
| Dashboard | None | Brand fonts; `admin` |
| Plugins screen and console, while the notice shows | `setup-notice` | Core notice styles only |
| Report editor (`amc_report`, block editor) | Section blocks (their `editorScript`) | Brand fonts (`enqueue_block_assets`), section block editor styles |
| Client view (`amc-reports`) | `report-view` (script for the print button) | `report-view`; `report-print` for print; `admin` for the list |
| Front end | Nothing | Nothing (`Core\Assets` note: no front-end assets) |

The plan's performance budget applies: nothing runs on the front end, and the console loads only on its own screens.

---

## 15. Install, upgrade, uninstall

### 15.1 Activation

`Core\PluginSetup::activate()` (`inc/Core/PluginSetup.php`, line 43):

1. `Installer::install()`: `dbDelta()` for the seven tables (`Installer::schema()`), `add_option( 'amc_settings', defaults, '', false )`, then `update_option( 'amc_schema_version', '1', false )`.
2. `Capabilities::on_activation()`: adds `amc_view_reports` to the `administrator` role. If no engineer exists yet, it grants `amc_manage` to the activating user.
3. `Scheduler::schedule()`: the five-minute tick.
4. `update_option( 'amc_version', '0.1.0', false )`.
5. `do_action( 'amc_activate' )`. Listeners: `Retention::schedule()`, `Digest::on_activate()`, `ReportSchedules::schedule_all()`, `SetupRedirect::queue()`.
6. A log line.

### 15.2 Deactivation

`PluginSetup::deactivate()` (line 61): `Scheduler::unschedule()` (clears the tick and the queue event), then `do_action( 'amc_deactivate' )`. Listeners: `Retention::unschedule()`, `Digest::unschedule()`, `ReportSchedules::unschedule_all()` (also clears `amc_report_build` events), and `Throttle::reset()` (deletes `amc_notify_sent_times`). **Data is kept.**

### 15.3 Upgrade

- There are no migration scripts. `Installer::maybe_upgrade()` (line 66) compares `amc_schema_version` with `SCHEMA_VERSION` (`'1'`). On a mismatch it runs `install()` again. `dbDelta()` adds missing columns and indexes, but never drops them (the docblock's rule). It is hooked only on `admin_init` (`inc/Core/CoreModule.php`, line 30), so it runs on the next wp-admin page load after an update: **not** under WP-CLI, WP-Cron or the front end. After deploying a schema change with WP-CLI alone, open any wp-admin page (or call `AMC\Core\Installer::maybe_upgrade()` with `wp eval`) before relying on new columns.
- It runs on `admin_init` (`Core\CoreModule`), on every scheduler tick (`Scheduler::tick()`), and in WP-CLI, never on a front-end request.
- The version bump rules are in `Installer`'s docblock: bump `SCHEMA_VERSION`, edit the `CREATE TABLE` statement, and update `docs/CONTRACTS.md`.
- `amc_version` is not updated on upgrade (section 1, gap 6). A plugin update does not run the activation hook, so the stored version can be stale until the plugin is reactivated.

### 15.4 Uninstall

`uninstall.php` runs when WordPress deletes the plugin. It returns early unless `WP_UNINSTALL_PLUGIN` is defined. It then:

1. Loads `vendor/autoload.php` and defines `AMC_COPILOT_FILE` if missing.
2. `Scheduler::unschedule()`, and `do_action( 'amc_deactivate' )`. In the uninstall context no listeners are attached (section 1, gap 7), so this line is effectively a no-op. WordPress normally deactivates the plugin before deleting it, which runs the same listeners.
3. If `amc_delete_reports_on_uninstall` is set, deletes every `amc_report` post in batches of 100, forced, across all post statuses.
4. `Capabilities::remove_all()`: removes `amc_view_reports` from `administrator`, and `amc_manage` from every engineer found (limit 200, gap 8).
5. `Installer::uninstall()`: drops the seven tables, and deletes `amc_schema_version`.
6. Deletes the options `amc_settings`, `amc_saved_fields`, `amc_version`, `amc_delete_reports_on_uninstall` and `amc_setup_completed`.
7. Deletes the transient `amc_activation_redirect`, and the user meta `amc_setup_notice_dismissed` for all users.

| Removed | Kept |
| --- | --- |
| The seven tables and their data | `amc_report` posts, unless `amc_delete_reports_on_uninstall` was ticked |
| `amc_settings`, `amc_saved_fields`, `amc_version`, `amc_schema_version`, `amc_delete_reports_on_uninstall`, `amc_setup_completed` | Report post meta on kept reports (`_amc_snapshot` and so on) |
| `amc_view_reports` from the administrator role; `amc_manage` from engineers | The `amc_notify_sent_times` option, if deactivation did not already delete it (gap 7) |
| The `amc_activation_redirect` transient and the notice dismissal user meta | The dashboard transient, which expires within five minutes |

On a network install, options and reports are removed only for the site the uninstall runs on; the shared tables are dropped for all sites (gap 14).

---

## 16. Glossary

| Term | Meaning in this codebase |
| --- | --- |
| **Run** | One execution of one check, one row in `amc_runs`. A run can be `queued`, `running`, one of four result statuses (`pass`, `warn`, `fail`, `info`) or `error`. The console calls the run's row "the run". |
| **Check** | A class implementing `Checks\Check`. It reads the site and returns a `Result`. It never writes to the site. |
| **Check id** | A stable name in the form `group.name`, for example `updates.plugins`. It never changes. The dedupe keys and the abilities are built from it. |
| **Group** | One of 11 categories a check belongs to (`updates`, `vulnerabilities`, `errors`, `smoke`, `security`, `performance`, `health`, `inventory`, `users`, `hosting`, `detection`). The Overview shows one status per group. |
| **Schedule** | How often a check runs by default: `hourly`, `twicedaily`, `daily`, `weekly`, or `event` (on demand only). The per-site override is an interval in settings. |
| **Effective schedule** | The override from `amc_settings` if one is set, otherwise the check's default. |
| **Result** | What `Check::run()` returns: status, summary, data, findings and `complete`. |
| **Partial result** | A result from one batch of a long check. It never resolves findings, because the other batches have not run yet. |
| **Cursor** | A position kept in a check's `data` so the next run resumes a batched job. |
| **Catch-up once** | A check that missed several intervals runs once, not once per missed interval. |
| **Finding** | An issue that needs an engineer's action, one row in `amc_findings`, identified by check id and dedupe key. |
| **Dedupe key** | The identity of a finding within its check (for example `plugin:<file>:<new_version>`). A changed key is a new finding. |
| **Severity** | `info`, `low`, `medium`, `high` or `critical`. It decides how a finding is routed and shown. |
| **Finding status** | `open` (counted and alerted), `accepted` (accepted risk, with a note), `muted` (silenced, not alerted) or `resolved` (the issue went away). |
| **Resolved and comeback** | A finding is resolved when the check no longer reports it. If it comes back, it is a new row, so it can alert again. |
| **Alerted** | `alerted_at` is set once an immediate alert or a digest has included the finding. |
| **Immediate, digest, none** | The three alert routes (section 7.1). |
| **Held back** | An immediate alert that the hourly throttle stopped. It goes into the next digest. |
| **Throttle** | The limit of immediate emails per hour (`immediate_alerts_per_hour`, default 10). |
| **Kill switch** | Stops capture, runs, queued work, alerts and report generation. The constant `AMC_COPILOT_DISABLED` wins over the setting. |
| **Lock** | A row in `amc_locks` that stops two processes running the same job. |
| **Queue** | The `queued` rows in `amc_runs`, drained by `amc_process_queue` and the scheduler tick. |
| **Snapshot (inventory)** | A row in `amc_snapshots` (kinds `plugins`, `themes`, `roles`, `admins`). It is the baseline for the next run's diff. |
| **Snapshot (report)** | The JSON in a report's `_amc_snapshot` meta. It holds every auto and saved value for one report. |
| **Report snapshot `taken_at`** | The UTC time of the report's last build. |
| **Period** | The date range a report covers: month, quarter, year or custom, in site time. |
| **Contract start** | The date that quarters and years follow (`contract_start`). Empty means the calendar. |
| **Report id** | The human reference for a report, for example `AMC-NWT-2026-10`. |
| **Site code** | The short code in a report id, from the site name (section 10.2). |
| **Draft and publish** | A draft is editable by engineers only. Publishing makes the report visible to client administrators, and sends the published email once. |
| **Build status** | `pending`, `ready` or `failed`: the state of the latest snapshot build. |
| **Auto field** | A value the generator fills from the snapshot. |
| **Saved field** | A stable fact (for example the client's name) carried forward from the latest published report for the same environment. |
| **Engineer field** | A value typed per report. Never in the snapshot. |
| **Override** | An engineer's replacement for an auto or saved value, kept in the block's `overrides`. |
| **Section block** | One of the 18 locked blocks in a report, one per snapshot section. Each renders from the snapshot and its attributes. |
| **Custom section** | A free-form block an engineer can insert anywhere. |
| **FRESH_CHECKS** | The ten inventory and detection checks run immediately before every snapshot build. |
| **Change log** | The `amc_changes` table: code, user and access changes, with their source. |
| **Source (change)** | Where a change came from: `admin` (wp-admin), `cli` (WP-CLI), `deploy` (found by the diff, so a git or file deploy) or `auto` (background updates). |
| **Deploy diff** | The comparison of the plugin snapshot with the last run, which logs changes that no hook recorded. |
| **Event (error)** | An `amc_events` row: one signature in one hour, with a count. |
| **Signature** | The md5 identity of an error (type, file, line and normalised message), or of a slow query (SQL and caller). |
| **Sampled query** | A query captured on one request in 100, if it took 100 ms or more. |
| **Engineer** | A user with `amc_manage`. Full console access, and report editing and publishing. |
| **Client administrator** | An administrator with `amc_view_reports`. Read-only access to published reports. |
| **Ability** | A WordPress Abilities API registration. The plugin registers each check and two report actions, read only. |
| **Fixture provider** | The bundled test source for CVE matches, enabled only by constant or filter. |
| **NullProvider** | The default CVE provider, which returns no matches and is not ready. |
| **Component** | An installed core, plugin or theme, as the CVE matcher sees it. |

---

## How to keep this document current

1. **Check list.** When a check is added, changed or removed, update section 4 (the identity and the data tables), section 5.5 if it takes a new lock, and section 7.1 if its alert route changes. The provider files are `inc/Checks/Providers/`.
2. **Routes.** When a route is added or its permission changes, update section 8, section 13.1 and `tests/php/Rest/RoutesTest.php::ROUTES`. Check the controllers in `inc/Rest/` and `RestModule::get_classes()`.
3. **Tables and options.** When `Installer::schema()`, `Settings::defaults()` or any `update_option` / `add_option` / `get_option` call changes, update section 3. Run `grep -rn "update_option\|add_option\|get_option\|delete_option" inc` to find them all, and `grep -rn "Repository" inc` for the readers and writers.
4. **Hooks.** The list of fired hooks is in section 14 of `docs/CONTRACTS.md`. Any new `apply_filters` or `do_action` in `inc/` belongs there, and in the relevant section of this file. Regenerate the list with `grep -rhoE "(apply_filters|do_action)\( *'[a-z_]+'" inc`.
5. **Cron.** Any `wp_schedule_event`, `wp_schedule_single_event` or `wp_clear_scheduled_hook` change goes in section 5.4, and in section 15 if activation or deactivation changes.
6. **UI.** When a screen or an `api/index.js` function changes, update section 9.2. The screen list is `src/js/console/constants.js`.
7. **Report format.** Changes to `inc/Reports/SnapshotSchema.php` need section 10.5, and `docs/report-snapshot.md`. A rename, retype or removal also needs a `VERSION` bump, as the docs say.
8. **Known gaps.** When a gap in section 1 is fixed, remove it. When a new drift is found, add it there.
9. **Unclear points.** Each **Unclear from code** note should be resolved or kept, with the reason, the next time this file is reviewed.
