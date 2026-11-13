---
name: init
description: Set up this cloned plugin-elementary into a named project, or manage its identity and capabilities later. Installs dependencies (`npm install`, `composer install`), then drives `npm run init`. Always confirms before destructive or install steps; expects a clean working tree.
---

# init

Two jobs: **bootstrap + setup** a fresh clone into a real plugin, or **manage** an already-set-up project. Always interactive: gather inputs first, confirm the resolved plan, then act. This skill is a thin front end for `npm run init` - it does not reimplement what the engine already asks for.

## Use for
- First run: install dependencies, rename starter tokens, pick capabilities to keep.
- First run WITH a feature brief: after rename + capabilities, implement the described features by chaining to the **scaffold** skill (TDD), replacing the matching `Example*` placeholders (step 5).
- Later: rename / re-prefix, toggle features (Tailwind, HMR, Dev Tools).

## Do not use for
- Adding a feature class → the **scaffold** skill (`npx wp-tooling add`).
- Bug fixes, refactors, hand-written features.
- Re-running init to change capabilities after first setup. Removal is one-shot (markers are consumed); a second run leaves dangling `Main::CLASSES` refs. Set the set ONCE; change it later via scaffold.

## Be interactive (required)
Ask the developer for every input the chosen path needs, in ONE batched message, before acting. Never assume a project name. Show derived values back in that same exchange. Wait for explicit consent before any install, file rewrite, or destructive step. If an answer is missing, ask; do not guess.

## Plan and announce (required) - developer experience first
1. **State the plan up front** in one short message: the mode (setup / manage) and the ordered steps for THIS request - detect mode -> install deps -> ask + run init -> (setup with a brief) hand each feature to the scaffold skill.
2. **Maintain a live TODO list** (`TodoWrite` in Claude Code): one entry per planned step, exactly one `in_progress` at a time.
3. **Announce each step with a short title** and report its outcome in one line. Never go silent during a long step.

## Steps

### 1. Detect mode
```bash
test -f .wp-scaffold.json && echo manage || echo setup
```
No `.wp-scaffold.json` → **setup**. Present → **manage** (read it for current identity; `npm run init -- --list` shows feature status).

### 2. Install dependencies
With consent, run:
```bash
npm install
composer install
```
Skip either that's already installed and current. If consent is declined, surface both as developer actions and stop - init cannot run without them.

Then verify a clean working tree (`git status`) - init rewrites files irreversibly, and a clean tree is the only undo. If dirty, ask to commit/stash.

### 3. Ask, confirm, run - one step
`npm run init` is a single command; don't turn gathering its inputs into a multi-round-trip wizard.

**Setup:** in ONE message ask for project name (required, e.g. `Acme Blog` → namespace `Acme_Blog\Features`, package `rtcamp/acme-blog-features`, text domain, prefixes, main file - show these back), version (default `1.0.0`), which capability sets to remove, and which features to enable (defaults: keep all sets, `hmr` on, `tailwind` off - see Capability model). The engine derives the tokens itself; do not read the engine source (`identity.js` etc.) - the mapping above is the contract, and the graph answers any deeper question (see the graphify policy in `AGENTS.md`).

**Manage:** ask which of identity / features to change. It's a single flag on an existing project - no wizard needed.

Get explicit consent on the resolved values (init is destructive), then run:
```bash
# Setup:
npm run init -- --name="Acme Blog" --version=1.0.0 --yes \
  --remove-examples=cron,rest --features=hmr,tailwind
# Manage:
npm run init -- --list
npm run init -- --enable=tailwind --yes
npm run init -- --features=hmr --yes        # exact enabled set (empty = none)
```
- `--keep-examples` keeps all; `--remove-examples` (no value) removes all; `--remove-examples=a,b` removes listed keys.
- `--features=a,b` sets the exact enabled set; `--enable`/`--disable` are deltas.
- `--yes` requires `--name`. For the guided wizard, run bare `npm run init` (space toggles, enter confirms) - the wizard already asks everything above, so prefer it over hand-building flags when the developer has no strong opinion on the exact command.
- `npm run init` also runs `npm run sync-ai`; that is expected.

**Setup WITH a feature brief - clear the briefed examples in THIS step (not a separate round-trip):** keep the capabilities the brief needs (do NOT list them in `--remove-examples`); remove every other capability. Then, batched with the init run, strip the kept-briefed capabilities of their demo code so scaffold can fill them: for each, delete the `Example*` class file(s) under `inc/Modules/<Kind>/`, delete `tests/php/<Cap>Test.php`, and set that module's `get_classes()` to `return [];` (drop the example `use` imports). The module stays wired in `Main::CLASSES` - only its example contents go, leaving an empty, already-wired module ready for scaffold. Example: brief = Testimonial CPT + Industry taxonomy + draft REST -> keep `post-types`/`taxonomies`/`rest`, `--remove-examples=blocks,shortcodes,cli,cron,settings,roles,cache,transients,admin`, then empty the PostTypes/Taxonomies/REST modules.

