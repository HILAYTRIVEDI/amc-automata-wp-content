#!/usr/bin/env bash
#
# AMC Copilot front-end performance comparison.
#
# Measures front-end p95 with k6 over TOTAL_REQUESTS requests against the same
# target in two states — plugin deactivated (baseline) then activated — and
# prints the delta. Exits non-zero when the active p95 is >= 2 ms above the
# baseline (the Phase 1 budget, docs/phase-1-plan.md).
#
# Configuration (environment variables):
#   BASE_URL          target origin, no trailing slash (default http://localhost:8888)
#   TOTAL_REQUESTS    requests per measured run (default 10000)
#   VUS               virtual users (default 20)
#   WARMUP_REQUESTS   requests per warm-up pass (default 200)
#   K6                k6 binary (default "k6")
#   WP_CLI            wp-cli invocation (default "wp"; e.g. "npx wp-env run cli wp")
#   RESULTS_DIR       where JSON summaries are written (default <script dir>/results)
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8888}"
TOTAL_REQUESTS="${TOTAL_REQUESTS:-10000}"
VUS="${VUS:-20}"
WARMUP_REQUESTS="${WARMUP_REQUESTS:-200}"
K6="${K6:-k6}"
WP_CLI="${WP_CLI:-wp}"
RESULTS_DIR="${RESULTS_DIR:-$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/results}"

BUDGET_MS="2.0"
PLUGIN="rtcamp-amc-copilot"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

mkdir -p "$RESULTS_DIR"
RUN_ID="$( date +%Y%m%d-%H%M%S )"
BASELINE_FILE="$RESULTS_DIR/${RUN_ID}-baseline.json"
ACTIVE_FILE="$RESULTS_DIR/${RUN_ID}-active.json"
WARMUP_FILE="$RESULTS_DIR/${RUN_ID}-warmup.json"

if ! command -v "$K6" >/dev/null 2>&1; then
	echo "error: k6 not found (K6=\"$K6\"). Install it: https://k6.io/docs/get-started/installation/" >&2
	exit 1
fi

# $WP_CLI is intentionally unquoted so "npx wp-env run cli wp" splits correctly.
# shellcheck disable=SC2086
run_wp() { $WP_CLI "$@"; }

deactivate_plugin() {
	if run_wp plugin is-active "$PLUGIN" >/dev/null 2>&1; then
		run_wp plugin deactivate "$PLUGIN"
	fi
}

activate_plugin() {
	if ! run_wp plugin is-active "$PLUGIN" >/dev/null 2>&1; then
		run_wp plugin activate "$PLUGIN"
	fi
}

# Remember the site's state so it is restored on exit, even after a failure:
# whether the plugin was active and the kill-switch setting.
WAS_ACTIVE=0
if run_wp plugin is-active "$PLUGIN" >/dev/null 2>&1; then
	WAS_ACTIVE=1
	KILL_SWITCH_WAS="$( run_wp eval 'var_export( (bool) \AMC\Core\Settings::get_value( "kill_switch" ) );' )"
else
	KILL_SWITCH_WAS="unknown"
fi

restore_site() {
	if [ "$KILL_SWITCH_WAS" = "true" ] || [ "$KILL_SWITCH_WAS" = "false" ]; then
		run_wp eval "\AMC\Core\Settings::update( [ 'kill_switch' => $KILL_SWITCH_WAS ] );" >/dev/null 2>&1 || true
	fi
	if [ "$WAS_ACTIVE" -eq 1 ]; then
		activate_plugin >/dev/null 2>&1 || true
	else
		deactivate_plugin >/dev/null 2>&1 || true
	fi
	echo "==> Site restored: plugin $( [ "$WAS_ACTIVE" -eq 1 ] && echo active || echo inactive ), kill switch setting ${KILL_SWITCH_WAS}"
}
trap restore_site EXIT

# Forward optional per-URL overrides to k6 (see frontend.js).
URL_ENVS=( HOME_URL POST_URL ARCHIVE_URL NOT_FOUND_URL SEARCH_URL SLEEP_MS )
K6_EXTRA_ENV=()
for name in "${URL_ENVS[@]}"; do
	if [ -n "${!name:-}" ]; then
		K6_EXTRA_ENV+=( -e "$name=${!name}" )
	fi
done

# Run k6 once. $1 = request count, $2 = summary JSON path.
run_k6() {
	"$K6" run --quiet \
		-e BASE_URL="$BASE_URL" \
		-e TOTAL_REQUESTS="$1" \
		-e VUS="$VUS" \
		-e SUMMARY_FILE="$2" \
		"${K6_EXTRA_ENV[@]}" \
		"$SCRIPT_DIR/frontend.js"
}

# p95_ms is written as a plain decimal (rounded in handleSummary), so a simple
# grep extracts it without jq.
p95_from_file() {
	grep -o '"p95_ms": *[0-9.]*' "$1" | head -n1 | grep -o '[0-9.]*$'
}

echo "==> Baseline: plugin deactivated"
deactivate_plugin
run_k6 "$WARMUP_REQUESTS" "$WARMUP_FILE"
run_k6 "$TOTAL_REQUESTS" "$BASELINE_FILE"

echo "==> Active: plugin activated (capture layer on)"
activate_plugin
# Clear a stale kill-switch setting, then prove the capture layer is actually
# on: KillSwitch::is_active() covers both the setting and the constant.
run_wp eval '\AMC\Core\Settings::update( [ "kill_switch" => false ] );'
if run_wp eval 'var_export( \AMC\Core\KillSwitch::is_active() );' | grep -q 'true'; then
	echo "error: capture layer is disabled (kill switch) during the active run" >&2
	exit 1
fi
run_k6 "$WARMUP_REQUESTS" "$WARMUP_FILE"
run_k6 "$TOTAL_REQUESTS" "$ACTIVE_FILE"

BASELINE_P95="$( p95_from_file "$BASELINE_FILE" )"
ACTIVE_P95="$( p95_from_file "$ACTIVE_FILE" )"
DELTA="$( awk -v a="$BASELINE_P95" -v b="$ACTIVE_P95" 'BEGIN { printf "%.3f", b - a }' )"
OVER_BUDGET="$( awk -v d="$DELTA" -v budget="$BUDGET_MS" 'BEGIN { print ( d >= budget ) ? 1 : 0 }' )"

echo
echo "baseline (plugin off): p95 = ${BASELINE_P95} ms"
echo "active   (plugin on):  p95 = ${ACTIVE_P95} ms"
echo "delta: ${DELTA} ms (budget: < ${BUDGET_MS} ms)"
echo "results: $BASELINE_FILE"
echo "         $ACTIVE_FILE"

if [ "$OVER_BUDGET" -eq 1 ]; then
	echo "FAIL: p95 rose by ${DELTA} ms, at or above the ${BUDGET_MS} ms budget" >&2
	exit 1
fi

echo "PASS: p95 delta ${DELTA} ms is under the ${BUDGET_MS} ms budget"
