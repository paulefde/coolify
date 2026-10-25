<?php

use App\Livewire\Project\Application\Advanced;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function buildHostsFixture(): array
{
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner->id, ['role' => 'owner']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    test()->actingAs($owner);
    session(['currentTeam' => $team]);

    return [$application, $owner, $team];
}

test('build hosts default to legacy behavior and the selected policy survives reload', function () {
    [$application] = buildHostsFixture();
    Livewire::test(Advanced::class, ['application' => $application])
        ->assertSet('buildHostsMode', 'all')
        ->set('buildHostsMode', 'list')
        ->set('buildHosts', "redis, database\nredis")
        ->call('saveBuildTimeHosts')
        ->assertHasNoErrors()
        ->assertDispatched('success');
    expect($application->settings()->first()->build_hosts)->toBe(['database', 'redis']);
    Livewire::test(Advanced::class, ['application' => $application->fresh()])
        ->assertSet('buildHostsMode', 'list')
        ->assertSet('buildHosts', "database\nredis");
});

test('invalid host names are rejected without changing saved settings', function () {
    [$application] = buildHostsFixture();
    Livewire::test(Advanced::class, ['application' => $application])
        ->set('buildHostsMode', 'list')
        ->set('buildHosts', 'db;id')
        ->call('saveBuildTimeHosts')
        ->assertHasErrors(['buildHosts']);
    expect($application->settings()->first()->build_hosts_mode)->toBe('all');
});

test('switching to another team does not allow changing build hosts', function () {
    [$application, , $team] = buildHostsFixture();
    $member = User::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);
    test()->actingAs($member);
    $component = Livewire::test(Advanced::class, ['application' => $application]);
    $other = Team::factory()->create();
    $other->members()->attach($member->id, ['role' => 'owner']);
    session(['currentTeam' => $other]);
    $component->set('buildHostsMode', 'none')
        ->call('saveBuildTimeHosts')
        ->assertDispatched('error')
        ->assertNotDispatched('success');
    expect($application->settings()->first()->build_hosts_mode)->toBe('all');
});
