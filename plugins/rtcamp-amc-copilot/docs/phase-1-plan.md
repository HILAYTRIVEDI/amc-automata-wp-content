# AMC Copilot — Phase 1 Implementation Plan

Nov 13, 2026 · @Hilay Trivedi

## Overview

AMC Copilot is a WordPress plugin that puts every recurring AMC check for a site on one wp-admin screen and turns the monthly Website Maintenance Report from a hand-typed Google Doc into a generated, editable report. Phase 1 covers checks, runs, alerts and reports. Phase 2 (automated actions such as applying updates) is out of scope here.

**Goals**

- One view per site of everything the AMC checklist asks engineers to look at, built on WordPress core APIs.
- Reports in rtCamp's existing Website Maintenance Report structure, about 60% filled automatically and 20% carried forward, so engineers type only what changed.
- Email alerts for the events that can't wait for the next maintenance cycle.
- Works the same on standard hosts, WordPress VIP and git-deployed sites.
- Built for a single site first, with a network view for multisites (12 to 16 subsites) added without rewriting checks.

**Success criteria for Phase 1**

- An engineer can produce a client-ready monthly report in under 20 minutes, against about 1 to 2 hours today.
- Plugin update lists in reports need zero manual typing.
- No measurable slowdown on production: under 2 ms added per front-end request at the 95th percentile.
- Runs on WordPress VIP without file writes and passes VIP code review.

## Locked decisions

Everything below was agreed in the requirements discussion and is not open for change in Phase 1.

| Area | Decision |
| --- | --- |
| Plugin | Name AMC Copilot · slug `rtcamp-amc-copilot` · prefix `amc_` |
| Platform | PHP 8.1+ · WordPress 6.9+ · core APIs only; api.wordpress.org allowed |
| Hosting | Standard hosts, WordPress VIP and git-deployed sites are all in scope |
| Scope of a view | Per site first; multisite network view later, on the same check code |
| Environments | Each environment stands alone; no cross-environment comparison |
| Runs | Every check runs on a WP-Cron schedule and on demand |
| Vulnerabilities | Supplied by an rtCamp-built CVE provider through a plugin interface |
| Check data storage | Custom database tables, 13 months of history |
| Production capture | Errors and deprecations always captured; slow queries sampled on 1 in 100 requests |
| Engineers | Full console through a capability assigned to individual user accounts |
| Clients | Administrators see published reports only |
| Alerts | Email; immediate alerts plus a daily digest at 09:00 site time |
| Smoke checks | Server-side HTTP checks; home, login and latest post or product by default; engineers add URLs |
| Smoke pass rule | HTTP 200, response under 3 s, no PHP error text, optional required text |
| Reports | Custom post type in the block editor, rtCamp's Website Maintenance Report structure |
| Report periods | Monthly, quarterly and annual; contract year when set, calendar year otherwise |
| Report output | HTML with print styles; the browser saves it as PDF |
| Report branding | rtCamp logo (placeholder until the SVG arrives); engineer's name shown |
| Abilities API | Every check is also registered as a WordPress ability |

UI rule: all screens, blocks and emails use WordPress core styles, `@wordpress/components` and core admin classes; no custom design system (see Engineer console).

## Architecture

Every check is a small class that inspects one site and returns a result; everything else (scheduling, storage, alerts, console, reports) consumes those results. That single contract is what lets the multisite network view reuse the same checks later by looping over subsites.

**Check contract**

Each check implements `AMC\Checks\Check`:

- `id()`: stable slug, for example `updates.plugins`.
- `group()`: one of Updates, Vulnerabilities, Errors, Smoke, Security, Performance, Health, Inventory, Users, Hosting, Detection.
- `schedule()`: default cron interval.
- `run( Context $ctx ): Result`: reads the site and returns a result. Never writes to the site.
- `Result` holds a status (`pass`, `warn`, `fail`, `info`), a summary line, structured data (JSON) and zero or more findings, each with severity, title and a dedupe key.

`Context` wraps the site being checked. Today it is the current site; for multisite it will carry a blog ID and the runner calls `switch_to_blog()` around `run()`. Checks never call `get_current_blog_id()` themselves.

**Plugin layout**

