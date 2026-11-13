# Development guide

This guide shows how to extend the plugin by hand: where new code belongs, which framework base class to start from, and worked, test-first examples. Use it after [initialization](docs/initialization.md). For generated classes, see [Scaffolding](docs/scaffolding.md); the result follows the same pattern.

Run commands from the plugin directory. The examples use an initialized amc automata featuresd **Acme Content**: namespace `Acme_Content\Features`, tests namespace `Acme_Content\Features\Tests`, text domain `acme-content-features`. Replace them with the values in your own `composer.json` and plugin header.

The plugin sits on two layers. `vendor/rtcamp/wp-primitives/` is the upstream [framework](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/index.md): the registration system, abstract base classes, loaders and utilities, installed by Composer. `inc/` is everything project-specific. Never edit `vendor/`; it is overwritten on the next `composer install`, and framework changes belong in the framework repository.

## Before adding code

| Location | Purpose |
| --- | --- |
| `inc/Main.php` | Bootstrap. `Main::CLASSES` lists every class the plugin loads: core services and one module per domain. |
| `inc/Modules/<Domain>.php` | A module (`AbstractModule`) that owns one domain's classes through `get_classes()`. |
| `inc/Modules/<Domain>/` | The concrete classes for that domain. |
| `inc/Core/` | Always-loaded infrastructure and shared services. |
| `inc/Helpers/` | Stateless static utilities: `final`, private constructor, static methods only. |
| `templates/` | PHP templates a theme can override. |
| `src/` | Editable JS, CSS and block sources; built into `assets/build/` (never edit the output). |
| `tests/php/` | PHPUnit tests, one `<Name>Test.php` per module or feature, extending `Acme_Content\Features\Tests\TestCase`. |

Namespaces follow PSR-4 from `inc/`: directory segments match namespace segments and the file name matches the class (`Acme_Content\Features\Modules\PostTypes\Book` → `inc/Modules/PostTypes/Book.php`). Do not add `classmap` autoloading.

Every PHP file starts with `declare( strict_types = 1 );`, uses full parameter and return types, carries `@package` / `@since` docblocks, and uses `static::` rather than `self::`. The full rule set, including the security rules below, is in [AGENTS.md](AGENTS.md) and [`.github/instructions/`](.github/instructions/).

## How a class gets loaded

1. The main plugin file defines constants, loads Composer through `inc/Autoloader.php`, and calls `Main::get_instance()`.
2. `Main` (`use Singleton; use Loader;`) passes `Main::CLASSES` to the framework `Loader`.
3. The `Loader` instantiates each class. If it implements `Registrable`, it calls `register_hooks()`; a `ConditionallyRegistrable` class is skipped when `can_register()` returns false. If it implements `Shareable`, the instance is kept in `Main`'s container for `get_shared()`.
4. A module is itself `Registrable`: its `register_hooks()` loads the classes from its `get_classes()` the same way.

A class that is not reachable from `Main::CLASSES` never runs, even if it autoloads. The framework's [architecture overview](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/architecture.md) explains the loop and [when hooks actually fire](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/architecture.md#when-hooks-actually-fire).

