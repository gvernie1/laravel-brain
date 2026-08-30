<?php

use LaraMint\LaravelBrain\Analysis\ConsoleAnalyzer;
use LaraMint\LaravelBrain\Analysis\ConsoleCommandDefinition;
use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Analysis\SourceProvenance;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Graph\GraphSplitter;
use LaraMint\LaravelBrain\Graph\Node;

/** @param array<string,string> $files */
function contractProject(array $files): string
{
    $root = sys_get_temp_dir().'/brain-contract-'.uniqid();
    foreach ($files as $path => $contents) {
        $full = $root.'/'.$path;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0o777, true);
        }
        file_put_contents($full, $contents);
    }

    return $root;
}

function removeContractProject(string $root): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

/** @return array<string,string> */
function contractLifecycleFiles(): array
{
    return [
        'composer.json' => <<<'JSON'
{"autoload":{"psr-4":{"App\\":"app/","Illuminate\\":"framework/"}}}
JSON,
        'vendor/composer/autoload_psr4.php' => <<<'PHP'
<?php
return ['App\\' => [__DIR__.'/../../app'], 'Illuminate\\' => [__DIR__.'/../../framework']];
PHP,
        'framework/Database/Eloquent/Model.php' => <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
class Model {}
PHP,
        'framework/Foundation/Http/FormRequest.php' => <<<'PHP'
<?php
namespace Illuminate\Foundation\Http;
class FormRequest
{
    public function safe(): array { return []; }
    public function validated(): array { return []; }
}
PHP,
        'app/Models/Order.php' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Order extends Model {}
PHP,
        'app/Models/Projection.php' => <<<'PHP'
<?php
namespace App\Models;
class Projection { public function find(): void {} }
PHP,
        'app/Enums/State.php' => <<<'PHP'
<?php
namespace App\Enums;
enum State { case READY; }
PHP,
        'app/Contracts/Gateway.php' => <<<'PHP'
<?php
namespace App\Contracts;
interface Gateway { public function send(): void; }
PHP,
        'app/Support/AbstractWorker.php' => <<<'PHP'
<?php
namespace App\Support;
abstract class AbstractWorker { public function work(): void {} }
PHP,
        'app/Support/Clock.php' => <<<'PHP'
<?php
namespace App\Support;
class Clock extends \DateTimeImmutable {}
PHP,
        'app/Exceptions/ProblemException.php' => <<<'PHP'
<?php
namespace App\Exceptions;
class ProblemException extends \RuntimeException {}
PHP,
        'app/Http/Requests/OrderRequest.php' => <<<'PHP'
<?php
namespace App\Http\Requests;
class OrderRequest extends \Illuminate\Foundation\Http\FormRequest
{
    public function rules(): array { return ['name' => ['required']]; }
    public function helper(): void {}
}
PHP,
        'app/Http/Resources/OrderResource.php' => <<<'PHP'
<?php
namespace App\Http\Resources;
class OrderResource { public function transform(): array { return []; } }
PHP,
        'app/Http/Middleware/AuditMiddleware.php' => <<<'PHP'
<?php
namespace App\Http\Middleware;
class AuditMiddleware { public function inspect(): void {} }
PHP,
        'app/Mail/ReceiptMail.php' => <<<'PHP'
<?php
namespace App\Mail;
class ReceiptMail { public function build(): void {} }
PHP,
        'app/Services/BarService.php' => <<<'PHP'
<?php
namespace App\Services;
use App\Models\Order;
class BarService { public function finish(): void { Order::where('open', true); } }
PHP,
        'app/Services/ResolvedValue.php' => <<<'PHP'
<?php
namespace App\Services;
class ResolvedValue { public function actual(): void {} }
PHP,
        'app/Services/ValueResolver.php' => <<<'PHP'
<?php
namespace App\Services;
class ValueResolver { public function resolve(): ResolvedValue { return new ResolvedValue; } }
PHP,
        'app/Services/ShadowService.php' => <<<'PHP'
<?php
namespace App\Services;
class ShadowService
{
    public function __construct(private ValueResolver $credentials) {}
    public function run(): void
    {
        $credentials = $this->credentials->resolve();
        $credentials->actual();
    }
}
PHP,
        'app/Services/FooService.php' => <<<'PHP'
<?php
namespace App\Services;
use App\Jobs\DeepJob;
class FooService
{
    public function __construct(private BarService $bar) {}
    public function run(): void { $this->bar->finish(); dispatch(new DeepJob); }
}
PHP,
        'app/Jobs/DeepJob.php' => <<<'PHP'
<?php
namespace App\Jobs;
use App\Services\BarService;
class DeepJob
{
    public function __construct(private ?BarService $bar = null) {}
    public function handle(BarService $bar): void { $bar->finish(); }
}
PHP,
        'app/Console/Commands/SyncOrders.php' => <<<'PHP'
<?php
namespace App\Console\Commands;
use App\Services\FooService;
class SyncOrders
{
    protected string $signature = 'orders:sync';
    public function handle(FooService $foo): void { $foo->run(); }
}
PHP,
        'app/Http/Controllers/OrderController.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
use App\Contracts\Gateway;
use App\Enums\State;
use App\Exceptions\ProblemException;
use App\Http\Middleware\AuditMiddleware;
use App\Http\Requests\OrderRequest;
use App\Http\Resources\OrderResource;
use App\Jobs\DeepJob;
use App\Mail\ReceiptMail;
use App\Models\Order;
use App\Models\Projection;
use App\Services\FooService;
use App\Services\ShadowService;
use App\Support\AbstractWorker;
use App\Support\Clock;
use Illuminate\Http\Request;
class OrderController
{
    public function index(
        FooService $foo,
        OrderRequest $orderRequest,
        OrderResource $resource,
        AuditMiddleware $middleware,
        Gateway $gateway,
        AbstractWorker $worker,
        Clock $clock,
        Request $frameworkRequest,
        Projection $projection,
        ShadowService $shadow,
    ): void {
        $foo->run();
        $this->helper();
        $orderRequest->helper();
        $orderRequest->safe();
        $orderRequest->validated();
        $resource->transform();
        $middleware->inspect();
        $gateway->send();
        $worker->work();
        $clock->format('c');
        $frameworkRequest->user();
        $projection->find();
        $shadow->run();
        Order::where('id', 1);
        DeepJob::dispatch();
        new ReceiptMail;
        new ProblemException('broken');
        State::READY;
    }
    private function helper(): void {}
}
PHP,
        'routes/web.php' => <<<'PHP'
<?php
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;
Route::get('/orders', [OrderController::class, 'index']);
PHP,
    ];
}

