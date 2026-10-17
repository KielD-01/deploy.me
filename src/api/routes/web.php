<?php

use App\Http\Controllers\DeploymentLogController;
use App\Http\Controllers\EnrollController;
use App\Http\Controllers\SetupController;
use App\Livewire;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::livewire('/', Livewire\Dashboard::class)->name('dashboard');

    Route::livewire('/servers', Livewire\Servers\Index::class)->name('servers.index');
    // Adding a server trusts its host key, so it asks you to confirm with a passkey first.
    Route::livewire('/servers/create', Livewire\Servers\Create::class)->middleware('password.confirm')->name('servers.create');
    Route::livewire('/servers/{server}', Livewire\Servers\Show::class)->name('servers.show');

    Route::livewire('/projects', Livewire\Projects\Index::class)->name('projects.index');
    Route::livewire('/projects/import', Livewire\Projects\Import::class)->name('projects.import');
    Route::livewire('/projects/{project}', Livewire\Projects\Show::class)->name('projects.show');
    Route::livewire('/projects/{project}/targets/{target}/convert', Livewire\Projects\Convert::class)->name('projects.convert');
    Route::livewire('/deployments', Livewire\Deployments\Index::class)->name('deployments.index');
    Route::livewire('/deployments/{deployment}', Livewire\Deployments\Show::class)->name('deployments.show');
    Route::get('/deployments/{deployment}/log.txt', DeploymentLogController::class)->name('deployments.log');
    Route::livewire('/audit', Livewire\Audit\Index::class)->name('audit');
    Route::livewire('/settings', Livewire\Settings\Index::class)->name('settings');
});

// First run in the browser, while no account exists (SetupController::available()).
Route::middleware(['web', 'guest'])->group(function () {
    Route::get('/setup', [SetupController::class, 'show'])->name('setup');
    Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:10,1')->name('setup.store');
});

// One-time link from `php artisan deployme:enroll`: signs in to register a passkey.
Route::get('/enroll/{token}', EnrollController::class)
    ->middleware(['web', 'throttle:10,1'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->name('enroll');

// Every daisyUI component in the deploy.me theme, for checking it against
// design/canvas. Local only, and it shows no data.
if (app()->isLocal()) {
    Route::livewire('/_kit', Livewire\Kit::class)->name('kit');
}
