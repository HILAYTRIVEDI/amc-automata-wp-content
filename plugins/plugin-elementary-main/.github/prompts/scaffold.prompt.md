---
mode: agent
description: Add one feature (CPT, taxonomy, block, REST controller, shortcode, cron, CLI, settings/admin page, user role, service, CI workflow) to this plugin via @rtcamp/wp-tooling, TDD-first. Derives test cases first, scaffolds via the engine, writes tests, implements to green.
---

# /scaffold

Copilot equivalent of the Claude `scaffold` skill. Keep it in step with [`.claude/skills/scaffold/SKILL.md`](../../.claude/skills/scaffold/SKILL.md) (the fuller reference). Read [`AGENTS.md`](../../AGENTS.md) and [`.github/instructions/`](../instructions/) first.

## Be interactive (required for Copilot)

1. Confirm the scaffold target and the test-case checklist with the developer BEFORE scaffolding. Ask in one message; **stop and wait** for the reply.
2. Show every cross-file wiring edit (file + line + snippet) and get an explicit "yes" before applying it.
3. If the request is ambiguous or project patterns conflict, ask once with 1-3 concrete options. Never guess.

## Use for
CPT, taxonomy, REST controller, dynamic block, shortcode, cron, CLI command, admin/settings page, user role, plain `Registrable` service, framework module, CI/CD workflow.

## Do not use for
Refactors, bug fixes, hand-written features, anything no scaffold covers.

## Steps

### 1. Discover
`npx wp-tooling list --json` → pick one `category/slug`. If ambiguous, ask with candidates. `origin: "remote"` entries fetch their manifest on first `add` (network I/O; can fail `EFETCHFAIL`).

### 2. Introspect (once per session)
Read: `composer.json` `autoload.psr-4` (root ns + path) and `autoload-dev.psr-4` (tests ns); `package.json` scripts (block build `--output-path`); main plugin file (bootstrap class + register method); 2-3 existing implementations of the same kind. Confirm findings in one short message.

### 3. Canonical layout
Group files by **kind**, never by feature. `<Root>` = autoload root. Source `inc/<Kind>/`, tests `tests/php/<Kind>/`, module `inc/Modules/<Kind>.php`. A multi-kind feature spans the per-kind dirs and wires into each kind's module. A per-feature module folder (`Modules/<Feature>/...`) is an anti-pattern; flag it, do not add to it.

### 4. Derive test cases (BEFORE any scaffold call)
Checklist: happy path, edge cases (empty/missing/boundary), error paths (invalid input, missing auth/capability), kind-specific integration (e.g. `wp/cpt`: `post_type_exists()`, supports, REST exposure; `wp/rest`: route registered, `permission_callback`, schema). Show the checklist; resolve add/remove with the developer before scaffolding.

### 5. Invoke the engine (never hand-write what it covers)
```bash
npx wp-tooling add <category>/<slug> --non-interactive --json --<input>=<value> ...
```
Apply project-sampled conventions (class suffix, sub-namespace, vendor prefix, build dir). `--dry-run` to preview. Multi-kind order: `wp/module` for any missing kind-module first, then artifacts in dependency order; re-read `ai.wiring` after each call. Result: `{ scaffold, engine, developer, ai, warnings }`.

### 6. Process the result
- `engine.wrote/skipped` → report. `developer.install.*` → print as copy-paste; **never run** `composer require`/`npm install`. `developer.secrets` → print as `gh secret set` checklist; **never read/write values**.
- `ai.wiring`: for each snippet, place after its anchor (or last sampled occurrence), show file + line + rendered snippet, get consent, insert idempotently.

### 7. TDD loop (mandatory)
Tests before implementation, no exceptions. A. Expand the engine stub into the full suite from §4; strip every `markTestIncomplete`. B. Run `composer test` (PHP) / `npm run test:js` (JS); expect red. C. Implement just enough to flip ONE test green. D. Re-run. E. Loop B-D one test at a time. F. Refactor, re-run. G. Final gates: full PHPUnit, full Jest if JS touched, PHP compliance (§7a), `npm run lint:js`. For blocks, surface `npm run build` as a developer action before testing.

### 7a. PHP compliance (required, on changed PHP)
Write compliant PHP up front (`declare( strict_types = 1 );`, short arrays, full types, prefixed globals, `@package`/`@since`, `static::`). Then: `composer format` (phpcbf) → `composer lint` (phpcs, `rtCampWP` ruleset) → `composer phpstan` (level 5). Resolve every finding in the generated code. Never silence a real issue with a blanket `phpcs:ignore`/`@phpstan-ignore`; if a fix is unclear or changes behaviour/API, STOP and ask.

### 8. Escalate when stuck (do not guess)
Stop and report (what you tried, observed, what's blocking, 1-3 options) after: 3 stuck C-D iterations on one test; a result contradicting your model (re-read the file); conflicting project patterns; ambiguity after one clarification.

### 9a. Refresh the knowledge graph
After all files are green, before the report: `graphify update .` to re-extract changed files into the LOCAL graph (never commit it; never run the cross-repo `/graphify . --update` or `merge-graphs`). <=30 words to the developer. If not installed/built, say so in one line; do not block. See `AGENTS.md` (graphify).

### 9b. Final report
Files written (by directory), wiring applied (file/line/pattern), tests authored + pass counts, lint result, graph refreshed (or skipped + reason), outstanding developer actions (installs, `npm run build`, secrets, CI branch protection).

## Hard rules
- Never write production code before its test exists on disk.
- Never hand-write an artifact the engine can scaffold.
- Never group multiple kinds under a per-feature folder.
- Never declare done without its test passing, or PHP done without §7a clean.
- Never silence a real phpcs/phpstan finding, weaken assertions, or leave `markTestIncomplete`/`@todo`.
- Never run `composer require`, `npm install`, `npm run build`, or `gh secret set` without consent.
- Never read/write/log/transmit secret values; never edit branch protection or repo settings.
- Never commit, push, open PRs, or apply wiring without showing the diff and getting consent.
- Never modify `composer.json`/`package.json`/lockfiles beyond what the engine wrote.

## Reference
- Engine contract / examples: `node_modules/@rtcamp/wp-tooling/docs/`.
- Fuller version of this workflow: `.claude/skills/scaffold/SKILL.md`.
