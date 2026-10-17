<?php

namespace App\Deploy;

use App\Models\Project;
use App\Models\Release;

/**
 * Builds for images a compose file names but doesn't say how to build.
 *
 * A project like warehouse pins `image: ${REGISTRY}/warehouse-api:${IMAGE_TAG}` with no
 * `build:` section, because its CI builds that image. Shipped as files instead
 * (push archive), the server has to build it itself, and the project isn't
 * changed to say how: the recipe lives in deploy.me (`projects.server_builds`)
 * and becomes a compose override written into the release, read only by the
 * build. The image keeps the name the compose file gives it, tagged with the
 * release's commit (WritePins), so every other service that names the same
 * image runs what was just built, and nothing is pulled.
 */
final class ServerBuild
{
    /** The override, inside the release. */
    public const FILE = '.deployme-build.yml';

    /**
     * The compose override that adds a `build:` to each service the project
     * builds this way, or null when it has none.
     */
    public static function override(Project $project, Release $release): ?string
    {
        $builds = $project->serverBuilds();

        if ($builds === []) {
            return null;
        }

        $sha = (string) ($release->commit_sha ?? '');
        $sha7 = (string) ($release->shortSha() ?? $release->name);
        $lines = ['# Written by deploy.me for this release\'s image build; compose reads it only to build.', 'services:'];

        foreach ($builds as $build) {
            $lines[] = '  '.self::quote($build['service']).':';
            $lines[] = '    build:';
            $lines[] = '      context: '.self::quote($build['context']);
            $lines[] = '      dockerfile: '.self::quote($build['dockerfile']);

            if ($build['target'] !== null) {
                $lines[] = '      target: '.self::quote($build['target']);
            }

            if ($build['args'] !== []) {
                $lines[] = '      args:';

                foreach ($build['args'] as $name => $value) {
                    $lines[] = '        '.self::quote($name).': '.self::quote(strtr($value, ['{sha}' => $sha, '{sha7}' => $sha7]));
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The release's shared links (storage folders, env files) as paths inside a
     * build context, for its ignore file: a link to a folder outside the release
     * is no use in an image, and an env file has no business in one.
     *
     * @return list<string>
     */
    public static function excluded(Project $project, string $context): array
    {
        $paths = [...$project->sharedPaths(), ...Project::normalizePaths($project->envFiles()->pluck('path')->all())];
        $base = trim($context, './');
        $out = [];

        foreach ($paths as $path) {
            if ($base === '') {
                $out[] = $path;
            } elseif (str_starts_with($path, $base.'/')) {
                $out[] = substr($path, strlen($base) + 1);
            }
        }

        return array_values(array_unique($out));
    }

    /** A YAML scalar: a JSON string is valid YAML and escapes everything that needs it. */
    private static function quote(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
