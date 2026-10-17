<?php

namespace App\Providers;

use App\Platform\Local\ProjectRoots;
use App\Platform\Ssh\AuditingSshClient;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;
use App\Platform\Ssh\SshConfigReader;
use App\Platform\Ssh\SshTargetResolver;
use Illuminate\Support\ServiceProvider;

/**
 * The SSH layer (how deploy.me reaches servers from inside its containers)
 * and the host folders it can read projects from.
 */
class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SshConfigCompiler::class, fn () => SshConfigCompiler::fromConfig());
        $this->app->bind(SshConfigReader::class, fn () => SshConfigReader::fromConfig());
        $this->app->singleton(KnownHosts::class);
        $this->app->singleton(SshTargetResolver::class);
        // Every remote command goes through the audit log on its way out.
        $this->app->singleton(SshClient::class, fn ($app) => new AuditingSshClient($app->make(ProcessSshClient::class)));
        $this->app->bind(ProjectRoots::class, fn () => ProjectRoots::fromConfig());
    }
}
