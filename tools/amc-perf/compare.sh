#!/usr/bin/env bash
#
# Compare front-end p95 latency with AMC Copilot deactivated (baseline) and
# activated, using tools/amc-perf/k6-frontend.js.
#
#   ./tools/amc-perf/compare.sh                     # 10,000 requests per run
#   QUICK=1 ./tools/amc-perf/compare.sh             # 1,000 requests per run
#
# Environment variables
#   BASE_URL       Site root. Default: http://localhost:8888
#   URLS           Comma-separated paths. Default: /
#   WP_CLI         Command prefix for WP-CLI. Default: wp
#                  Point it at wp-env with:
#                    WP_CLI="npx wp-env run cli wp"
#   K6             k6 binary. Default: k6
#   QUICK          Set to 1 for a 1,000-request smoke comparison.
#   ITERATIONS     Override the request count (wins over QUICK).
#   VUS            Virtual users. Default: 10
#   MAX_DELTA_MS   Allowed p95 regression before failing. Default: 2
#   OUT_DIR        Where to write baseline/activated JSON. Default: a temp dir
#   PLUGIN_SLUG    Plugin to toggle. Default: rtcamp-amc-copilot
#
# Exit status: 0 when the p95 delta is below MAX_DELTA_MS, 1 when the delta is
# greater than or equal to it (or when a run cannot be completed).
#
# The plugin's original active/inactive state is restored on exit.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"

K6="${K6:-k6}"
BASE_URL="${BASE_URL:-http://localhost:8888}"
WP_CLI="${WP_CLI:-wp}"
PLUGIN_SLUG="${PLUGIN_SLUG:-rtcamp-amc-copilot}"
URLS="${URLS:-/}"
VUS="${VUS:-10}"
MAX_DELTA_MS="${MAX_DELTA_MS:-2}"

if [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
	sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
	exit 0
fi

if [ "${QUICK:-0}" = "1" ]; then
	ITERATIONS="${ITERATIONS:-1000}"
else
	ITERATIONS="${ITERATIONS:-10000}"
fi

log() {
	printf '==> %s\n' "$*"
}

die() {
	printf 'error: %s\n' "$*" >&2
	exit 1
}

if ! command -v "${K6}" >/dev/null 2>&1; then
	die "${K6} not found. Install k6 (https://k6.io/docs/get-started/installation/) or set K6=/path/to/k6."
fi

if ! command -v jq >/dev/null 2>&1 && ! command -v python3 >/dev/null 2>&1; then
	die "neither jq nor python3 is available; one of them is needed to read the k6 summary."
fi

case "${MAX_DELTA_MS}" in
	'' | *[!0-9.]*) die "MAX_DELTA_MS must be a number, got '${MAX_DELTA_MS}'" ;;
esac

TMP_BASE="${TMPDIR:-/tmp}"
TMP_BASE="${TMP_BASE%/}"
OUT_DIR="${OUT_DIR:-${TMP_BASE}/amc-perf-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "${OUT_DIR}"
BASELINE_JSON="${OUT_DIR}/baseline.json"
ACTIVATED_JSON="${OUT_DIR}/activated.json"

# WP_CLI is deliberately a prefix: it may expand to several words, e.g.
# "npx wp-env run cli wp". Shellcheck's SC2086 is the point here.
# shellcheck disable=SC2086
wp_cli() {
	${WP_CLI} "$@"
}

read_metric() {
	local file="$1"
	local key="$2"

	if command -v jq >/dev/null 2>&1; then
		jq -r --arg key "${key}" '.latencyMs[$key] // empty' "${file}"
	else
		python3 - "${file}" "${key}" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    value = json.load(handle).get("latencyMs", {}).get(sys.argv[2])
print("" if value is None else value)
PY
	fi
}

run_k6() {
	local label="$1"
	local out="$2"

	log "k6 (${label}): ${ITERATIONS} requests, ${VUS} VUs -> ${out}"
	"${K6}" run \
		-e "BASE_URL=${BASE_URL}" \
		-e "URLS=${URLS}" \
		-e "VUS=${VUS}" \
		-e "ITERATIONS=${ITERATIONS}" \
		-e "SUMMARY_OUT=${out}" \
		"${SCRIPT_DIR}/k6-frontend.js"
}