```
rtcamp-amc-copilot/
  rtcamp-amc-copilot.php      bootstrap, version and requirement checks
  inc/
    Core/          Plugin, Installer (tables), Capabilities, Settings, KillSwitch
    Checks/        Check interface, Registry, one class per check
    Runner/        Scheduler (WP-Cron), Lock, Runner, CLI commands
    Capture/       ErrorCapture, DeprecationCapture, QuerySampler, ChangeLog
    Storage/       repositories for each custom table, Retention
    Vulnerability/ ProviderInterface, Matcher, NullProvider
    Notify/        AlertRules, Mailer, Digest
    Reports/       PostType, Generator, Snapshot, FieldCatalogue, Periods
    Abilities/     registers each check and report action as an ability
    Admin/         console screens (React, @wordpress/components)
  blocks/          one block per report section + custom section block
  assets/          built JS and CSS, print stylesheet
  tests/           PHPUnit (unit + integration), Playwright for blocks
```

**Runtime pieces**

- **Runner.** Takes a check ID, acquires a per-check lock (row in `amc_locks`, 10-minute expiry), runs it, stores the result, then hands findings to alert rules. Missed cron runs are caught up once, oldest first.
- **Capture layer.** Hooks loaded on every request, kept tiny: a PHP error handler, deprecation hooks, a 1-in-100 query sampler and change-log listeners on upgrader and activation hooks. Writes are buffered and flushed once in `shutdown`.
- **Kill switch.** `AMC_COPILOT_DISABLED` constant or a setting stops all capture and cron jobs. The constant always wins.
- **Interfaces.** Admin screens use the REST API (`amc/v1`). WP-CLI mirrors every action (`wp amc check run`, `wp amc report generate`). Each check is also registered as an ability through the WordPress 6.9 Abilities API, read-only.

The diagram below shows how the pieces connect.

&#91;embedded content: AMC Copilot architecture · triggers, engine, storage, outputs\]

Every output reads stored results, so the console, alerts and reports never run a check themselves and always agree with each other.

## Checks catalogue

Phase 1 ships 31 checks in 11 groups. "Immediate" in the alert column means an email goes out as soon as the finding first appears; "Digest" means it waits for the daily digest; "None" means it only shows in the console and reports.

| Check ID | What it reads | Schedule | Alert |
| --- | --- | --- | --- |
| `updates.core` | `get_core_updates()` | Twice daily | Digest |
| `updates.plugins` | `update_plugins` transient, `plugins_api()` for changelog, tested-up-to, requires PHP | Twice daily | Digest |
| `updates.themes` | `update_themes` transient, `themes_api()` | Twice daily | Digest |
| `updates.php` | `phpversion()`, `wp_check_php_version()`, each plugin's Requires PHP | Daily | Digest |
| `updates.readiness` | Each active plugin's tested-up-to against the next WordPress major | Weekly | None |
| `vuln.match` | Inventory sent to the CVE provider; matches returned | Twice daily | Immediate (High/Critical), Digest (others) |
| `inventory.plugins` | `get_plugins()`, active and network-active lists, wp.org vs non-wp.org, closed on wp.org, last updated | Weekly | Digest (closed or abandoned over 2 years) |
| `inventory.themes` | `wp_get_themes()`, active theme and parent | Weekly | None |
| `inventory.mu_dropins` | `get_mu_plugins()`, `_get_dropins()` | Weekly | None |
| `inventory.editor` | Classic Editor plugin, `use_block_editor_for_post_type()` per public post type | Weekly | None |
| `errors.fatal` | Fatal errors captured by the error handler and recovery mode | Hourly | Immediate (new signature) |
| `errors.warnings` | Warnings and notices captured, grouped by signature and source | Hourly | Digest (new source) |
| `errors.deprecations` | `deprecated_*_run` and `doing_it_wrong_run` hooks, grouped by caller | Hourly | Digest (new caller) |
| `smoke.urls` | `wp_remote_get()` to each configured URL; status, time, error text, required text | Hourly | Immediate (2 failures in a row) |
| `security.checksums` | `get_core_checksums()` vs files on disk | Daily | Immediate (any modified core file) |
| `security.config` | `DISALLOW_FILE_EDIT`, `WP_DEBUG_DISPLAY`, salts set, `admin` username, XML-RPC, directory listing on uploads | Daily | Digest |
| `security.admins` | Administrator accounts and changes since last run | Daily | Immediate (admin added) |
| `ssl.certificate` | Peer certificate via `stream_socket_client` with `capture_peer_cert`: issuer, expiry | Daily | Immediate (14 days or less) |
| `perf.response` | Server response time of smoke URLs | Hourly | Digest (20% slower than 4-week average) |
| `perf.database` | Table sizes from `information_schema`, autoloaded options size, transients count | Daily | Digest (autoload over 800 KB) |
| `perf.cache` | `WP_Site_Health` `get_test_persistent_object_cache()` and `get_test_page_cache()` (status, label, response time); page cache headers and `advanced-cache.php` from core's page cache detail | Daily | None |
| `perf.slow_queries` | Sampled queries over 100 ms, grouped by caller | Hourly | Digest |
| `health.site_health` | `WP_Site_Health` tests run server-side as core's own cron check does (direct tests, plus async tests that have a direct runner; `skip_cron` tests and `page_cache` skipped). Critical results are Medium findings, recommended results Low | Daily | Digest (critical and recommended results) |
| `health.cron` | `_get_cron_array()` overdue events | Hourly | Digest (overdue over 1 h) |
| `users.roles` | `count_users()`, role and capability snapshot diff | Daily | Digest (capability changes) |
| `users.changes` | `user_register`, `delete_user`, `set_user_role` hooks | Event | None |
| `hosting.platform` | Host constants (VIP, WP Engine, Pantheon, Kinsta, Pressable), server software, DB version, OS, memory limit, disk where readable | Weekly | None |
| `detect.cdn` | Response headers of the home page (`cf-ray`, `x-cache`, `x-served-by`, `server`) | Weekly | None |
| `detect.forms` | Active form plugins (Gravity Forms, WPForms, CF7, Ninja Forms, Fluent Forms), form counts, add-ons | Weekly | None |
| `detect.mailer` | SMTP and mailer plugins, `phpmailer_init` hooks present | Weekly | None |
| `detect.analytics` | GTM and GA IDs parsed from the home page HTML | Weekly | None |
| `detect.accessibility` | Known accessibility tool scripts in the home page; a page with "accessibility" in its slug | Weekly | None |

