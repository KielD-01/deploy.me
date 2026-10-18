<?php

namespace App\Mcp;

use Throwable;

/**
 * deploy.me as a Model Context Protocol server: JSON-RPC 2.0 messages in,
 * answers out (the transport is `deployme:mcp`, over stdio). It offers the
 * tools only; the read ones always, the ones that start or stop runs on a
 * server only when it was started with them allowed.
 */
final class Server
{
    public const NAME = 'deploy.me';

    public const VERSION = '1.0.0';

    // Newest first; an initialize asking for another gets the newest.
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const TOOLS = [
        Tools\ListServers::class,
        Tools\GetServer::class,
        Tools\ProbeServer::class,
        Tools\ListProjects::class,
        Tools\GetProject::class,
        Tools\ListDeployments::class,
        Tools\GetDeployment::class,
        Tools\GetDeploymentLog::class,
        Tools\ListAudit::class,
        Tools\ListActions::class,
        Tools\PlanDeploy::class,
        Tools\Deploy::class,
        Tools\Rollback::class,
        Tools\CancelDeployment::class,
        Tools\RunAction::class,
    ];

    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct(public readonly bool $allowDeploys = false)
    {
        foreach (self::TOOLS as $class) {
            $tool = app($class);

            if ($allowDeploys || ! $tool->deploys()) {
                $this->tools[$tool->name()] = $tool;
            }
        }
    }

    /**
     * One line from the client: a request, a notification, or a batch of them.
     * Null when nothing goes back (notifications only).
     */
    public function handleLine(string $line): ?string
    {
        try {
            $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return self::encode(self::error(null, -32700, 'Parse error: that line isn’t JSON.'));
        }

        if (is_array($message) && array_is_list($message) && $message !== []) {
            $answers = array_values(array_filter(array_map(fn ($one) => $this->handle($one), $message)));

            return $answers === [] ? null : self::encode($answers);
        }

        $answer = $this->handle($message);

        return $answer === null ? null : self::encode($answer);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function handle(mixed $message): ?array
    {
        // An id is a string, a number or null; any other isn't echoed back.
        $validId = fn (mixed $id) => $id === null || is_string($id) || is_int($id) || is_float($id);

        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            $id = is_array($message) ? ($message['id'] ?? null) : null;

            return self::error($validId($id) ? $id : null, -32600, 'Invalid request: expected a JSON-RPC 2.0 message with a method.');
        }

        // notifications/initialized, notifications/cancelled…: nothing runs, nothing goes back.
        if (! array_key_exists('id', $message)) {
            return null;
        }

        if (! $validId($message['id'])) {
            return self::error(null, -32600, 'Invalid request: an id is a string or a number.');
        }

        $id = $message['id'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        $result = match ($message['method']) {
            'initialize' => $this->initialize($params),
            'ping' => [],
            'tools/list' => ['tools' => array_values(array_map(fn (Tool $tool) => $tool->definition(), $this->tools))],
            'tools/call' => $this->callTool($params),
            'resources/list' => ['resources' => []],
            'prompts/list' => ['prompts' => []],
            default => null,
        };

        if ($result === null) {
            return self::error($id, -32601, "Method not found: {$message['method']}.");
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result];
    }

    /**
     * @return array<string, Tool>
     */
    public function tools(): array
    {
        return $this->tools;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($asked, self::PROTOCOL_VERSIONS, true) ? $asked : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => self::NAME, 'version' => self::VERSION],
            'instructions' => $this->instructions(),
        ];
    }

    private function instructions(): string
    {
        return 'deploy.me deploys the person’s projects to their own servers over SSH. '
            .'Start with list_projects and list_servers; get_deployment explains a run step by step, and get_deployment_log pages through its redacted log. '
            .($this->allowDeploys
                ? 'deploy, rollback, cancel_deployment and run_action change what a server runs: say what you are about to do and get a clear yes before calling them. plan_deploy shows what a deploy would ship without starting it; list_actions shows a project’s actions and which an agent may run.'
                : 'This server was started read-only: it can look, plan and probe, but not deploy, roll back, cancel or run actions. Starting it with --allow-deploys adds those tools.');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $tool = $this->tools[$name] ?? null;

        if ($tool === null) {
            $why = in_array($name, array_map(fn (string $class) => app($class)->name(), self::TOOLS), true)
                ? "{$name} needs the server started with --allow-deploys."
                : "There is no tool “{$name}”.";

            return self::failed($why);
        }

        try {
            $result = $tool->call(is_array($params['arguments'] ?? null) ? $params['arguments'] : []);
        } catch (ToolFailed $e) {
            return self::failed($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return self::failed('deploy.me couldn’t answer that: '.class_basename($e).'. The details are in its log.');
        }

        return ['content' => [['type' => 'text', 'text' => self::encode($result, pretty: true)]], 'isError' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private static function failed(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function encode(mixed $value, bool $pretty = false): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_THROW_ON_ERROR);
    }
}
