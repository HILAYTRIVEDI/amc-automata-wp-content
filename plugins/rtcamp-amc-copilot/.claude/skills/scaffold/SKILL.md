---
name: scaffold
description: Add a scaffold (PHP class, block, CI workflow) to the current project using @rtcamp/wp-tooling. TDD-first - derive test cases from the developer brief, scaffold via the engine, write tests, then implement to green under a red-green-refactor loop. Never runs package-manager commands or writes secret values; surfaces them as developer actions.
---

# scaffold

Drive `@rtcamp/wp-tooling`. Map the developer's request to a scaffold, derive test cases first, invoke the engine, expand tests, implement to green, report.

This skill is the canonical wp-tooling scaffold skill, tailored to this skeleton's structure (`inc/Modules/<Kind>`, namespace `<Root>\Modules\<Kind>`, tests in `tests/php/`) and the private-package pilot.

## Use for

CPT, taxonomy, REST controller, dynamic block, shortcode, cron, CLI command, admin/settings page, user role, plain Registrable service, framework module, CI/CD workflow.

## Do not use for

Refactors, bug fixes, hand-written features, anything no scaffold covers.

## Workflow

### 0. Plan and announce - before any other work

Before discovery, scaffolding, or coding, write a TODO list covering every step you intend to run for this task and show it to the developer. Use the host's task-tracking surface (`TodoWrite` in Claude Code; the host equivalent elsewhere).

Minimum entries:

1. Introspect project (§2).
2. Derive test-case checklist (§4) and confirm with developer.
3. Scaffold call(s) - one entry per kind for multi-kind features.
4. Apply wiring (§6a) - one entry per `ai.wiring` snippet, with consent.
5. Expand tests, run red (§7 A-B).
6. Implement to green (§7 C-E), one test at a time.
7. Refactor + final gates (§7 F-G).
8. Report (§9).

Update the list in real time. Mark each entry `in_progress` before starting it and `completed` the moment it is done. Exactly one entry `in_progress` at any time. Add new entries when the work reveals them (e.g. a missing kind-module, a stuck-loop escalation).

This makes progress legible to the developer and gives them a stable surface to interrupt or re-prioritise.

### 1. Discover

```bash
npx wp-tooling list --json
```

Result: `{ scaffolds: [{ id, slug, category, kind, origin, counts }, ...] }`. Pick one `category/slug`. If ambiguous, ask the developer with candidates. Never guess. Entries with `origin: "remote"` live in another repo: their manifest is fetched on the first `add`, so `counts` is `null` here and the kind is always `template`. That first `add` does network I/O and can fail with `EFETCHFAIL` (see Engine errors) - treat a remote scaffold exactly like a local one once it resolves; the only difference is the fetch.

### 2. Introspect once per session (cache result)

**Reuse `/init`'s findings if it handed off to you in this session.** When `/init` chained here, you already established the resolved identity and conventions while running init - root namespace, base path (`inc/`), tests namespace + path (`<Root>\Tests` -> `tests/php/`), text domain, and constant prefix. They are facts you set, not guesses: reuse them and SKIP the `composer.json` / plugin-header / `Main.php` reads below that only recover them. You still read a sample implementation for the registration pattern (next paragraph). Because init deleted the briefed `Example*`, sample the pattern from the framework abstract for the kind (`vendor/rtcamp/wp-primitives/inc/Contracts/Abstracts/Abstract<Kind>.php`) or any remaining example; do not guess.

**The graph saves tokens for orientation only; read the file for any data you copy.** This repo commits a queryable graph (`graphify-out/graph.json`). Use it to LOCATE things cheaply so you open fewer files: which classes implement a kind, what references a symbol, the path between two classes - `graphify query "..."`, `graphify explain "<Class>"`, `graphify affected "<Class>"`, `graphify path "A" "B"`. The graph is structural (not the full source), so it is NOT a source of truth for the patterns you act on. For registration shape, exact namespace, how a module's `get_classes()` lists classes, and wiring location, **read the actual file** - 100% accuracy beats a few saved tokens. (If you or init changed code this session, refresh the local slice first with `graphify update .`, seconds, **never push `graphify-out/graph.json`** - AGENTS.md graphify policy.)

