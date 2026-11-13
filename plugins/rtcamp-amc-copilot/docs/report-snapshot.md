# Report snapshot format

**Frozen in Wave 0.** Source of truth in code: [`inc/Reports/SnapshotSchema.php`](../inc/Reports/SnapshotSchema.php). If this page and the class disagree, the class wins. Fix this page.

A report (`amc_report` post) stores every detected value in the post meta `_amc_snapshot` as a JSON string. Section blocks render from it, so a published report never changes unless an engineer edits it or regenerates it.

## Where each field's value lives

| Source | Who fills it | Stored in |
| --- | --- | --- |
| **auto** | Generator (D1), from stored runs, findings and changes in the period | `_amc_snapshot` → `sections.<section>.<field>` |
| **saved** | Generator copies from the `amc_saved_fields` option. On publish, changed values are written back (D1) | `_amc_snapshot` → `sections.<section>.<field>` |
| **engineer** | The engineer, inline in the editor (RichText) | Block attribute `engineer.<field>` (HTML) |

Engineer overrides of auto or saved values go in the block attribute `overrides.<field>`. When the block renders, the value shown is the first that exists:

1. `overrides[field]`
2. `snapshot.sections[section][field]`
3. `null` (renders as "—")

An overridden field shows an **Edited** badge in the editor only.

## JSON shape

```json
{
  "schema_version": 1,
  "taken_at": "2026-11-01T06:00:00Z",
  "period": { "type": "month", "start": "2026-10-01", "end": "2026-10-31" },
  "site": { "url": "https://example.com", "name": "Example", "blog_id": 1, "environment": "production" },
  "sections": {
    "overview": {
      "website_url": "https://example.com",
      "developer_name": "Jane Engineer",
      "report_date": "2026-11-01",
      "client_name": "Acme",
      "maintenance_schedule": "Monthly, first week"
    },
    "cms": { "wp_version_old": "6.9.1", "wp_version_new": "6.9.2", "editor_type": "block", "is_multisite": false, "websites_covered": "example.com" }
  }
}
```

Quarterly, annual and long custom (over 62 days) reports also carry two top-level keys, added by D1 (additive, no version bump): `totals` (`plugins_updated`, `plugins_added`, `plugins_removed`, `themes_updated`, `core_updates`, `users_added`, `users_removed`, `fatal`, `warning`, `deprecation`, `vulnerabilities_found`, `vulnerabilities_resolved`) and `monthly` (one entry per calendar month: `label`, `start`, `plugins_updated`, `plugins_added`, `plugins_removed`, `fatal`, `warning`, `deprecation`). Monthly reports have neither.

Rules:

- Every section key is always present, and every auto or saved field key is present in its section. A value the generator could not detect is `null`. Use `SnapshotSchema::empty_snapshot()` as the starting point.
- Engineer fields are never in the snapshot.
- `period.type` is `month`, `quarter`, `year` or `custom`. Dates are `Y-m-d` in site time. `taken_at` is ISO 8601 UTC.
- Read a report's snapshot with `SnapshotSchema::for_post( $post_id )`, which merges the stored data over the empty snapshot.

## Section block attributes (all 18 blocks)

| Attribute | Type | Default | Meaning |
| --- | --- | --- | --- |
| `lock` | object | `{ "move": true, "remove": true }` | Sections cannot be moved or removed (`supports.lock: false` hides the unlock UI) |
| `hidden` | boolean | `false` | "Hide this section": renders nothing on the published report |
| `overrides` | object | `{}` | field → string, overriding an auto or saved value |
| `engineer` | object | `{}` | field → HTML, for engineer fields |

## Field catalogue

Types: `string`, `html`, `bool`, `int`, `date` (`Y-m-d`), `url`, `list` (array of objects with the listed keys), `object` (the listed keys), `map` (slug → html).

