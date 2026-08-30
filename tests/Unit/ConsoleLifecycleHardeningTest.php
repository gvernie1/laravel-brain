<?php

use LaraMint\LaravelBrain\Analysis\ConsoleAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

/** @param array<string,string> $files */
function consoleLifecycleProject(array $files): string
{
    $root = sys_get_temp_dir().'/brain-console-'.uniqid();
    foreach ($files as $path => $contents) {
        $full = $root.'/'.$path;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0o777, true);
        }
        file_put_contents($full, $contents);
    }

    return $root;
}

function removeConsoleLifecycleProject(string $root): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

/** @return array<string,string> */
function consoleLifecycleFiles(): array
{
    return [
        'composer.json' => <<<'JSON'
{"autoload":{"psr-4":{"App\\":"app/"}}}
JSON,
        'routes/console.php' => <<<'PHP'
<?php
use App\Jobs\CleanupJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
Artisan::command('reports:closure {account?}', function (): void {});
Schedule::command('reports:sync acme --force', ['--limit' => 10])
    ->dailyAt('13:30')
    ->timezone('America/Toronto')
    ->withoutOverlapping(15);
Schedule::command('reports:cron')->cron('15 2 * * *');
Schedule::command('vendor:prune')->daily();
Schedule::job(new CleanupJob())->everyFiveMinutes()->onOneServer();
PHP,
        'bootstrap/app.php' => <<<'PHP'
<?php
use Illuminate\Console\Scheduling\Schedule;
return Application::configure()->withSchedule(function (Schedule $schedule): void {
    $schedule->command('reports:cron')->daily()->withoutOverlapping();
});
PHP,
        'app/Console/Kernel.php' => <<<'PHP'
<?php
namespace App\Console;
use Illuminate\Console\Scheduling\Schedule;
class Kernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('reports:sync beta')->weeklyOn(1, '08:00')->timezone('UTC');
    }
}
PHP,
        'app/Console/Commands/SyncReports.php' => <<<'PHP'
<?php
namespace App\Console\Commands;
use App\Services\ReportWorker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
#[Signature('reports:sync {account?} {--force}')]
#[Description('Synchronize reports')]
class SyncReports
{
    public function handle(ReportWorker $worker): void { $worker->run(); }
}
PHP,
        'app/Console/Commands/CronReports.php' => <<<'PHP'
<?php
namespace App\Console\Commands;
class CronReports
{
    protected $signature = 'reports:cron {--dry-run}';
    protected $description = 'Cron reports';
    public function handle(): void {}
}
PHP,
        'app/Services/ReportWorker.php' => <<<'PHP'
<?php
namespace App\Services;
use App\Models\Record;
class ReportWorker { public function run(): void { Record::where('ready', true); } }
PHP,
        'app/Jobs/CleanupJob.php' => <<<'PHP'
<?php
namespace App\Jobs;
use App\Services\ReportWorker;
class CleanupJob { public function handle(ReportWorker $worker): void { $worker->run(); } }
PHP,
        'app/Models/Record.php' => <<<'PHP'
<?php
namespace App\Models;
class Record extends \Illuminate\Database\Eloquent\Model {}
PHP,
    ];
}

it('parses complete schedule chains and canonical command identities', function () {
    $root = consoleLifecycleProject(consoleLifecycleFiles());
    $result = (new ConsoleAnalyzer)->analyze($root);
    $commands = $result['commands'];
    $schedules = $result['schedule'];

    $command = fn (string $name) => array_values(array_filter($commands, fn ($command) => $command->canonicalName === $name))[0] ?? null;
    $schedule = fn (string $target, string $frequency) => array_values(array_filter(
        $schedules,
        fn ($schedule) => $schedule->canonicalTarget === $target && $schedule->frequency === $frequency,
    ))[0] ?? null;

    $sync = $command('reports:sync');
    $closure = $command('reports:closure');
    $dailyAt = $schedule('reports:sync', 'dailyAt');
    $cron = $schedule('reports:cron', 'cron');
    $kernel = $schedule('reports:sync', 'weeklyOn');
    $job = $schedule('App\Jobs\CleanupJob', 'everyFiveMinutes');
    $external = $schedule('vendor:prune', 'daily');
    $bootstrap = $schedule('reports:cron', 'daily');

    expect($sync)->not->toBeNull()
        ->declaredSignature->toBe('reports:sync {account?} {--force}')
        ->description->toBe('Synchronize reports')
        ->class->toBe('App\Console\Commands\SyncReports')
        ->and($closure)->not->toBeNull()
        ->declaredSignature->toBe('reports:closure {account?}')
        ->source->toBe('route')
        ->sourceScope->toBe('application')
        ->and($dailyAt)->not->toBeNull()
        ->target->toBe('reports:sync acme --force')
        ->rawInvocation->toBe('reports:sync acme --force')
        ->cadenceArguments->toBe(['13:30'])
        ->invocationArguments->toContain('acme')
        ->invocationOptions->toContain('--force', ['--limit' => 10])
        ->targetResolution->toBe('local')
        ->targetClass->toBe('App\Console\Commands\SyncReports')
        ->definitionScope->toBe('application')
        ->sourceScope->toBe('application')
        ->targetScope->toBe('application')
        ->and(array_column($dailyAt->modifiers, 'method'))->toBe(['timezone', 'withoutOverlapping'])
        ->and($dailyAt->modifiers[0]['arguments'])->toBe(['America/Toronto'])
        ->and($dailyAt->modifiers[1]['arguments'])->toBe([15])
        ->and($cron)->not->toBeNull()
        ->cronExpression->toBe('15 2 * * *')
        ->cadenceArguments->toBe(['15 2 * * *'])
        ->and($kernel)->not->toBeNull()
        ->cadenceArguments->toBe([1, '08:00'])
        ->and(array_column($kernel->modifiers, 'method'))->toBe(['timezone'])
        ->and($job)->not->toBeNull()
        ->type->toBe('job')
        ->targetResolution->toBe('local')
        ->and($external)->not->toBeNull()
        ->targetResolution->toBe('unresolved')
        ->targetClass->toBeNull()
        ->definitionScope->toBe('application')
        ->sourceScope->toBe('application')
        ->targetScope->toBe('unknown')
        ->and($bootstrap)->not->toBeNull()
        ->and(array_column($bootstrap->modifiers, 'method'))->toBe(['withoutOverlapping']);

    removeConsoleLifecycleProject($root);
});