/** @return array{root:string,routes:array,controllers:array,httpEdges:array,commandEdges:array,graph:Graph,command:ConsoleCommandDefinition} */
function buildContractLifecycle(): array
{
    $root = contractProject(contractLifecycleFiles());
    $routes = (new RouteAnalyzer)->analyze($root);
    $controllerAnalyzer = new ControllerAnalyzer;
    $controllers = $controllerAnalyzer->analyze($root, $routes);
    $tracer = new MethodTracer;
    $httpEdges = $tracer->trace($controllers, $controllerAnalyzer->getPsr4Map(), $root);
    $commandEdges = $tracer->traceMethod('App\Console\Commands\SyncOrders', 'handle', $controllerAnalyzer->getPsr4Map(), $root);
    new ConsoleAnalyzer; // Loads the colocated command DTO used by the analyzer.
    $command = new ConsoleCommandDefinition(
        signature: 'orders:sync',
        description: '',
        class: 'App\Console\Commands\SyncOrders',
        file: $root.'/app/Console/Commands/SyncOrders.php',
        source: 'class',
    );
    $models = (new ModelAnalyzer)->analyze($root, ['App\Models\Order']);
    $builder = new GraphBuilder;
    $graph = $builder->build('contract', $routes, new MiddlewareRegistry([], [], []), $controllers, $httpEdges, $models, $root);
    $builder->addConsoleCommands([$command], [], $commandEdges);

    return compact('root', 'routes', 'controllers', 'httpEdges', 'commandEdges', 'graph', 'command');
}

