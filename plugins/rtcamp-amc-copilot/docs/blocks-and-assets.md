# Blocks and assets

How the plugin builds scripts, styles and blocks, and how each output reaches WordPress. For running the watchers see [Local development](local-development.md#edit-source-and-see-the-result); for the loader API see the framework's [`AssetLoader` reference](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/loaders.md#assetloader).

Names below use an initialized **Acme Content** project (`acme-content-features` text domain and handle prefix).

## Two builds

| Script | Config | Source → output |
| --- | --- | --- |
| `build:assets` / `start:assets` | [`webpack.config.js`](../webpack.config.js) (extends `@wordpress/scripts`) | `src/css/**` → `assets/build/css/`, `src/js/**` → `assets/build/js/`, `src/js/modules/**` → `assets/build/js/modules/` (ES modules) |
| `build:blocks` / `start:blocks` | `@wordpress/scripts` default; [`webpack.blocks.config.js`](../webpack.blocks.config.js) for the dev server | `src/blocks/<block>/` → `assets/build/blocks/<block>/` |

`npm run build:dev` and `npm run build:prod` run both. Every file in `src/js` and `src/css` becomes its own entry, and the output mirrors the folder structure (`src/js/admin/reports.js` → `assets/build/js/admin/reports.js`). Files and folders starting with `_` are skipped, so use `_partial.scss` for imports. Each script build also writes a `*.asset.php` manifest that `AssetLoader` reads for dependencies and version.

## Scripts and styles

[`inc/Core/Assets.php`](../inc/Core/Assets.php) extends the framework `AssetLoader` and is shared through `Main`'s container. It prefixes every handle with `acme-content-features-` (`Assets::handle( 'frontend' )`).

| Handle | Source | Behaviour |
| --- | --- | --- |
| `frontend` | `src/js/main.js`, `src/css/main.scss` | Registered and enqueued on every frontend page. |
| `admin` | `src/js/admin.js`, `src/css/admin.scss` | Registered on `admin_enqueue_scripts`; enqueue it on the screens that need it. |
| `editor` | `src/js/editor.js`, `src/css/editor.scss` | Registered on `enqueue_block_editor_assets` only if you create these files (none ship). |
| `tailwind` | `src/css/tailwind.css` | Enqueued on the frontend when the Tailwind feature is on; see [Tailwind](tailwind.md). |
| `@acme-content-features/module` | `src/js/modules/module.js` | Script module with an `@wordpress/interactivity` dependency, enqueued on the frontend. |

`register_script()` and `register_style()` return `false` when the built file is missing, so a missing build never prints a broken tag. They also raise a `_doing_it_wrong` notice (visible with `WP_DEBUG`), which is why `Assets` checks `has_asset()` before registering optional entries such as `editor`.

### Add a script for one screen

Create the source, for example `src/js/admin/reports.js`, then register and enqueue it from the class that owns the screen, using the shared `Assets` instance:

```php
use Acme_Content\Features\Core\Assets;
use Acme_Content\Features\Main;

public function register_hooks(): void {
	add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
}

public function enqueue( string $hook_suffix ): void {
	if ( 'tools_page_acme-content-features-reports' !== $hook_suffix ) {
		return;
	}

	$assets = Main::get_instance()->get_shared( Assets::class );
	$handle = $assets->handle( 'reports' );

	if ( $assets->register_script( $handle, 'js/admin/reports' ) ) {
		wp_enqueue_script( $handle );
	}
}
```

Keep enqueues conditional per screen, and never print inline `<script>` or `<style>` from PHP.

## Blocks

There are two ways a block reaches WordPress, and the difference decides where you register it.

| Kind | Examples | Server rendering | Register it in |
| --- | --- | --- | --- |
| **Metadata block**: registered straight from its built `block.json` | `example-block` (static), `example-block-interactive` (Interactivity API, `render.php` + `view.js`) | None, or a `render.php` file referenced in `block.json` | Add the folder name to `Assets::STATIC_BLOCKS` in `inc/Core/Assets.php` |
| **Class-based dynamic block**: an `AbstractBlock` subclass | `example-block-dynamic` with `Modules/Blocks/ExampleDynamicBlock.php` | The class's `render()` method; the example renders `templates/block-templates/example-block-dynamic.php` through `Util::templates()` | Add the class to `Modules\Blocks::get_classes()` |

Choose a class when rendering needs plugin services, several templates, or unit tests of the render logic; `render.php` is enough for simple markup. Both kinds build from `src/blocks/<block>/` into `assets/build/blocks/<block>/`. Because rendered templates go through the template loader, a theme can override them from `<theme>/acme-content-features/block-templates/`.

`AbstractBlock`'s lifecycle and overridable methods (`get_block_dir()`, `get_block_args()`) are in the framework's [block reference](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstractblock).

### Create a block

| Route | Produces | Then |
| --- | --- | --- |
| `npx wp-tooling add wp/block-dynamic` (or `/scaffold`) | An `AbstractBlock` class, its test and block sources | Register the class in `Modules\Blocks::get_classes()`. See [Scaffolding](scaffolding.md). |
| `npm run create:block` | An interactive prompt for a static, dynamic or meta block; writes `src/blocks/<slug>/` from the templates in `bin/block-scaffold/templates/` | Follow the printed next step: add the slug to `Assets::STATIC_BLOCKS`. Meta blocks are written to `src/blocks/meta-blocks/`, which `STATIC_BLOCKS` does not cover yet. |

Run `npm run build:dev` (or keep `npm start` running) and insert the block in the editor. For block editor Fast Refresh while editing components, see [Live reload and block HMR](hmr.md).

For `block.json`, attributes, supports and the Interactivity API themselves, use the WordPress [Block Editor Handbook](https://developer.wordpress.org/block-editor/).

## Troubleshooting

| Symptom | Check and next action |
| --- | --- |
| Block missing from the inserter | Metadata block: is its folder in `STATIC_BLOCKS` and built under `assets/build/blocks/`? Class block: is the class in `Blocks::get_classes()` and does `get_block_dir()` point at the build folder? |
| Script registered but not on the page | Only `frontend` is enqueued automatically; call `wp_enqueue_script()` for the others. |
| New `src/js` file not built | Its name starts with `_`, it is inside `src/js/modules/` (built as a module), or the watcher needs a restart to pick up a new entry. |
