<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\View;

/**
 * The interface's themes. A theme is a whole skin: its own layout, page views
 * and tokens (type, colour, density), over the same Livewire components and
 * data. Its views live in resources/views/themes/<theme>/ and are looked up
 * before the base ones, so a page the theme doesn't have yet falls back to the
 * base view inside the theme's layout. Classic is the base itself.
 *
 * Each person's choice is users.ui: theme, mode (dark, light or system),
 * density, and the Console theme's pane layouts.
 */
final class Ui
{
    /**
     * @var array<string, array{label: string, views: bool, mode: string, density: string, description: string, type: string}>
     */
    public const THEMES = [
        'console' => [
            'label' => 'Console',
            'views' => true,
            'mode' => 'dark',
            'density' => 'compact',
            'description' => 'A tmux session you split into panes: a shell to deploy from, the run’s log, htop, git.',
            'type' => 'JetBrains Mono · compact · dark first',
        ],
        'classic' => [
            'label' => 'Classic',
            'views' => false,
            'mode' => 'system',
            'density' => 'comfortable',
            'description' => 'The original pages: a top bar, cards and tables.',
            'type' => 'Inter · comfortable · follows the system',
        ],
    ];

    // Designed (see the redesign canvas), not built yet.
    public const COMING = [
        'command' => ['label' => 'Command center', 'description' => 'A sidebar with your projects, KPI tiles and a project × server matrix.'],
        'stream' => ['label' => 'Release stream', 'description' => 'The deploy in progress up top, project cards with their release history.'],
    ];

    public const DEFAULT_THEME = 'console';

    public const MODES = ['dark', 'light', 'system'];

    public const DENSITIES = ['compact', 'comfortable'];

    /**
     * daisyUI theme names per theme, light and dark.
     *
     * @var array<string, array{light: string, dark: string}>
     */
    private const PALETTES = [
        'console' => ['light' => 'console-light', 'dark' => 'console'],
        'classic' => ['light' => 'deployme', 'dark' => 'deployme-dark'],
    ];

    /**
     * Someone's choices with the theme's defaults filled in. A guest gets the default theme.
     *
     * @return array{theme: string, mode: string, density: string, layouts: array<string, mixed>}
     */
    public static function preferences(?User $user): array
    {
        $stored = $user?->ui ?? [];
        $theme = array_key_exists($stored['theme'] ?? '', self::THEMES) ? $stored['theme'] : self::DEFAULT_THEME;
        $defaults = self::THEMES[$theme];

        return [
            'theme' => $theme,
            'mode' => in_array($stored['mode'] ?? null, self::MODES, true) ? $stored['mode'] : $defaults['mode'],
            'density' => in_array($stored['density'] ?? null, self::DENSITIES, true) ? $stored['density'] : $defaults['density'],
            'layouts' => is_array($stored['layouts'] ?? null) ? $stored['layouts'] : [],
        ];
    }

    /**
     * Changes some of someone's choices; an unknown value is refused.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function save(User $user, array $changes): void
    {
        $ui = $user->ui ?? [];

        foreach ($changes as $key => $value) {
            $valid = match ($key) {
                'theme' => array_key_exists($value, self::THEMES),
                'mode' => in_array($value, self::MODES, true),
                'density' => in_array($value, self::DENSITIES, true),
                'layouts' => is_array($value),
                default => false,
            };

            if (! $valid) {
                throw new \InvalidArgumentException("Not a valid {$key}.");
            }

            $ui[$key] = $value;
        }

        // A theme of its own brings its own mode and density until they're chosen again.
        if (array_key_exists('theme', $changes) && ! array_key_exists('mode', $changes)) {
            unset($ui['mode'], $ui['density']);
        }

        $user->forceFill(['ui' => $ui])->save();
    }

    /**
     * Looks the theme's views up before the base ones, for the rest of this
     * request. Switching back (a test's second request, Classic) restores the
     * base paths first, so no theme's views linger.
     */
    public static function apply(string $theme): void
    {
        $finder = View::getFinder();

        if (! app()->bound('ui.base')) {
            app()->instance('ui.base', ['paths' => $finder->getPaths(), 'layouts' => $finder->getHints()['layouts'] ?? []]);
        }

        if (app()->bound('ui.applied') && app('ui.applied') === $theme) {
            return;
        }

        $base = app('ui.base');
        $finder->setPaths($base['paths']);
        View::replaceNamespace('layouts', $base['layouts']);
        $finder->flush();

        if (self::THEMES[$theme]['views'] ?? false) {
            $path = resource_path("views/themes/{$theme}");
            $finder->prependLocation($path);
            View::prependNamespace('layouts', "{$path}/layouts");
        }

        app()->instance('ui.applied', $theme);
    }

    /**
     * The daisyUI theme names this theme paints with.
     *
     * @return array{light: string, dark: string}
     */
    public static function palette(string $theme): array
    {
        return self::PALETTES[$theme] ?? self::PALETTES['classic'];
    }

    /**
     * For tests: forget which theme this application applied.
     */
    public static function reset(): void
    {
        app()->forgetInstance('ui.applied');
    }
}