it('reserves action identities for genuine route actions and uses factual caller edge types', function () {
    $built = buildContractLifecycle();
    $graph = $built['graph'];
    $nodes = [];
    foreach ($graph->nodes() as $node) {
        $nodes[$node->id] = $node;
        if (str_starts_with($node->id, 'action::')) {
            expect($node->type)->toBe('action');
        }
    }

    $actionId = 'action::App\Http\Controllers\OrderController::index';
    $helperId = 'app_http_controllers_ordercontroller::helper';
    $requestHelperId = 'app_http_requests_orderrequest::helper';
    $resourceId = 'app_http_resources_orderresource::transform';
    $middlewareId = 'app_http_middleware_auditmiddleware::inspect';
    $fooId = 'app_services_fooservice::run';
    $barId = 'app_services_barservice::finish';
    $jobId = 'app_jobs_deepjob::handle';
    $modelId = 'app_models_order::where';
    $projectionId = 'app_models_projection::find';

    expect($nodes)->toHaveKeys([$actionId, $helperId, $requestHelperId, $resourceId, $middlewareId, $fooId, $barId, $jobId, $modelId, $projectionId])
        ->and($nodes[$actionId]->type)->toBe('action')
        ->and($nodes[$helperId]->type)->toBe('service')
        ->and($nodes[$requestHelperId]->type)->toBe('service')
        ->and($nodes[$resourceId]->type)->not->toBe('action')
        ->and($nodes[$middlewareId]->type)->not->toBe('action')
        ->and($nodes[$projectionId]->type)->toBe('service')
        ->and($graph->hasNode('app_services_valueresolver::actual'))->toBeFalse()
        ->and($graph->hasNode('action::App\Http\Requests\OrderRequest::helper'))->toBeFalse()
        ->and($graph->hasNode('action::Illuminate\Http\Request::user'))->toBeFalse();

    $matching = function (string $source, string $target) use ($graph): array {
        return array_values(array_filter(
            $graph->edges(),
            static fn ($edge): bool => $edge->source === $source && $edge->target === $target,
        ));
    };

    expect($matching($actionId, $fooId)[0]->type)->toBe('action-to-service')
        ->and($matching($fooId, $barId))->toHaveCount(2)
        ->and(array_unique(array_map(static fn ($edge): string => $edge->type, $matching($fooId, $barId))))->toBe(['service-to-service'])
        ->and($matching($barId, $modelId)[0]->type)->toBe('service-to-model')
        ->and($matching($fooId, $jobId)[0]->type)->toBe('service-to-job')
        ->and($matching($jobId, $barId)[0]->type)->toBe('job-to-service');

    removeContractProject($built['root']);
});

it('normalizes method and class ownership without fabricating dynamic declarations', function () {
    $built = buildContractLifecycle();
    $nodes = [];
    foreach ($built['graph']->nodes() as $node) {
        $nodes[$node->id] = $node;
    }

    $action = $nodes['action::App\Http\Controllers\OrderController::index'];
    $model = $nodes['app_models_order::where'];
    $job = $nodes['app_jobs_deepjob::handle'];
    $interface = $nodes['app_contracts_gateway::send'];
    $abstract = $nodes['app_support_abstractworker::work'];
    $mail = $nodes['app_mail_receiptmail::build'];
    $clock = $nodes['app_support_clock::format'];
    $exception = $nodes['app_exceptions_problemexception::__construct'];
    $controller = $nodes['controller::App\Http\Controllers\OrderController'];
    $modelClass = $nodes['model::App\Models\Order'];
    $enum = $nodes['enum::app_enums_state'];
    $safe = $nodes['app_http_requests_orderrequest::safe'];
    $validation = $nodes['app_http_requests_orderrequest::validated'];

    expect($action->data)->toMatchArray([
        'receiverFqcn' => 'App\Http\Controllers\OrderController',
        'declaringFqcn' => 'App\Http\Controllers\OrderController',
        'receiverScope' => 'application',
        'declaringScope' => 'application',
        'sourceScope' => 'application',
        'ownerKind' => 'controller',
        'subtype' => 'controller_action',
    ])->and($model->data)->toMatchArray([
        'receiverFqcn' => 'App\Models\Order',
        'receiverScope' => 'application',
        'declaringScope' => 'unknown',
        'ownerKind' => 'model',
        'subtype' => 'eloquent_operation',
    ])->and($model->data)->not->toHaveKey('declaringFqcn')
        ->and($job->data)->toMatchArray(['ownerKind' => 'job', 'receiverScope' => 'application', 'declaringScope' => 'application'])
        ->and($interface->data)->toMatchArray(['ownerKind' => 'interface', 'declaringFqcn' => 'App\Contracts\Gateway'])
        ->and($abstract->data)->toMatchArray(['ownerKind' => 'abstract_class', 'declaringFqcn' => 'App\Support\AbstractWorker'])
        ->and($mail->data)->toMatchArray(['ownerKind' => 'mail', 'declaringFqcn' => 'App\Mail\ReceiptMail'])
        ->and($clock->data)->toMatchArray([
            'receiverFqcn' => 'App\Support\Clock',
            'receiverScope' => 'application',
            'declaringFqcn' => 'DateTimeImmutable',
            'declaringScope' => 'runtime',
            'sourceScope' => 'runtime',
        ])->and($exception->data)->toMatchArray([
            'receiverFqcn' => 'App\Exceptions\ProblemException',
            'receiverScope' => 'application',
            'declaringFqcn' => 'Exception',
            'declaringScope' => 'runtime',
            'ownerKind' => 'exception',
        ])->and($controller->data)->toMatchArray(['ownerKind' => 'controller', 'sourceScope' => 'application'])
        ->and($modelClass->data)->toMatchArray(['ownerKind' => 'model', 'sourceScope' => 'application'])
        ->and($enum->data)->toMatchArray(['ownerKind' => 'enum', 'sourceScope' => 'application'])
        ->and($safe->data)->toMatchArray(['receiverScope' => 'application', 'declaringScope' => 'framework', 'sourceScope' => 'framework'])
        ->and($validation->data)->toMatchArray(['ownerKind' => 'form_request', 'receiverScope' => 'application', 'declaringScope' => 'framework', 'sourceScope' => 'framework'])
        ->and($validation->data['file'])->toEndWith('/app/Http/Requests/OrderRequest.php')
        ->and($validation->data['declaringFile'])->toEndWith('/framework/Foundation/Http/FormRequest.php')
        ->and($validation->data['relativeDeclaringFile'])->toBe('framework/Foundation/Http/FormRequest.php');

    removeContractProject($built['root']);
});