**Change log.** Not a scheduled check: `upgrader_process_complete`, `activated_plugin`, `deactivated_plugin`, `deleted_plugin`, `switch_theme` and `_core_updated_successfully` write a row to `amc_changes` with old and new versions. Updates done by git deploys don't fire these hooks, so `inventory.plugins` also diffs each run against the previous snapshot and logs any version change it finds with source `deploy`.

## Data model

Check data lives in seven custom tables created with `dbDelta()` on activation and upgraded by a schema version option. Reports live in a custom post type (see Reports). Every table carries `blog_id` from day one so the multisite view needs no migration.

| Table | One row per | Key columns | Retention |
| --- | --- | --- | --- |
| `amc_runs` | Check execution | `id`, `blog_id`, `check_id`, `trigger` (cron, manual, cli), `status`, `summary`, `data` (JSON), `started_at`, `duration_ms` | 13 months |
| `amc_findings` | Distinct issue | `id`, `blog_id`, `check_id`, `dedupe_key`, `severity`, `title`, `data` (JSON), `first_seen`, `last_seen`, `resolved_at`, `alerted_at` | 13 months after resolved |
| `amc_events` | Captured error or deprecation signature per hour | `blog_id`, `type`, `signature` (hash), `message`, `source`, `file`, `line`, `hour`, `count` | 13 months |
| `amc_queries` | Sampled slow query signature per hour | `blog_id`, `signature`, `sql_sample`, `caller`, `hour`, `count`, `max_ms`, `avg_ms` | 90 days |
| `amc_changes` | Change to code or users | `blog_id`, `type` (plugin, theme, core, user), `action` (install, update, activate, deactivate, delete, role), `item`, `old_version`, `new_version`, `source` (admin, cli, deploy, auto), `user_id`, `created_at` | 13 months |
| `amc_snapshots` | Weekly inventory snapshot | `blog_id`, `kind`, `data` (JSON), `taken_at` | 13 months |
| `amc_locks` | Running job | `name`, `owner`, `expires_at` | Cleared on finish |

**Aggregation instead of raw rows.** Errors and slow queries are counted per signature per hour, never stored one row per occurrence. A noisy site with a warning on every page load still writes at most one row per signature per hour.

**Retention job.** A daily cron deletes expired rows in batches of 1,000 with a time limit of 20 seconds per run.

**Options** (all autoload off):

- `amc_settings`: site type, contract start date, recipients, digest time, smoke URLs, kill switch, check overrides (interval, enabled).
- `amc_saved_fields`: carried-forward report values (see Reports).
- `amc_schema_version`.