it('links schedules to local command and job lifecycles without fabricating package commands', function () {
    $root = consoleLifecycleProject(consoleLifecycleFiles());
    $result = (new ConsoleAnalyzer)->analyze($root);
    $psr4 = ['App\\' => [$root.'/app']];
    $tracer = new MethodTracer;
    $callEdges = [];
    foreach ($result['commands'] as $command) {
        if ($command->class !== '') {
            $callEdges = [...$callEdges, ...$tracer->traceMethod($command->class, 'handle', $psr4, $root)];
        }
    }
    $callEdges = [...$callEdges, ...$tracer->traceMethod('App\Jobs\CleanupJob', 'handle', $psr4, $root)];

    $build = function () use ($root, $result, $callEdges) {
        $builder = new GraphBuilder;
        $graph = $builder->build('console', [], new MiddlewareRegistry([], [], []), [], [], [], $root);
        $builder->addConsoleCommands($result['commands'], $result['schedule'], $callEdges);

        return $graph;
    };
    $graph = $build();
    $nodeIds = array_map(fn ($node) => $node->id, $graph->nodes());
    $edgeTypes = array_map(fn ($edge) => $edge->type, $graph->edges());

    expect($nodeIds)->toContain(
        'command::reports:sync',
        'app_jobs_cleanupjob::handle',
        'app_services_reportworker::run',
        'app_models_record::where',
    )->not->toContain('command::vendor:prune')
        ->and($edgeTypes)->toContain('schedule-to-command', 'schedule-to-job', 'command-to-service', 'job-to-service');

    $scheduleNodes = array_values(array_filter($graph->nodes(), fn ($node) => $node->type === 'schedule'));
    $local = array_values(array_filter($scheduleNodes, fn ($node) => ($node->data['canonicalTarget'] ?? null) === 'reports:sync'))[0];
    $external = array_values(array_filter($scheduleNodes, fn ($node) => ($node->data['canonicalTarget'] ?? null) === 'vendor:prune'))[0];
    expect($local->data['rawInvocation'])->toBe('reports:sync acme --force')
        ->and($local->data['targetResolution'])->toBe('local')
        ->and($local->data)->toMatchArray([
            'sourceScope' => 'application',
            'definitionScope' => 'application',
            'targetScope' => 'application',
        ])
        ->and($external->data['targetResolution'])->toBe('unresolved')
        ->and($external->data['targetClass'])->toBeNull()
        ->and($external->data)->toMatchArray([
            'sourceScope' => 'application',
            'definitionScope' => 'application',
            'targetScope' => 'unknown',
        ]);

    foreach ($graph->edges() as $edge) {
        expect($graph->hasNode($edge->source))->toBeTrue()
            ->and($graph->hasNode($edge->target))->toBeTrue();
    }

    $firstIds = [
        array_map(fn ($node) => $node->id, $graph->nodes()),
        array_map(fn ($edge) => $edge->id, $graph->edges()),
    ];
    $second = $build();
    expect($firstIds)->toBe([
        array_map(fn ($node) => $node->id, $second->nodes()),
        array_map(fn ($edge) => $edge->id, $second->edges()),
    ]);

    removeConsoleLifecycleProject($root);
});
