<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function addHostFlagsFor(array $containers, ?string $mode = null, array $hosts = []): string
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $application = new Application;
    $application->setRelation('settings', (new ApplicationSetting)->forceFill([
        'build_hosts_mode' => $mode,
        'build_hosts' => $hosts,
    ]));
    (new ReflectionProperty(ApplicationDeploymentJob::class, 'application'))->setValue($job, $application);

    return (new ReflectionMethod(ApplicationDeploymentJob::class, 'addHostFlags'))->invoke($job, collect($containers));
}

it('keeps build helper containers of other deployments out of --add-host', function () {
    ApplicationDeploymentQueue::create(['application_id' => '1', 'deployment_uuid' => 'k0s8g4wc4c8gkkso0gk4cgww']);

    $flags = addHostFlagsFor([
        'a' => ['Name' => 'redis-db', 'IPv4Address' => '10.0.1.5/24'],
        'b' => ['Name' => 'k0s8g4wc4c8gkkso0gk4cgww', 'IPv4Address' => '10.0.1.9/24'],
        'c' => ['Name' => 'coolify-proxy', 'IPv4Address' => '10.0.1.2/24'],
        'd' => ['Name' => 'app-uuid-20260908T141530', 'IPv4Address' => '10.0.1.7/24'],
    ]);

    expect($flags)->toBe('--add-host redis-db:10.0.1.5');
});

it('omits all destination hosts when the application does not need build-time hosts', function () {
    expect(addHostFlagsFor([
        ['Name' => 'database', 'IPv4Address' => '10.0.1.3/24'],
    ], 'none'))->toBe('');
});

it('keeps explicitly selected hosts stable when unrelated containers start or stop', function () {
    $database = ['Name' => 'database', 'IPv4Address' => '10.0.1.3/24'];
    $tool = ['Name' => 'temporary-tool', 'IPv4Address' => '10.0.1.4/24'];
    expect(addHostFlagsFor([$database, $tool], 'list', ['database']))
        ->toBe(addHostFlagsFor([$database], 'list', ['database']))
        ->toBe('--add-host database:10.0.1.3');
});

it('fails rather than silently removing a required host from the build', function () {
    addHostFlagsFor([], 'list', ['database']);
})->throws(RuntimeException::class, 'Required build-time host is unavailable: database');

it('does not allow an explicit host to bypass the helper filter', function () {
    ApplicationDeploymentQueue::create(['application_id' => '1', 'deployment_uuid' => 'helper-deployment-uuid']);
    addHostFlagsFor([
        ['Name' => 'helper-deployment-uuid', 'IPv4Address' => '10.0.1.4/24'],
    ], 'list', ['helper-deployment-uuid']);
})->throws(RuntimeException::class, 'Required build-time host is unavailable');

it('sorts and deduplicates the selected hosts while resolving current addresses', function () {
    expect(addHostFlagsFor([
        ['Name' => 'redis', 'IPv4Address' => '10.0.1.8/24'],
        ['Name' => 'database', 'IPv4Address' => '10.0.1.9/24'],
    ], 'list', ['redis', 'database', 'redis']))
        ->toBe('--add-host database:10.0.1.9 --add-host redis:10.0.1.8');
});

it('rejects shell syntax in a configured host name', function () {
    addHostFlagsFor([], 'list', ['database;id']);
})->throws(InvalidArgumentException::class);

it('rejects unknown policy modes', function () {
    addHostFlagsFor([], 'typo');
})->throws(InvalidArgumentException::class);

it('keeps the --add-host set stable whether or not a build helper is running', function () {
    ApplicationDeploymentQueue::create(['application_id' => '1', 'deployment_uuid' => 'helper-deployment-uuid']);
    $database = ['Name' => 'postgres-main', 'IPv4Address' => '10.0.1.3/24'];

    expect(addHostFlagsFor([$database, ['Name' => 'helper-deployment-uuid', 'IPv4Address' => '10.0.1.4/24']]))
        ->toBe(addHostFlagsFor([$database]))
        ->toBe('--add-host postgres-main:10.0.1.3');
});
