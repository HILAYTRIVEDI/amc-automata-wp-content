# Knowledge graph

The repository commits a queryable code graph so AI assistants can answer structural questions by querying it instead of reading the whole codebase. It is built with [Graphify](https://graphify.net) (`graphifyy` on PyPI; the command is `graphify`). The graph is optional and may be stale: verify exact behaviour from current source before documenting it.

Committed artifacts (everything else in `graphify-out/` is gitignored):

- `graphify-out/graph.json`: the graph, covering this plugin plus `wp-tooling`, `wp-primitives` and `wp-shared-workflows`, with a per-node `repo` tag.
- `graphify-out/GRAPH_REPORT.md`: a human-readable summary.

## Install (one time)

```bash
scripts/graphify/install.sh    # auto-detects uv, pipx or pip
scripts/graphify/verify.sh
```

Windows and manual options are in [`scripts/graphify/README.md`](../../scripts/graphify/README.md). The package name (`graphifyy`) differing from the command (`graphify`) is expected.

## Use the graph

```bash
graphify query "how does the init engine remove a capability"
graphify path "Main" "AbstractModule"
graphify explain "ExampleCronJob"
graphify affected "Loader"
```

In Claude Code, the `/graphify` skill takes the same questions in natural language.

## Refresh locally

After changing code, refresh your local copy of the plugin's slice (tree-sitter, local, seconds):

```bash
graphify update .
```

**Never commit or push `graphify-out/graph.json` from routine work.** The committed file is a cross-repo baseline; a local refresh overwrites it with a single-repo view.

## Rebuild the committed baseline (maintainers)

Only when this repository or a shared repository has changed enough to matter, with all four repositories checked out as siblings:

```bash
ROOT="$(cd .. && pwd)"
graphify update .
( cd "$ROOT/wp-tooling"          && graphify update . )
( cd "$ROOT/wp-primitives"       && graphify update . )
( cd "$ROOT/wp-shared-workflows" && graphify update . )
graphify merge-graphs \
  graphify-out/graph.json \
  "$ROOT/wp-tooling/graphify-out/graph.json" \
  "$ROOT/wp-primitives/graphify-out/graph.json" \
  "$ROOT/wp-shared-workflows/graphify-out/graph.json" \
  --out graphify-out/graph.json
graphify cluster-only . --no-label --no-viz   # refresh GRAPH_REPORT.md
```

This leaves a `graphify-out/` folder in each sibling; remove those yourself if you do not want them. Review the graph and report diff in its own pull request. AI assistants must not run the merge or the `/graphify . --update` subagent (see `AGENTS.md`).

## Optional: semantic layer

The committed graph is structural only. Community naming and inferred "why" edges need an LLM, either with an API key (for example `GEMINI_API_KEY`) and `graphify extract . --mode deep`, or through the `/graphify` skill in Claude Code, which uses the active session.