## Engineer console

The console is a top-level **AMC Copilot** menu in wp-admin, visible only to users with `amc_manage`. It is a React app built with `@wordpress/components`, reading from the `amc/v1` REST API.

| Screen | What it shows | Actions |
| --- | --- | --- |
| Overview | Overall status, open findings by severity, last run per group, versions (WP, PHP, theme), counts (plugins, themes, pending updates), next scheduled runs | Run all checks |
| Updates | Core, plugin, theme and PHP updates with current and available version, changelog excerpt, tested-up-to, requires PHP, compatibility flags; major-upgrade readiness matrix | Run check, open changelog |
| Vulnerabilities | Open and resolved matches with reference, severity, affected and fixed version, first seen | Mark as accepted risk (with note) |
| Errors | Fatal errors, warnings and deprecations grouped by signature, with source, count, first and last seen, and a 30-day sparkline | Mark resolved, mute signature |
| Smoke checks | Each URL with pass rate, last status, response time trend | Add URL, edit required text, run now |
| Security | Checksum result, configuration checks, administrator list and changes, SSL details | Run check |
| Performance | Response time trend, database and autoload size, cache status, slow queries by caller | Run check |
| Inventory | Plugins (active, inactive, wp.org or not, closed, last updated), themes, must-use plugins, drop-ins, editor type, hosting, detections | Export CSV |
| Changes | Timeline of code and user changes with source (admin, CLI, deploy, auto) | Filter by date and type |
| Runs | Every run with trigger, duration, status, and the result's data | Re-run |
| Reports | Report list, generate a report, saved fields | Generate, edit, publish |
| Settings | Site type, contract start, recipients, digest time, check intervals and on/off, engineers with access, retention, kill switch | Save |

**Behaviour**

- Status uses colour plus a label (Pass, Warning, Failed), never colour alone.
- "Run now" queues the check through the runner and polls for the result; it never runs a check inside the REST request.
- Findings show the run that produced them, so an engineer can see the raw data behind any claim.
- Every list is filterable and exports to CSV.

**UI and styling: WordPress styles only**

Every screen, block and email uses WordPress's own styles and pre-built classes. The plugin does not ship its own design system, colour palette or component library.

- **React screens:** `@wordpress/components` (`Card`, `Panel`, `TabPanel`, `Button`, `Notice`, `Spinner`, `Modal`, `SelectControl`, `ToggleControl`), `@wordpress/dataviews` for every list and table, `@wordpress/icons` for icons.
- **PHP-rendered admin pages:** core admin markup and classes: `wrap`, `wp-heading-inline`, `page-title-action`, `widefat striped`, `form-table`, `postbox`, `notice notice-error|warning|success|info`, `button button-primary|secondary`, `dashicons-*`, `screen-reader-text`.
- **Colours:** the admin colour scheme variables (`--wp-admin-theme-color` and its shades) and core notice colours for status. No hard-coded hex values.
- **Report blocks:** built from core blocks and block supports (`color`, `spacing`, `typography`, `border`) so they follow the site's `theme.json` and the editor's styles. Tables use the core Table block styles, sections use Group with core spacing presets.
- **Allowed custom CSS:** only the print stylesheet (page size, page breaks, hiding admin chrome) and small layout rules core doesn't cover, scoped under `.amc-` and kept in one file under 5 KB.
- **Review rule:** a pull request that adds a new colour, font, button style or component that core already provides is sent back.

## Reports

Reports are posts of a private custom post type, edited in the block editor with one block per section of rtCamp's Website Maintenance Report. Detected values are frozen into the post when it is generated, so a published report never changes unless an engineer edits or regenerates it.

**Post type `amc_report`**

- `public` false, `show_ui` true, `show_in_rest` true, `supports` title, editor, revisions, author, custom-fields.
- Custom capability type, mapped so `amc_manage` can create, edit and publish, and `amc_view_reports` can read published reports only.
- Post meta: `_amc_period_type` (month, quarter, year, custom), `_amc_period_start`, `_amc_period_end`, `_amc_environment`, `_amc_snapshot` (JSON of every detected value), `_amc_snapshot_taken_at`, `_amc_report_id` (for example `AMC-NWT-2026-10`).
- Statuses: draft (generated, engineer editing) and publish (visible to clients). Revisions keep every edit.

**Blocks**