**Do not default to `Singleton`.** It is for `Main` only. Use a plain `Registrable` loaded by a module; add `Shareable` only when another class must retrieve the instance (see [sharing vs. singletons](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/architecture.md#sharing-vs-singletons)).

## The module pattern

Each domain has a module that lists its classes:

```php
// inc/Modules/PostTypes.php
final class PostTypes extends AbstractModule {
	protected function get_classes(): array {
		return [
			ExamplePostType::class,
			ExamplePostTypeTwo::class,
		];
	}
}
```

`Main::CLASSES` lists modules, not individual feature classes. To add a post type, add a class to `inc/Modules/PostTypes/` and to `PostTypes::get_classes()`; `Main.php` is untouched. A new domain gets a new module, added once to `Main::CLASSES`.

**Exception: WP-CLI.** `Modules\CLI` is a `ConditionallyRegistrable` class, not an `AbstractModule`, so it only loads under WP-CLI. Commands implement `CLICommand` and are listed in `CLI::get_commands()`, which registers each as `wp acme-content-features <name>`.

## Picking a base

| Feature | Extends / implements | Framework reference |
| --- | --- | --- |
| Custom post type | `AbstractPostType` | [abstracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstractposttype) |
| Taxonomy | `AbstractTaxonomy` | [abstracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstracttaxonomy) |
| Dynamic block rendered by a class | `AbstractBlock` (see [Blocks and assets](docs/blocks-and-assets.md#blocks)) | [abstracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstractblock) |
| Shortcode | `AbstractShortcode` | [abstracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstractshortcode) |
| REST controller | `AbstractRESTController` | [abstracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstractrestcontroller) |
| Settings page | `AbstractSettingsPage` | [abstracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstractsettingspage) |
| Other admin page | `AbstractAdminPage` | [abstracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstractadminpage) |
| User role | `AbstractUserRole` | [abstracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstractuserrole) |
| Behaviour behind a runtime feature flag | `AbstractFeature` (not used by the skeleton yet) | [abstracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstractfeature) |
| WP-CLI command | `CLICommand` interface, listed in `CLI::get_commands()` | [contracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/contracts.md#clicommand) |
| Cron job, cache or transient wrapper, integration — anything that just wires hooks | `Registrable` interface (see `ExampleCronJob`, `ExampleCache`) | [contracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/contracts.md#registrable) |
| Same, but only in some contexts | `ConditionallyRegistrable` interface | [contracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/contracts.md#conditionallyregistrable) |
| A service other classes retrieve | `Registrable` + `Shareable`, listed in `Main::CLASSES` | [contracts](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/contracts.md#shareable) |

Never call `register_post_type()`, `register_taxonomy()`, `register_rest_route()` outside a controller, `add_menu_page()`, `add_shortcode()` or `register_block_type()` directly from a feature class: extend the matching abstract. The framework's [hook table](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#which-hook-each-one-uses) shows which WordPress hook each abstract uses.

## Add a post type

This example adds a `book` post type. It shows the four pieces every feature needs: a test, a class, registration and a check. The same classes are what the starter theme's documentation expects a companion content plugin to contain.

Start with the failing test, `tests/php/BookTest.php`:

```php
<?php
/**
 * Tests for the Book post type.
 *
 * @package Acme_Content\Features
 */

declare( strict_types = 1 );

namespace Acme_Content\Features\Tests;

use Acme_Content\Features\Modules\PostTypes;
use Acme_Content\Features\Modules\PostTypes\Book;
use ReflectionMethod;

/**
 * Class BookTest
 */
final class BookTest extends TestCase {

	/**
	 * The PostTypes module lists Book.
	 */
	public function test_module_lists_book(): void {
		$method = new ReflectionMethod( PostTypes::class, 'get_classes' );
		$method->setAccessible( true );

		$this->assertContains( Book::class, $method->invoke( new PostTypes() ) );
	}

	/**
	 * Registering creates the book post type with its labels.
	 */
	public function test_registers_book(): void {
		( new Book() )->register();

		$this->assertTrue( post_type_exists( 'book' ) );
		$this->assertSame( 'Books', get_post_type_object( 'book' )->labels->name );
	}
}
```

Run it and confirm it fails: `npm run test:php -- --filter BookTest`.

Then create `inc/Modules/PostTypes/Book.php`:

```php
<?php
/**
 * Book post type.
 *
 * @package Acme_Content\Features
 */

declare( strict_types = 1 );

namespace Acme_Content\Features\Modules\PostTypes;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractPostType;

/**
 * Class Book
 *
 * @since 1.0.0
 */
final class Book extends AbstractPostType {

	/**
	 * {@inheritDoc}
	 */
	public static function get_slug(): string {
		return 'book';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_singular_label(): string {
		return __( 'Book', 'acme-content-features' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_plural_label(): string {
		return __( 'Books', 'acme-content-features' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_menu_icon(): string {
		return 'dashicons-book';
	}
}
```

Register it in `inc/Modules/PostTypes.php`, keeping the existing entries:

```php
use Acme_Content\Features\Modules\PostTypes\Book;

protected function get_classes(): array {
	return [
		// Keep the existing entries.
		Book::class,
	];
}
```

Rerun the test, then check the site: `npm run wp-env run cli -- wp post-type list` includes `book`, and **Books** appears in the admin menu. `AbstractPostType` registers on `init`; override `get_editor_supports()`, `get_custom_options()` and the other methods listed in the [post type reference](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md#abstractposttype) to change its behaviour.

## Add a taxonomy

The remaining examples trim docblocks for brevity; PHPCS requires them as in the example above.

Add the assertion first, in `tests/php/GenreTest.php`:

```php
public function test_registers_genre_for_books(): void {
	( new Genre() )->register();

	$this->assertTrue( taxonomy_exists( 'genre' ) );
	$this->assertContains( 'book', get_taxonomy( 'genre' )->object_type );
}
```

Then `inc/Modules/Taxonomies/Genre.php`. `get_object_types()` is what attaches it to books:

```php
namespace Acme_Content\Features\Modules\Taxonomies;

use Acme_Content\Features\Modules\PostTypes\Book;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractTaxonomy;

final class Genre extends AbstractTaxonomy {
	public static function get_slug(): string {
		return 'genre';
	}

	public static function get_object_types(): array {
		return [ Book::get_slug() ];
	}

	public function get_singular_label(): string {
		return __( 'Genre', 'acme-content-features' );
	}

	public function get_plural_label(): string {
		return __( 'Genres', 'acme-content-features' );
	}
}
```

Append `Genre::class` to `Taxonomies::get_classes()`. After the tests pass, `wp taxonomy list` includes `genre` and the Book editor shows a Genres panel.

## Add a new domain

When a feature fits no existing module, for example a publisher feed integration:

1. Write the test for the behaviour, then the class, `inc/Modules/Integrations/PublisherFeed.php`, implementing `Registrable` and adding its hooks in `register_hooks()`.
2. Create the module, `inc/Modules/Integrations.php`:

   ```php
   namespace Acme_Content\Features\Modules;

   use Acme_Content\Features\Modules\Integrations\PublisherFeed;
   use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

   final class Integrations extends AbstractModule {
   	protected function get_classes(): array {
   		return [
   			PublisherFeed::class,
   		];
   	}
   }
   ```

3. Add `Modules\Integrations::class` to `Main::CLASSES` in `inc/Main.php`.
4. Add `tests/php/IntegrationsTest.php` asserting the module lists the class and is in `Main::CLASSES`, as the existing module tests do.

`npx wp-tooling add wp/module` generates the module for you.

## Add a WP-CLI command

Implement `CLICommand` (`get_name()`, `get_description()`, static `run( array $args, array $assoc_args )`) in `inc/Modules/CLI/`, then add the class to the list in `CLI::get_commands()`. `Modules/CLI/Healthcheck.php` is the reference. The command becomes `wp acme-content-features <name>`.

## Use the shared services

`inc/Core/` services are shared through `Main`'s container. `Helpers\Util` has accessors for the common ones:

```php
use Acme_Content\Features\Helpers\Util;

Util::logger()->info( 'Feed imported', [ 'count' => $count ] );   // silent unless WP_DEBUG
$cipher = Util::encryption()->encrypt( $api_key );                 // needs ACME_CONTENT_FEATURES_ENCRYPTION_KEY
$html   = Util::templates()->get( 'content/book-card', null, [ 'book' => $post ] );
```

Anything shared without an accessor (`Assets`, `Components`) is retrieved with `Main::get_instance()->get_shared( Assets::class )`.

To add a shared service, create `inc/Core/<Service>.php` implementing `Shareable` (and `Registrable` if it hooks WordPress), add it to `Main::CLASSES` under the core services, and add a one-line accessor to `Util`. The framework utilities you can wrap this way are documented in [utilities](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/utilities.md): `Cache`, `Transients`, `Encryptor`, `Logger`, `FeatureSelector`, `Timer`.

## Conditional registration

Implement `ConditionallyRegistrable` instead of `Registrable` when a class should load only in some contexts:

```php
final class ImportTools implements ConditionallyRegistrable {
	public function can_register(): bool {
		return is_admin();
	}

	public function register_hooks(): void {
		// Wire admin-only hooks here; check capabilities inside the callbacks.
	}
}
```

The `Loader` calls `can_register()` first and skips `register_hooks()` when it returns false. `can_register()` runs while plugins are loading, before the current user is set up, so keep it to checks that are valid that early (constants, `is_admin()`, `wp_doing_ajax()`); do capability checks inside the hooked callbacks.

## Security checklist

- Escape on output (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`); sanitize on input (`sanitize_text_field()`, `absint()`, `sanitize_key()`).
- Verify a nonce **and** `current_user_can()` before any mutation (form, AJAX or REST).
- Use `$wpdb->prepare()` for every query with input.
- Give every REST route a real `permission_callback` and an `args` schema; never `__return_true` for writes.
- Wrap user-facing strings in `__()` / `esc_html__()` with the plugin's text domain.
- Enqueue assets conditionally on the screens that need them; no inline `<script>` or `<style>`.

## Finish a change

Run the focused test, then the full checks in [Local development](docs/local-development.md#check-a-change): `npm run test:php`, `composer format`, `composer lint`, `composer phpstan`, and the JS and CSS checks if you touched `src/`. New classes are picked up by PSR-4 without extra steps; run `composer dump-autoload` if you renamed or moved one.

## Troubleshooting

| Symptom | Check and next action |
| --- | --- |
| Class autoloads but nothing happens | Is it in its module's `get_classes()`, and is that module in `Main::CLASSES`? For CLI commands, is it in `CLI::get_commands()`? |
| `Class not found` | The namespace must match the directory and the file name the class. Run `composer dump-autoload` after moving files. |
| Hook callback never runs | Check `register_hooks()` adds it, and that the hook has not already fired when the class loads (see [when hooks fire](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/architecture.md#when-hooks-actually-fire)). |
| `get_shared()` fails | The class must implement `Shareable` and be listed in `Main::CLASSES` (a module's classes are not shared through `Main`). |
| Post type or taxonomy missing | Run `wp post-type list` / `wp taxonomy list`; confirm registration and that the plugin is active. |
| Composer dependencies not installed | The plugin shows an admin notice instead of a fatal error. Run `composer install` and reload. |

Keep framework API details in the [framework docs](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/index.md), and see [Included features](docs/features.md) for what the skeleton already provides.
