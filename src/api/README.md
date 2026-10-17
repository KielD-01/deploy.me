# deploy.me — the Laravel app

This folder is the application itself (Laravel 13, Livewire 4, Reverb). It runs
inside the `deployme-*` containers; start it from the repository root:

```bash
make init     # first run
make up       # afterwards
```

- [README](../README.md) — what deploy.me is and how to run it
- [User guide](../docs/usage.md) — servers, projects, deploys, rollbacks
- [MCP server](../docs/mcp.md) — deploy.me for AI agents
- [CLAUDE.md](../CLAUDE.md) — the detailed code map for anyone changing it
