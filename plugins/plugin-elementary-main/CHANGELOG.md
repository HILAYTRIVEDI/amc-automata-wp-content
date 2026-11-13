# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

<!-- In the skeleton, release-please writes each release below from the Conventional Commit pull request titles merged to main, so don't add entries under `## Unreleased` here. Projects made from the skeleton keep `## Unreleased` for `npm run release:changelog`. -->

## Unreleased

## [1.0.1](https://github.com/rtCamp/plugin-elementary/compare/v1.0.0...v1.0.1) (2026-11-12)


### Fixed

* **ci:** separate file and project lint commands ([#65](https://github.com/rtCamp/plugin-elementary/issues/65)) ([2c4c7b9](https://github.com/rtCamp/plugin-elementary/commit/2c4c7b92f149a23b267f6e881a536f6d68b69e64))
* **deps:** override isomorphic-git to 1.38.6 for two path-traversal advisories ([bbdd4a7](https://github.com/rtCamp/plugin-elementary/commit/bbdd4a7ed3d72b095e93117994865abc21bb9e1c))
* **deps:** pin webpack-dev-server to 6.0.0 to stop the block editor reload loop ([bbdd4a7](https://github.com/rtCamp/plugin-elementary/commit/bbdd4a7ed3d72b095e93117994865abc21bb9e1c))
* **deps:** require patched Node for CVE-2026-111497 ([#60](https://github.com/rtCamp/plugin-elementary/issues/60)) ([22532ef](https://github.com/rtCamp/plugin-elementary/commit/22532efe109ce4dd158d11e6d21698b6fd88f068))
* **deps:** update @wordpress/scripts to 37 and refresh stale npm overrides ([#77](https://github.com/rtCamp/plugin-elementary/issues/77)) ([d32154e](https://github.com/rtCamp/plugin-elementary/commit/d32154e0de0a2f58f195643c0236a0068de49c1d))
* **deps:** update dev dependencies with security advisories ([#74](https://github.com/rtCamp/plugin-elementary/issues/74)) ([c6e3bc5](https://github.com/rtCamp/plugin-elementary/commit/c6e3bc5906511069b70d798f3681c858b86489c5))


### Changed

* **deps-dev:** bump @eslint-community/eslint-plugin-eslint-comments ([694c48a](https://github.com/rtCamp/plugin-elementary/commit/694c48a829a9cbfe7a0bc3a8f2158daaf43d88b3))
* **deps-dev:** bump @eslint-community/eslint-plugin-eslint-comments from 4.7.2 to 4.8.1 ([#44](https://github.com/rtCamp/plugin-elementary/issues/44)) ([694c48a](https://github.com/rtCamp/plugin-elementary/commit/694c48a829a9cbfe7a0bc3a8f2158daaf43d88b3))
* **deps-dev:** bump @wordpress/babel-preset-default ([033374c](https://github.com/rtCamp/plugin-elementary/commit/033374ca2fd6a9a562667f824ef3c07910e1027d))
* **deps-dev:** bump @wordpress/babel-preset-default from 10.0.0 to 10.1.0 ([#71](https://github.com/rtCamp/plugin-elementary/issues/71)) ([033374c](https://github.com/rtCamp/plugin-elementary/commit/033374ca2fd6a9a562667f824ef3c07910e1027d))
* **deps-dev:** bump @wordpress/server-side-render from 7.0.0 to 7.1.0 ([#70](https://github.com/rtCamp/plugin-elementary/issues/70)) ([e5272c9](https://github.com/rtCamp/plugin-elementary/commit/e5272c9eb8ee0ab5d0e664b1c6ea32ccf6adb8b8))
* **deps-dev:** bump eslint from 10.5.0 to 10.14.0 ([#72](https://github.com/rtCamp/plugin-elementary/issues/72)) ([19c692a](https://github.com/rtCamp/plugin-elementary/commit/19c692a3da3a8cc0d71fcfc2427b738988c6cc06))
* **deps-dev:** bump eslint-import-resolver-typescript ([a1c78df](https://github.com/rtCamp/plugin-elementary/commit/a1c78dfe9b8910d2d7f7f1308cc4797b08ee3f12))
* **deps-dev:** bump eslint-import-resolver-typescript from 4.4.4 to 4.4.5 ([#47](https://github.com/rtCamp/plugin-elementary/issues/47)) ([a1c78df](https://github.com/rtCamp/plugin-elementary/commit/a1c78dfe9b8910d2d7f7f1308cc4797b08ee3f12))
* **deps-dev:** bump eslint-plugin-jest from 29.15.2 to 29.17.0 ([#67](https://github.com/rtCamp/plugin-elementary/issues/67)) ([5488f09](https://github.com/rtCamp/plugin-elementary/commit/5488f095cf753719dfa515cf09f35db088134a61))
* **deps-dev:** bump jest from 30.5.0 to 30.5.2 ([#42](https://github.com/rtCamp/plugin-elementary/issues/42)) ([aec9447](https://github.com/rtCamp/plugin-elementary/commit/aec9447c56fa9bd165a37fcb30ed96e69f1b1a2c))
* **deps-dev:** bump jest-environment-jsdom from 30.5.0 to 30.5.2 ([#46](https://github.com/rtCamp/plugin-elementary/issues/46)) ([00dd8a3](https://github.com/rtCamp/plugin-elementary/commit/00dd8a3bd21f575166e8791305f6d46ce52eb54c))
* **deps-dev:** bump phpstan/phpstan-phpunit from 2.0.21 to 2.1.1 ([#54](https://github.com/rtCamp/plugin-elementary/issues/54)) ([6fdeefa](https://github.com/rtCamp/plugin-elementary/commit/6fdeefa0d6e8507438b5caa4d429f3d6b1379191))
* **deps-dev:** bump wp-phpunit/wp-phpunit from 7.1.1 to 7.1.3 ([#62](https://github.com/rtCamp/plugin-elementary/issues/62)) ([c354c0e](https://github.com/rtCamp/plugin-elementary/commit/c354c0e7028598a052975665b01e555c02cc35a4))

## 1.0.0 - 2026-10-01

### Added

- Framework-service example modules demonstrating the shared wp-primitives engine.
- Conventional-commit git hook and `release:bump` / `release:changelog` / `release:zip` scripts, provided by `@rtcamp/wp-tooling`.
- Developer documentation: Getting Started, Initialization, Local development, Included features, Scaffolding, Blocks and assets, and Tailwind guides, published through a Docusaurus workflow.
- `CONTRIBUTING.md` and maintainer guidance under `docs/internal/`.
- A `.distignore`, so `release:zip` packs only the files the plugin ships.

### Changed

- **Renamed from `features-plugin-skeleton` to `plugin-elementary`** and moved to the
  `rtCamp/plugin-elementary` repository, aligning it with the sibling `theme-elementary`.
  The scaffold placeholder tokens (`AMC_Automata_Features`, `amc-automata-features`) are unchanged. The
  Composer package is now `rtcamp/amc-automata-features-features`, matching the repository, and
  `npm run init` still replaces it with `rtcamp/<slug>-features`.
- Updated the shared engine dependency from `rtcamp/wp-framework` to `rtcamp/wp-primitives ^2.1`.
- Adopted the rtCamp shared coding standards from the common packages (PHPCS, PHPStan, ESLint, Stylelint).
- Rewrote `README.md` and `DEVELOPMENT.md` as entry points that link framework API detail to `rtcamp/wp-primitives`.
- Installs `@rtcamp/eslint-config`, `@rtcamp/stylelint-config` and `@rtcamp/wp-tooling` from the npm registry, and `rtcamp/wp-primitives`, `rtcamp/wp-phpcs` and `rtcamp/wp-phpstan` from Packagist, instead of git branches and VCS repositories.
- `@wordpress/scripts` 36. JS tests run through `wp-scripts test-unit-jest`, with `jest` and `jest-environment-jsdom` as direct dev dependencies.
- PHP tests run in their own wp-env environment (`.wp-env.tests.json`), so they never touch the development site.
- CI calls [rtCamp/wp-shared-workflows](https://github.com/rtCamp/wp-shared-workflows) at `@v1`.

### Removed

- The pilot-era quick start and internal-testing guides (superseded by the new guides and maintainer docs).

### Fixed

- `composer phpstan` works on a checkout without `node_modules`.

### Security

- Fixes every open npm and Composer security advisory in the plugin's dependencies.
