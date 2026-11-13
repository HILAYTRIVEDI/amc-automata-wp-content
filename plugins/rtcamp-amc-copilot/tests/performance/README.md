# AMC Copilot front-end performance harness

A repeatable k6 test that proves the Phase 1 performance budget
([`docs/phase-1-plan.md`](../../docs/phase-1-plan.md), "Performance budget on
production"): the plugin's capture layer adds **under 2 ms** to front-end p95
response time, measured over 10,000 requests, comparing the same target with
and without the plugin.

It complements the in-process PHP benchmark at
[`tests/php/Capture/PerformanceTest.php`](../php/Capture/PerformanceTest.php),
which measures the capture layer's per-request cost directly; this harness
measures the *end-to-end* effect of the whole plugin over HTTP, which is the
number the acceptance criterion actually cares about.

## Prerequisites

- **k6** — [install](https://k6.io/docs/get-started/installation/).
- **wp-cli** — either a standalone `wp` on the target's `PATH`, or the one
  bundled with this repo's `wp-env` (`npx wp-env run cli wp …`).
- **bash**, plus `grep` and `awk` (POSIX) to read the results. No `jq` needed.

## Running against wp-env

Start wp-env from the plugin directory, then run the harness with the wp-env
wp-cli wrapper:

```sh
cd plugins/rtcamp-amc-copilot
npm run wp-env start        # or: npx wp-env start
WP_CLI="npx wp-env run cli wp" \
BASE_URL="http://localhost:8888" \
  ./tests/performance/run.sh
```

## Running against a pilot staging clone

Anywhere `wp` (or an `ssh`-wrapped `wp`) talks to the target, point `WP_CLI` at
it and set `BASE_URL` to the public origin k6 should hit:

```sh
WP_CLI="wp --path=/srv/www/site/current" \
BASE_URL="https://pilot.example.com" \
  ./tests/performance/run.sh
```

If the pilot is behind an SSH host, wrap it (the extra quoting keeps
`WP_CLI` a single prefix): `WP_CLI="ssh pilot 'wp --path=…'"` — set the quoting
to match your shell.

## What it does

1. Deactivates the plugin, warms up, then measures the **baseline** p95.
2. Activates the plugin, clears any stale kill-switch setting, asserts the
   capture layer is on (`KillSwitch::is_active()` is `false`), warms up, then
   measures the **active** p95.
3. Prints both p95 values and the delta, and exits non-zero when the delta is
   `>= 2 ms`.

The plugin is left **activated** at the end. If `AMC_COPILOT_DISABLED` is
defined as `true` in the target's `wp-config.php`, the capture layer cannot be
turned on and the active run aborts with an error.

## Configuration

| Variable | Default | Meaning |
| --- | --- | --- |
| `BASE_URL` | `http://localhost:8888` | Target origin, no trailing slash. |
| `TOTAL_REQUESTS` | `10000` | Requests per measured run. |
| `VUS` | `20` | Virtual users. |
| `WARMUP_REQUESTS` | `200` | Requests in each warm-up pass. |
| `K6` | `k6` | k6 binary. |
| `WP_CLI` | `wp` | wp-cli invocation prefix. |
| `RESULTS_DIR` | `<script dir>/results` | Where JSON summaries are written. |

### URL mix

The script hits a weighted mix of five URLs, all derived from `BASE_URL` as
query strings so they work regardless of permalink settings:

- home (`/`), a single post (`?p=1`), an archive (`?cat=1`), a 404
  (`?p=999999`), and a search (`?s=amc`).

Post `1` and category `1` are the defaults of a fresh install; override the
derived URLs for a real target whose IDs differ by exporting any of `HOME_URL`,
`POST_URL`, `ARCHIVE_URL`, `NOT_FOUND_URL`, `SEARCH_URL` (or `SLEEP_MS`) before
running — `run.sh` forwards them to k6:

```sh
POST_URL="https://pilot.example.com/hello-world/" \
ARCHIVE_URL="https://pilot.example.com/category/news/" \
WP_CLI="wp --path=/srv/www/site/current" \
BASE_URL="https://pilot.example.com" \
  ./tests/performance/run.sh
```

A request counts as failed only on a 5xx or a network error (`http.setResponseCallback`
in `frontend.js`); the deliberate 404 is expected and does not trip the
`rate==0` threshold.

## Reading the results

Each run writes two JSON files under `RESULTS_DIR`, named
`<timestamp>-baseline.json` and `<timestamp>-active.json`, each containing:

- `p95_ms` — the p95 of `http_req_duration`, the number that matters;
- `failed_rate`, `checks_passed`, `checks_failed` — sanity values (should be
  `0`, `total`, `0`); and
- `total_requests`, `vus`, `base_url`, `timestamp` — for reproducibility.

`run.sh` prints both p95 values and the delta, then exits `0` on pass and `1`
on fail.

## Results are noisy — interpret with care

Response-time measurements are dominated by the machine and its neighbours.
To trust the number:

- Run on a **quiet** machine (nothing else hitting the target, no builds or
  cron on the box running k6).
- **Repeat 3 times** and look at the spread; a single run that trips the 2 ms
  budget is not evidence on its own.
- The warm-up pass settles caches and opcode caches before each measurement,
  so the two measured runs are comparable.
- A negative delta (plugin active is *faster*) is normal noise and passes.

This harness is intentionally **not** part of required CI: a stable 2 ms
measurement needs a dedicated, quiet host, not a shared runner.
