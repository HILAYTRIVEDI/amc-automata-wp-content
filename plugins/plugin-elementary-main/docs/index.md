# Plugin Elementary

Build a WordPress features plugin from this skeleton, then keep the examples and tools your project needs. Start with [Getting Started](getting-started.md).

## How the pieces fit

The skeleton is a consumer of shared rtCamp libraries. It owns the plugin's structure, examples, build and checks; the libraries own the reusable behaviour.

| Piece | What it provides | Where it is documented |
| --- | --- | --- |
| This skeleton | Plugin layout, `Main::CLASSES`, modules, example classes, asset pipeline, tests, CI caller | These guides |
| [`rtcamp/wp-primitives`](https://github.com/rtCamp/wp-primitives/blob/v2.0.0/docs/index.md) | `Registrable` + `Loader` + `Container`, the `Abstract*` base classes, asset/component/template loaders, `Cache`, `Transients`, `Encryptor`, `Logger` | Framework docs (linked from each guide) |
| [`@rtcamp/wp-tooling`](https://github.com/rtCamp/wp-tooling/blob/main/node-packages/wp-tooling/README.md) | `npm run init` engine, `npx wp-tooling add` scaffolds, shared ESLint/Stylelint configs, release scripts | wp-tooling docs |
| [`rtCamp/wp-shared-workflows`](https://github.com/rtCamp/wp-shared-workflows) | Reusable lint, test and build workflows | wp-shared-workflows docs |

## Two ways to work

The skeleton is set up and extended either with **AI skills** (guided: `/init` and `/scaffold`) or the **raw CLI** (manual: `npm run init` and `npx wp-tooling add`).

- **`/init` / `npm run init`** names the plugin and selects which example sets and optional features ship. Run it once per project; see [Initialization](initialization.md).
- **`/scaffold` / `npx wp-tooling add`** adds one feature (a post type, REST controller, block, CLI command, …). `/scaffold` also wires it into its module; with the CLI you add that line yourself. See [Scaffolding](scaffolding.md).

Both are valid: pick the CLI for full control over every input, or the AI skill when you want it to infer conventions, write the test first and run the checks.

| I want to… | Read |
| --- | --- |
| Create a plugin and see it running | [Getting Started](getting-started.md) |
| Understand the init prompts or change options later | [Initialization](initialization.md) |
| Run WordPress, check a change, build for delivery | [Local development](local-development.md) |
| Find the supplied infrastructure and examples | [Included features](features.md) |
| Generate one feature with the CLI or AI | [Scaffolding](scaffolding.md) |
| Add blocks, scripts or styles | [Blocks and assets](blocks-and-assets.md) |
| Extend the plugin by hand | [Development guide](../DEVELOPMENT.md) |

## Further reading

- [Live reload and block HMR](hmr.md) and [Tailwind](tailwind.md) cover the optional development features.
- [Contributing](../CONTRIBUTING.md) and the [maintainer guidance](internal/README.md) are for developers working on the skeleton itself.

## Command reference

| Task | Command |
| --- | --- |
| Personalize the plugin (AI) | `/init <brief>` |
| Personalize the plugin (CLI) | `npm run init` |
| Check identity and feature state | `npm run init -- --list` |
| Generate a feature (AI) | `/scaffold <description>` |
| Preview a feature (CLI) | `npx wp-tooling add wp/<kind> ... --dry-run` |
| Start / stop WordPress | `npm run wp-env start` / `npm run wp-env stop` |
| Watch and build assets | `npm start` / `npm run build:prod` |
| Run PHP tests | `npm run test:php` |
| Run PHP checks | `composer lint && composer phpstan` |
| Run JS and CSS checks | `npm run lint:js && npm run lint:css && npm run test:js` |
