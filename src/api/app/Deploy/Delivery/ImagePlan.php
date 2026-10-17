<?php

namespace App\Deploy\Delivery;

use App\Deploy\Compose;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * The images a commit's CI publishes, and the compose variables that pin a
 * release to them: IMAGE_TAG=sha-a1b2c3d, or API_IMAGE=ghcr.io/org/api:sha-a1b2c3d.
 * Built from the project's image templates, which start as what detection made
 * of the workflow (image and tag templates) and the compose file (which
 * variables hold a tag or a whole image), and can be corrected by hand.
 */
final readonly class ImagePlan
{
    /**
     * @param  list<array{image: string, tag: string}>  $images
     * @param  array<string, string>  $pins
     */
    public function __construct(
        public string $workflow,
        public array $images,
        public array $pins,
    ) {}

    public static function for(Project $project, string $sha): ?self
    {
        $owner = strtolower((string) $project->github_owner);
        $repo = strtolower((string) $project->github_repo);
        $fill = fn (string $template) => str_replace(['{owner}', '{repo}', '{sha}', '{sha7}'], [$owner, $repo, $sha, substr($sha, 0, 7)], $template);

        $images = [];
        $pins = [];

        foreach ($project->imageTemplates() as $template) {
            $image = $fill($template['image']);
            $tag = $fill($template['tag']);

            // A placeholder nothing filled in ({service}, say): no image to look for.
            if (str_contains($image, '{') || str_contains($tag, '{')) {
                continue;
            }

            $pins[$template['var']] = $template['kind'] === 'image' ? "{$image}:{$tag}" : $tag;
            $images["{$image}:{$tag}"] = ['image' => $image, 'tag' => $tag];
        }

        return $pins !== []
            ? new self((string) ($project->detected()->imagePublishing()['workflow'] ?? ''), array_values($images), $pins)
            : null;
    }

    /**
     * What detection makes of the project: one row per compose variable that
     * pins an image, with the image and tag its workflow publishes. The seed
     * for the project's own list, and what "back to detection" restores.
     *
     * @return list<array{var: string, kind: string, image: string, tag: string}>
     */
    public static function detected(Project $project): array
    {
        $publishing = $project->detected()->imagePublishing();
        $compose = Compose::detected($project);

        if ($publishing === null || $compose === null) {
            return [];
        }

        $owner = strtolower((string) $project->github_owner);
        $repo = strtolower((string) $project->github_repo);
        $templates = $publishing['images'];
        $rows = [];

        foreach ($compose['pins'] ?? [] as $pin) {
            $repository = $pin['image'] ?? null;
            $template = ($repository !== null ? self::matching($templates, $repository, $owner, $repo) : null)
                ?? (count($templates) === 1 ? $templates[0] : null);
            $var = $pin['var'] ?? null;

            if ($template === null || ! is_string($var) || $var === '') {
                continue;
            }

            $rows[] = [
                'var' => $var,
                'kind' => ($pin['kind'] ?? null) === 'image' ? 'image' : 'tag',
                // {sha} and {sha7} stay: only the commit fills those in.
                'image' => self::image($repository, str_replace(['{owner}', '{repo}'], [$owner, $repo], $template['image'])),
                'tag' => $template['tag'],
            ];
        }

        return $rows;
    }

    /**
     * The workflow template a compose image comes from: {owner} and {repo}
     * filled in, {service} standing for any one name.
     *
     * @param  list<array{image: string, tag: string}>  $templates
     * @return array{image: string, tag: string}|null
     */
    private static function matching(array $templates, string $repository, string $owner, string $repo): ?array
    {
        $repository = strtolower($repository);
        // `${REGISTRY}/api`: .env fills the start in on the server, so only
        // the name after it can match.
        $variable = str_contains($repository, '${');

        foreach ($templates as $template) {
            $pattern = self::pattern($variable ? Str::afterLast($template['image'], '/') : $template['image'], $owner, $repo);

            if (preg_match("#^{$pattern}$#", $variable ? Str::afterLast($repository, '/') : $repository) === 1) {
                return $template;
            }
        }

        return null;
    }

    private static function pattern(string $template, string $owner, string $repo): string
    {
        return str_replace(
            ['\{owner\}', '\{repo\}', '\{service\}'],
            [preg_quote($owner, '#'), preg_quote($repo, '#'), '[a-z0-9._-]+'],
            preg_quote(strtolower($template), '#'),
        );
    }

    /**
     * Where to look for the image: the compose file's own name for it, or,
     * when a variable starts that name, the workflow's registry and owner
     * with the compose file's image name.
     */
    private static function image(?string $repository, string $published): string
    {
        if ($repository === null) {
            return $published;
        }

        return str_contains($repository, '${')
            ? Str::beforeLast($published, '/').'/'.Str::afterLast($repository, '/')
            : $repository;
    }
}
