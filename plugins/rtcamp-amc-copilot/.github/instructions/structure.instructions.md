---
applyTo: "inc/**"
description: "AMC Copilot plugin structure. Merges with primitives-php.instructions.md and copilot-instructions.md."
---

# Plugin structure (AMC Copilot)

Namespace `AMC\` → `inc/`; tests `AMC\Tests\` → `tests/php/` (mirrors `inc/`). Text domain `rtcamp-amc-copilot`. Hook/option prefix `amc_`. Constants `AMC_COPILOT_{FILE,VERSION,PATH,URL}`; kill switch `AMC_COPILOT_DISABLED`. Entry: `rtcamp-amc-copilot.php` → `Autoloader::autoload()` → `Main::get_instance()`.

The contracts every lane builds on are in [`docs/CONTRACTS.md`](../../docs/CONTRACTS.md). Read it before changing anything under `inc/`.

One folder per domain, each with ONE module (`AbstractModule`) that lists its classes. `Main::CLASSES` lists the modules and is frozen.

| Folder | Module | Owner |
| --- | --- | --- |
| `inc/Core/` | `CoreModule` (+ `Assets`, `PluginSetup`, … listed in `Main`) | Wave 0 |
| `inc/Checks/` | `ChecksModule` → `Providers\{CoreChecks,LaneB1Checks,LaneB2Checks}` | W0 / B1 / B2 |
| `inc/Runner/` | `RunnerModule`, `Cli\CliModule` | Wave 0 |
| `inc/Rest/` | `RestModule` (route map frozen; one controller per owner) | per controller |
| `inc/Storage/` | `StorageModule` (Retention) | A1 (repositories: W0) |
| `inc/Capture/` | `CaptureModule` | A1 |
| `inc/Notify/` | `NotifyModule` | A2 |
| `inc/Abilities/` | `AbilitiesModule` | B2 |
| `inc/Admin/` | `AdminModule` | C |
| `inc/Reports/` | `ReportsModule` | D1 |
| `inc/Reports/Blocks/` | `SectionBlocksModule` | D2 |
| `inc/Vulnerability/` | (no hooks; contract + resolver) | W0 / B2 |

To add a class: create it in your domain folder, extend the right framework abstract, add its `::class` to YOUR domain's module. Checks are added to your lane's provider in `inc/Checks/Providers/`, not to a module.

## Flag

- 🚩 New concrete class not registered in its module's `get_classes()` (or its lane's check provider) → it never loads.
- 🚩 Any edit to `Main::CLASSES`, `RestModule`, `CliModule` or another lane's module/provider.
- 🚩 SQL against an `amc_*` table outside `inc/Storage/`.
- 🚩 A check that writes to the site, calls `get_current_blog_id()`/`switch_to_blog()`, or runs inside a REST request.
- 🚩 Anything that adds a DB query or asset to front-end requests.
- 🚩 Plugin entry / directly-accessible PHP file without `defined( 'ABSPATH' ) || exit;`.
