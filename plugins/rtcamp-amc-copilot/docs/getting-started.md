# Getting started

This guide turns the skeleton into a working plugin: you'll create a personalized **Acme Content** plugin, run it on a local WordPress site, and confirm a source edit shows up on the frontend. Run commands from the plugin directory unless a step says otherwise.

## Prerequisites

- **Git**
- **Composer 2**
- **PHP 8.2+** (for Composer and the host-side PHPCS/PHPStan checks)
- **Node and npm** matching [`.nvmrc`](../.nvmrc) (Node 24.22.1; 26.12.1+ also meets `engines` in `package.json`, and `@wordpress/env` 12 needs Node 24) — use `nvm` or another version manager
- **Docker**, running, for the bundled `wp-env` site and the PHP test suite

An existing local WordPress installation can replace the `wp-env` site; Docker is still needed for the container-based PHP test command.

## 1. Get the skeleton

Download it into the folder that will become your plugin directory. Composer gives you a copy without the skeleton's Git history and installs the Composer dependencies:

```bash
composer create-project rtcamp/rtcamp-amc-copilot acme-content-features
cd acme-content-features
git init
git add .
git commit -m "chore: start from plugin-elementary"
nvm install
nvm use
```

Or clone the repository:

```bash
git clone https://github.com/rtCamp/plugin-elementary.git acme-content-features
cd acme-content-features
nvm install
nvm use
```

The first commit gives the Composer copy a baseline to review init's changes against, and the repository lets `npm install` in step 2 set up the Git hooks.

The folder name is the plugin's directory in WordPress and is what you pass to `wp plugin activate`. Name it after the main plugin file init will create (`acme-content-features`), so the local copy matches the release zip.

## 2. Install dependencies

```bash
composer install
npm install
```

All dependencies install from public registries: `rtcamp/wp-primitives` and the coding standards from Packagist, and `@rtcamp/wp-tooling` and the lint configs from npm. No token or sibling checkout is needed.

`npm install` also installs two Git hooks (a commit-message check and a pre-commit hook that runs lint-staged) and runs `npm run sync-ai`, which copies the framework's review rules into `.github/instructions/`. Resolve installation errors before continuing; see [initialization troubleshooting](initialization.md#troubleshooting).

## 3. Personalize

Check the working tree is clean, then run the setup wizard:

```bash
git status --short
npm run init
```

Use **Acme Content** as the plugin name. For this first walkthrough, keep every example set and the default features: HMR on, Tailwind and Dev Tools off. [Initialization](initialization.md) explains each prompt.

Alternatively, open the clone in Claude Code and ask:

```text
/init Set up this plugin as "Acme Content". Keep the examples and default features.
```

Use one route. The AI route can also run step 2 for you if you start it first.

> **Using a different AI assistant?** `/init` and `/scaffold` are Claude Code skills, with matching Copilot prompts in `.github/prompts/`. [AGENTS.md](../AGENTS.md) is the tool-agnostic source of truth; with another tool, describe the task and use the CLI route.

Review the result. For Acme Content you should see:

| Item | Value |
| --- | --- |
| Main plugin file | `acme-content-features.php` (renamed from `rtcamp-amc-copilot.php`) |
| PHP namespace | `Acme_Content\Features` |
| Composer package | `rtcamp/acme-content-features` |
| Text domain | `acme-content-features` |
| Constants | `ACME_CONTENT_FEATURES_*` |
| Recorded state | `.wp-scaffold.json` |

Init renames the Composer package, so run `composer update --lock` to refresh `composer.lock`. A baseline commit after this review gives you a checkpoint before feature work.

## 4. Start WordPress

```bash
npm run wp-env start
npm run wp-env run cli -- wp plugin activate acme-content-features
npm run build:dev
```

`wp-env` activates the plugin when it starts, so on a fresh site the activate command reports that the plugin is already active. Keep it for a site where the plugin is not active yet.

The committed [`.wp-env.json`](../.wp-env.json) mounts this directory as a plugin in the development site; the PHP tests use their own site from [`.wp-env.tests.json`](../.wp-env.tests.json). Open [the local site](http://localhost:8888) and [WordPress admin](http://localhost:8888/wp-admin/); a fresh `wp-env` install uses `admin` / `password`.

Confirm the plugin is active under **Plugins**, then check two of the retained examples:

- **Settings → Acme Content Features** is the example settings page.
- **Tools → Features Tools** is the example admin page.

From the terminal, the WP-CLI example confirms the bootstrap and autoloader:

```bash
npm run wp-env run cli -- wp acme-content-features health-check
```

If the plugin sits inside an existing WordPress project instead, start that project's environment, activate the plugin there, and run `npm run build:dev` from the plugin directory.

## 5. Make a visible edit

Start the asset watcher:

```bash
npm run start:assets
```

In `src/css/main.scss`, temporarily add `body { outline: 3px solid red; }`. Wait for the rebuild, refresh the frontend, and confirm the outline appears. Remove the rule and stop the watcher with Ctrl+C.

Manual refresh always works. [Local development](local-development.md) covers automatic reload, the block dev server, checks and production builds.

The plugin is now running locally with a source-to-frontend edit confirmed. Next, [explore the supplied features](features.md), [generate a feature](scaffolding.md), or [extend the plugin by hand](../DEVELOPMENT.md).
