# Initialize and manage the plugin

`npm run init` turns the skeleton into a named plugin and records your choices; later runs manage identity and optional features. It is a thin wrapper ([`bin/init.js`](../bin/init.js)) around the shared init engine in `@rtcamp/wp-tooling`, configured by [`bin/scaffold.config.js`](../bin/scaffold.config.js).

## Before init

Install dependencies first (see [Getting Started](getting-started.md#2-install-dependencies)); the engine lives in `node_modules/@rtcamp/wp-tooling`. Then confirm the working tree is clean:

```bash
git status --short
```

Init rewrites and deletes files in place. A clean checkout is your only undo.

## CLI or AI

| Entry point | Role |
| --- | --- |
| `npm run init` | The interactive wizard (or flags for a scripted run). |
| `/init` | Claude Code skill (Copilot: `.github/prompts/init.prompt.md`). Installs dependencies with your consent, runs the same wizard, and can hand a feature brief to `/scaffold`. |
| `/scaffold` | Adds features after personalization; see [Scaffolding](scaffolding.md). |
| `/setup` | Generic tooling bootstrap for other projects. It is not a personalization path for this skeleton. |

## What the wizard asks

1. **Confirm setup and name the plugin.** A first run has no `.wp-scaffold.json`, so init starts in setup mode.
2. **Review identity.** Accept the derived values or edit any field before applying. For `Acme Content`, the review screen shows:

   | Field | Value |
   | --- | --- |
   | Plugin Name | `Acme Content` |
   | Version | `1.0.0` by default |
   | Text Domain | `acme-content` |
   | Package | `rtcamp/acme-content-features` |
   | Namespace | `Acme_Content\Features` (tests: `Acme_Content\Features\Tests`) |
   | Function Prefix | `acme_content_` |
   | Constant Prefix | `ACME_CONTENT` |

   `.wp-scaffold.json` stores these values. The generated code adds `-features`: plugin header `Acme Content Features`, text domain `acme-content-features`, prefixes `acme_content_features_` and `ACME_CONTENT_FEATURES_`, and main file `acme-content-features.php`.

3. **Select capabilities.** One grouped prompt lists the twelve example sets, the CI workflow and the three optional features. Space toggles, Enter confirms. Unchecking an example set removes it entirely.
4. **Apply.** Init rewrites the tokens, removes deselected sets, writes `.wp-scaffold.json`, and regenerates the Composer autoloader. The wrapper then runs `npm run sync-ai`.
5. **Git and hooks.** A first run can start a new Git repository. This defaults to **No**; accepting deletes the existing `.git` and the skeleton's history, so decline it when keeping that history or working inside another repository. Non-interactive `--yes` skips optional Git setup.

Defaults: every example set kept, HMR on, Tailwind and Dev Tools off.

For a scripted first run:

```bash
npm run init -- --name="Acme Content" --version=1.0.0 --yes \
  --remove-examples=blocks,shortcodes,cli,cron,settings,admin,roles,cache,transients
```

### Capability keys

| Category | Example-set keys (keep/remove once) | Optional feature keys (toggle any time) |
| --- | --- | --- |
| Content | `post-types`, `taxonomies` | |
| Editor & Front-end | `blocks`, `shortcodes` | `tailwind` |
| APIs & Automation | `rest`, `cli`, `cron` | |
| Admin | `settings`, `admin`, `roles` | |
| Utilities | `cache`, `transients` | |
| Developer Tooling | `test-measure` (the CI caller workflow) | `hmr` |

What each set contains is listed in [Included features](features.md).

## What changes

| Area | Result |
| --- | --- |
| Identity | `AMC Automata Features` / `amc-automata-features` / `AMC_Automata_Features` / `AMC_AUTOMATA_FEATURES` tokens are replaced across source, config, tests and docs. The main file is renamed. |
| Version | Written to the main file's header and `package.json`. |
| Removed example sets | The module file (`inc/Modules/<Module>.php`), its folder, its test (`tests/php/<Module>Test.php`), its `Main::CLASSES` line and any coupled region (for example the static block list in `inc/Core/Assets.php` for `blocks`, the unschedule call in `inc/Core/PluginSetup.php` for `cron`). |
| Kept example sets | Stay as working references. Their `// wp:example:<key>` markers are removed. |
| Optional features | `hmr` edits `.env.local`; `tailwind` adds `src/css/tailwind.css`, `postcss.config.js`, dependencies and flips the Tailwind constant. |
| State | `.wp-scaffold.json` records identity and feature choices. Do not edit it by hand. |
| Cleanup | The skeleton's own repository files are removed: `.gitattributes`, `.github/CODEOWNERS`, the bug and feature issue forms and their `config.yml`, `.github/labels.yml`, `SECURITY.md`, and its release workflow, config and manifest (`.github/workflows/release-starter.yml`, `.github/release-starter/`). A `composer create-project` copy never contains them; a git clone does until init runs. Copilot files, the other workflows and templates under `.github/` stay. |
| AI instructions | `sync-ai` refreshes `.github/instructions/framework-php.instructions.md` from the installed framework. |

Init does not generate a translation template. Run `npm run pot` when preparing translations.

## Review the result

1. Run `npm run init -- --list` and confirm the kept sets and feature states match your choices.
2. Inspect the main plugin file, `composer.json`, `package.json` and `.wp-scaffold.json` for the resolved name, namespace, prefixes and version.
3. Review `git status` and the diff: removed sets should be gone from `inc/Modules/`, `tests/php/` and `Main::CLASSES`.
4. Start WordPress and confirm the plugin activates and the kept examples load.

Follow any dependency instructions init prints, run `composer update --lock` (the package was renamed), then make a baseline commit.

## Change things later

Identity edits and optional-feature toggles are repeatable. **Example removal is a one-time setup decision**: its markers are consumed, so a later run can neither restore a removed set nor safely remove another one. To remove a set later, delete its module, classes, test and `Main::CLASSES` line by hand. To add functionality, use [Scaffolding](scaffolding.md).

Bare `npm run init` on an initialized project opens manage mode. Useful commands:

```bash
npm run init -- --list                   # read-only status
npm run init -- --list --json            # one machine-readable line
npm run init -- --enable=tailwind --yes  # change one feature
npm run init -- --disable=hmr --yes
npm run init -- --features=hmr --yes     # set the exact enabled set
npm run init -- --help                   # options for your installed engine
```

`--features` replaces the whole enabled set; `--enable` and `--disable` change one feature at a time. Do not combine them. For JSON without npm's banner, run `node bin/init.js --list --json`. Do not use `--reinit` to restore examples.

### Optional dependencies

- **HMR:** toggling only flips `ENABLE_HMR` in `.env.local`, a per-developer file. See [Live reload](hmr.md).
- **Tailwind:** enabling changes declarations and adds files; run `npm install` afterwards. See [Tailwind](tailwind.md).

## Troubleshooting

| Symptom | Check and next action |
| --- | --- |
| `Could not load the init engine` | Dependencies are not installed. Run `npm install` from the plugin root and confirm `node_modules/@rtcamp/wp-tooling` exists. |
| Option rejected | Run `npm run init -- --help`; flags differ between setup and manage mode. |
| `--name`/`--version` ignored | The project is already set up, so init is in manage mode. Edit identity through bare `npm run init`. |
| Interrupted or partial setup | Inspect the diff and `.wp-scaffold.json`. Restore the pre-init checkout if needed; do not blindly rerun a destructive setup. |
| Feature enabled but package missing | A declaration is not an installed package. Run the `npm install` or `composer update` command init printed. |
| An example set is missing | Run `npm run init -- --list`. If it was removed, recover it from a clean skeleton checkout; manage mode cannot restore consumed markers. |
| `init succeeded but sync-ai failed` | Run `composer install`, then `npm run sync-ai`. |

Generic engine behaviour is documented upstream in [wp-tooling](https://github.com/rtCamp/wp-tooling/tree/main/node-packages/wp-tooling); use the local `--help` output for your installed revision.
