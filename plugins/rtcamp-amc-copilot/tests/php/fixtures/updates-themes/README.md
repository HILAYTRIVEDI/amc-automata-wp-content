# updates.themes fixtures

`transient.php` returns what `get_site_transient( 'update_themes' )` returns;
`themes.php` returns a `wp_get_themes()`-shaped array of fake theme objects
(Twenty Twenty-Six 1.0.2 active, Child of Twenty Twenty-Six 1.0.0 as its
child, Storefront 4.6.0 inactive). `changelog.php` is a `themes_api()`
result.

| File | Returns |
| --- | --- |
| `transient-none.php` | No transient yet (`false`) |
| `transient-pending.php` | Twenty Twenty-Six 1.0.3 update available |
| `transient-up-to-date.php` | No updates pending |
| `themes.php` | The three installed themes |
| `changelog.php` | wp.org changelog section for Twenty Twenty-Six |
