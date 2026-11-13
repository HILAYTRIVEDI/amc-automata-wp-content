# AMC Copilot performance harness

Two small tools for answering one question: **does AMC Copilot slow the front
end down?**

- `k6-frontend.js` — a [k6](https://k6.io/) load script that hits a list of
  front-end URLs and records p50 / p95 / p99 in a JSON summary.
- `compare.sh` — runs that script twice, once with the plugin deactivated and
  once with it activated, then prints the p95 delta and fails when it is 2 ms
  or worse.

Nothing here is part of the plugin. It all lives under `tools/amc-perf/` at the
`wp-content` repo root so it can be run against any site.

## Requirements

- [k6](https://k6.io/docs/get-started/installation/) — `brew install k6` on
  macOS, or the Docker image below.
- WP-CLI with access to the site under test. Under wp-env that is
  `npx wp-env run cli wp`; on a real site it is `wp` (locally) or
  `wp --ssh=…` / `wp @alias` if you have aliases configured.
- `jq` or `python3` for reading the summary JSON (both are optional; one is
  needed).

## Quick mode

1,000 requests per run — the sanity check to run on every PR that touches
front-end code.

```bash
cd plugins/rtcamp-amc-copilot
WP_CLI="npx wp-env run cli wp" QUICK=1 ../../tools/amc-perf/compare.sh
```

## Full mode

10,000 requests per run (the default) — before a release, or when you suspect a
regression but the quick run is inconclusive.

```bash
cd plugins/rtcamp-amc-copilot
WP_CLI="npx wp-env run cli wp" ../../tools/amc-perf/compare.sh
```

Expect the full run to take a few minutes. `ITERATIONS` overrides the request
count if 10,000 is too heavy for the machine you are on (the delta is noisier
below ~1,000).

## Against a local site

`wp-env` is the usual local setup, and its default site address is
`http://localhost:8888`:

```bash
# from plugins/rtcamp-amc-copilot, if wp-env is not already running
npx wp-env start

# then, from the same directory
WP_CLI="npx wp-env run cli wp" ../../tools/amc-perf/compare.sh
```

A classic local WordPress (no wp-env) works too:

```bash
BASE_URL="http://amc-automata.test" WP_CLI="wp --path=/path/to/wordpress" \
  ./tools/amc-perf/compare.sh
```

The script deactivates and reactivates `rtcamp-amc-copilot` for you and
restores the plugin to its original state after the run, including when it
fails. It refuses to compare if the plugin cannot be activated, so a set-up
error never shows up as a fake "no regression" result.

## Against a pilot clone

Point `BASE_URL` at the pilot host (or an SSH tunnel to it) and `WP_CLI` at the
same install. Over HTTPS on a shared host, keep the load modest: the pilot is
not yours to hammer.

```bash
BASE_URL="https://pilot.example.com" \
WP_CLI="wp @pilot" \
URLS="/,/sample-page/,/blog/" \
VUS=5 ITERATIONS=1000 \
  ./tools/amc-perf/compare.sh
```

If WP-CLI cannot reach the pilot at all, you can still measure. Toggle the
plugin in the pilot admin, and run the two measurements by hand:

```bash
BASE_URL="https://pilot.example.com" URLS="/,/sample-page/" \
  k6 run tools/amc-perf/k6-frontend.js

# (activate the plugin in the pilot admin, then)
BASE_URL="https://pilot.example.com" URLS="/,/sample-page/" SUMMARY_OUT=activated.json \
  k6 run tools/amc-perf/k6-frontend.js
```

Read `.latencyMs.p95` from each JSON and subtract.

## Running k6 directly

`compare.sh` is a thin wrapper. The script is useful on its own for a single
measurement, or when k6 lives in Docker:

```bash
# installed k6
BASE_URL=http://localhost:8888 URLS=/,/sample-page/ VUS=25 ITERATIONS=5000 \
  k6 run tools/amc-perf/k6-frontend.js

# Docker (Linux; on macOS drop --network host and use host.docker.internal)
docker run --rm -i --network host \
  -e BASE_URL=http://localhost:8888 -e URLS=/ \
  -v "$PWD/tools/amc-perf:/scripts" \
  grafana/k6 run /scripts/k6-frontend.js
```

| Variable        | Default                 | Meaning                                                        |
| --------------- | ----------------------- | -------------------------------------------------------------- |
| `BASE_URL`      | `http://localhost:8888` | Site root, no trailing slash                                   |
| `URLS`          | `/`                     | Comma-separated paths (or absolute URLs), round-robined         |
| `VUS`           | `10`                    | Virtual users                                                  |
| `ITERATIONS`    | `10000`                 | Total requests, one per iteration                              |
| `P95_THRESHOLD` | unset                   | Adds a k6 `p(95)<value` threshold; leave unset for `compare.sh` |
| `SUMMARY_OUT`   | `summary.json`          | JSON summary path                                              |
| `MAX_DURATION`  | `30m`                   | Scenario cap                                                   |

The JSON summary looks like this:

```json
{
  "generatedAt": "2026-11-13T10:00:00.000Z",
  "baseUrl": "http://localhost:8888",
  "urls": ["/"],
  "vus": 10,
  "iterations": 10000,
  "requests": 10000,
  "latencyMs": { "avg": 41.2, "min": 12.1, "p50": 38.9, "p95": 61.4, "p99": 98.7, "max": 240.2 }
}
```

## Reading the result

- **p50** is the typical page load. A change here is a real change.
- **p95** is what slow-but-normal visitors see. This is the number the 2 ms
  gate uses.
- **p99** is the tail: caches, cold queries, a page that misses an object cache
  entry. Watch it for large moves even when p95 passes.

Run both modes from a quiet machine. Other things happening on the laptop —
Docker builds, a backup, a video call — move the numbers more than the plugin
does. If a run looks bad, run it again before believing it.
