# security-checksums fixtures

`checksums.php` is a toy `get_core_checksums()` result. The test hasher serves each file's "contents" as `md5( <key> )`, so a file is "modified" when the test overrides its content and "missing" when it removes it.

`wp-content/index.php` is in the list on purpose: the check must skip `wp-content/`. Sorted check order: index.php, license.txt, readme.html, wp-admin/index.php, wp-includes/load.php, wp-includes/version.php, wp-login.php, wp-settings.php.
