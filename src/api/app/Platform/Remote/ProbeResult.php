<?php

namespace App\Platform\Remote;

use App\Platform\Ssh\Exceptions\RemoteCommandFailed;
use JsonException;

/**
 * What resources/ssh-scripts/probe.sh reported about a server.
 */
final readonly class ProbeResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data) {}

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RemoteCommandFailed('The probe returned output that isn’t valid JSON.');
        }

        if (! is_array($data) || ($data['v'] ?? null) !== 1) {
            throw new RemoteCommandFailed('The probe returned an unexpected format.');
        }

        return new self($data);
    }

    public function os(): string
    {
        return $this->data['os']['pretty'] ?? trim(($this->data['os']['id'] ?? '').' '.($this->data['os']['version'] ?? '')) ?: 'Unknown OS';
    }

    public function memoryUsedPercent(): ?float
    {
        $total = (int) ($this->data['mem']['total'] ?? 0);

        return $total > 0 ? round(100 * (1 - ((int) ($this->data['mem']['available'] ?? 0)) / $total), 1) : null;
    }

    /**
     * The filesystem a path lives on: the mount with the longest matching prefix.
     *
     * @return array{source: string, fstype: string, size: int, used: int, available: int, mount: string}|null
     */
    public function diskFor(string $path): ?array
    {
        $best = null;

        foreach ($this->data['disks'] ?? [] as $disk) {
            $mount = rtrim($disk['mount'], '/').'/';
            if (str_starts_with(rtrim($path, '/').'/', $mount) && ($best === null || strlen($disk['mount']) > strlen($best['mount']))) {
                $best = $disk;
            }
        }

        return $best;
    }

    public static function usedPercent(?array $disk): ?float
    {
        return $disk && $disk['size'] > 0 ? round(100 * $disk['used'] / $disk['size'], 1) : null;
    }

    /**
     * Static facts about the machine, stored on the server.
     *
     * @return array<string, mixed>
     */
    public function specs(): array
    {
        return [
            'hostname' => $this->data['hostname'] ?? null,
            'os' => $this->os(),
            'kernel' => $this->data['kernel'] ?? null,
            'arch' => $this->data['arch'] ?? null,
            // ARM VMs report "-" when the CPU has no marketing name.
            'cpu_model' => in_array($model = trim((string) ($this->data['cpu']['model'] ?? '')), ['', '-'], true) ? null : $model,
            'cores' => $this->data['cpu']['cores'] ?? null,
            'threads' => $this->data['cpu']['threads'] ?? null,
            'mhz_max' => $this->data['cpu']['mhz_max'] ?? null,
            'mem_total' => $this->data['mem']['total'] ?? null,
            'home' => $this->data['home'] ?? null,
            'user' => $this->data['user'] ?? null,
        ];
    }

    /**
     * What deploy.me can use on this server.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        $tools = $this->data['tools'] ?? [];

        return [
            'docker' => ($tools['docker_server'] ?? '') ?: null,
            'docker_error' => ($tools['docker_error'] ?? '') ?: null,
            'compose' => ($tools['compose'] ?? '') ?: null,
            'git' => ($tools['git'] ?? '') ?: null,
            'rsync' => ($tools['rsync'] ?? '') ?: null,
            'gnu_mv' => (bool) ($tools['gnu_mv'] ?? false),
            'flock' => (bool) ($tools['flock'] ?? false),
            'caddy_docker_proxy' => ($this->data['proxy']['caddy_docker_proxy'] ?? '') ?: null,
            'proxy_network' => ($this->data['proxy']['network'] ?? '') !== '',
            'caddy_on_network' => (bool) ($this->data['proxy']['caddy_on_network'] ?? false),
            'sudo_nopasswd' => (bool) ($this->data['access']['sudo_nopasswd'] ?? false),
            'docker_group' => (bool) ($this->data['access']['docker_group'] ?? false) || (int) ($this->data['access']['uid'] ?? -1) === 0,
        ];
    }

    /**
     * Point-in-time readings for server_snapshots.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        [$load1, $load5, $load15] = array_pad($this->data['cpu']['load'] ?? [], 3, null);

        return [
            'cpu_usage' => $this->data['cpu']['usage'] ?? null,
            'cpu_mhz' => $this->data['cpu']['mhz'] ?? null,
            'load_1' => $load1,
            'load_5' => $load5,
            'load_15' => $load15,
            'mem_total' => $this->data['mem']['total'] ?? null,
            'mem_available' => $this->data['mem']['available'] ?? null,
            'swap_total' => $this->data['mem']['swap_total'] ?? null,
            'swap_free' => $this->data['mem']['swap_free'] ?? null,
            'disks' => array_values($this->data['disks'] ?? []),
            'uptime' => $this->data['uptime'] ?? null,
            'stacks' => array_values($this->data['stacks'] ?? []),
            'processes' => $this->processes(),
        ];
    }

    /**
     * The busiest processes over the probe's sample window, ten of each: CPU
     * (100 is one core, the way top counts it), resident memory in bytes, and
     * TCP traffic in bytes per second each way. The network list needs root on
     * the server; `net_note` says why it's missing when it is. Null from a
     * server whose probe predates the lists.
     *
     * @return array{window: ?float, cpu: list<array{pid: int, name: string, container: ?string, cpu: float}>, mem: list<array{pid: int, name: string, container: ?string, rss: int}>, net: list<array{pid: int, name: string, container: ?string, tx: float, rx: float}>, net_note: ?string}|null
     */
    public function processes(): ?array
    {
        $top = $this->data['top'] ?? null;

        if (! is_array($top)) {
            return null;
        }

        $rows = fn (string $list, callable $readings) => collect($top[$list] ?? [])
            ->filter(fn ($row) => is_array($row) && is_numeric($row['pid'] ?? null))
            ->take(10)
            ->map(fn (array $row) => [
                'pid' => (int) $row['pid'],
                'name' => mb_substr(trim((string) ($row['name'] ?? '')), 0, 32) ?: '?',
                'container' => is_string($row['container'] ?? null) && $row['container'] !== '' ? mb_substr($row['container'], 0, 128) : null,
                ...$readings($row),
            ])
            ->values()
            ->all();

        return [
            'window' => is_numeric($top['window'] ?? null) ? round((float) $top['window'], 2) : null,
            'cpu' => $rows('cpu', fn (array $row) => ['cpu' => round((float) ($row['cpu'] ?? 0), 1)]),
            'mem' => $rows('mem', fn (array $row) => ['rss' => (int) ($row['rss'] ?? 0)]),
            'net' => $rows('net', fn (array $row) => ['tx' => round((float) ($row['tx'] ?? 0), 1), 'rx' => round((float) ($row['rx'] ?? 0), 1)]),
            'net_note' => is_string($top['net_note'] ?? null) && $top['net_note'] !== '' ? $top['net_note'] : null,
        ];
    }
}
