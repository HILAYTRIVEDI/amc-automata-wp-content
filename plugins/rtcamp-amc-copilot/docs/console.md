# Engineer console (lane C)

The 12 engineer screens from the plan, as a React app built from `@wordpress/components` and `@wordpress/dataviews`, reading the `amc/v1` REST API frozen in [CONTRACTS.md](CONTRACTS.md) section 8. The Dashboard widget is server-rendered.

## Where things are

| What | Where |
| --- | --- |
| App source | `src/js/console/` (one webpack entry: `src/js/console/index.js` to `assets/build/js/console.js` and `assets/build/css/console.css`) |
| Data access (the only module that talks to REST) | `src/js/console/api/index.js` |
| Fixtures for development (`useMocks`) | `src/js/console/api/mocks/` |
| Menu, mount point | `inc/Admin/ConsolePage.php` (menu `amc-copilot`, capability `amc_manage`, `#amc-console-root`) |
| Enqueue | `inc/Admin/ConsoleAssets.php` (only on `toplevel_page_amc-copilot`, only for `amc_manage`) |
| Dashboard widget | `inc/Admin/DashboardWidget.php` |
| JS tests | `tests/js/console/` |
| PHP tests | `tests/php/Admin/` |

`window.amcCopilot = { restNonce, useMocks: false }` is printed before the script. To work on the UI with fixtures on a local site, flip it from an mu-plugin:

```php
add_filter( 'amc_console_config', static fn ( array $c ): array => array_merge( $c, [ 'useMocks' => true ] ) );
```

Optional `window.amcCopilot.mockLatency` (ms) and `pollInterval` (ms, default 2500) tune development.

## Routing

`?page=amc-copilot&screen=<slug>` with no router library. Slugs: `overview updates vulnerabilities errors smoke security performance inventory changes runs reports settings`.

Extra query args:

- `&run=<id>` on any screen opens that run's raw data (Runs screen). Every finding and check result links there.
- `&finding=<id>` on any screen opens that finding and the run that produced it.
- **Alert email deep link** (lane A2): `?page=amc-copilot&screen=findings&finding=<id>`. There is no "findings" screen. The console fetches `GET /findings/{id}`, replaces the URL with the screen for the finding's check group, and leaves `finding=<id>` so the modal opens. Mapping: `vuln.*` to Vulnerabilities, `errors.*` to Errors, `smoke.*` to Smoke checks, `security.*` and `ssl.*` to Security, `perf.*` to Performance, `updates.*` to Updates, `inventory.*`, `detect.*` and `hosting.*` to Inventory, `users.*` and `health.*` to Overview (`src/js/console/utils/findingScreen.js`).

## Routes used

| Screen / action | Route |
| --- | --- |
| Overview | `GET /overview`; Run all checks: `POST /checks/run { check_ids: ["all"] }` |
| Updates, Inventory, Security | `GET /updates`, `GET /inventory`, `GET /security` (`{ checks: { id: { run, data } \| null } }`) |
| Vulnerabilities | `GET /vulnerabilities?status=`; accept risk: `POST /findings/{id}/status { status: "accepted", note }` |
| Errors | `GET /errors?type=&days=` (run ids joined from `GET /findings?check_id=errors.*&status=unresolved`); mark resolved / mute / unmute: `POST /findings/{finding_id}/status` (`resolved`, `muted`, `open`) |
| Smoke checks | `GET /smoke` (stub from B2); add URL / edit required text: `POST /settings { smoke_urls }` |
| Performance | `GET /performance?days=` (trend stub from B2) |
| Changes | `GET /changes?type=&source=&since=&until=&per_page=` |
| Runs | `GET /runs`, `GET /runs/{id}`, re-run: `POST /runs/{id}/rerun` |
| Run now (every screen) | `POST /checks/run { check_ids }` (202 `{ runs: [{ check_id, run_id }] }`), then poll `GET /runs/{id}` every 2.5 s until the status is not `queued` or `running` (more than 3 runs are polled with one `GET /runs?per_page=500` instead) |
| Which checks can a screen run | `GET /checks`; only checks registered on the site are requested, so a lane that has not shipped a check is never asked for it |
| Reports | `GET /reports`, `POST /reports/generate` (D1), publish: core `POST /wp/v2/amc-reports/{id} { status: "publish" }`, edit opens `edit_url` |
| Saved fields | `GET /saved-fields`, `POST /saved-fields` (D1) |
| Settings | `GET /settings`, `POST /settings` (needs `manage_options`), `GET /checks` for the schedule table, `GET/POST/DELETE /access` and `GET /wp/v2/users` for engineers |