- 18 section blocks in rtCamp's order: Overview, CMS, Theme, Plugins and themes, Database backups, User management, Hosting, Domains, SSL, Caching and CDN, Security, Forms, Analytics and tagging, Accessibility, then the new PHP errors, Smoke checks, Performance, and Engineer's notes.
- Section blocks are dynamic: PHP renders them from the snapshot plus the block's attributes, so the editor and the published page always match.
- Each section block carries the block-level `lock` attribute (move and remove), so sections can't be removed or reordered, while custom sections can still be inserted between them. Each section has a "Hide this section" toggle.
- **Custom section** block: a title and free rich text (paragraphs, lists, tables, images). Engineers insert it anywhere and can tick "Carry forward" to repeat it in the next report.
- Field editing: Engineer fields are edited inline with `RichText`. Auto and Saved fields show their value with a pencil in the block sidebar to override it; an overridden Auto field shows an "Edited" badge in the editor only.

**Field sources**

- **Auto:** read from the snapshot. About 60% of fields.
- **Saved:** stable facts entered once and carried forward from the latest published report for the same environment; stored in `amc_saved_fields` and editable from Settings. About 20%.
- **Engineer:** typed per report; empty at generation. Under 20%.

**Field catalogue**

| Section | Auto | Saved | Engineer |
| --- | --- | --- | --- |
| Overview | Website URL, developer name (report author), date | Client name, maintenance schedule | Hours used |
| CMS | WordPress old and new version, editor type, multisite | Websites covered | — |
| Theme | Active theme and parent with versions | Theme origin | — |
| Plugins and themes | Counts installed and updated; updated, added, removed and inactive lists with versions and dates | — | Reason for each added or removed plugin |
| Database backups | — | Method, schedule, retention | Working correctly, most recent backup |
| User management | Total users, users per role, administrators, users added and removed in period | — | — |
| Hosting | Host (when detectable), server type, web server, MySQL and PHP versions, memory limit, PHP update recommendation | Hosting type, control panel, server backup frequency and retention, redundancy, account owners, hosting users | Disk space, server backups running, most recent server backup |
| Domains | Primary domain | Expiry and auto-renewal, subdomains | — |
| SSL | HTTPS, issuer, expiry, days left | Purchased or free, auto-renewal | — |
| Caching and CDN | Page cache, object cache, CDN | — | Configured correctly |
| Security | Vulnerabilities with severity and status, core checksums, configuration checks | — | Remediation notes, malware scan result |
| Forms | Form plugin and form count, sending method, integrations | — | Entries saving, notifications confirmed |
| Analytics and tagging | GTM and GA IDs | — | Tag Assistant warnings and errors |
| Accessibility | Third-party tool, statement page | Defined standard | WAVE result |
| PHP errors | Fatal, warning and deprecation counts; top signatures | — | — |
| Smoke checks | Per URL: runs, pass rate, average response | — | — |
| Performance | Weekly or monthly response trend, database size, autoload size | — | — |
| Engineer's notes | — | — | Note, custom sections |

The fixed disclaimers from the current template (backups, hosting, domains, accessibility) are part of their section blocks and can be edited per site in Settings.

**Generation**

1. A cron job or the "Generate report" button calls `Reports\Generator` with a period type and dates.
2. The generator builds the snapshot from stored runs, findings and changes within the period, plus a fresh run of the inventory and detection checks.
3. It creates a draft `amc_report` with all section blocks, the snapshot in meta and Saved fields filled from `amc_saved_fields`.
4. The engineers on the recipient list get an email with a link to the draft.
5. The engineer edits, hides sections, adds custom sections and publishes. On publish, any changed Saved fields are written back to `amc_saved_fields`.
6. Client administrators get an email that a new report is available (on by default, per-site setting).
7. "Regenerate data" on a draft replaces the snapshot and keeps every Engineer field and custom section.

**Schedule**

| Report | Runs on | Covers |
| --- | --- | --- |
| Monthly | 1st of each month, 06:00 site time | The previous month |
| Quarterly | 1st day of each contract quarter, 06:15 | The previous quarter |
| Annual | Contract anniversary, 06:30 | The previous 12 months |
| Custom | On demand | Any date range the engineer picks |

Quarters and years follow the contract start date set in Settings; with none set they follow the calendar. Quarterly and annual reports use the same sections, with totals for the period and a monthly trend in the Performance section.

**Client view and PDF**