Read, in order (use the graph to find them fast; read the files for their contents):

- `composer.json` -> `autoload.psr-4` (root namespace + base path; here `<Root>\` -> `inc/`, e.g. `RT_Testimonial\Features\` -> `inc/`). `autoload-dev.psr-4` for the tests namespace (`<Root>\Tests\` -> `tests/php/`).
- `package.json` -> scripts. For block scaffolds, parse `build:blocks` for `--output-path=<DIR>` and pass as `--build_dir`. Default `assets/build/blocks`.
- Main plugin file -> bootstrap class (`Main`) + `Main::CLASSES`.
- 2-3 existing implementations of the same kind under `inc/Modules/<Kind>/` (e.g. `Example*`): registration pattern, class-name suffix, sub-namespace, and how the module's `get_classes()` lists them. **Read these files** - they are the source of truth for the patterns you copy; use `graphify` only to find them.
- Block scaffolds: sample one `src/blocks/*/block.json` for vendor prefix and source dir.
- CI scaffolds: sample one `.github/workflows/*.yml` for filename and trigger style.

Anchors (`// scaffold:<kind>:classes`) are hints, not ground truth. Sampled patterns win.

Confirm findings with the developer in one short message. Proceed on confirmation.

### 3. Canonical layout

Files group by **kind**, never by feature. `<Root>` = this skeleton's `composer.json` `autoload.psr-4` root (e.g. `RT_Testimonial\Features` -> `inc/`); tests autoload via `<Root>\Tests` -> `tests/php/`. **The engine's own defaults target a different layout (`includes/...`, `Inc\...`, `tests/...`), so you MUST pass this skeleton's conventions on every `add` (§5).**

| Scaffold | Source dir | Source ns | Test dir | Test ns | Module file (wire here) |
|---|---|---|---|---|---|
| `wp/cpt` | `inc/Modules/PostTypes/` | `<Root>\Modules\PostTypes` | `tests/php/` | `<Root>\Tests` | `inc/Modules/PostTypes.php` |
| `wp/taxonomy` | `inc/Modules/Taxonomies/` | `<Root>\Modules\Taxonomies` | `tests/php/` | `<Root>\Tests` | `inc/Modules/Taxonomies.php` |
| `wp/block-dynamic` | `inc/Modules/Blocks/` + `src/blocks/<slug>/` + `assets/build/blocks/<slug>/` | `<Root>\Modules\Blocks` | `tests/php/` | `<Root>\Tests` | `inc/Modules/Blocks.php` |
| `wp/rest` | `inc/Modules/REST/` | `<Root>\Modules\REST` | `tests/php/` | `<Root>\Tests` | `inc/Modules/REST.php` |
| `wp/shortcode` | `inc/Modules/Shortcodes/` | `<Root>\Modules\Shortcodes` | `tests/php/` | `<Root>\Tests` | `inc/Modules/Shortcodes.php` |
| `wp/admin-page` | `inc/Modules/Admin/` | `<Root>\Modules\Admin` | `tests/php/` | `<Root>\Tests` | `inc/Modules/Admin.php` |
| `wp/settings-page` | `inc/Modules/Settings/` | `<Root>\Modules\Settings` | `tests/php/` | `<Root>\Tests` | `inc/Modules/Settings.php` |
| `wp/user-role` | `inc/Modules/Roles/` | `<Root>\Modules\Roles` | `tests/php/` | `<Root>\Tests` | `inc/Modules/Roles.php` |
| `wp/cli` | `inc/Modules/CLI/` | `<Root>\Modules\CLI` | `tests/php/` | `<Root>\Tests` | `inc/Modules/CLI.php` |
| `wp/cron` | `inc/Modules/Cron/` | `<Root>\Modules\Cron` | `tests/php/` | `<Root>\Tests` | `inc/Modules/Cron.php` |
| `wp/registrable` | `inc/Modules/<Module>/` | `<Root>\Modules\<Module>` | `tests/php/` | `<Root>\Tests` | `inc/Modules/<Module>.php` |

Match the existing directory case exactly (`REST`, `CLI`, not `Rest`/`Cli`).

**Modules host one kind each. No `Modules/<Feature>/...`.** A multi-kind feature (e.g. Testimonials = CPT + taxonomy + REST) spans the per-kind directories and wires into each kind's module.

If the project already has a per-feature module folder, flag as anti-pattern. Offer migration before adding new artifacts. Do not scaffold into it.

### 4. Derive test cases from the developer brief - BEFORE any scaffold call

Write a test-case checklist covering:

- **Happy path** - central behaviour the developer stated.
- **Edge cases** - empty/missing/boundary inputs, large input.
- **Error paths** - invalid input, missing auth, wrong capability.
- **Integration** - kind-specific:
  - `wp/cpt`: `post_type_exists()`, supports, REST exposure, attached taxonomies.
  - `wp/taxonomy`: `taxonomy_exists()`, attached object types, term assignment.
  - `wp/rest`: route appears in `rest_get_server()->get_routes()`, permission check, request/response schema, dedupe behaviour.
  - `wp/block-dynamic`: block name, `register_hooks` action, `render()` markup with `WP_Query` fixture, empty state, count cap, attribute filters.
  - `wp/cron`: `wp_next_scheduled()`, callback fires, unschedule works.
  - `wp/cli`: `WP_CLI::add_command` registered, `__invoke` behaviour, dry-run flag.

Show the checklist to the developer. Ask: confirm, add, remove? Resolve before scaffolding. This is the cheapest place to catch a misread requirement.

### 5. Apply conventions, invoke the engine

Always invoke the engine for any kind it covers. Hand-writing is not a substitute.

**Always pass this skeleton's conventions (§3)** - the engine defaults target a different layout. With `<Root>` = the autoload root and `<Kind>` = the module dir:

```bash
npx wp-tooling add wp/<kind> --non-interactive --json \
    --namespace='<Root>\Modules\<Kind>' --base_path=inc/Modules/<Kind> \
    --tests_namespace='<Root>\Tests' --tests_path=tests/php --text_domain=<text-domain> \
    --slug=<slug> --class=<Class> --<other-inputs>=<value> ...
```

Per-scaffold inputs are not listed by `--help`; run once with `--dry-run` to discover required inputs (a CPT needs `slug`/`singular`/`plural`; a taxonomy also needs `--object_type=<cpt-slug>` to attach it to a CPT; a REST controller needs `--name` + `--rest_namespace`). Dry-run preview: append `--dry-run`. Never use the interactive wizard.

**Multi-kind feature run order:**

1. `wp/module` for any missing kind-module (e.g. `--name=PostTypes --kind=cpt`). Most kept capabilities already ship a module (`inc/Modules/<Kind>.php`).
2. Artifacts in dependency order: CPT before any taxonomy attaching to it; CPT before any block/REST controller querying it.
3. Re-read `ai.wiring` after each call.

Result shape: `{ scaffold, engine, developer, ai, warnings }`.

### 6. Process the result

| Block | Action |
|---|---|
| `engine.wrote` / `engine.skipped` | Already on disk. Report. |
| `developer.install.composer` / `developer.install.npm` | Print as copy-paste command. **Never run `composer require` / `npm install`.** Pilot notes: npm installs need `npm install --install-links`; the framework (`rtcamp/wp-primitives`) already ships, so ignore a `composer require rtcamp/wp-primitives` suggestion. |
| `developer.secrets` | Print as `gh secret set` checklist. **Never read/write/log/transmit values.** |
| `ai.wiring` | Adaptive wiring with consent (see 6a). |
| `ai.tests` | Mandatory expansion under TDD loop (see 7). |
| `warnings` | Print to developer. |

#### 6a. Adaptive wiring

For each `{ targetFile, anchor, snippet, description }`:

0. **Target file** - the engine miscomputes `targetFile` for this layout (it emits `inc/Modules/Modules/<Kind>.php`, doubled). The real module file is `inc/Modules/<Kind>.php` (§3).
1. **Snippet** - use canonical if it matches the sampled project pattern; else translate using the sampled shape with the new class substituted. Show both. If patterns conflict or no samples exist, ask.
2. **Location** - the skeleton modules carry no `// scaffold:<kind>:classes` anchor, so insert the `::class` after the last existing entry in the module's `get_classes()` array (and add the matching `use` import). Anchor present -> after it; else after the last `::class` in `get_classes()`; else skip and print as a manual instruction.
3. **Consent** - show targetFile + line range + description + rendered snippet. Ask `[apply / different location / edit snippet / skip]`. Never apply without consent.
4. **Idempotent** - search first, do not re-insert.

### 7. TDD loop (mandatory for every scaffolded artifact)

Tests come before implementation. No exceptions. If the developer says "skip tests", explain the policy and decline.

For block scaffolds, surface a developer action before testing: "run `npm run build` so the editor can read the compiled block." Do not run it yourself.

| Step | Action |
|---|---|
| A | Expand the engine's stub into the full suite from §4's checklist. Strip every `markTestIncomplete`. |
| B | Run: `npm run test:php` (PHP, under wp-env) / `npm run test:js` (JS). Expect red. PHP tests need the WP test env: if the runner cannot connect, surface `npm run wp-env start` as a developer action and retry. |
| C | Implement just enough production code to flip **one** failing test green. |
| D | Re-run. Confirm that one test passes. |
| E | Loop B-D one test at a time. |
| F | Once green, refactor; re-run. |
| G | Final gates - all must pass: full PHPUnit suite (`npm run test:php`), full Jest suite if JS touched (`npm run test:js`), `composer format` (phpcbf), `composer lint` (phpcs), `composer phpstan` (PHPStan level 5), `npm run lint:js`. Never silence a real phpcs/phpstan finding with a blanket ignore; fix it, or escalate (§8). |

Frameworks per kind:

| Kind | Framework |
|---|---|
| `wp/cpt`, `wp/taxonomy`, `wp/cron`, `wp/cli`, `wp/rest`, `wp/shortcode`, `wp/admin-page`, `wp/settings-page`, `wp/user-role`, `wp/registrable` | PHPUnit |
| `wp/block-dynamic` | Jest (edit.js) + PHPUnit (render method) |
| `block/interactive` | Jest + Playwright |
| `ci/*` | actionlint + yaml-parse |

### 8. Escalate when stuck - do not guess

Stop and report findings to the developer when any of the following holds. Wait for response before continuing.

- 3 consecutive iterations of step C-D on the same test without progress.
- A test result contradicts your model of the code (likely hallucination - re-read the file on disk before guessing again).
- Sampled project patterns conflict and you cannot resolve which to follow.
- A `ai.wiring` snippet's anchor and project pattern both differ from canonical.
- Developer requirements remain ambiguous after one round of clarification.

Escalation report format: **what you tried, what you observed, what's blocking, 1-3 specific resolution options.** Do not keep iterating in silence.

### 9. Refresh the graph, then report

Once green, refresh the LOCAL graph so it reflects the new artifacts (and any later query stays accurate): `graphify update .` (tree-sitter, seconds). **Never commit or push `graphify-out/graph.json`** - it is a maintainer-maintained cross-repo baseline; your refresh stays local (AGENTS.md graphify policy). Do not run the cross-repo `/graphify . --update` subagent or `merge-graphs`.

Then report:

- Files written, grouped by top-level directory.
- Wiring applied: file, line, pattern used.
- Tests authored and pass count per file.
- Lint + PHPStan result.
- Outstanding developer actions: composer / npm installs (pilot: `--install-links`), `npm run build` (blocks), secrets to set, branch-protection note (CI).

## Pilot environment + engine quirks (this skeleton)

**Test env (`wp-env`):**
- Always use `npx wp-env` (or `node_modules/.bin/wp-env`), never bare `wp-env`.
- If `wp-env start` reports a port already allocated, start on a free alternate: `WP_ENV_PORT=8890 npm run wp-env start`, or `WP_ENV_PORT=8891 npm run test:php` for the test environment in `.wp-env.tests.json` (find a free port with `lsof -nP -iTCP:<port> -sTCP:LISTEN`).
- `wp-env start` can flake on a transient image pull (TLS timeout); one retry is allowed, and exit 0 does not mean "up" - confirm `WordPress development site started` appears in the output.
- `pretest:php` skips `composer install` when `vendor/` is already mounted; if `npm run test:php` still fails on it, run PHPUnit directly: `npx wp-env run --config=.wp-env.tests.json cli --env-cwd=wp-content/plugins/$(basename "$PWD") -- vendor/bin/phpunit -c phpunit.xml.dist`.

**Generated-code quirks (write the code right up front; these survive `composer format`):**
- **Fully-qualify WP global classes** (`\WP_Error`, `\WP_REST_Request`, `\WP_REST_Response`) everywhere they appear - in code AND docblocks - with NO `use` statement for them. Reason: `composer format` force-qualifies `WP_Error` (Slevomat `FullyQualifiedExceptions` treats `*Error` as an exception) and then strips the now-unused imports, including docblock-only ones like `WP_REST_Request` (`UnusedUses` runs without `searchAnnotations`); PHPStan (scanning `inc/`) then reports `class.notFound` / `return.type` for `<Namespace>\WP_Error`. Writing them fully-qualified avoids the format -> phpstan round-trip.
- **`wp/rest` overridden controller methods must match WP core's untyped signatures.** The template emits typed overrides like `get_items_permissions_check( WP_REST_Request $request ): bool`; adding a parameter type to a `WP_REST_Controller` override is a fatal LSP/contravariance violation that crashes `wp-env start` during provisioning. Rewrite every overridden method (`get_item`/`create_item`/`update_item`/`delete_item` + their `*_permissions_check`) with **untyped** params/return and express the types in PHPDoc only.
- **`wp/rest` route is double-versioned.** The generated `$namespace` already includes the version (e.g. `testimonials/v1`) yet `register_routes()` appends `/v{version}` again -> `v1/v1`. Pick one source: set `$namespace` to the base only and keep `/v{version}`, or register with `$this->namespace` directly. Fix the generated test's asserted route too.
- The engine emits compact `declare(strict_types=1);` and test files with no `@package` tag or per-method doc comments. Run `composer format` (phpcbf) for the spacing, then hand-add the `@package` file tag + a one-line doc comment on each `test_*` method (PHPCS requires them; phpcbf does not add them).
- **File-header order:** the file docblock must immediately follow `<?php`, *before* `declare(strict_types=1);` (PHPCS `PSR12.Files.FileHeader.IncorrectOrder` + `Squiz.Commenting.FileComment.Missing`). If the engine emits `declare` first, move the docblock above it. phpcbf does not reorder this for you.
- **Narrow always-non-null `?string` overrides.** Several framework abstracts type a method `?string` with a `null` default (e.g. `AbstractAdminPage::get_parent_slug()`, `AbstractBlock::get_block_dir()`). When your override always returns a concrete value, declare the override `: string` (a covariant-legal narrowing) and give it a `@return string` tag - the shared PHPStan baseline runs `checkTooWideReturnTypesInProtectedAndPublicMethods`, so an always-non-null `?string` is a `return.unusedType` error.
- **`wp/rest` inputs:** `--class` takes the BASE name - the engine appends `Controller` (`--class=TestimonialFeed` -> `TestimonialFeedController`); `--slug` and `--text_domain` are not `wp/rest` inputs (warned + ignored). The generated controller is GET-only - replace it with the create/POST flow the brief needs.
- **REST tests:** the generated test calls `register_routes()` directly, which trips WP's `_doing_it_wrong` under `WP_UnitTestCase`. Register via the action instead: in `set_up()`, `global $wp_rest_server; $wp_rest_server = new WP_REST_Server(); do_action( 'rest_api_init' );`, and reset it to `null` in `tear_down()`.

**Judge your work scoped, not repo-wide.** Run the gates against the files you authored (`vendor/bin/phpcs <files>`, `vendor/bin/phpstan analyse <files>`) and scope `composer format` to them; a repo-wide non-zero exit is not necessarily your code. The skeleton ships gate-clean, so any finding outside your files is a regression to report, not ambient noise.

## Hard rules - never violate

- **BASE (see AGENTS.md guardrails):** never run history/remote `git`/`gh` (commit, push, `branch -D`, `reset --hard`, PR, issue comment, `gh secret set`, `gh repo edit`); print them as developer actions. `git clone`/`checkout` are fine. Never do a destructive operation outside this plugin directory. Never commit or push `graphify-out/graph.json`.
- Never write production code before its test exists on disk.
- Never hand-write an artifact the engine can scaffold.
- Never group multiple kinds under a per-feature folder (`Modules/<Feature>/...`).
- Never declare an artifact done without its test file passing.
- Never declare PHP done without the §7 G gates (`composer format` -> `composer lint` -> `composer phpstan`) clean on the generated code.
- Never lower assertion strength to make a test pass (`assertTrue(true)`, widened types).
- Never delete or skip a test the developer would expect to pass.
- Never leave `markTestIncomplete`, `markTestSkipped`, or `@todo` in committed state.
- Never run `composer require`, `npm install`, `npm run build`, or any write-side CLI without explicit consent.
- Never read, write, log, or transmit secret values.
- Never edit branch protection, repo settings, webhooks, or any GitHub admin surface.
- Never apply wiring without showing the diff and getting consent.
- Never invent a third registration pattern when canonical and sampled disagree - ask.
- Never restore scaffold anchor comments without explicit consent.
- Never modify `composer.json`, `package.json`, or any lockfile beyond what the engine wrote.

**Detect-and-correct:** if you notice an earlier artifact in the wrong directory (e.g. `includes/...` from unpassed conventions) or missing its test file, stop new work, migrate to the canonical layout (§3), add the missing tests, then resume.

## Engine errors

| Code | Response |
|---|---|
| `ENOSCAFFOLD` | Surface `available` list, suggest closest, ask. |
| `EMISSINGINPUT` | Read `missingDetails`, run §2 discovery, retry with resolved values. |
| `EBADSCAFFOLD` | Invalid manifest. Surface verbatim, do not retry. For an `origin: "remote"` scaffold this means the fetched manifest at its pinned ref is broken - surface it; do not try to repair another repo's scaffold. |
| `EWRITEFAIL` | Surface path + errno. Do not retry. |
| `ERENDERFAIL` | Scaffold author bug. Surface. |
| `EFETCHFAIL` | Network/HTTP failure fetching an `origin: "remote"` scaffold's manifest or a template. Surface `url` + `statusCode`. If the payload sets `rateLimited`, tell the developer to set `WP_TOOLING_GITHUB_TOKEN` and stop. A timeout is transient - one retry is reasonable; a 404 means the source pin (`sources.json` repo/ref/path) or the owning repo's index is wrong - surface, do not retry. Never hand-write the artifact to work around a failed fetch (the engine owns it). |
| Unknown | Surface, exit non-zero, do not crash. |

## CI/CD variant

- `ai.wiring` usually empty.
- `developer.secrets` usually populated. For multi-workflow setups, emit one consolidated `gh secret set` checklist at the end (dedupe).
- `ai.tests` framework is `actionlint` or `yaml-parse`. Validate; do not fill the YAML.

## Reference

- Engine contract: `node_modules/@rtcamp/wp-tooling/docs/ai-orchestration.md`
- Examples: `node_modules/@rtcamp/wp-tooling/docs/examples.md`
- Engine source: `node_modules/@rtcamp/wp-tooling/src/scaffolds/`
- Test templates: `node_modules/@rtcamp/wp-tooling/scaffolds/wp/<kind>/templates/test.php.mustache`