## Not available yet

A route that returns 501 `amc_not_implemented` shows a calm "not available yet" notice naming the owning lane, never a broken screen. A route that returns placeholder data with `X-AMC-Stub: <lane>` shows "Some of this is placeholder data until lane X ships it."

| Needs | Lane | Console behaviour until it ships |
| --- | --- | --- |
| `POST /reports/generate`, `POST /saved-fields` | D1 | Generate report and Save saved fields show the "not available yet" notice |
| `GET /smoke` real data, `GET /performance` `response_trend` | B2 | Stub notice; tables show the placeholder rows |
| `data` shapes of the B1 and B2 checks | B1, B2 | Rendered generically (see below) |
| Report `view_url` (client read-only view) | D2 | The View action is hidden while `view_url` is null |
| `updates.*`, `inventory.*`, `detect.*`, `security.*` and other checks themselves | B1, B2 | Panels say "This check has not run yet", and the Run check button says "Check not available yet" |
| `POST /notify/test` | A2 | Not used by the console |

## Rendering check data

`Result::$data` is owned by each check, and only `updates.core` documents its shape today. The Updates, Inventory, Security and Performance screens therefore show one panel per check (name, status badge with text, summary, when it ran, a link to the run) and render `data` without knowing its shape (`components/CheckData.js`):

- scalars as a description list (ISO dates, `_bytes`, `_ms`, `_pct`, booleans and `*status` keys are formatted; status values get a badge with text);
- arrays of objects as a table with filters, sorting, pagination and CSV export (the Updates tables get an "Open changelog" action when rows have `changelog_url`);
- nested objects as sections, and anything deeper than three levels as JSON.

When B1 and B2 land their checks, their documented shapes render without console changes. Dedicated columns or labels can be added per check afterwards.

## Behaviour notes

- **Status** is never colour alone: `StatusBadge` shows an icon and a text label (Pass, Warning, Failed, Info), using the `components-badge is-*` classes from `@wordpress/components`. The Dashboard widget does the same with a dashicon and text.
- **Every list** is a `DataViews` table with search, filters, sorting, pagination and an Export CSV button (all fields of the filtered rows; cells starting with `=`, `+`, `@` are prefixed so spreadsheets do not evaluate them).
- **Styling:** core classes and `@wordpress/components` only, no hex colours. One stylesheet, `src/js/console/console.scss` (layout only, under 2 KB, scoped `.amc-`), which also pulls in the bundled DataViews stylesheet. DataViews has no WordPress script handle, so it is bundled (about 885 KB minified JS).
- **Dashboard widget:** one internal `GET /amc/v1/overview` dispatch, cached in a five minute transient and dropped on `amc_run_completed`; shown only to `amc_manage`; nothing loads on the front end.

## Dependencies added to `package.json`

`@wordpress/api-fetch`, `@wordpress/dataviews`, `@wordpress/date`, `@wordpress/url` (the console imports them; they were only present transitively), and `react`, `react-dom` (the component tests render with React's `act`).

## Verify

```sh
npx eslint src/js/console tests/js
npx wp-scripts test-unit-jest --config=tests/js/jest.config.js
npm run build:assets
composer lint && composer phpstan
vendor/bin/phpunit tests/php/Admin
```