- Client administrators see **Maintenance Reports** in wp-admin: a list of published reports and a read-only view of each.
- The view page loads a print stylesheet: A4 page size, section bands kept with their content, page numbers in the footer, no wp-admin chrome. The browser's Save as PDF produces the file.
- Reports are never shown on the front end and never indexed.

## Access, notifications and the CVE provider

**Capabilities**

| Capability | Granted to | Allows |
| --- | --- | --- |
| `amc_manage` | Individual engineer accounts, assigned in Settings or with `wp amc access grant <user>` | Console, run checks, settings, generate, edit and publish reports |
| `amc_view_reports` | The `administrator` role, added on activation | Read published reports under Maintenance Reports |

- `amc_manage` is a user capability, not a role, so an engineer keeps their normal role on the site.
- Granting or removing access needs `amc_manage` plus `manage_options`, and every change writes to `amc_changes`.
- On multisite (later), super admins get `amc_manage` across the network by default.

**Notifications**

- Recipients: a per-site list, pre-filled with every user who has `amc_manage`; any address can be added.
- Immediate alerts: a new High or Critical CVE match, a new fatal error signature, two smoke check failures in a row, SSL expiring within 14 days, any modified core file, an administrator account added.
- Daily digest at 09:00 site time: every other new finding from the last 24 hours plus anything still open and unacknowledged. No digest is sent when there is nothing new.
- Report emails: draft ready (to engineers), report published (to client administrators, per-site toggle, on by default).
- Throttling: the same finding alerts once; it alerts again only if it resolves and comes back. At most 10 immediate emails per hour per site; anything beyond goes into the next digest.
- Sent with `wp_mail()`, so the site's existing mailer plugin or host mail is used. Subjects start with `[AMC][site name]`. Plain-text and HTML parts.

**CVE provider interface**

The plugin ships with a `NullProvider` that returns no matches; rtCamp's provider replaces it.

```php
namespace AMC\Vulnerability;

interface ProviderInterface {
    public function id(): string;            // 'rtcamp-cve'
    public function is_ready(): bool;        // credentials or data present
    /** @param Component[] $components  @return Match[] */
    public function match( array $components ): array;
}

// Component: type (core|plugin|theme), slug, version, name
// Match: component slug, reference (CVE id), title, severity
//        (low|medium|high|critical), cvss, affected range,
//        fixed_in (nullable), url, published_at
```

- Registered with the filter `amc_vulnerability_provider`.
- The runner calls `match()` twice daily with the full inventory and stores each match as a finding keyed by component, version and reference. A match that disappears (plugin updated or removed) resolves its finding.
- Provider errors are logged to the run and shown on the Vulnerabilities screen; they never block other checks.

## Hosting constraints and performance budget

The plugin is built to VIP's rules everywhere, so one codebase runs on every host without special cases beyond detection.

| Constraint | Where it applies | How the plugin handles it |
| --- | --- | --- |
| No writes to the filesystem | VIP, most managed hosts | Nothing is written to disk; PDFs come from the browser; logs go to database tables |
| No access to `debug.log` | VIP, many managed hosts | Errors captured in PHP by the plugin's own handler, which chains to any previous handler |
| Plugins and core updated by git, not wp-admin | VIP, git-deployed sites | Update data is view-only in Phase 1; deploy-time version changes are found by snapshot diff and logged with source `deploy` |
| Cron run by the host, not page loads | VIP (Cron Control), some hosts | Only the standard WP-Cron API is used; jobs are short and locked so a late or doubled run is harmless |
| Custom tables need review | VIP | Tables are small, indexed and aggregated; schema doc submitted with the code review |
| Loopback requests may be blocked | Some hosts, sites behind basic auth | Smoke checks report "Loopback blocked" as its own status, not a site failure; optional basic-auth credentials in Settings |
| `information_schema` may be restricted | Some shared hosts | Database size check falls back to `SHOW TABLE STATUS`, then to "unavailable" |
| Peer certificate not readable | Hosts without `openssl` or outbound 443 | SSL check reports "unavailable"; the report field becomes Engineer-entered |

**Performance budget on production**

- Front-end requests: under 2 ms added at the 95th percentile, and no extra database queries. The capture layer buffers in memory and writes once in `shutdown`, using `INSERT … ON DUPLICATE KEY UPDATE` on the hourly aggregate row.
- Query sampling: `SAVEQUERIES` is never turned on site-wide. On 1 request in 100 the sampler times queries through the `query` and `log_query_custom_data` filters and keeps only those over 100 ms.
- Admin: the console loads only on its own screens. The Dashboard widget is one cached REST call.
- Cron: each check finishes within 20 seconds; long checks (checksums, inventory) work in batches and resume on the next run.
- Memory: the capture layer keeps at most 200 events in memory per request and drops the rest with a counter.