it('represents middleware aliases groups classes and parameters without fake FQCNs', function () {
    $root = contractProject([
        'app/Http/Middleware/LocalMiddleware.php' => '<?php namespace App\Http\Middleware; class LocalMiddleware {}',
    ]);
    $route = new RouteDefinition('GET', '/mw', 'Closure', '', [
        'auth', 'throttle:60,1', 'custom:alpha,beta', 'web', 'App\Http\Middleware\LocalMiddleware',
    ], '', $root.'/routes/web.php', 1, 'GET /mw');
    $graph = (new GraphBuilder)->build('mw', [$route], new MiddlewareRegistry([], ['web' => []], []), [], [], [], $root);
    $byRaw = [];
    foreach ($graph->nodes() as $node) {
        if ($node->type === 'middleware') {
            $byRaw[$node->data['raw']] = $node;
        }
    }

    expect($byRaw['auth']->data)->toMatchArray([
        'alias' => 'auth',
        'fqcn' => 'Illuminate\Auth\Middleware\Authenticate',
        'resolution' => 'framework',
        'sourceScope' => 'framework',
    ])->and($byRaw['throttle:60,1']->data)->toMatchArray([
        'alias' => 'throttle',
        'fqcn' => 'Illuminate\Routing\Middleware\ThrottleRequests',
        'params' => '60,1',
        'resolution' => 'framework',
    ])->and($byRaw['custom:alpha,beta']->data)->toMatchArray([
        'alias' => 'custom',
        'params' => 'alpha,beta',
        'resolution' => 'unresolved',
    ])->and($byRaw['custom:alpha,beta']->data)->not->toHaveKey('fqcn')
        ->and($byRaw['web']->data)->toMatchArray(['group' => 'web', 'resolution' => 'group'])
        ->and($byRaw['web']->data)->not->toHaveKey('fqcn')
        ->and($byRaw['App\Http\Middleware\LocalMiddleware']->data)->toMatchArray([
            'fqcn' => 'App\Http\Middleware\LocalMiddleware',
            'ownerKind' => 'middleware',
            'sourceScope' => 'application',
        ]);

    removeContractProject($root);
});

it('guards node identity while preserving idempotent insertion and repeated edges', function () {
    $graph = new Graph;
    $node = new Node('service::one', 'service', 'One', ['fqcn' => 'App\One']);
    $graph->addNode($node);
    $graph->addNode(new Node('service::one', 'service', 'One', ['fqcn' => 'App\One']));

    expect($graph->nodeCount())->toBe(1);

    expect(fn () => $graph->addNode(new Node('service::one', 'model', 'One', ['fqcn' => 'App\One'])))
        ->toThrow(LogicException::class, 'Contradictory graph node identity');
});

