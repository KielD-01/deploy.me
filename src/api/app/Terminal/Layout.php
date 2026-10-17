<?php

namespace App\Terminal;

/**
 * The Console session's panes, as tmux keeps them: a tree of splits whose
 * leaves are panes. A leaf is ['id' => 'p1', 'kind' => 'shell', 'arg' => null];
 * a split is ['dir' => 'row'|'col', 'sizes' => [55, 45], 'children' => [...]],
 * sizes in percent. Every operation returns a new tree; a tree read back from
 * someone's saved layouts goes through normalize() first.
 */
final class Layout
{
    public const KINDS = ['shell', 'status', 'tail', 'htop', 'git'];

    public const PRESETS = ['ops', 'deploy', 'watch'];

    /**
     * @return array<string, mixed>
     */
    public static function preset(string $name): array
    {
        return match ($name) {
            'deploy' => ['dir' => 'row', 'sizes' => [50, 50], 'children' => [self::leaf('p0', 'shell'), self::leaf('p1', 'tail')]],
            'watch' => ['dir' => 'col', 'sizes' => [62, 38], 'children' => [self::leaf('p0', 'status'), ['dir' => 'row', 'sizes' => [50, 50], 'children' => [self::leaf('p1', 'tail'), self::leaf('p2', 'shell')]]]],
            default => ['dir' => 'row', 'sizes' => [55, 45], 'children' => [
                self::leaf('p0', 'shell'),
                ['dir' => 'col', 'sizes' => [40, 30, 30], 'children' => [self::leaf('p1', 'tail'), self::leaf('p2', 'htop'), self::leaf('p3', 'git')]],
            ]],
        };
    }

    /**
     * @return array{id: string, kind: string, arg: ?string}
     */
    public static function leaf(string $id, string $kind, ?string $arg = null): array
    {
        return ['id' => $id, 'kind' => $kind, 'arg' => $arg];
    }

    /**
     * A tree that came from storage or the browser, made valid: unknown kinds
     * become shells, sizes add up to 100, empty splits go. Null when nothing's left.
     *
     * @return array<string, mixed>|null
     */
    public static function normalize(mixed $node, int $depth = 0): ?array
    {
        if (! is_array($node) || $depth > 8) {
            return null;
        }

        if (isset($node['id'])) {
            $kind = in_array($node['kind'] ?? null, self::KINDS, true) ? $node['kind'] : 'shell';
            $arg = isset($node['arg']) && is_scalar($node['arg']) && preg_match('/^[A-Za-z0-9._#-]{1,80}$/', (string) $node['arg']) ? (string) $node['arg'] : null;

            return self::leaf(preg_match('/^p\d{1,4}$/', (string) $node['id']) ? (string) $node['id'] : 'p0', $kind, $arg);
        }

        $children = array_values(array_filter(array_map(fn ($child) => self::normalize($child, $depth + 1), is_array($node['children'] ?? null) ? $node['children'] : [])));

        if ($children === []) {
            return null;
        }

        if (count($children) === 1) {
            return $children[0];
        }

        $sizes = array_map(fn ($size) => max(5.0, (float) $size), array_slice(is_array($node['sizes'] ?? null) ? $node['sizes'] : [], 0, count($children)));
        $sizes = array_pad($sizes, count($children), 100 / count($children));

        return ['dir' => ($node['dir'] ?? 'row') === 'col' ? 'col' : 'row', 'sizes' => self::percent($sizes), 'children' => $children];
    }

    /**
     * Splits a pane in two, the new pane right of it (row) or below it (col).
     *
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    public static function split(array $tree, string $id, string $dir, string $kind = 'shell', ?string $arg = null): array
    {
        $new = self::leaf(self::nextId($tree), $kind, $arg);

        return self::map($tree, $id, fn (array $leaf) => ['dir' => $dir === 'col' ? 'col' : 'row', 'sizes' => [50, 50], 'children' => [$leaf, $new]]);
    }

    /**
     * Closes a pane; its sibling takes its place. The last pane stays.
     *
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    public static function close(array $tree, string $id): array
    {
        if (isset($tree['id'])) {
            return $tree;
        }

        return self::normalize(self::without($tree, $id)) ?? self::preset('ops');
    }

    /**
     * Points a pane at something else.
     *
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    public static function set(array $tree, string $id, string $kind, ?string $arg): array
    {
        return self::map($tree, $id, fn (array $leaf) => self::leaf($leaf['id'], in_array($kind, self::KINDS, true) ? $kind : 'shell', $arg));
    }

    /**
     * New sizes for the split at a path of child indexes ([] is the root).
     *
     * @param  array<string, mixed>  $tree
     * @param  list<int>  $path
     * @param  list<float|int>  $sizes
     * @return array<string, mixed>
     */
    public static function resize(array $tree, array $path, array $sizes): array
    {
        if ($path === []) {
            if (isset($tree['id']) || count($sizes) !== count($tree['children'])) {
                return $tree;
            }

            return [...$tree, 'sizes' => self::percent(array_map(fn ($size) => max(5.0, (float) $size), $sizes))];
        }

        $index = array_shift($path);

        if (! isset($tree['children'][$index])) {
            return $tree;
        }

        $tree['children'][$index] = self::resize($tree['children'][$index], $path, $sizes);

        return $tree;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return list<array{id: string, kind: string, arg: ?string}>
     */
    public static function leaves(array $tree): array
    {
        if (isset($tree['id'])) {
            return [$tree];
        }

        return array_merge(...array_map(fn (array $child) => self::leaves($child), $tree['children']));
    }

    /**
     * @param  array<string, mixed>  $tree
     */
    public static function nextId(array $tree): string
    {
        $ids = array_map(fn (array $leaf) => (int) substr($leaf['id'], 1), self::leaves($tree));

        return 'p'.(max($ids) + 1);
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    private static function map(array $tree, string $id, callable $change): array
    {
        if (isset($tree['id'])) {
            return $tree['id'] === $id ? $change($tree) : $tree;
        }

        $tree['children'] = array_map(fn (array $child) => self::map($child, $id, $change), $tree['children']);

        return $tree;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>|null
     */
    private static function without(array $tree, string $id): ?array
    {
        if (isset($tree['id'])) {
            return $tree['id'] === $id ? null : $tree;
        }

        $kept = [];
        $sizes = [];
        foreach ($tree['children'] as $i => $child) {
            $child = self::without($child, $id);
            if ($child !== null) {
                $kept[] = $child;
                $sizes[] = $tree['sizes'][$i] ?? 50;
            }
        }

        return $kept === [] ? null : [...$tree, 'sizes' => $sizes, 'children' => $kept];
    }

    /**
     * @param  list<float>  $sizes
     * @return list<float>
     */
    private static function percent(array $sizes): array
    {
        $total = array_sum($sizes) ?: 1;

        return array_map(fn (float $size) => round($size / $total * 100, 2), $sizes);
    }
}
