# Local development

Day-to-day work on an initialized plugin: running WordPress, editing source and seeing the result, checking a change, and building for delivery. Start here after [initialization](initialization.md). Commands run from the plugin directory; examples assume the folder is `acme-content-features`.

## Start and stop WordPress

```bash
npm run wp-env start
npm run wp-env run cli -- wp plugin activate acme-content-features
```

`wp-env` activates the plugin when it starts; the activate command is for a site where it is not active yet.

| Site | URL | Used for |
| --- | --- | --- |
| Development | `http://localhost:8888` (`admin` / `password`) | Manual testing |
| Tests | `http://localhost:8889` | The PHPUnit suite, in its own environment ([`.wp-env.tests.json`](../.wp-env.tests.json)); `npm run test:php` starts it |

Both mount this directory as a plugin (see [`.wp-env.json`](../.wp-env.json) and [`.wp-env.tests.json`](../.wp-env.tests.json)), each with its own database. Activation uses the **folder name**, not the plugin display name. Stop the environments with `npm run wp-env stop` and `npm run wp-env -- stop --config=.wp-env.tests.json`.

If those ports are taken, set `WP_ENV_PORT`: `WP_ENV_PORT=8890 npm run wp-env start`, or `WP_ENV_PORT=8891 npm run test:php` for the test environment.

For an existing WordPress installation (LocalWP, Lando, a site repository), use its own startup and activation, set `WP_HOST` in `.env.local` for live reload, and run the plugin-local commands below from this directory.

## Edit source and see the result

| Edit | Output / purpose |
| --- | --- |
| `inc/` | PHP behaviour. Loads on the next request. |
| `templates/` | Theme-overridable PHP templates. Loads on the next request. |
| `src/css/**/*.scss` | `assets/build/css/` — one stylesheet per file (names starting `_` are partials). |
| `src/js/**/*.js` | `assets/build/js/` — one script per file. |
| `src/js/modules/` | ES modules (Interactivity API) under `assets/build/js/modules/`. |
| `src/blocks/<block>/` | Block builds under `assets/build/blocks/<block>/`. |

Treat `assets/build/` as generated output; never edit it. [Blocks and assets](blocks-and-assets.md) explains how each output is registered and enqueued.

| Command | What it runs |
| --- | --- |
| `npm start` | Both watchers below, in parallel. |
| `npm run start:assets` | Watches `src/css` and `src/js`; BrowserSync live reload on port 3003 when HMR is on. |
| `npm run start:blocks` | Block dev server with Fast Refresh for block editor components. |
| `npm run build:dev` | One-off development build of assets and blocks. |

### Automatic reload

Copy `.env.local.example` to `.env.local` (gitignored) and set `WP_HOST` to your site's hostname. For `wp-env`, use `WP_HOST=localhost` and `BLOCKS_DEV_SERVER_PORT=8886`. HMR is on by default and needs `WP_ENVIRONMENT_TYPE` to be `local` (the `wp-env` default). Block Fast Refresh also needs `SCRIPT_DEBUG`. See [Live reload and block HMR](hmr.md) for HTTPS, custom ports and turning it off.

## Check a change

Run the focused test first, then the full set before opening a pull request.

```bash
# PHP tests — run inside the wp-env test environment (started on demand).
npm run test:php -- --filter ShortcodesTest   # one test class
npm run test:php                              # the whole suite

# PHP style and static analysis — run on the host (PHP 8.2+).
composer lint          # PHPCS (rtCampWP ruleset)
composer format        # PHPCBF: auto-fix what PHPCS can
composer phpstan       # PHPStan level 5

# JavaScript, CSS and package.json. An initialized plugin has no Jest tests until you add one.
npm run test:js
npm run lint:js
npm run lint:css
npm run lint:package-json
```

`npm run test:php` needs `npm run wp-env start` first; its `pretest` step runs `composer install` inside the container when `vendor/` is missing. `composer test` expects a WordPress test library on the host and is not the supported route.

**Coverage:** start the environment with Xdebug, then run the coverage script. The HTML report is written to `coverage/`.

```bash
npm run wp-env start -- --xdebug=coverage
npm run test:php:coverage
```

