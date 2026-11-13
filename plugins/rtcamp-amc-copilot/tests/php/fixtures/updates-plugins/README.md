# updates.plugins fixtures

Each file returns what one WordPress read returns, trimmed to the fields the
check reads. Installed plugins include Hello Dolly 1.7.2 (active) and a
premium plugin `premium-tools/premium-tools.php` 2.1.0 (inactive, not on
wp.org). The plugin inventory fixture `plugins.php` is shared by every
fixture file below (each returns `[ transient, plugins ]` where `transient`
is the `update_plugins` site transient and `plugins` is the `get_plugins()`
array).

| File | Situation |
| --- | --- |
| `none.php` | No transient yet (`false`) |
| `up-to-date.php` | Every plugin on wp.org is up to date (`no_update`) |
| `pending.php` | Akismet has a pending 5.3.6 update (tested 6.9, PHP 7.2) |
| `closed.php` | A plugin is on wp.org but closed (absent from both lists) |