it('keeps split graphs as lossless deterministic projections without dangling endpoints', function () {
    $built = buildContractLifecycle();
    $splitter = new GraphSplitter;
    $first = $splitter->split($built['graph'], $built['routes'], [$built['command']], [], [], 'contract', '2026-08-30T00:00:00Z');
    $second = $splitter->split($built['graph'], $built['routes'], [$built['command']], [], [], 'contract', '2026-08-30T00:00:00Z');

    expect(array_keys($first['subgraphs']))->toBe(array_keys($second['subgraphs']));
    foreach ($first['subgraphs'] as $id => $subgraph) {
        expect($subgraph->toJson())->toBe($second['subgraphs'][$id]->toJson());
        foreach ($subgraph->nodes() as $node) {
            expect($node)->toBe($built['graph']->getNode($node->id));
        }
        foreach ($subgraph->edges() as $edge) {
            expect($built['graph']->hasEdge($edge->id))->toBeTrue()
                ->and($subgraph->hasNode($edge->source))->toBeTrue()
                ->and($subgraph->hasNode($edge->target))->toBeTrue();
        }
    }

    removeContractProject($built['root']);
});

it('normalizes relative provenance through symlinked project roots', function () {
    $root = contractProject(['app/Services/Thing.php' => '<?php']);
    $link = $root.'-link';
    symlink($root, $link);

    expect(SourceProvenance::relativePath($root.'/app/Services/Thing.php', $link))->toBe('app/Services/Thing.php')
        ->and(SourceProvenance::relativePath('/tmp/outside.php', $link))->toBeNull();

    unlink($link);
    removeContractProject($root);
});

it('traces Artisan and schedule closures through the existing recursive tracer', function () {
    $root = contractProject([
        'composer.json' => <<<'JSON'
{"autoload":{"psr-4":{"App\\":"app/","Illuminate\\":"framework/"}}}
JSON,
        'vendor/composer/autoload_psr4.php' => <<<'PHP'
<?php
return ['App\\' => [__DIR__.'/../../app'], 'Illuminate\\' => [__DIR__.'/../../framework']];
PHP,
        'framework/Database/Eloquent/Model.php' => '<?php namespace Illuminate\Database\Eloquent; class Model {}',
        'app/Models/Report.php' => '<?php namespace App\Models; class Report extends \Illuminate\Database\Eloquent\Model {}',
        'app/Services/ReportRunner.php' => <<<'PHP'
<?php
namespace App\Services;
use App\Models\Report;
class ReportRunner { public function run(): void { Report::where('ready', true); } }
PHP,
        'app/Jobs/CleanupJob.php' => <<<'PHP'
<?php
namespace App\Jobs;
use App\Services\ReportRunner;
class CleanupJob { public function handle(ReportRunner $runner): void { $runner->run(); } }
PHP,
        'routes/console.php' => <<<'PHP'
<?php
use App\Jobs\CleanupJob;
use App\Services\ReportRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
Artisan::command('reports:run', function () {
    $this->comment('Running reports');
    $runner = new ReportRunner;
    $runner->run();
});
Schedule::call(function () { dispatch(new CleanupJob); })->daily();
PHP,
    ]);

    $result = (new ConsoleAnalyzer)->analyze($root);
    $tracer = new MethodTracer;
    $psr4 = ['App' => [$root.'/app'], 'Illuminate' => [$root.'/framework']];
    $edges = [];
    foreach ($result['commands'] as $command) {
        expect($command->closureNode)->not->toBeNull();
        $edges = array_merge($edges, $tracer->traceClosure(
            $command->closureNode,
            $command->closureUseMap,
            'command::'.$command->canonicalName,
            $psr4,
            $root,
        ));
    }
    foreach ($result['schedule'] as $schedule) {
        expect($schedule->closureNode)->not->toBeNull();
        $edges = array_merge($edges, $tracer->traceClosure(
            $schedule->closureNode,
            $schedule->closureUseMap,
            $schedule->graphId(),
            $psr4,
            $root,
        ));
    }

    $builder = new GraphBuilder;
    $graph = $builder->build('closures', [], new MiddlewareRegistry([], [], []), [], [], [], $root);
    $builder->addConsoleCommands($result['commands'], $result['schedule'], $edges);
    $types = array_map(static fn ($edge): string => $edge->type, $graph->edges());
    $virtualSelfEdges = array_filter(
        $graph->edges(),
        static fn ($edge): bool => $edge->source === $edge->target
            && str_starts_with($edge->source, 'command::'),
    );

    expect($types)->toContain('command-to-service', 'schedule-to-job', 'job-to-service', 'service-to-model')
        ->and($virtualSelfEdges)->toBeEmpty()
        ->and($graph->toJson())->not->toContain('PhpParser');

    removeContractProject($root);
});