## Two-day delivery plan

Phase 1 reaches a release candidate in 2 working days by running four lanes in parallel, each led by one engineer directing Claude Code agents, on top of the existing amc-auto-pilot code (runner, locks, catch-up, kill switch, settings and the Runs page are already merged). Hand-written, the same scope is about 74 engineer-days; the 2-day plan holds only if the check contract and table schema are frozen in the first hour and every lane builds and tests against them.

&#91;embedded content: Two-day plan · 4 lanes, 2 days\]

Each lane depends only on the frozen contract (check interface, table schema, REST routes, snapshot format), not on another lane's code. A lane that finishes early takes the next item from the fast-follow list below.

| Lane | Day 1 | Day 2 | Done means |
| --- | --- | --- | --- |
| A Platform | Freeze contract and schema (hour 1); tables, registry, REST and CLI on the existing runner; capture layer, change log, deploy diff | Alert rules, throttling, mailer, daily digest; 1,000-request performance check | Capture writes aggregated rows; immediate and digest emails arrive on the pilot site |
| B Checks | Updates, PHP, readiness, inventory, CVE interface with a fixture provider, errors, security, SSL | Smoke, performance, health, users, hosting and detection checks; abilities registration | All 31 checks pass unit tests and run from WP-CLI |
| C Console | App shell, shared DataViews tables, Overview, Updates, Vulnerabilities, Errors, Settings | Smoke, Security, Performance, Inventory, Changes, Runs, Reports list | All 12 screens built from core components, reading live data |
| D Reports | Post type and capabilities, snapshot builder, field catalogue, saved fields, generator | 18 section blocks and custom section, client view, print stylesheet, schedules, report emails | An October report is generated, edited, published and saved as PDF on the pilot site |

**Checkpoints**

1. Day 1, hour 1: contract, schema, REST routes and snapshot format merged. No lane writes feature code before this.
2. Day 1, end of day: each lane demos; checks write runs, the console reads them, and a draft report is generated from real data.
3. Day 2, midday: feature freeze. Only fixes and tests after this.
4. Day 2, end of day: release candidate tagged, installed on one pilot site, acceptance criteria run and results recorded.

**Fast-follow, the week after.** These don't fit in 2 days and don't block the release candidate: VIP's formal code review (submitted on day 2), the pilot on the remaining two sites, the full 10,000-request performance test, the console accessibility pass, and trend charts in quarterly and annual reports (period generation itself ships in the 2 days).

**What the 2 days need.** Four engineers with Claude Code, one per lane (two engineers running two lanes each roughly doubles the review load and puts day 2 at risk). One pilot site with staging access before day 1 starts. The CVE provider's interface agreed in the first hour; the real provider can plug in later. The rtCamp logo SVG by day 2 midday.

## Model routing

Each lane and each follow-up task is executed by an AI agent. Work is assigned by complexity, so the expensive models only touch the hard parts and the cheap ones handle routine edits. Model names and prices change often: this table was last checked on Nov 14, 2026 against the OpenCode model catalogue and the Claude provider list.

| Tier | Kind of work | Model | Price per million tokens (in / out) |
| --- | --- | --- | --- |
| Routine | Docs, CI YAML, lint fixes, small test fixes, copy edits | `opencode-go/deepseek-v4.1-flash` | $0.15 / $0.60 |
| Standard | Check classes, REST endpoints, blocks, console screens, unit tests | `opencode-go/deepseek-v4-pro` | $0.66 / $1.98 |
| Hard | Report snapshot and generator, CVE matching, security checks, UI architecture | `opencode-go/kimi-k3.1-pro` | $2 / $10 |

**Rules**

- One agent per task. The brief carries the frozen contract, the exact file list the agent may touch, and the checks that must pass; agents work in their own checkout so parallel tasks cannot collide.
- Agents never merge their own pull request. The lead reviews and merges.
- Every agent runs the CI checks locally before opening the pull request, and the pull request must be green before merge: PHPCS, PHPStan, PHPUnit, ESLint, Stylelint, Jest, Playwright and the VIP scan.
- The Claude models (`claude-opus-5-5`, `claude-sonnet-5-5`, `claude-fable-5-2`, `claude-haiku-5-5`) are the fallback when an OpenCode route is down. They stay idle while the individual Claude spend limit is reached; that limit resets Nov 19, 2026 at 2:30am Asia/Calcutta.

