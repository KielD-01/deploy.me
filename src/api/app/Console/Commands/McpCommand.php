<?php

namespace App\Console\Commands;

use App\Mcp\Server;
use App\Mcp\Stdio;
use Illuminate\Console\Command;

/**
 * deploy.me's MCP server for AI agents, over stdio. An MCP client starts it
 * through the web container:
 *
 *     docker exec -i deployme-web php artisan deployme:mcp
 *
 * Read-only unless --allow-deploys: then it can also deploy, roll back and
 * cancel. docs/mcp.md has the client set-ups.
 */
class McpCommand extends Command
{
    protected $signature = 'deployme:mcp
        {--allow-deploys : Also offer the tools that deploy, roll back and cancel runs}
        {--list : Print the tools this server would offer, and exit}';

    protected $description = 'Serve deploy.me to an AI agent over the Model Context Protocol (stdio)';

    public function handle(): int
    {
        $server = new Server(allowDeploys: (bool) $this->option('allow-deploys'));

        if ($this->option('list')) {
            $this->table(['Tool', 'Changes', 'What it does'], collect($server->tools())->map(fn ($tool) => [
                $tool->name(),
                $tool->deploys() ? 'yes' : '—',
                str($tool->description())->before('. ')->finish('.'),
            ])->values()->all());

            return self::SUCCESS;
        }

        Stdio::serve($server, STDIN, STDOUT);

        return self::SUCCESS;
    }
}
