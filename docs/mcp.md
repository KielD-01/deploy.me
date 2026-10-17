# 🤖 deploy.me for AI agents (MCP)

deploy.me includes a [Model Context Protocol](https://modelcontextprotocol.io) server, so
Claude, Cursor, VS Code or any other MCP client can read your servers, projects and runs,
explain why a deploy failed, and (if you allow it) deploy, roll back and cancel.

It is an Artisan command that speaks JSON-RPC over stdio, with no new dependencies and
no open port. The MCP client starts it inside the running web container:

```bash
docker exec -i deployme-web php artisan deployme:mcp
```

> [!IMPORTANT]
> The stack must be running (`make up`): the client starts the server with `docker exec`.
> It answers with the same data the pages show. Secrets are never included: no tokens,
> no env file contents, and logs are already redacted.

## 🔐 Two modes

| Mode | Started with | What the agent can do |
|---|---|---|
| **Read-only** (default) | `deployme:mcp` | Look at servers, projects, releases, runs, logs and the audit log; plan a deploy; queue a probe |
| **Deploys allowed** | `deployme:mcp --allow-deploys` | All of the above, plus `deploy`, `rollback` and `cancel_deployment` |

In read-only mode the deploy tools aren't listed at all. If an agent asks for one anyway,
the answer tells it the server must be started with `--allow-deploys`. Runs an agent starts
are like any other run: they take the target's lock, stream a live log, and appear on the
Deployments page with **AI agent (MCP)** as their trigger.

To see what a server would offer:

```bash
docker exec deployme-web php artisan deployme:mcp --list --allow-deploys
```

## ⚙️ Connecting a client

### Claude Code

```bash
claude mcp add deployme -- docker exec -i deployme-web php artisan deployme:mcp
```

With deploys allowed (a separate name helps keep the two apart):

```bash
claude mcp add deployme-ops -- docker exec -i deployme-web php artisan deployme:mcp --allow-deploys
```

### Claude Desktop

Add this to `~/Library/Application Support/Claude/claude_desktop_config.json`, then restart
Claude:

```json
{
  "mcpServers": {
    "deployme": {
      "command": "docker",
      "args": ["exec", "-i", "deployme-web", "php", "artisan", "deployme:mcp"]
    }
  }
}
```

> [!TIP]
> Claude Desktop starts with a short `PATH`. If it can't find `docker`, put the full path
> in `command`. Run `which docker` to find it, usually `/usr/local/bin/docker`.

### Cursor

`.cursor/mcp.json` in a project, or `~/.cursor/mcp.json` for every project:

```json
{
  "mcpServers": {
    "deployme": {
      "command": "docker",
      "args": ["exec", "-i", "deployme-web", "php", "artisan", "deployme:mcp"]
    }
  }
}
```

### VS Code (GitHub Copilot agent mode)

`.vscode/mcp.json`:

```json
{
  "servers": {
    "deployme": {
      "type": "stdio",
      "command": "docker",
      "args": ["exec", "-i", "deployme-web", "php", "artisan", "deployme:mcp"]
    }
  }
}
```

For any other client, the command is `docker` and the arguments are
`exec -i deployme-web php artisan deployme:mcp`, plus `--allow-deploys` if you want it.

## 🧰 Tools

| Tool | Changes something? | What it answers |
|---|:---:|---|
| `list_servers` | — | Every server: status, environment, last seen, CPU and memory |
| `get_server` | — | One server: specs, tools, how it’s reached (its route and the last route check, never the proxy password), the latest probe (load, disks, Compose stacks, busiest processes), its projects |
| `probe_server` | — ¹ | Queues a fresh probe of a server |
| `list_projects` | — | Every project: branch, CI, and per server its health, live commit and last run |
| `get_project` | — | One project: deploy settings, delivery strategies in order, recent releases and which can be rolled back to |
| `list_deployments` | — | Recent runs, filtered by project, server or status |
| `get_deployment` | — | One run: its steps, why its strategy was picked, and the end of its log |
| `get_deployment_log` | — | A run's redacted log, page by page or one step at a time |
| `list_audit` | — | Who did what, and (optionally) every command run on a server |
| `plan_deploy` | — | Which strategy would ship a commit, or what it would wait for, without starting anything |
| `deploy` | ✅ ² | Starts a deploy of a project (latest commit or a named one) to one of its servers. Arguments: `project`\*, `server`, `commit`, `backup`, `skip_migrations` ³ |
| `rollback` | ✅ ² | Rolls a project on a server back to an earlier release (the previous one by default). Arguments: `project`\*, `server`, `release` ³ |
| `cancel_deployment` | ✅ ² | Cancels a queued or running run |

¹ A probe only reads the server, and the scheduler already runs one every few minutes.
² Offered only with `--allow-deploys`, and marked `destructiveHint`, so clients ask you
before calling them.
³ \* marks a required argument. The two tools take:

| Argument | Tool | Type | Meaning |
|---|---|---|---|
| `project`\* | `deploy`, `rollback` | string | The project's slug or name |
| `server` | `deploy`, `rollback` | string | The server (slug, name or ssh alias). Needed when the project deploys to more than one |
| `commit` | `deploy` | string | A commit sha (7–40 hex). Empty: the branch's latest commit |
| `backup` | `deploy` | boolean, default `true` | Docker projects that migrate: `pg_dump` the database first, when the project can |
| `skip_migrations` | `deploy` | boolean, default `false` | Docker projects that migrate: don't run the migrations |
| `release` | `rollback` | string | The release's name, like `20260911-120000`. Empty: the previous release |

Projects are named by slug or name, servers by slug, name or SSH alias, and runs by number
(`42` or `#42`). If a name doesn't match, the error lists the ones that do exist.

## 💬 Things to ask

- *"What's deployed where, and is anything unhealthy?"*
- *"Why did the last deploy of the shop fail? Show me the step that broke."*
- *"Which servers are above 80% disk?"*
- *"Is there a green build on main that isn't live yet? What would a deploy ship?"*
- *"Deploy demo to staging, then watch the run and tell me when it's healthy."* (needs `--allow-deploys`)
- *"Roll the blog back to the release before last."* (needs `--allow-deploys`)

## 🛡️ How it stays safe

- **Read-only by default.** The deploy tools exist only when you start the server with
  `--allow-deploys`, and the instructions the server gives the agent tell it to ask you
  before using them.
- **The same rails as the UI.** `deploy` and `rollback` go through `StartDeployment`, just
  as the deploy dialog and the Releases tab do. That means one run per target, a folder that
  still needs converting is refused, and only releases that can be rolled back to are
  accepted.
- **No secrets.** Answers never include GitHub tokens, env file contents, Telegram
  credentials or `APP_KEY`. Log lines are redacted before they are stored, so the agent
  sees what you see.
- **Local only.** stdio through `docker exec` is reachable only by someone who can already
  run Docker on your Mac. No port is opened and nothing listens on the network.
- **Accountable.** Runs started over MCP have the trigger `mcp`, and every command they run
  on a server goes into the audit log like any other.

## 🩺 Troubleshooting

| Symptom | Fix |
|---|---|
| `Error: No such container: deployme-web` | Start the stack: `make up` |
| The client shows no tools | Run the command by hand and paste `{"jsonrpc":"2.0","id":1,"method":"tools/list"}`. You should get one line of JSON back |
| `docker: command not found` in Claude Desktop | Use the full path to `docker` in `command` |
| `deploy needs the server started with --allow-deploys` | Add `--allow-deploys` to the client's arguments, on purpose |
| A run started but nothing happens | The worker runs deploys: `make workers`, `make logs s=deployme-worker` |

## 🔧 For developers

- `app/Mcp/Server.php` is the JSON-RPC side: `initialize`, `ping`, `tools/list` and
  `tools/call`. It supports protocol versions `2025-06-18`, `2025-03-26` and `2024-11-05`.
- `app/Mcp/Stdio.php` is the transport: one message per line. PHP warnings go to stderr,
  so they never corrupt the stream.
- `app/Mcp/Tools/*`: one class per tool, extending `App\Mcp\Tool`. A tool that starts or
  stops runs returns `true` from `deploys()`.
- To add a tool, write the class, list it in `Server::TOOLS`, and cover it in
  `tests/Feature/Mcp/McpServerTest.php`.