| Section key / block | Field | Source | Type | Notes |
| --- | --- | --- | --- | --- |
| `overview` / `amc/section-overview` | `website_url` | auto | url | |
| | `developer_name` | auto | string | Report author display name |
| | `report_date` | auto | date | |
| | `client_name` | saved | string | |
| | `maintenance_schedule` | saved | string | |
| | `hours_used` | engineer | html | |
| `cms` / `amc/section-cms` | `wp_version_old` | auto | string | Version at period start |
| | `wp_version_new` | auto | string | Version at period end |
| | `editor_type` | auto | string | `block` \| `classic` \| `mixed` |
| | `is_multisite` | auto | bool | |
| | `websites_covered` | saved | string | |
| `theme` / `amc/section-theme` | `active_theme` | auto | object | name, slug, version |
| | `parent_theme` | auto | object | name, slug, version; `null` if not a child theme |
| | `theme_origin` | saved | string | |
| `plugins_themes` / `amc/section-plugins-themes` | `counts` | auto | object | installed, active, updated, added, removed, inactive, themes_installed, themes_updated |
| | `updated` | auto | list | type, slug, name, old_version, new_version, date, source (from `amc_changes`) |
| | `added` | auto | list | type, slug, name, version, date |
| | `removed` | auto | list | type, slug, name, version, date |
| | `inactive` | auto | list | slug, name, version |
| | `change_reasons` | engineer | map | slug → html, one reason per added or removed plugin |
| `database_backups` / `amc/section-database-backups` | `backup_method`, `backup_schedule`, `backup_retention` | saved | string | |
| | `backups_working`, `latest_backup` | engineer | html | |
| `user_management` / `amc/section-user-management` | `total_users` | auto | int | |
| | `users_per_role` | auto | list | role, label, count |
| | `administrators` | auto | list | login, display_name, email |
| | `users_added` | auto | list | login, role, date |
| | `users_removed` | auto | list | login, date |
| `hosting` / `amc/section-hosting` | `host`, `server_type`, `web_server`, `mysql_version`, `php_version`, `memory_limit`, `php_recommendation` | auto | string | `host` is `null` when not detectable |
| | `hosting_type`, `control_panel`, `server_backup_frequency`, `server_backup_retention`, `redundancy`, `account_owners`, `hosting_users` | saved | string | |
| | `disk_space`, `server_backups_running`, `latest_server_backup` | engineer | html | |
| `domains` / `amc/section-domains` | `primary_domain` | auto | string | |
| | `domain_expiry`, `auto_renewal`, `subdomains` | saved | string | |
| `ssl` / `amc/section-ssl` | `https` | auto | bool | |
| | `issuer` | auto | string | `null` = unavailable; engineer fills it through `overrides` |
| | `expires_at` | auto | date | |
| | `days_left` | auto | int | |
| | `ssl_type`, `ssl_auto_renewal` | saved | string | |
| `caching_cdn` / `amc/section-caching-cdn` | `page_cache`, `object_cache`, `cdn` | auto | string | |
| | `configured_correctly` | engineer | html | |
| `security` / `amc/section-security` | `vulnerabilities` | auto | list | component, reference, severity, status, fixed_in, first_seen, resolved_at |
| | `core_checksums` | auto | object | status, modified_files |
| | `config_checks` | auto | list | id, label, status |
| | `remediation_notes`, `malware_scan` | engineer | html | |
| `forms` / `amc/section-forms` | `form_plugins` | auto | list | name, slug, form_count, addons |
| | `sending_method` | auto | string | |
| | `integrations` | auto | list | name |
| | `entries_saving`, `notifications_confirmed` | engineer | html | |
| `analytics` / `amc/section-analytics` | `gtm_ids`, `ga_ids` | auto | list | List of strings |
| | `tag_assistant` | engineer | html | |
| `accessibility` / `amc/section-accessibility` | `third_party_tool` | auto | string | |
| | `statement_page` | auto | url | |
| | `defined_standard` | saved | string | |
| | `wave_result` | engineer | html | |
| `php_errors` / `amc/section-php-errors` | `fatal_count`, `warning_count`, `deprecation_count` | auto | int | |
| | `top_signatures` | auto | list | type, message, source, count (max 10) |
| `smoke_checks` / `amc/section-smoke-checks` | `urls` | auto | list | url, runs, pass_rate, avg_response_ms |
| `performance` / `amc/section-performance` | `response_trend` | auto | list | label, start, avg_ms (weekly points for monthly reports, monthly points for quarterly and annual) |
| | `database_size_bytes`, `autoload_size_bytes` | auto | int | |
| `engineer_notes` / `amc/section-engineer-notes` | `note` | engineer | html | Custom sections are separate blocks |

`amc_saved_fields` option shape: `{ "<section>": { "<field>": "<string>" } }`, holding only the saved fields above (`SnapshotSchema::saved_fields()`).

## Changing the format

Adding a field is allowed: add it to `SnapshotSchema`, to this page, and to the block's `FIELDS` list. Renaming, retyping or removing a field needs `SnapshotSchema::VERSION` bumped plus a migration for stored reports. Talk to Wave 0 / D1 first.