### 4. After init
- Tailwind enabled → developer runs `npm install` (it added `src/css/tailwind.css`, `postcss.config.js`, and pinned `@rtcamp/tailwind-config`).
- `composer dump-autoload` (engine runs it when `composer.json` is present).
- Trust the engine's own output to verify (it reports the removed sets, drops their `Main::CLASSES` lines and tests, and regenerates the autoloader). To confirm a symbol or reference, run a single `graphify query`/`affected` against the graph - never grep `Main.php`/`inc/` to check removal.
- If you hand-edited PHP and are NOT handing off to scaffold (e.g. a manage-mode dangling `Main::CLASSES` cleanup), run `composer lint` on the change and fix it. When a brief follows (step 5), leave the lint/PHPStan/test gates to the scaffold skill - init does not run them.
- **Refresh the LOCAL graph** with `graphify update .` (plugin slice, tree-sitter, no API, seconds). **Never commit or push `graphify-out/graph.json`** - the committed file is a maintainer-maintained cross-repo baseline (rebuilt and pushed when this or a shared repo changes); your local refresh stays local. Do NOT run the cross-repo `/graphify . --update` subagent or `merge-graphs` (expensive; clobbers the baseline). If graphify is not installed, say so in one line; do not block.
- Report: new identity, capabilities removed/kept, features toggled, outstanding developer actions.

### 5. Implement the brief by handing off to scaffold (setup only, when features were described)
By this point step 3 has already emptied the briefed capabilities (their `Example*` classes/tests are deleted and the modules are empty but still wired in `Main::CLASSES`). init's remaining job is purely to **hand each described feature to the scaffold skill** - it does NOT build the class, write or run tests, set up the test env (`npm run wp-env start`), or run the lint/PHPStan gates. The **scaffold skill takes over** and owns all of that.

For each described feature, **invoke the scaffold skill**, passing the brief (e.g. a `testimonial` CPT; an `industry` taxonomy attached to `testimonial`; a REST controller that creates testimonials in `draft`). The scaffold skill owns conventions, the `wp-tooling add` call, wiring into the already-present module, the TDD loop, test execution, and the gates - report its result; do not duplicate that work here.

**Pass your findings to scaffold (speeds it up).** init has already resolved the project identity while renaming, so hand those values to the scaffold skill in the same message instead of making it re-derive them. State explicitly: the resolved **PHP namespace root** (e.g. `Acme\Testimonials`), **base_path** (`inc`), **tests_namespace** (e.g. `Acme\Testimonials\Tests`), **tests_path** (`tests/php`), **text_domain** and **slug** (e.g. `testimonial-features`), the **plugin display name**, and **which capabilities step 3 kept-and-emptied** (their `inc/Modules/<Kind>/` folders already exist and are wired in `Main::CLASSES`, so scaffold wires into them rather than creating a module). With these provided, the scaffold skill skips its own `composer.json` / plugin-header / `Main.php` discovery reads. It still reads source files when it needs exact code (the graph and a handoff are for orientation, not a substitute for reading).

For a kept capability the brief did NOT mention, leave its `Example*` but tell the developer it is demo-only, not for deployment, and offer to scaffold a real class or drop the capability.

End state: init has renamed, trimmed capabilities, and emptied the briefed examples (step 3); the scaffold skill has implemented the real features.

## Capability model
ONE "Select the capabilities to include" prompt. Each is keep-or-remove; removing deletes it entirely (concrete classes, `inc/Modules/<X>.php`, its `Main::CLASSES` line, coupled Core regions, feature deps).

| Category | Keys |
|---|---|
| Content | `post-types`, `taxonomies` |
| Editor & Front-end | `blocks`, `shortcodes`, `tailwind` (feature) |
| APIs & Automation | `rest`, `cli`, `cron` |
| Admin | `settings`, `admin`, `roles` |
| Utilities | `cache`, `transients` |
| Developer Tooling | `test-measure` (CI workflow), `hmr` (feature) |

Sets are kept by default; pass keys to `--remove-examples` to drop. Features: `hmr` on, `tailwind` off; toggle via `--features`/`--enable`/`--disable`. Everything here installs from public registries.

### Changing capabilities after setup
Add later → scaffold skill / `npx wp-tooling add <category>/<slug>` (writes the class, wires the module). Remove later → delete its `inc/Modules/<X>/` classes + `Main::CLASSES` line by hand. Toggle a feature any time via `npm run init -- --enable=<key>`/`--disable=<key>`. Do not re-run init to change the capability set.

## Hard rules
- **BASE (see AGENTS.md guardrails):** never run history/remote `git`/`gh` (commit, push, `branch -D`, `reset --hard`, PR, issue comment, `gh secret set`) - print them as developer actions. Never do a destructive operation outside this plugin directory.
- Never commit or push `graphify-out/graph.json`; local graph refreshes stay local.
- Package managers only with consent: `npm install`, `composer install` (step 2), and `npm run init`. Outside those, surface install commands as developer actions.
- Never run a destructive init without confirming resolved values, on a clean tree.
- Never commit, push, open PRs, or edit `.wp-scaffold.json` by hand.
- Never invent flags. Supported: `--name`, `--version`, `--yes`, `--keep-examples`, `--remove-examples[=...]`, `--features`, `--enable`, `--disable`, `--reinit`, `--list`, `--clean`, `--help`. Run `npm run init -- --help` if unsure.

## Reference
- Engine: `@rtcamp/wp-tooling/init` (via `bin/init.js`). Capability map: `bin/scaffold.config.js`.
- Conventions renamed into: `AGENTS.md`, `.github/instructions/structure.instructions.md`.
- Graphify policy: `AGENTS.md`.
