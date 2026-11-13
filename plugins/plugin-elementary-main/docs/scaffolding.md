# Add a feature with CLI or AI

Generate a new feature class and its test instead of writing them by hand. There are two routes to the same result: the raw CLI (`npx wp-tooling add`) or the AI-guided `/scaffold` skill. Both use the same scaffold engine from `@rtcamp/wp-tooling`. The engine writes files but never edits existing ones: registering the class in its module's `get_classes()` is done by you (CLI) or by the skill after you approve the diff (AI).

The worked example adds three features to **Acme Content**: a `book` post type, a `genre` taxonomy attached to it, and a REST controller that imports books as drafts.

| Aspect | AI `/scaffold` | Raw CLI (`wp-tooling add`) |
| --- | --- | --- |
| Conventions and inputs | Inferred from your brief and the codebase | Passed explicitly as flags |
| Wiring | Added to the module's `get_classes()` after you approve the diff | You add the `::class` entry and `use` import, guided by the output's suggested snippet |
| Multi-feature order | Sequenced for you (post type before its taxonomy and REST) | Run in the order you choose |
| Code written | Implemented from your brief | A stub; the logic is yours |
| Tests | Written first (TDD) and run for you | A stub to complete |
| Quality gates (`phpcs` / `phpstan`) | Run and fixed for you | Run when you choose |
| Output | Generated from intent; review it | Deterministic, exactly as specified |

Rule of thumb: use the CLI for a single artifact you can fully specify; use `/scaffold` for several related features, unfamiliar conventions, or when you want the tests and checks handled.

## Before generating

Finish [initialization](initialization.md) and install dependencies. Work from the plugin root and read your values from `composer.json`: the examples use namespace `Acme_Content\Features`, tests namespace `Acme_Content\Features\Tests` and text domain `acme-content-features`.

Where generated files go:

| Kind | Class folder | Register it in |
| --- | --- | --- |
| `wp/cpt` | `inc/Modules/PostTypes/` | `inc/Modules/PostTypes.php` |
| `wp/taxonomy` | `inc/Modules/Taxonomies/` | `inc/Modules/Taxonomies.php` |
| `wp/rest` | `inc/Modules/REST/` | `inc/Modules/REST.php` |
| `wp/block-dynamic` | `inc/Modules/Blocks/` + `src/blocks/` | `inc/Modules/Blocks.php` |
| `wp/shortcode` | `inc/Modules/Shortcodes/` | `inc/Modules/Shortcodes.php` |
| `wp/settings-page`, `wp/admin-page` | `inc/Modules/Settings/`, `inc/Modules/Admin/` | Their module |
| `wp/user-role` | `inc/Modules/Roles/` | `inc/Modules/Roles.php` |
| `wp/cron`, `wp/registrable` | `inc/Modules/<Domain>/` | Their module |
| `wp/cli` | `inc/Modules/CLI/` | The list in `CLI::get_commands()` |
| `wp/module` | `inc/Modules/<Domain>.php` | `Main::CLASSES` |

Tests go in `tests/php/`. If you removed a domain's example set at init, its module no longer exists: generate one with `wp/module` first (or let `/scaffold` do it).

## CLI route

List the catalogue your installed engine supports:

```bash
npx wp-tooling list --json
```

Generate the features in dependency order. Append `--dry-run` to preview a command and to discover a kind's required inputs.

```bash
# Book post type
npx wp-tooling add wp/cpt --non-interactive --json \
  --namespace='Acme_Content\Features\Modules\PostTypes' --base_path=inc/Modules/PostTypes \
  --tests_namespace='Acme_Content\Features\Tests' --tests_path=tests/php \
  --text_domain=acme-content-features --slug=book --singular=Book --plural=Books --class=Book

# Genre taxonomy, attached to book (--object_type)
npx wp-tooling add wp/taxonomy --non-interactive --json \
  --namespace='Acme_Content\Features\Modules\Taxonomies' --base_path=inc/Modules/Taxonomies \
  --tests_namespace='Acme_Content\Features\Tests' --tests_path=tests/php \
  --text_domain=acme-content-features --slug=genre --object_type=book \
  --singular=Genre --plural=Genres --class=Genre

# REST controller that creates books in draft: /wp-json/acme-content-features/v1/book-import
npx wp-tooling add wp/rest --non-interactive --json \
  --namespace='Acme_Content\Features\Modules\REST' --base_path=inc/Modules/REST \
  --tests_namespace='Acme_Content\Features\Tests' --tests_path=tests/php \
  --name=book-import --rest_namespace=acme-content-features
```

