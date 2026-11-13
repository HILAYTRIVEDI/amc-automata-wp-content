# updates.php fixtures

Each file returns the Serve Happy response shape
(`wp_check_php_version()`), keyed the way the API returns it.

| File | Situation |
| --- | --- |
| `none.php` | No response (`null`, API down or too-old WordPress) |
| `acceptable.php` | 8.2 is acceptable and supported |
| `unacceptable.php` | 7.4 is not acceptable and below the future minimum |
