<?php

use Illuminate\Support\Facades\Broadcast;

// Private channels. deploy.me has a single user; every channel still requires
// an authenticated session, so /broadcasting/auth never authorises a guest.

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Probe results for the servers list, the dashboard and each server page.
Broadcast::channel('servers', fn ($user) => $user !== null);
Broadcast::channel('servers.{serverId}', fn ($user, $serverId) => $user !== null);

// CI state and health checks for the projects list, the dashboard and each project page.
Broadcast::channel('projects', fn ($user) => $user !== null);
Broadcast::channel('projects.{projectId}', fn ($user, $projectId) => $user !== null);

// Runs: the deployments list, and each run page's steps and log.
Broadcast::channel('deployments', fn ($user) => $user !== null);
Broadcast::channel('deployments.{deploymentId}', fn ($user, $deploymentId) => $user !== null);