For `wp/rest`, `--name` is the route segment and the class name is derived from it (`BookImportController`). Pass `--rest_namespace` without the version: the engine appends `v1` from `--rest_version` (default `1`), so `acme-content-features/v1` would produce `/v1/v1`.

Each command writes the class and a test stub. Its JSON output lists the class under `engine.wrote` and the test under `ai.tests`. The `ai.wiring` block is a suggested snippet only; nothing outside the new files is edited. Then:

1. Register each class in its module. For the book post type, in `inc/Modules/PostTypes.php`:

   ```php
   use Acme_Content\Features\Modules\PostTypes\Book;

   protected function get_classes(): array {
   	return [
   		// Keep the existing entries.
   		Book::class,
   	];
   }
   ```

   Do the same for `Genre` in `inc/Modules/Taxonomies.php` and `BookImportController` in `inc/Modules/REST.php`. If the suggested snippet names a different file or pattern (the catalogue is generic), follow this skeleton's module pattern instead.
2. Add failing assertions to the generated test (for example `post_type_exists( 'book' )`, `taxonomy_exists( 'genre' )`, the REST route's permission and draft status) and run it: `npm run test:php -- --filter BookTest`.
3. Implement the logic, rerun the test, then run `composer format`, `composer lint` and `composer phpstan`.

## AI route

In Claude Code, describe the features:

```text
/scaffold Add a book post type, a genre taxonomy attached to it, and a REST
endpoint under acme-content-features/v1 that creates books in draft for a
publisher import feed. Only administrators may call it.
```

The skill confirms conventions and a test checklist, shows the wiring diff and waits for your consent, writes failing tests, implements the features, and runs PHPCBF, PHPCS, PHPStan and the tests before reporting back. Installation and build steps also need your consent. Review the diff and the test results; a generated stub alone is not a finished feature.

With `/init` and a feature brief, the same happens during setup: init keeps the capabilities the brief needs, empties their examples, and hands each feature to `/scaffold`.

## Code consistency and standards

Code should read the same whoever, or whatever, writes it:

- **The same standards, every run.** Generated code is checked against the gates a developer runs locally: [`phpcs.xml.dist`](../phpcs.xml.dist) (the shared `rtCampWP` ruleset: WPCS, VIP, docs, Slevomat), [`phpstan.neon.dist`](../phpstan.neon.dist) (the `rtcamp/wp-phpstan` level-5 baseline), [`eslint.config.mjs`](../eslint.config.mjs) and [`.stylelintrc.json`](../.stylelintrc.json).
- **Verified, not just formatted.** The AI route writes the test first and runs it, then PHPCBF → PHPCS → PHPStan.
- **No drift between developers.** Registration, wiring and lifecycle come from the [`rtcamp/wp-primitives`](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md) abstracts, so the structure is identical whoever generates it; review can focus on the logic.

The conventions these gates enforce are listed in [AGENTS.md](../AGENTS.md).

## Verify and continue

With the environment running, confirm `wp post-type list` includes `book`, the Book editor shows a Genre panel, and the REST route rejects a non-administrator and creates a draft for an administrator. Commit once the tests and checks pass.

For other kinds, see the installed `list` output and the [upstream scaffold catalogue](https://github.com/rtCamp/wp-tooling/tree/main/node-packages/wp-tooling/scaffolds); each entry's `scaffold.json` lists its inputs and defaults, and the [engine reference](https://github.com/rtCamp/wp-tooling/blob/main/node-packages/wp-tooling/docs/ai-orchestration.md#2-the-engine-surface) documents invocation options. For the methods each generated class can override, see the framework's [abstracts reference](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/abstracts.md). For blocks, see [Blocks and assets](blocks-and-assets.md). To write a class by hand, see the [Development guide](../DEVELOPMENT.md).

## Troubleshooting

| Symptom | Check and next action |
| --- | --- |
| Files appear under `includes/` or a wrong namespace | Pass the explicit `--namespace`, `--base_path` and test flags above; check the `--dry-run` output first. |
| `EMISSINGINPUT` or another engine error | Read the error, supply that input, and rerun. Do not guess a flag; use `npx wp-tooling add <kind> --help`. |
| Class exists but nothing happens | It is not in its module's `get_classes()` (or the module is not in `Main::CLASSES`). |
| Feature runs twice | It is listed in two places, such as its module and `Main::CLASSES`; keep one. |
| `wp-env start` crashes after adding a REST controller | Overridden `WP_REST_Controller` methods must keep WordPress core's untyped signatures. Remove parameter and return types from `get_items()`, `*_permissions_check()` and similar overrides; document types in PHPDoc. |
| Generated block has no assets | Run `npm run build:dev` and confirm `assets/build/blocks/<block>/` exists. |
