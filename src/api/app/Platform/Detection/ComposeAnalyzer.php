<?php

namespace App\Platform\Detection;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a Compose file for what deploys need: the project name, the
 * services and which of them a deploy recreates (built here, running its
 * own image, or serving files from the folder), the variables that pin
 * image tags, the domains in caddy labels, and whether it joins the shared
 * proxy-network.
 */
final class ComposeAnalyzer
{
    // Web servers among them: one that mounts the folder's files serves the release.
    private const WEB_SERVERS = '~^(?:docker\.io/)?(?:library/)?(?:nginx|caddy|httpd)(?:[:@]|$)~i';

    // Off-the-shelf services: a deploy neither builds nor recreates them.
    private const INFRA = '~^(?:docker\.io/)?(?:library/)?(?:postgres|postgis/postgis|mysql|mariadb|redis|valkey/valkey|memcached|mongo|rabbitmq|getmeili/meilisearch|meilisearch|minio/minio|axllent/mailpit|mailhog/mailhog|nginx|caddy|lucaslorentz/caddy-docker-proxy|traefik|elasticsearch|opensearchproject/opensearch|gotenberg/gotenberg|clickhouse/clickhouse-server|qdrant/qdrant|typesense/typesense|openbao/openbao|hashicorp/vault|grafana/grafana|prom/prometheus)(?:[:@]|$)~i';

    /**
     * Pins are the variables a deploy sets to put a commit's image in place:
     * a tag (IMAGE_TAG in `ghcr.io/org/app:${IMAGE_TAG}`) or a whole
     * reference (API_IMAGE in `${API_IMAGE:-ghcr.io/org/api:main}`).
     *
     * @return array{file: string, name: ?string, services: list<string>, app_services: list<string>, images: array<string, string>, pins: list<array{var: string, kind: string, image: ?string}>, ghcr: list<string>, proxy_network: bool, external_networks: list<string>, domains: list<string>, env_files: list<string>, fixed_names: list<string>, commands: array<string, string>, stop_grace: array<string, int>, edge_services: list<string>, healthchecked: list<string>, error: ?string}
     */
    public function analyze(string $file, string $yaml): array
    {
        $result = [
            'file' => $file, 'name' => null, 'services' => [], 'app_services' => [], 'images' => [], 'pins' => [],
            'ghcr' => [], 'proxy_network' => false, 'external_networks' => [], 'domains' => [], 'env_files' => [], 'fixed_names' => [], 'commands' => [], 'stop_grace' => [],
            'edge_services' => [], 'healthchecked' => [], 'error' => null,
        ];

        try {
            $doc = self::unwrap(Yaml::parse($yaml, Yaml::PARSE_CUSTOM_TAGS));
        } catch (ParseException $e) {
            return ['error' => 'Not valid YAML (line '.$e->getParsedLine().').'] + $result;
        }

        if (! is_array($doc)) {
            return ['error' => 'Empty compose file.'] + $result;
        }

        if (is_string($doc['name'] ?? null)) {
            $result['name'] = self::withDefaults($doc['name']);
        }

        $pins = $ghcr = $domains = $envFiles = $app = $fixed = $commands = $grace = $edge = $healthy = [];
        $networks = is_array($doc['networks'] ?? null) ? $doc['networks'] : [];
        $proxy = array_key_exists('proxy-network', $networks);
        $external = self::externalNetworks($networks);

        $services = is_array($doc['services'] ?? null) ? $doc['services'] : [];
        $built = self::builtImages($services);

        foreach ($services as $name => $service) {
            $name = (string) $name;
            $result['services'][] = $name;

            if (! is_array($service)) {
                continue;
            }

            $image = is_string($service['image'] ?? null) ? $service['image'] : null;
            $ownImage = $dataStore = false;

            if ($image !== null) {
                $result['images'][$name] = $image;

                // `${API_IMAGE:-ghcr.io/org/api:main}`: one variable holds the
                // whole reference, and its default says what it normally is.
                [$variable, $default] = self::variableImage($image) ?? [null, null];
                $effective = $variable !== null ? $default : $image;
                $isGhcr = $effective !== null && stripos($effective, 'ghcr.io/') === 0;
                $isInfra = $effective !== null && preg_match(self::INFRA, $effective) === 1;

                if ($variable !== null) {
                    if ($isGhcr || $effective === null) {
                        $pins[$variable] = ['var' => $variable, 'kind' => 'image', 'image' => $effective !== null ? strtolower(self::splitImage($effective)[0]) : null];
                    }
                } else {
                    [$repository, $tag] = self::splitImage($image);
                    if ($tag !== null && preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*)/', $tag, $vars)) {
                        foreach ($vars[1] as $var) {
                            $pins[$var] = ['var' => $var, 'kind' => 'tag', 'image' => strtolower($repository)];
                        }
                    }
                }

                if ($isGhcr) {
                    $ghcr[] = strtolower(self::splitImage($effective)[0]);
                }

                // Its own image, or the one another service here builds (a
                // Reverb or queue service running the api's image).
                $ownImage = ! $isInfra && ($isGhcr || $effective === null || str_contains($effective, '${')
                    || in_array(strtolower(self::splitImage($effective)[0]), $built, true));
                $dataStore = $isInfra && preg_match(self::WEB_SERVERS, $effective) !== 1;
            }

            // A service mounting files from the folder reads them from the
            // release, so each deploy recreates it too: a web server serving
            // the site, not a data store keeping its data there. One behind a
            // profile doesn't start with `up`: a deploy runs it on purpose (a
            // migrate one-off), and never brings it up as a service.
            if (! isset($service['profiles']) && (isset($service['build']) || $ownImage || (! $dataStore && self::mountsFolder($service['volumes'] ?? null)))) {
                $app[] = $name;
            }

            // What it runs, when it says: a dedicated migrate service migrates by itself.
            if (isset($service['command'])) {
                $commands[$name] = is_array($service['command']) ? implode(' ', array_map(strval(...), $service['command'])) : (string) $service['command'];
            }

            // How long compose waits for it to stop (a worker finishing its
            // job): a deploy that recreates it waits as long.
            if (($seconds = self::seconds($service['stop_grace_period'] ?? null)) !== null) {
                $grace[$name] = $seconds;
            }

            // A fixed container name: `compose run` can't start a second one.
            if (is_string($service['container_name'] ?? null)) {
                $fixed[] = $name;
            }

            $labels = self::labels($service['labels'] ?? null);

            foreach ($labels as $key => $value) {
                if (preg_match('/^caddy(?:_\d+)?$/', $key)) {
                    array_push($domains, ...preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY));
                }
            }

