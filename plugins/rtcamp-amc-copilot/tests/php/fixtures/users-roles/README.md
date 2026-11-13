# users.roles fixtures

| File | Purpose |
| --- | --- |
| `counts.php` | `count_users()`: five users over administrator, editor and subscriber |
| `roles.php` | The baseline role definitions; capabilities are unsorted |
| `roles-changed.php` | One week later: administrator gained `edit_theme_options`, editor swapped `edit_posts` for `publish_posts`, subscriber unchanged |
| `counts-no-total.php` | `count_users()` without `total_users` (the total comes from the role sum) |
