# inventory.plugins fixtures

The installed plugin lists are `get_plugins()`-shaped. `api.php` is the
`plugins_api( 'plugin_information', [ 'slug' => ... ] )` response keyed by
slug, in the shapes core really returns: an object for a plugin on wp.org, a
`WP_Error` ("Plugin not found.") for one that is not, and a `WP_Error` with
message `closed` for one closed on wp.org. `api-closed.json` and
`api-not-found.json` are the recorded api.wordpress.org bodies (a closed
plugin, `display-widgets`, and an unknown slug); a test feeds them through the
real `plugins_api()` with `pre_http_request`. Akismet is updated in 2026,
Hello Dolly in 2025; `old-closed` (closed) and `stale-plugin` (last updated
2024-01-01) are the alert-worthy ones. Tests pin the clock to 2026-11-14, so
"over two years" means a `last_updated` before 2024-11-14.

| File | Situation |
| --- | --- |
| `plugins.php` | Everything: two healthy plugins, one premium plugin not on wp.org, one closed plugin, one stale plugin |
| `plugins-clean.php` | Only healthy/unknown plugins; no findings |
| `plugins-closed.php` | A closed wp.org plugin plus a healthy one |
| `plugins-stale.php` | A plugin whose wp.org `last_updated` is over two years old, plus a healthy one |
| `plugins-changed.php` | The next week's inventory for the diff: Akismet 5.4.0 (updated), Hello Dolly removed, `new-plugin` added |