            // What the edge routes to, and what says when it's ready: both are
            // needed before a deploy can start a service's replacement beside
            // it and wait for the new one instead of replacing it in place.
            if (preg_grep('/^caddy(?:_\d+)?(?:\.|$)/', array_keys($labels)) !== []) {
                $edge[] = $name;
            }

            $check = $service['healthcheck'] ?? null;
            if (is_array($check) && ($check['test'] ?? null) !== null && ! ($check['disable'] ?? false)) {
                $healthy[] = $name;
            }

            foreach ((array) ($service['env_file'] ?? []) as $envFile) {
                $path = is_array($envFile) ? ($envFile['path'] ?? null) : $envFile;
                if (is_string($path)) {
                    $envFiles[] = $path;
                }
            }

            $networks = is_array($service['networks'] ?? null) ? $service['networks'] : [];
            if (in_array('proxy-network', array_is_list($networks) ? $networks : array_keys($networks), true)) {
                $proxy = true;
            }
        }

        return array_merge($result, [
            'app_services' => $app,
            'commands' => $commands,
            'stop_grace' => $grace,
            'pins' => array_values($pins),
            'ghcr' => array_values(array_unique($ghcr)),
            'proxy_network' => $proxy,
            'external_networks' => $external,
            'domains' => array_values(array_unique(array_filter($domains, fn (string $domain) => ! preg_match('/[${}]/', $domain)))),
            'env_files' => array_values(array_unique($envFiles)),
            'fixed_names' => $fixed,
            'edge_services' => $edge,
            'healthchecked' => $healthy,
        ]);
    }

    /**
     * The networks the stack expects to find on the server: Compose creates
     * its own, but never an external one, so `up` stops with "network not
     * found" when it's missing. A name a variable fills in can't be checked,
     * so it isn't listed.
     *
     * @param  array<mixed>  $networks
     * @return list<string>
     */
    private static function externalNetworks(array $networks): array
    {
        $external = [];

        foreach ($networks as $key => $network) {
            $flag = is_array($network) ? ($network['external'] ?? false) : false;

            if ($flag !== true && ! is_array($flag)) {
                continue;
            }

            // `external: {name: …}` is the old spelling of `name:`.
            $named = is_string($network['name'] ?? null) ? $network['name'] : (is_array($flag) && is_string($flag['name'] ?? null) ? $flag['name'] : (string) $key);
            $name = self::withDefaults($named);

            if ($name !== null) {
                $external[] = $name;
            }
        }

        return array_values(array_unique($external));
    }

    /**
     * The repositories of the images services here build.
     *
     * @return list<string>
     */
    private static function builtImages(array $services): array
    {
        $built = [];

        foreach ($services as $service) {
            if (is_array($service) && isset($service['build']) && is_string($service['image'] ?? null)) {
                $built[] = strtolower(self::splitImage($service['image'])[0]);
            }
        }

        return $built;
    }

    /**
     * Whether a service bind-mounts something from the project's folder.
     */
    private static function mountsFolder(mixed $volumes): bool
    {
        foreach (is_array($volumes) ? $volumes : [] as $volume) {
            $source = match (true) {
                is_string($volume) => explode(':', $volume, 2)[0],
                is_array($volume) && ($volume['type'] ?? null) === 'bind' => (string) ($volume['source'] ?? ''),
                default => '',
            };

            if ($source === '.' || str_starts_with($source, './')) {
                return true;
            }
        }

        return false;
    }

    /**
     * An image reference as [repository, tag]. ${…} expressions don't count
     * as separators, and a digest pins the image without a tag.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function splitImage(string $image): array
    {
        $tokens = [];
        $masked = (string) preg_replace_callback('/\$\{[^}]*\}/', function (array $match) use (&$tokens) {
            $tokens[] = $match[0];

            return "\x01".(count($tokens) - 1)."\x01";
        }, $image);
        $restore = fn (string $value) => (string) preg_replace_callback("/\x01(\\d+)\x01/", fn (array $m) => $tokens[(int) $m[1]], $value);

        $masked = explode('@', $masked, 2)[0];
        $slash = strrpos($masked, '/');
        $colon = strpos($masked, ':', $slash === false ? 0 : $slash);

        return $colon === false
            ? [$restore($masked), null]
            : [$restore(substr($masked, 0, $colon)), $restore(substr($masked, $colon + 1))];
    }

    /**
     * [variable, default] when a single variable holds the whole image reference.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private static function variableImage(string $image): ?array
    {
        if (! preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)(?::?-(.*))?\}$/s', trim($image), $match)) {
            return null;
        }

        return [$match[1], isset($match[2]) && $match[2] !== '' ? $match[2] : null];
    }

    /**
     * `${NAME:-default}` becomes the default; anything still unresolved gives null.
     */
    private static function withDefaults(string $value): ?string
    {
        $value = (string) preg_replace('/\$\{[A-Za-z_][A-Za-z0-9_]*:?-([^}]*)\}/', '$1', $value);

        return str_contains($value, '$') || trim($value) === '' ? null : trim($value);
    }

    /**
     * A Compose duration (`1900s`, `1m30s`, `2h`) in whole seconds, rounded up.
     */
    private static function seconds(mixed $duration): ?int
    {
        if (is_int($duration) || is_float($duration)) {
            return (int) ceil($duration);
        }

        if (! is_string($duration) || ! preg_match_all('/(\d+(?:\.\d+)?)(ns|us|µs|ms|h|m|s)/', $duration, $parts, PREG_SET_ORDER)) {
            return null;
        }

        $unit = ['h' => 3600, 'm' => 60, 's' => 1, 'ms' => 1e-3, 'us' => 1e-6, 'µs' => 1e-6, 'ns' => 1e-9];

        return (int) ceil(array_sum(array_map(fn (array $part) => (float) $part[1] * $unit[$part[2]], $parts)));
    }

    /**
     * Labels as a map, whether written as a map or as a list of key=value.
     *
     * @return array<string, string>
     */
    private static function labels(mixed $labels): array
    {
        if (! is_array($labels)) {
            return [];
        }

        if (! array_is_list($labels)) {
            return array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $labels);
        }

        $map = [];
        foreach ($labels as $label) {
            if (is_string($label) && str_contains($label, '=')) {
                [$key, $value] = explode('=', $label, 2);
                $map[trim($key)] = trim($value);
            }
        }

        return $map;
    }

    private static function unwrap(mixed $value): mixed
    {
        if ($value instanceof TaggedValue) {
            return self::unwrap($value->getValue());
        }

        return is_array($value) ? array_map(self::unwrap(...), $value) : $value;
    }
}
