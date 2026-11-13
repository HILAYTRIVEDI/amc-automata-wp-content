# updates.core fixtures

Each file returns what `get_site_transient( 'update_core' )` returns on a real
site (an object with `updates` and `last_checked`), trimmed to the fields the
check reads. The installed version used by the tests is **6.9.1**.

| File | Situation |
| --- | --- |
| `none.php` | No transient yet (`false`) |
| `latest.php` | api.wordpress.org says 6.9.1 is the latest |
| `minor.php` | 6.9.2 (security release) is available |
| `major.php` | 7.0 is available; 6.9.2 also offered |