# --- Remember the starting state so the site is left as we found it. ---------
was_active=0
if wp_cli plugin is-active "${PLUGIN_SLUG}" >/dev/null 2>&1; then
	was_active=1
fi

restore_plugin_state() {
	local status=$?

	if [ "${was_active}" -eq 1 ]; then
		wp_cli plugin activate "${PLUGIN_SLUG}" >/dev/null 2>&1 || true
	else
		wp_cli plugin deactivate "${PLUGIN_SLUG}" >/dev/null 2>&1 || true
	fi

	return "${status}"
}
trap restore_plugin_state EXIT

# --- Baseline: plugin deactivated. -------------------------------------------
log "Deactivating ${PLUGIN_SLUG}"
wp_cli plugin deactivate "${PLUGIN_SLUG}" >/dev/null 2>&1 || log "  (plugin was already inactive)"

if wp_cli plugin is-active "${PLUGIN_SLUG}" >/dev/null 2>&1; then
	die "${PLUGIN_SLUG} is still active after deactivation; check WP_CLI and PLUGIN_SLUG."
fi

run_k6 "deactivated" "${BASELINE_JSON}"

# --- Activated run. ----------------------------------------------------------
log "Activating ${PLUGIN_SLUG}"
if ! wp_cli plugin activate "${PLUGIN_SLUG}" >/dev/null 2>&1; then
	die "could not activate ${PLUGIN_SLUG}. Is it installed in the site BASE_URL points at? For wp-env use WP_CLI='npx wp-env run cli wp'."
fi

if ! wp_cli plugin is-active "${PLUGIN_SLUG}" >/dev/null 2>&1; then
	die "${PLUGIN_SLUG} is not active after activation; refusing to compare."
fi

run_k6 "activated" "${ACTIVATED_JSON}"

# --- Compare. ----------------------------------------------------------------
baseline_p50="$(read_metric "${BASELINE_JSON}" p50)"
baseline_p95="$(read_metric "${BASELINE_JSON}" p95)"
baseline_p99="$(read_metric "${BASELINE_JSON}" p99)"
activated_p50="$(read_metric "${ACTIVATED_JSON}" p50)"
activated_p95="$(read_metric "${ACTIVATED_JSON}" p95)"
activated_p99="$(read_metric "${ACTIVATED_JSON}" p99)"

for value in "${baseline_p95}" "${activated_p95}"; do
	[ -n "${value}" ] || die "the k6 summary is missing p95; inspect ${OUT_DIR}."
done

delta_raw="$(awk -v activated="${activated_p95}" -v baseline="${baseline_p95}" 'BEGIN { printf "%.6f", activated - baseline }')"
delta_display="$(awk -v delta="${delta_raw}" 'BEGIN { printf "%+.3f", delta }')"
delta_abs="$(awk -v delta="${delta_raw}" 'BEGIN { printf "%.3f", (delta < 0 ? -delta : delta) }')"

printf '\nAMC Copilot front-end p95 comparison\n'
printf '  base URL:          %s\n' "${BASE_URL}"
printf '  urls:              %s\n' "${URLS}"
printf '  requests per run:  %s (%s VUs)\n' "${ITERATIONS}" "${VUS}"
printf '  mode:              %s\n' "$([ "${QUICK:-0}" = "1" ] && echo "quick" || echo "full")"
printf '\n  %-14s %10s %10s\n' 'latency (ms)' 'deactivated' 'activated'
printf '  %-14s %10s %10s\n' 'p50' "${baseline_p50}" "${activated_p50}"
printf '  %-14s %10s %10s\n' 'p95' "${baseline_p95}" "${activated_p95}"
printf '  %-14s %10s %10s\n' 'p99' "${baseline_p99}" "${activated_p99}"
printf '\n  p95 delta: %s ms (fails at >= %s ms)\n' "${delta_display}" "${MAX_DELTA_MS}"
printf '  summaries: %s\n\n' "${OUT_DIR}"

if awk -v delta="${delta_raw}" -v limit="${MAX_DELTA_MS}" 'BEGIN { exit !(delta >= limit) }'; then
	printf 'FAIL: AMC Copilot increased front-end p95 by %s ms (limit %s ms).\n' "${delta_abs}" "${MAX_DELTA_MS}" >&2
	exit 1
fi

printf 'PASS: p95 delta %s ms is below the %s ms limit.\n' "${delta_display}" "${MAX_DELTA_MS}"