> Use the explicit commands above rather than `npm test` or `npm run lint`. Their `test:*` / `lint:*` wildcards also match the watch, coverage and `:fix` scripts, so they neither terminate cleanly nor stay read-only. See [maintenance known gaps](internal/maintenance.md#known-gaps).

CI runs the same checks through [`.github/workflows/test-measure.yml`](../.github/workflows/test-measure.yml): lint, JS tests, the PHP × WordPress test matrix (edit `php-versions` / `wp-versions` there) and the build, each gated on which files changed. The jobs themselves live in [wp-shared-workflows](https://github.com/rtCamp/wp-shared-workflows).

The Git hooks installed by `npm install` enforce [Conventional Commits](https://www.conventionalcommits.org/) on commit messages and run lint-staged on staged files before each commit.

## Build for delivery

`vendor/` and `assets/build/` are gitignored, so a deployable copy has to be built:

```bash
composer install --no-dev --optimize-autoloader
npm run build:prod
npm run release:zip        # writes dist/acme-content-features-<version>.zip
```

If your host deploys from a build step or a site repository instead, run the first two commands there.

### What goes in the zip

`release:zip` packs the working tree as it is on disk and never reads `.gitignore`. That is how the built `vendor/` and `assets/build/` get in, but it also means:

- The plugin ships a `.distignore` that leaves out development files: dotfiles (including the gitignored `.env.local` and `.wp-env.override.json`), `src/`, `docs/`, `scripts/`, `tests/`, `bin/`, `graphify-out/`, build and lint configs, and the lock files.
- A `.distignore` replaces the built-in default list rather than extending it, and does not support `!` negation, so it must list everything to leave out. Keep it up to date when you add files that should not ship.
- Without a `.distignore`, only `.git/`, `dist/`, `tests/`, `node_modules/`, `bin/`, `.github/`, `*.config.js` and `package-lock.json` would be left out.

The shipped `.distignore`:

```text
# Dotfiles, including .env.local and .wp-env.override.json
/.*
/bin/
/coverage/
/docs/
/graphify-out/
/node_modules/
/scripts/
/src/
/tests/
/*.config.js
/*.config.mjs
/*.dist
/AGENTS.md
/CLAUDE.md
/CONTRIBUTING.md
/DEVELOPMENT.md
/composer.lock
/package.json
/package-lock.json
```

It keeps the main plugin file, `inc/`, `templates/`, `languages/`, `assets/`, `vendor/`, `composer.json`, `README.md`, `CHANGELOG.md` and `LICENSE.md`. Check the result with `unzip -l dist/*.zip`.

### Release scripts

Related scripts from `@rtcamp/wp-tooling`:

| Command | Purpose |
| --- | --- |
| `npm run release:bump` | Bump the version in the plugin header and `package.json`. |
| `npm run release:changelog` | Generate `CHANGELOG.md` entries from Conventional Commits. |
| `npm run pot` | Regenerate `languages/<text-domain>.pot` (runs WP-CLI's `i18n make-pot` through Composer). |

Run each with `-- --help` for the options your installed revision supports.

## Troubleshooting

| Symptom | Check and next action |
| --- | --- |
| `wp-env start` reports a port in use | Another site or the block dev server holds 8888/8889. Stop it or set `WP_ENV_PORT` for the environment you start. |
| `localhost:8888` shows a webpack 404 page instead of WordPress | `BLOCKS_DEV_SERVER_PORT` in `.env.local` is set to 8888, the `wp-env` site's port. Set it back to 8886 (or another free port) and restart `npm start`. |
| `npm start` fails with `EADDRINUSE` | Another dev server already holds the block dev server's port; stop it or set `BLOCKS_DEV_SERVER_PORT` in `.env.local`. |
| `npm run test:php` cannot connect | The environment is not running. Run `npm run wp-env start` and retry. |
| Admin notice: "The Composer autoloader was not found" | `vendor/autoload.php` is missing. Run `composer install`; the plugin shows this notice instead of a fatal error and loads nothing else. |
| Plugin inactive after init renamed the main file | Reactivate it: `npm run wp-env run cli -- wp plugin activate acme-content-features`. |
| A script, style or block is missing | Check the source under `src/`, run `npm run build:dev`, and confirm the file exists under `assets/build/`. |
| No live reload | Check `ENABLE_HMR`, `WP_HOST` and `WP_ENVIRONMENT_TYPE`; see [hmr.md](hmr.md). |
| PHPCS reports on the wrong PHP version | Run `composer lint` with PHP 8.2+ on the host. |
