<?php

namespace App\Platform\Detection;

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a GitHub Actions workflow for what matters to deploys: when it runs,
 * which commit-tagged images it pushes to ghcr.io, and whether it deploys to
 * a server itself.
 *
 * Image names come out as templates: {owner}/{repo} for the repository,
 * {sha} and {sha7} for the commit, and {name} for anything else the workflow
 * computes (a matrix value, a step output that couldn't be followed).
 */
final class WorkflowAnalyzer
{
    /**
     * @return array{file: string, name: string, triggers: array{push: ?list<string>, pull_request: bool, manual: bool, schedule: bool}, jobs: int, images: list<array{image: string, tag: string, count: int}>, deploys: ?array{via: string, script: ?string, path: ?string}, error: ?string}
     */
    public function analyze(string $file, string $yaml): array
    {
        $result = [
            'file' => $file,
            'name' => (string) preg_replace('/\.ya?ml$/', '', $file),
            'triggers' => ['push' => null, 'pull_request' => false, 'manual' => false, 'schedule' => false],
            'jobs' => 0,
            'images' => [],
            'deploys' => null,
            'error' => null,
        ];

        try {
            $doc = self::unwrap(Yaml::parse($yaml, Yaml::PARSE_CUSTOM_TAGS));
        } catch (ParseException $e) {
            return ['error' => 'Not valid YAML (line '.$e->getParsedLine().').'] + $result;
        }

        if (! is_array($doc)) {
            return ['error' => 'Empty workflow.'] + $result;
        }

        if (is_string($doc['name'] ?? null) && trim($doc['name']) !== '') {
            $result['name'] = trim($doc['name']);
        }

        // A YAML 1.1 reader turns the bare `on` key into true.
        $result['triggers'] = $this->triggers($doc['on'] ?? $doc[1] ?? null);

        $jobs = is_array($doc['jobs'] ?? null) ? $doc['jobs'] : [];
        $result['jobs'] = count($jobs);

        $outputs = $this->registryOutputs($yaml);
        $workflowEnv = $this->envImages($doc['env'] ?? null, $outputs);
        $images = [];

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }

            $matrix = $this->matrix($job);
            $jobEnv = [...$workflowEnv, ...$this->envImages($job['env'] ?? null, $outputs)];
            $metadata = [];
            $found = [];

            foreach (is_array($job['steps'] ?? null) ? $job['steps'] : [] as $step) {
                if (! is_array($step)) {
                    continue;
                }

                $uses = strtolower((string) ($step['uses'] ?? ''));
                $with = is_array($step['with'] ?? null) ? $step['with'] : [];
                // Image names kept in env (IMAGE: ghcr.io/…) count as known outputs too.
                $env = [...$jobEnv, ...$this->envImages($step['env'] ?? null, $outputs)];
                $known = [...$outputs, ...$env];
                $run = is_string($step['run'] ?? null) ? $this->substituteLocals($this->substituteEnv($step['run'], $env)) : '';

                if (str_starts_with($uses, 'docker/metadata-action')) {
                    $metadata[(string) ($step['id'] ?? '')] = $this->metadataImages($with, $known);
                }

                if (str_starts_with($uses, 'docker/build-push-action') && $this->truthy($with['push'] ?? false)) {
                    foreach ($this->csv($with['tags'] ?? '') as $tag) {
                        if (preg_match('/steps\.([\w-]+)\.outputs\.tags/', $tag, $match)) {
                            array_push($found, ...($metadata[$match[1]] ?? []));
                        } elseif (($image = $this->image($tag, $known)) !== null) {
                            $found[] = $image;
                        }
                    }
                }

                if ($run !== '' && preg_match_all('/docker\s+(?:image\s+)?push\s+(?:-\S+\s+)*([^\s;&|]+)/', $run, $pushes)) {
                    foreach ($pushes[1] as $reference) {
                        if (($image = $this->image($reference, $known)) !== null) {
                            $found[] = $image;
                        }
                    }
                }

                $result['deploys'] ??= $this->deployStep($uses, $with, $run);
            }

            foreach ($found as $image) {
                // A matrix job pushes one image per matrix entry when the name varies with it.
                $varies = collect($matrix['keys'])->contains(fn (string $key) => str_contains($image['image'], '{'.$key.'}'));
                $images[$image['image'].':'.$image['tag']] = $image + ['count' => $varies ? $matrix['size'] : 1];
            }
        }

        $result['images'] = array_values($images);

        return $result;
    }

    /**
     * @return array{push: ?list<string>, pull_request: bool, manual: bool, schedule: bool}
     */
    private function triggers(mixed $on): array
    {
        $triggers = ['push' => null, 'pull_request' => false, 'manual' => false, 'schedule' => false];

        if (is_string($on)) {
            $on = [$on => null];
        } elseif (is_array($on) && array_is_list($on)) {
            $on = array_fill_keys(array_filter($on, 'is_string'), null);
        }

        if (! is_array($on)) {
            return $triggers;
        }

        if (array_key_exists('push', $on)) {
            $push = is_array($on['push']) ? $on['push'] : [];
            $branches = $push['branches'] ?? null;

            $triggers['push'] = match (true) {
                is_array($branches) => array_values(array_map('strval', $branches)),
                is_string($branches) => [$branches],
                // Only tag pushes.
                isset($push['tags']) => null,
                default => ['*'],
            };
        }

        $triggers['pull_request'] = array_key_exists('pull_request', $on) || array_key_exists('pull_request_target', $on);
        $triggers['manual'] = array_key_exists('workflow_dispatch', $on);
        $triggers['schedule'] = array_key_exists('schedule', $on);

        return $triggers;
    }

    /**
     * A pushed image reference as a template, if it's a commit-tagged
     * ghcr.io image.
     *
     * @return array{image: string, tag: string}|null
     */
    private function image(string $reference, array $outputs): ?array
    {
        $reference = trim($this->expand($reference, $outputs), " \t\"'");

        if (stripos($reference, 'ghcr.io/') !== 0) {
            return null;
        }

        $slash = strrpos($reference, '/');
        $colon = strpos($reference, ':', $slash === false ? 0 : $slash);

        if ($colon === false) {
            return null;
        }

        $tag = substr($reference, $colon + 1);

        return str_contains($tag, '{sha}') || str_contains($tag, '{sha7}')
            ? ['image' => substr($reference, 0, $colon), 'tag' => $tag]
            : null;
    }

    /**
     * Images from docker/metadata-action with a sha tag (sha-{sha7} by default).
     *
     * @return list<array{image: string, tag: string}>
     */
    private function metadataImages(array $with, array $outputs): array
    {
        $tags = [];
        foreach ($this->lines($with['tags'] ?? '') as $spec) {
            if (preg_match('/\btype=sha\b/', $spec)) {
                $prefix = preg_match('/\bprefix=([^,]*)/', $spec, $match) ? $match[1] : 'sha-';
                $tags[] = $prefix.(str_contains($spec, 'format=long') ? '{sha}' : '{sha7}');
            }
        }

        $images = [];
        foreach ($this->lines($with['images'] ?? '') as $line) {
            $name = preg_match('/^name=([^,]+)/', $line, $match) ? $match[1] : explode(',', $line)[0];
            $name = trim($this->expand(trim($name), $outputs), " \t\"'");

            if (stripos($name, 'ghcr.io/') === 0) {
                foreach ($tags as $tag) {
                    $images[] = ['image' => $name, 'tag' => $tag];
                }
            }
        }

        return $images;
    }

    /**
     * Replaces ${{ … }} expressions and GitHub's environment variables with
     * template placeholders.
     */
    private function expand(string $value, array $outputs): string
    {
        $value = (string) preg_replace_callback('/\$\{\{\s*(.+?)\s*\}\}/', function (array $match) use ($outputs): string {
            $expression = $match[1];
            $last = Str::afterLast($expression, '.');

            return match (true) {
                $expression === 'github.sha' => '{sha}',
                (bool) preg_match('/short_?sha|sha_?short|short-sha|sha7/i', $expression) => '{sha7}',
                $expression === 'github.repository' => '{owner}/{repo}',
                $expression === 'github.repository_owner' => '{owner}',
                in_array($expression, ['github.ref_name', 'github.head_ref'], true) => '{branch}',
                isset($outputs[$last]) => $outputs[$last],
                default => '{'.$last.'}',
            };
        }, $value);

        return str_replace(
            ['${GITHUB_SHA::7}', '${GITHUB_SHA}', '$GITHUB_SHA', '${GITHUB_REPOSITORY,,}', '${GITHUB_REPOSITORY}', '$GITHUB_REPOSITORY'],
            ['{sha7}', '{sha}', '{sha}', '{owner}/{repo}', '{owner}/{repo}', '{owner}/{repo}'],
            $value,
        );
    }

    /**
     * Step outputs a workflow sets to a ghcr.io name in a run script, such as
     * `echo "image=ghcr.io/${GITHUB_REPOSITORY,,}" >> "$GITHUB_OUTPUT"`.
     *
     * @return array<string, string>
     */
    private function registryOutputs(string $yaml): array
    {
        $outputs = [];

        if (preg_match_all('/\b([A-Za-z_][\w-]*)=["\']?(ghcr\.io\/[^"\'\s>]+)/i', $yaml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as [, $name, $value]) {
                $value = str_replace(['${GITHUB_REPOSITORY,,}', '${GITHUB_REPOSITORY}', '$GITHUB_REPOSITORY', '${{ github.repository }}'], '{owner}/{repo}', $value);
                $value = (string) preg_replace('/\$\{\{\s*github\.repository_owner\s*\}\}|\$\{[A-Za-z_]*OWNER[A-Za-z_]*(?:,,)?\}|\$[A-Za-z_]*OWNER[A-Za-z_]*/i', '{owner}', $value);
                $outputs[$name] ??= $value;
            }
        }

        return $outputs;
    }

    /**
     * env entries (workflow, job or step) holding a ghcr.io name.
     *
     * @return array<string, string>
     */
    private function envImages(mixed $env, array $outputs): array
    {
        $images = [];

        foreach (is_array($env) ? $env : [] as $name => $value) {
            if (is_string($value) && stripos($expanded = $this->expand(trim($value), $outputs), 'ghcr.io/') === 0) {
                $images[(string) $name] = $expanded;
            }
        }

        return $images;
    }

    /**
     * $IMAGE and ${IMAGE} in a run script, replaced with what env says.
     *
     * @param  array<string, string>  $env
     */
    private function substituteEnv(string $run, array $env): string
    {
        foreach ($env as $name => $value) {
            /* `${IMAGE,,}` is bash lowercasing the value, which registry names
               need and a GitHub owner rarely is — `ghcr.io/KielD-01/…` is not a
               valid reference. The case modifier changes nothing here: the value
               is already a template of lowercase placeholders, and dropping it is
               what lets the name be followed at all. `,`, `^` and `^^` are the
               same story. */
            $quoted = preg_quote((string) $name, '/');
            $run = (string) preg_replace_callback(
                '/\$\{'.$quoted.'(?:,,|,|\^\^|\^)?\}|\$'.$quoted.'\b/',
                fn () => $value,
                $run,
            );
        }

        return $run;
    }

    /**
     * Shell variables a run script sets to a registry name, so a push through
     * one can be followed.
     *
     * A workflow rarely pushes the env variable itself. It copies it first —
     * `image="${IMAGE,,}"` — and pushes `"$image:${GITHUB_SHA}"`, which without
     * this reads as a push of nothing in particular, and the project then looks
     * like one whose CI publishes no images at all.
     *
     * Only assignments that resolve to a ghcr.io name are followed: everything
     * else in a build script is noise, and a variable that shadows an env name
     * would otherwise rewrite it to something meaningless.
     */
    private function substituteLocals(string $run): string
    {
        $locals = [];

        foreach (preg_split('/\R/', $run) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_]\w*)=(.+?)\s*$/', (string) $line, $match) !== 1) {
                continue;
            }

            $value = trim($match[2], " \t\"'");

            if (stripos($value, 'ghcr.io/') === 0) {
                $locals[$match[1]] = $value;
            }
        }

        return $locals === [] ? $run : $this->substituteEnv($run, $locals);
    }

    /**
     * A step that reaches a server over SSH: a deploy that would race deploy.me.
     *
     * @return array{via: string, script: ?string, path: ?string}|null
     */
    private function deployStep(string $uses, array $with, string $run): ?array
    {
        if (preg_match('~^appleboy/(?:ssh|scp)-action~', $uses)) {
            $text = implode("\n", array_filter([$with['script'] ?? null, $with['source'] ?? null, $with['target'] ?? null], 'is_string'));
        } elseif ($run !== '' && (preg_match('/(?:^|[\s;&|(])(?:ssh|scp)\s/m', $run) || preg_match('/(?:^|[\s;&|(])rsync\s[^\n]*\S@\S+:/m', $run))) {
            $text = $run;
        } else {
            return null;
        }

        $script = preg_match('~(?:\}\}/|\./|\s|^|["\'])((?:[\w.-]+/)*deploy[\w.-]*\.sh)~m', $text, $match) ? $match[1] : null;
        $path = preg_match('~(?:\bcd\s+["\']?|:)(/(?:opt|srv|var/www|home|data)/[\w./@-]+)~', $text, $match) ? rtrim($match[1], '/') : null;

        return ['via' => 'ssh', 'script' => $script, 'path' => $path];
    }

    /**
     * @return array{size: int, keys: list<string>}
     */
    private function matrix(array $job): array
    {
        $matrix = $job['strategy']['matrix'] ?? null;

        if (! is_array($matrix)) {
            return ['size' => 1, 'keys' => []];
        }

        $size = 1;
        $keys = [];
        foreach ($matrix as $key => $values) {
            if ($key !== 'include' && $key !== 'exclude' && is_array($values) && array_is_list($values)) {
                $size *= max(1, count($values));
                $keys[] = (string) $key;
            }
        }

        $include = is_array($matrix['include'] ?? null) ? $matrix['include'] : [];
        if ($keys === [] && $include !== []) {
            $size = count($include);
        }
        foreach ($include as $entry) {
            if (is_array($entry)) {
                array_push($keys, ...array_map('strval', array_keys($entry)));
            }
        }

        return ['size' => $size, 'keys' => array_values(array_unique($keys))];
    }

    private function truthy(mixed $value): bool
    {
        return $value === true
            || (is_string($value) && (strtolower(trim($value)) === 'true' || str_contains($value, '${{')));
    }

    /**
     * @return list<string>
     */
    private function csv(mixed $value): array
    {
        return $this->split($value, '/[\n,]+/');
    }

    /**
     * @return list<string>
     */
    private function lines(mixed $value): array
    {
        return $this->split($value, '/\n+/');
    }

    /**
     * @return list<string>
     */
    private function split(mixed $value, string $pattern): array
    {
        $items = match (true) {
            is_array($value) => array_map(fn ($item) => is_scalar($item) ? (string) $item : '', $value),
            is_string($value) => preg_split($pattern, $value) ?: [],
            default => [],
        };

        return array_values(array_filter(array_map('trim', $items), fn (string $item) => $item !== ''));
    }

    private static function unwrap(mixed $value): mixed
    {
        if ($value instanceof TaggedValue) {
            return self::unwrap($value->getValue());
        }

        return is_array($value) ? array_map(self::unwrap(...), $value) : $value;
    }
}
