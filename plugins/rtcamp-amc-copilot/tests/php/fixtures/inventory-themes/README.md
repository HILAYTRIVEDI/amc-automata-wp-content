# inventory.themes fixtures

`themes.php` and `child.php` both return `wp_get_themes()`-shaped arrays
(stylesheet => theme object). Automated tests are keyed by stylesheet.

`themes.php` uses the anonymous fake theme objects the updates fixtures use
(no parent). `child.php` builds real `WP_Theme` objects from the tiny themes
under `themes/`: `child` declares `Template: parent`, so the check must
resolve the parent stylesheet. The fixture theme directories are never
installed; they only exist so `WP_Theme` can read real headers.

| File | Situation |
| --- | --- |
| `themes.php` | Twenty Twenty-Six 1.0.2 (active) and Storefront 4.6.0, no parents |
| `child.php` | A child theme 1.0.0 whose parent is `parent` 2.0.0 |
| `themes/child/style.css` | Child theme headers (`Template: parent`) |
| `themes/parent/style.css`, `index.php` | Parent theme headers and template file |
