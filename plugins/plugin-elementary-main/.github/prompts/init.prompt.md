---
mode: agent
description: Set up this cloned plugin-elementary into a named project, or manage its identity and capabilities later. Installs dependencies (`npm install`, `composer install`), then drives `npm run init`.
---

# /init

Copilot equivalent of the Claude `init` skill. Keep it in step with [`.claude/skills/init/SKILL.md`](../../.claude/skills/init/SKILL.md). Read [`AGENTS.md`](../../AGENTS.md) first for conventions and guardrails.

Two jobs: **bootstrap + setup** a fresh clone into a real plugin, or **manage** an already-set-up project. This prompt is a thin front end for `npm run init` - don't reimplement what the engine already asks for.

## Be interactive (required for Copilot)

Copilot must gather inputs BEFORE acting. Do not assume any value.

1. Ask the developer every question the chosen path needs, in ONE message.
2. **Stop and wait** for the answers. Do not run commands, edit files, or proceed until they reply.
3. Echo the resolved/derived values back and get an explicit "yes" before any install, file rewrite, or destructive step.
4. If an answer is missing or ambiguous, ask again. Never guess.

## Use for
- First run: install dependencies, rename starter tokens, pick capabilities to keep.
- Later: rename / re-prefix, toggle features (Tailwind, HMR, Dev Tools).

## Do not use for
- Adding a feature class → the `/scaffold` prompt.
- Bug fixes, refactors, hand-written features.
- Re-running init to change capabilities after first setup (removal is one-shot; a second run leaves dangling `Main::CLASSES` refs). Change the set later via `/scaffold` or by hand.

## Steps

### 1. Detect mode
Run: `test -f .wp-scaffold.json && echo manage || echo setup`. No file → **setup**. Present → **manage** (`npm run init -- --list` shows feature status).

### 2. Install dependencies
With consent, run:
```bash
npm install
composer install
```
Skip either that's already installed and current. If consent is declined, surface both as developer actions and stop.

Then verify a clean working tree (`git status`) - init rewrites files irreversibly, and a clean tree is the only undo. If dirty, ask to commit/stash.

### 3. Ask, confirm, run - one step
`npm run init` is a single command; don't turn gathering its inputs into a multi-round-trip wizard.

**Setup:** in ONE message ask for amc automata features (required, e.g. `Acme Blog` → namespace `Acme_Blog\Features`, package `rtcamp/acme-blog-features`, text domain, prefixes, main file - show these back), version (default `1.0.0`), which capability sets to remove, and which features to enable (defaults: keep all sets, `hmr` on, `tailwind` off - see Capability model).
**Manage:** ask which of identity / features to change - it's a single flag on an existing project, no wizard needed.

Get explicit consent on the resolved values (init is destructive), then run:
```bash
npm run init -- --name="Acme Blog" --version=1.0.0 --yes --remove-examples=cron,rest --features=hmr,tailwind
# Manage:
npm run init -- --list
npm run init -- --enable=tailwind --yes
npm run init -- --features=hmr --yes        # exact enabled set (empty = none)
```
- `--keep-examples` keeps all; `--remove-examples` (no value) removes all; `--remove-examples=a,b` removes listed keys.
- `--features=a,b` sets the exact enabled set; `--enable`/`--disable` are deltas. `--yes` requires `--name`.
- `npm run init` also runs `npm run sync-ai`; expected.

### 4. After init
- Tailwind enabled → developer runs `npm install` (added `src/css/tailwind.css`, `postcss.config.js`, pinned `@rtcamp/tailwind-config`).
- Suggest `composer dump-autoload`.
- If you hand-edited PHP under `inc/`, run `composer format` → `composer lint` → `composer phpstan` and resolve every finding. Never silence a real issue; if unclear, STOP and ask.
- Refresh the LOCAL knowledge graph with `graphify update .`. Never commit `graphify-out/graph.json`, and never run the cross-repo `/graphify . --update` or `merge-graphs`. If not installed, say so in one line; do not block. See `AGENTS.md` (graphify).
- Report: new identity, capabilities removed/kept, features toggled, outstanding developer actions.

## Capability model
ONE keep-or-remove prompt; removing deletes the capability entirely (classes, `inc/Modules/<X>.php`, its `Main::CLASSES` line, coupled Core regions, feature deps).

| Category | Keys |
|---|---|
| Content | `post-types`, `taxonomies` |
| Editor & Front-end | `blocks`, `shortcodes`, `tailwind` (feature) |
| APIs & Automation | `rest`, `cli`, `cron` |
| Admin | `settings`, `admin`, `roles` |
| Utilities | `cache`, `transients` |
| Developer Tooling | `test-measure` (CI workflow), `hmr` (feature) |

Sets kept by default; pass keys to `--remove-examples` to drop. Features: `hmr` on, `tailwind` off. Everything installs from public registries.

## Hard rules
- Package managers only with consent: `npm install`, `composer install` (step 2), and `npm run init`. Otherwise surface install commands as developer actions.
- Never run a destructive init without confirming resolved values, on a clean tree.
- Never commit, push, open PRs, or edit `.wp-scaffold.json` by hand.
- Never invent flags. Supported: `--name`, `--version`, `--yes`, `--keep-examples`, `--remove-examples[=...]`, `--features`, `--enable`, `--disable`, `--reinit`, `--list`, `--clean`, `--help`. Run `npm run init -- --help` if unsure.
