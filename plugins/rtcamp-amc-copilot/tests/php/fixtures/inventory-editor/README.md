# inventory.editor fixtures

| File | Situation |
| --- | --- |
| `plugins.php` | Installed plugins include `classic-editor/classic-editor.php` 1.6.7 |
| `editors.php` | `use_block_editor_for_post_type()` is true for post and page |
| `editors-mixed.php` | Post uses the block editor, page uses the classic editor |
| `editors-classic.php` | Both public post types fall back to the classic editor |
