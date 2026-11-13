# Maintainer guidance

For developers maintaining the Plugin Elementary itself. Developers building a client plugin should start with [Getting Started](../getting-started.md) and [Local development](../local-development.md).

The [maintenance guide](maintenance.md) covers:

- How the skeleton relates to `wp-primitives`, `wp-tooling` and `wp-shared-workflows`, and which files each one owns.
- How `npm run init` reads this repository: `bin/scaffold.config.js`, the `wp:example` markers, and how to add an example set or optional feature.
- Verifying the advertised developer journeys against a specific revision.
- Updating dependencies, and developing against a local dependency checkout.
- Keeping the Claude skills and Copilot prompts in step.
- Publishing and checking this documentation.
- Temporary procedures and known gaps.

Related pages:

- [Knowledge graph](knowledge-graph.md): installing graphify, querying the graph, and who refreshes the committed baseline.

Documentation and validation results must identify the skeleton commit and the installed dependency revisions. The guides target `main` and changes built on it; do not assume a separately installed dependency checkout behaves the same.

## Temporary procedure status

The sibling-clone pilot from the former `docs/quick-start-guide.md` and `docs/internal-testing.md` is retired. [`package.json`](../../package.json) consumes `@rtcamp/wp-tooling` and the lint configs from their GitHub `npm/*` distribution branches, and [`composer.json`](../../composer.json) resolves `rtcamp/wp-primitives` and the coding standards from GitHub. Local source overrides are now only for deliberate [dependency development](maintenance.md#local-dependency-development).

The remaining [temporary procedures](maintenance.md#temporary-procedures) each list the revision they apply to and the condition for retiring them.
