# What the plugin includes

The skeleton provides infrastructure you build on, examples you can adapt, and optional development tools you turn on when needed. This page maps all three: what each is, where its source lives, which framework piece it builds on, and how to see it working. [Initialization](initialization.md) owns the commands that keep, remove and toggle them; [Local development](local-development.md) owns builds and checks.

Names below use an initialized **Acme Content** project. In the unrenamed skeleton, read `acme-content-features` as `amc-automata-features-features`, `acme_content` as `amc_automata_features` and `ACME_CONTENT` as `AMC_AUTOMATA_FEATURES`.

## Plugin infrastructure

These stay in every project, whichever example sets you remove. Most are thin plugin-owned subclasses of a framework class; follow the link for that class's full API.

| Facility | Skeleton source | Framework piece | Default / how to use it |
| --- | --- | --- | --- |
| Bootstrap and registration | `acme-content-features.php`, `inc/Autoloader.php`, `inc/Main.php` | `Singleton`, `Loader`, `Registrable`, `Container` ([architecture](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/architecture.md), [contracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/contracts.md)) | The entry file defines the `ACME_CONTENT_FEATURES_{FILE,VERSION,PATH,URL}` constants, loads Composer, and boots `Main`. Every loaded class is listed in `Main::CLASSES`. |
| Feature modules | `inc/Modules/<Domain>.php` | `AbstractModule` ([abstracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstractmodule), [modules](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/architecture.md#modules-loaders-that-hold-loaders)) | Each domain's module returns its concrete classes from `get_classes()`. See the [Development guide](../DEVELOPMENT.md#the-module-pattern). |
| Asset registration | `inc/Core/Assets.php`, `src/` | `AssetLoader` ([loaders](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/loaders.md#assetloader)) | Enqueues `main.js`/`main.css` on the frontend, registers `admin` and `editor` handles, registers `block.json` blocks and the Interactivity module. See [Blocks and assets](blocks-and-assets.md). |
| Templates | `inc/Core/Templates.php`, `templates/` | `TemplateLoader` ([loaders](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/loaders.md#templateloader)) | `Util::templates()->get( 'block-templates/example-block-dynamic' )`. A theme overrides a template by copying it to `<theme>/acme-content-features/`. |
| Components | `inc/Core/Components.php` | `ComponentLoader` ([loaders](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/loaders.md#componentloader)) | Loader only; no components ship. Put PHP in `src/components/<Name>/<Name>.php` and assets in `src/css/components/` and `src/js/components/`. Resolve it with `Main::get_instance()->get_shared( Components::class )`. |
| Logging | `inc/Core/Logger.php`, `Util::logger()` | `Logger` ([utilities](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/utilities.md#logger)) | `Util::logger()->info( 'Imported', [ 'count' => 3 ] )`. Silent unless `WP_DEBUG`. |
| Encryption | `inc/Core/Encryption.php`, `Util::encryption()` | `Encryptor` ([utilities](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/utilities.md#encryptor)) | Define `ACME_CONTENT_FEATURES_ENCRYPTION_KEY` in `wp-config.php` first; without it, calls throw. `Util::encryption()->encrypt( $secret )` / `->decrypt()`. |
| Activation and i18n | `inc/Core/PluginSetup.php` | `Registrable` | Stores `acme_content_features_version` on activation, unschedules the example cron job on deactivation, loads the text domain from `languages/`. |
| Helpers | `inc/Helpers/Util.php` | — | Accessors for the shared services above, plus `get_data()` (reads `inc/data/<slug>.php`), `is_production()` and `is_mobile()`. |
| Quality checks | `tests/`, `phpcs.xml.dist`, `phpstan.neon.dist`, `eslint.config.mjs`, `.stylelintrc.json` | Shared `rtcamp/wp-phpcs`, `rtcamp/wp-phpstan`, `@rtcamp/eslint-config`, `@rtcamp/stylelint-config` | PHPUnit (one test per module), PHPCS, PHPStan level 5, ESLint, Stylelint, Jest. See [Check a change](local-development.md#check-a-change). |

The shared services (`Assets`, `Templates`, `Components`, `Logger`, `Encryption`) implement `Shareable`, so the `Loader` keeps one instance in `Main`'s container. Why that is preferred over singletons is explained in the framework's [sharing vs. singletons](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/architecture.md#sharing-vs-singletons).

## Supplied examples

Every set is kept by default. Removing a set at init deletes its module, classes, test and registration; it is not a switch you can flip back. Each kept example is a working, tested reference: copy it, rename it, and replace its logic.

| Init key | Source | What it demonstrates | How to see it |
| --- | --- | --- | --- |
| `post-types` | `Modules/PostTypes/ExamplePostType.php`, `ExamplePostTypeTwo.php` | `AbstractPostType`: slug, labels, menu icon | Admin menu **Post Type Labels**; `wp post-type list` includes `post-type-slug`. |
| `taxonomies` | `Modules/Taxonomies/ExampleTaxonomy.php`, `ExampleTaxonomyTwo.php` | `AbstractTaxonomy` attached to the example post types via `get_object_types()` | `wp taxonomy list` includes `taxonomy-slug`; it appears on the example post type's editor. |
| `blocks` | `Modules/Blocks/ExampleDynamicBlock.php`, `src/blocks/example-block*`, `templates/block-templates/example-block-dynamic.php` | A static block, a dynamic block (`AbstractBlock` rendering a template) and an Interactivity API block (`render.php` + `view.js`) | After `npm run build:dev`, insert **Example Block**, **Example Dynamic Block** or **Example Block Interactive** in the editor. |
| `shortcodes` | `Modules/Shortcodes/ExampleShortcode.php` | `AbstractShortcode` with default attributes, sanitized input and escaped output | Add `[acme_content_example title="Hello" count="3"]` to a post. |
| `rest` | `Modules/REST/ExampleRESTController.php` | `AbstractRESTController` with a real `permission_callback` (`manage_options`) | `GET /wp-json/acme-content-features/v1/examples` as an administrator (see below). |
| `cli` | `Modules/CLI.php`, `Modules/CLI/Healthcheck.php` | A `ConditionallyRegistrable` module that only loads under WP-CLI, and a `CLICommand` | `npm run wp-env run cli -- wp acme-content-features health-check` |
| `cron` | `Modules/Cron/ExampleCronJob.php` (+ region in `Core/PluginSetup.php`) | A plain `Registrable` that schedules a daily event on `init` and clears it on deactivation | `npm run wp-env run cli -- wp cron event list` shows `acme_content_features_daily_cleanup`. |
| `settings` | `Modules/Settings/ExampleSettingsPage.php` | `AbstractSettingsPage`: fields, sanitize callbacks, Settings API rendering | **Settings → Acme Content Features**; save the text field and reload. |
| `admin` | `Modules/Admin/ExampleAdminPage.php` | `AbstractAdminPage` as a submenu of Tools | **Tools → Features Tools**. |
| `roles` | `Modules/Roles/ExampleUserRole.php` | `AbstractUserRole` with versioned capabilities (bump `get_version()` to re-sync) | The role is added on `admin_init`: after loading any admin page, `wp role list` includes `acme_content_content_editor` (**Content Editor**). |
| `cache` | `Modules/Cache/ExampleCache.php` | Framework `Cache`: `remember()` plus group invalidation on `save_post` ([utilities](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/utilities.md#cache)) | No UI; call `get_recent_post_ids()` from your own code or a test. |
| `transients` | `Modules/Transients/ExampleTransients.php` | Framework `Transients` caching a remote request for an hour ([utilities](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/utilities.md#transients)) | No UI; call `get_remote_payload()` from a block, shortcode or REST route. |
| `test-measure` | `.github/workflows/test-measure.yml` | The CI caller for wp-shared-workflows | Runs on every pull request and on pushes to `master`. |

Paths are relative to `inc/`, except the `src/`, `templates/` and `.github/` entries. Each module's test is `tests/php/<Module>Test.php`.

To call the REST example from the terminal as an administrator:

```bash
npm run wp-env run cli -- wp --user=admin eval \
  'echo wp_json_encode( rest_do_request( "/acme-content-features/v1/examples" )->get_data() );'
```

After you remove an example, the original stays browsable in the [skeleton source](https://github.com/rtCamp/plugin-elementary/tree/main/inc/Modules). To build your own version of any of these, use [Scaffolding](scaffolding.md) or the [Development guide](../DEVELOPMENT.md).

## Optional development features

Unlike example sets, these can be turned on and off at any time with `npm run init -- --enable=<key>` / `--disable=<key>`.

| Init key | Skeleton source | Default | Result | More |
| --- | --- | --- | --- | --- |
| `hmr` | `webpack.config.js`, `webpack.blocks.config.js`, `inc/Core/Assets.php`, `.env.local` | On | BrowserSync live reload for assets and PHP; Fast Refresh for block editor components. Toggling only flips `ENABLE_HMR`; dependencies stay installed. | [Live reload](hmr.md) |
| `tailwind` | `bin/features/tailwind/`, `inc/Core/Assets.php`, main plugin file | Off | Adds `src/css/tailwind.css` and `postcss.config.js`, declares Tailwind dependencies, and sets `ACME_CONTENT_FEATURES_ENABLE_TAILWIND` to `true` so the stylesheet is enqueued. | [Tailwind](tailwind.md) |