**Current assignment (UI rework).** The console and report UI rework runs as two parallel agents on `opencode-go/deepseek-v4-pro`: the engineer console shell and shared components, and the report block editor plus print styles and dashboard widget. The 12 console screens follow in a second wave once the shared components land, so every screen is built on the same core patterns.

## Testing and acceptance criteria

**Test layers**

- Unit tests (PHPUnit) for every check's `run()` against fixture data, the alert rules, period maths and the snapshot builder.
- Integration tests (wp-env, single site and multisite) for table creation, cron scheduling, capture hooks and REST permissions.
- Block tests (Playwright) for inserting, editing, hiding and publishing a report, and for the client's read-only view.
- Performance test: k6 against a pilot clone, with and without the plugin, 10,000 requests, comparing p95 response time.
- Linting: PHPCS with WordPress-VIP-Go and WordPress-Extra, ESLint with `@wordpress/eslint-plugin`, all in CI on every pull request.

**Acceptance criteria**

- [ ] Every check in the catalogue runs on schedule and on demand, stores a run, and shows its result in the console.
- [ ] A plugin updated in wp-admin, by WP-CLI or by a git deploy appears in the change log with old and new versions within one inventory run.
- [ ] A fatal error triggered on a test site sends one immediate email within 5 minutes and no second email for the same signature.
- [ ] A smoke URL returning 500 twice in a row sends an immediate alert; a single failure does not.
- [ ] A modified core file is reported by name within one daily run.
- [ ] SSL expiry is reported correctly for a certificate with 10 days left.
- [ ] A CVE match from a test provider appears as a finding, alerts once, and resolves when the plugin is updated.
- [ ] The daily digest arrives at 09:00 site time and is skipped when there is nothing new.
- [ ] The monthly report draft is created at 06:00 on the 1st with every Auto field filled and every Saved field copied from the previous report.
- [ ] Plugin updated, added, removed and inactive lists in the report match the change log exactly.
- [ ] Sections can be hidden but not deleted or reordered; custom sections can be added anywhere.
- [ ] Regenerating a draft refreshes Auto fields and keeps Engineer fields and custom sections.
- [ ] A client administrator sees only published reports and cannot open the console or drafts.
- [ ] Save as PDF from Chrome and Safari produces an A4 report with no wp-admin chrome and no section split from its heading.
- [ ] p95 front-end response time rises by under 2 ms with the plugin active.
- [ ] The kill switch constant stops all capture and cron jobs within one request.
- [ ] The plugin passes VIP's automated code scan with no errors.
- [ ] Uninstalling removes tables, options and capabilities; reports are kept unless the engineer ticks "Delete reports".

## Out of scope, risks and open items

**Out of scope for Phase 1**

- Applying updates, rollbacks or any other change to the site (Phase 2).
- Multisite network view (the data model and check contract are ready for it).
- External uptime monitoring, browser-based flow tests, cross-browser checks, Lighthouse scores.
- Backup verification, restore tests, WAF and firewall review, domain WHOIS lookups; these stay as Engineer or Saved report fields.
- Comparing environments with each other.

**Risks**

| Risk | Effect | Mitigation |
| --- | --- | --- |
| Premium plugins with their own updaters don't report updates through the core transient | Pending updates under-reported | Show "No update source" for non-wp.org plugins so engineers check them by hand |
| Error handler conflicts with another plugin's handler | Lost errors or double logging | Chain to the previous handler, return its result, and test with Query Monitor and New Relic agents active |
| Loopback blocked or behind basic auth | Smoke and detection checks fail | Distinct "Loopback blocked" status and optional credentials in Settings |
| VIP review asks for changes to custom tables | Fast-follow release slips | Share the schema with VIP on day 1 |
| Engineers keep editing Saved fields per report | Carried-forward values drift | Show the previous value next to any changed Saved field before publish |
| CVE provider not ready in the 2 days | Vulnerability alerts untested | Build against a fixture provider; switch on the real one when ready |

**Open items**

- [ ] rtCamp logo as SVG for the report header.
- [ ] Confirm the three pilot sites.
- [ ] CVE provider owner and delivery date.
