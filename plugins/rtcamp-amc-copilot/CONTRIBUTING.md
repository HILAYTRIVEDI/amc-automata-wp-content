# Contributing to the Plugin Elementary

Thanks for improving the skeleton. Changes here reach every client plugin created from it, so keep them general, tested and documented.

This guide is for work on the skeleton itself. If you are building a client plugin from it, start with [Getting Started](docs/getting-started.md).

## Before you start

- Open or find an [issue](https://github.com/rtCamp/plugin-elementary/issues) describing the change.
- Check where the change belongs. Framework behaviour belongs in [`wp-primitives`](https://github.com/rtCamp/wp-primitives), the init or scaffold engine in [`wp-tooling`](https://github.com/rtCamp/wp-tooling), and CI jobs in [`wp-shared-workflows`](https://github.com/rtCamp/wp-shared-workflows). See [what this repository owns](docs/internal/maintenance.md#what-this-repository-owns).
- Branch from `main` and open your pull request against it.

## Development setup

Follow [Getting Started](docs/getting-started.md) steps 1 and 2 to clone and install, **but do not run `npm run init` in your working copy**: the skeleton must keep its `AMC Copilot` placeholders. Test init in a separate disposable clone. Then use [Local development](docs/local-development.md) for the environment, watchers and checks.

For init markers, adding example sets or features, dependency work and release validation, use the [maintainer guidance](docs/internal/README.md).

## Before you open a pull request

Run the checks in [Local development](docs/local-development.md#check-a-change); all must pass:

```bash
npm run test:php
composer lint
composer phpstan
npm run test:js
npm run lint:js
npm run lint:css
```

Then:

- If you changed an example, a module, `Main::CLASSES` or `bin/scaffold.config.js`, validate init in a disposable clone (see [release validation](docs/internal/maintenance.md#release-validation)).
- If you changed behaviour a developer sees, update the guide that describes it. Don't edit `CHANGELOG.md`: release-please writes it from the PR title.
- If you changed the `init` or `scaffold` workflow, update both the Claude skill and the Copilot prompt.

## Pull request checklist

- [ ] Tests cover new or changed behaviour (written first).
- [ ] The checks above pass.
- [ ] Documentation is updated.
- [ ] Placeholders (`AMC Copilot` / `rtcamp-amc-copilot` / `AMC`) are intact.
- [ ] Commits follow [Conventional Commits](https://www.conventionalcommits.org/) (enforced by the commit-message hook), and so does the PR title (checked by `pr-title.yml`): pull requests are squash-merged, and the title becomes the commit on `main` and the changelog entry.
- [ ] `graphify-out/graph.json` is not part of the change unless the pull request is a deliberate baseline refresh.

Use the pull request template and describe how you verified the change.

## License

By contributing, you agree that your contributions are licensed under the project's [GPL-2.0-or-later](LICENSE.md) license.
