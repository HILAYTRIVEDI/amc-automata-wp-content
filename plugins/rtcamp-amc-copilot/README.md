<h1 align="center">AMC Copilot</h1>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/WordPress-6.9%2B-21759b.svg" alt="WordPress 6.9+">
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg" alt="License: GPL-2.0-or-later"></a>
</p>

<p align="center">
  Every recurring AMC check for a site on one wp-admin screen, email alerts
  for what can't wait, and the monthly Website Maintenance Report generated
  instead of hand-typed.
</p>

- **Building on it?** Read [docs/CONTRACTS.md](docs/CONTRACTS.md) first, then the plan: [docs/phase-1-plan.md](docs/phase-1-plan.md).
- Report snapshot format: [docs/report-snapshot.md](docs/report-snapshot.md).
- WP-CLI: `wp amc check list`, `wp amc check run <id>|--all`, `wp amc access grant|revoke <user>`.
- Kill switch: `define( 'AMC_COPILOT_DISABLED', true );` in wp-config.php.

The rest of this README is the plugin-elementary skeleton's developer guide, which still applies to the tooling.

---

This skeleton gives a new client plugin its structure, working examples, asset pipeline, checks and CI, so a project starts from a tested baseline instead of an empty folder.

It builds on two shared rtCamp libraries and uses a third for CI:

- [`rtcamp/wp-primitives`](https://github.com/rtCamp/wp-primitives) (Composer, runtime): the registration system, `Abstract*` base classes, loaders and utilities that every class in `inc/` extends.
- [`@rtcamp/wp-tooling`](https://github.com/rtCamp/wp-tooling) (npm, development): the `npm run init` setup wizard, the `npx wp-tooling add` feature scaffolder, the shared lint configs and release scripts.
- [`rtCamp/wp-shared-workflows`](https://github.com/rtCamp/wp-shared-workflows): the reusable lint, test and build CI that `.github/workflows/test-measure.yml` calls.

The skeleton documents what it provides and how to build on it. The framework's API and lifecycle are documented in [its own docs](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/index.md), and these guides link there where needed.

> This is a **template**. `AMC Copilot` / `rtcamp-amc-copilot` / `AMC` are placeholders; `npm run init` renames them to your project.

## Get started

Create a new plugin with Composer, or clone this repository:

```bash
composer create-project rtcamp/rtcamp-amc-copilot my-plugin
```

Then follow [Getting Started](docs/getting-started.md): prerequisites, getting the skeleton, installing, naming the plugin, and seeing it running in a local WordPress site. It takes about fifteen minutes.

## What is included

- **Infrastructure** that stays in every project: bootstrap and loader, asset registration, theme-overridable templates and components, a shared logger, an encryption service, activation and deactivation handling, and PHPUnit, PHPCS, PHPStan, ESLint and Stylelint setups.
- **Twelve example sets**, one per feature domain (post types, taxonomies, blocks, REST, WP-CLI, cron, settings pages, admin pages, user roles, shortcodes, cache, transients). Each is a working, tested reference you keep or remove at setup.
- **Optional development features**: HMR live reload (on by default), Tailwind CSS, and Dev Tools runtime telemetry over MCP.

See [Included features](docs/features.md) for where each one lives and how to see it working.

## Choose your next task

| I want to… | Read |
| --- | --- |
| Name the plugin and choose what ships | [Initialization](docs/initialization.md) |
| Run WordPress locally, check a change, build for delivery | [Local development](docs/local-development.md) |
| See what the skeleton already provides | [Included features](docs/features.md) |
| Generate a feature with the CLI or AI | [Scaffolding](docs/scaffolding.md) |
| Add blocks, scripts or styles | [Blocks and assets](docs/blocks-and-assets.md) |
| Extend the plugin by hand | [Development guide](DEVELOPMENT.md) |
| Contribute to this skeleton | [Contributing](CONTRIBUTING.md) |

The full documentation index is [docs/index.md](docs/index.md).

## AI tooling

Setup and feature scaffolding are also available through AI assistants, kept in step across tools:

- **Claude Code:** skills in [`.claude/skills/`](.claude/skills/) — `/init` (set up or manage the plugin) and `/scaffold` (add one feature, test first). `/setup` is a generic tooling bootstrapper and does not replace `/init`.
- **GitHub Copilot:** matching `/init` and `/scaffold` prompts in [`.github/prompts/`](.github/prompts/).
- **Any assistant:** [AGENTS.md](AGENTS.md) is the tool-agnostic source of conventions; `CLAUDE.md` and `.github/copilot-instructions.md` point to it.

A committed knowledge graph in [`graphify-out/`](graphify-out/) lets assistants query the code's structure instead of reading it all; see [docs/internal/knowledge-graph.md](docs/internal/knowledge-graph.md).

## Folder structure

```
rtcamp-amc-copilot.php   # entry: constants → Autoloader::autoload() → Main::get_instance()
inc/                        # PSR-4 root: AMC\ → inc/
├── Autoloader.php          # loads vendor/autoload.php; admin notice instead of a fatal if missing
├── Main.php                # Main::CLASSES — the list of everything the plugin loads
├── Core/                   # always loaded: Assets, PluginSetup, Components, Templates, Encryption, Logger
├── Helpers/                # stateless static utilities (Util)
└── Modules/                # one module per feature domain, concrete classes in the matching subfolder
    ├── PostTypes/  Taxonomies/  Blocks/  Shortcodes/
    ├── REST/  CLI/  Cron/
    ├── Settings/  Admin/  Roles/
    └── Cache/  Transients/
src/{blocks,css,js}/        # editable sources → assets/build/ (generated)
templates/                  # theme-overridable PHP templates
tests/{php,js}/             # PHPUnit (mirrors inc/) and Jest
bin/                        # init wrapper, scaffold config, block scaffolder, helper scripts
docs/                       # these guides; docs/internal/ is for skeleton maintainers
.github/                    # CI caller, issue/PR templates, Copilot instructions and prompts
vendor/rtcamp/wp-primitives/ # the framework (Composer-managed; never edit)
```

Some of these folders hold examples that initialization can remove; see [Included features](docs/features.md#supplied-examples).

## License

[GPL-2.0-or-later](LICENSE.md)

<p align="center">
  <a href="https://rtcamp.com"><img src="https://n8e0ka87m9.gdcdn.us/kfnbt046p8/GitHub_Banner.webp" alt="rtCamp — high-performance enterprise WordPress" width="100%"></a>
</p>
