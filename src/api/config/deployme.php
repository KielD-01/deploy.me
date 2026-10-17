<?php

// Most of these come from the container environment (docker-compose.local.yml), not .env.
return [

    // Host folders mounted at identical paths inside the containers.
    'project_roots' => array_values(array_filter(explode(':', (string) env('DEPLOYME_PROJECT_ROOTS', '')))),

    'host_home' => env('DEPLOYME_HOST_HOME'),

    'host_user' => env('DEPLOYME_HOST_USER'),

    // While no account exists, the sign-in page offers to create it (/setup).
    // Off: only `make enroll` creates it, so nothing local can claim it first.
    'web_setup' => (bool) env('DEPLOYME_WEB_SETUP', true),

    'ssh' => [
        'binary' => env('DEPLOYME_SSH_BINARY', 'ssh'),
        'keygen_binary' => env('DEPLOYME_SSH_KEYGEN_BINARY', 'ssh-keygen'),

        // The user's ~/.ssh/config and known_hosts, mounted read-only.
        'source_config' => env('DEPLOYME_SSH_CONFIG_SOURCE', '/root/.ssh/config'),
        'user_known_hosts' => env('DEPLOYME_SSH_KNOWN_HOSTS', '/root/.ssh/known_hosts'),

        // More configs to include after the user's, e.g. the local sandbox (`make sandbox`).
        'extra_configs' => [storage_path('app/sandbox-ssh/ssh_config')],

        // Memory-only runtime state: compiled config, known_hosts, ControlPath sockets.
        'runtime_dir' => env('DEPLOYME_SSH_RUNTIME_DIR', '/run/deployme/ssh'),

        // Docker Desktop / OrbStack forward the host agent here; the entrypoint
        // unsets the variable when the socket is missing.
        'agent_socket' => env('SSH_AUTH_SOCK'),

        'connect_timeout' => 10,
    ],

    'git' => [
        'binary' => env('DEPLOYME_GIT_BINARY', 'git'),
        // Baked into the image: answers git's username and password prompts
        // from the environment of a single call.
        'askpass' => env('DEPLOYME_GIT_ASKPASS', '/usr/local/bin/deployme-git-askpass'),
    ],

    'github' => [
        'api' => env('DEPLOYME_GITHUB_API', 'https://api.github.com'),
        'timeout' => 20,
    ],

    'detection' => [
        // How many folders below the project root an import looks into
        // (src/api/app is three).
        'depth' => 3,
    ],

    'ci' => [
        // How often each project's GitHub Actions state is refreshed.
        'poll_interval' => 300,
    ],

    'sync' => [
        'rsync_binary' => env('DEPLOYME_RSYNC_BINARY', 'rsync'),
        // Below the syncs worker's --timeout.
        'timeout' => 3300,
        // How often a running sync checks for "Cancel" (ms).
        'poll_ms' => 200,
        // A scheduled sync never deletes more files than this; a bigger
        // difference needs a preview you apply.
        'scheduled_max_delete' => 100,
    ],

    'deploy' => [
        // How long an upload may take, below the deploys worker's --timeout.
        'upload_timeout' => 3000,
        // How often a running deploy looks for "Cancel" while a script runs (ms).
        'cancel_check_ms' => 2000,
    ],

    'probe' => [
        'default_interval' => 300,
        // The second of the minute scheduled probes start at, clear of the cron jobs
        // that start on the minute.
        'second' => 30,
        // A deploy-path filesystem this full marks the server as degraded.
        'disk_warning_pct' => 90,
    ],

    // Never deploy into these, whatever server they're on.
    'reserved_paths' => ['/opt/infra'],

    // The supervisor program list baked into the PHP image.
    'supervisor_queues_conf' => env('DEPLOYME_SUPERVISOR_QUEUES_CONF', '/etc/supervisor/conf.d/queues.conf'),

    // dev: the code is mounted from src/api and edited live. prod: it's baked
    // into the image (compose.prod.yml), cached at start, debug off.
    'mode' => env('DEPLOYME_MODE', 'dev') === 'prod' ? 'prod' : 'dev',

];
