<?php

namespace App\Platform\Detection;

use App\Support\DotEnv;

/**
 * Looks through a project's files for what deploys need: workflows, Compose
 * files, Dockerfiles, the framework, env templates and deploy scripts.
 */
final class ProjectDetector
{
    public function __construct(
        private readonly ComposeAnalyzer $compose,
        private readonly WorkflowAnalyzer $workflows,
        private readonly FrameworkDetector $frameworks,
    ) {}

    /**
     * @return array{workflows: list<array>, compose: list<array>, dockerfiles: list<string>, framework: array, env_templates: list<array{path: string, keys: int}>, deploy_scripts: list<string>, truncated: bool}
     */
    public function detect(FileSource $source): array
    {
        $files = $source->files();

        $workflowFiles = self::matching($files, '~^\.github/workflows/[^/]+\.ya?ml$~');
        $composeFiles = self::matching($files, '~(^|/)(docker-)?compose(\.[\w.-]+)?\.ya?ml$~', maxDepth: 2);
        $envTemplates = self::matching($files, '~(^|/)([\w-]+)?\.env\.(example|dist|sample|template)$~');
        $manifests = self::matching($files, '~(^|/)(composer\.json|composer\.lock|package\.json)$~', maxDepth: 3);

        $source->prefetch(array_values(array_unique([...$workflowFiles, ...$composeFiles, ...$envTemplates, ...$manifests])));

        $compose = array_map(fn (string $file) => $this->compose->analyze($file, (string) $source->read($file)), $composeFiles);
        usort($compose, fn (array $a, array $b) => self::rank($b['file']) <=> self::rank($a['file']) ?: strcmp($a['file'], $b['file']));

        return [
            'workflows' => array_map(fn (string $file) => $this->workflows->analyze(basename($file), (string) $source->read($file)), $workflowFiles),
            'compose' => $compose,
            'dockerfiles' => self::matching($files, '~(^|/)(Dockerfile(\.[\w.-]+)?|[\w.-]+\.Dockerfile|Containerfile)$~'),
            'framework' => $this->frameworks->detect($source, $files),
            'env_templates' => array_map(fn (string $file) => ['path' => $file, 'keys' => count(DotEnv::parse((string) $source->read($file)))], $envTemplates),
            'deploy_scripts' => self::matching($files, '~(^|/)deploy[\w.-]*\.sh$~', maxDepth: 2),
            'truncated' => $source->truncated(),
        ];
    }

    /**
     * How deploy-like a compose file is: production files first, local and
     * test overrides last, shallow before deep.
     */
    private static function rank(string $file): int
    {
        $name = strtolower(basename($file));

        $score = match (true) {
            str_contains($name, 'prod') => 40,
            in_array($name, ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'], true) => 30,
            (bool) preg_match('/(local|dev|test|ci|override|debug)/', $name) => 0,
            default => 10,
        };

        return $score - substr_count($file, '/') * 5;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private static function matching(array $files, string $pattern, int $maxDepth = 99): array
    {
        return array_values(array_filter($files, fn (string $file) => substr_count($file, '/') <= $maxDepth && preg_match($pattern, $file) === 1));
    }
}
