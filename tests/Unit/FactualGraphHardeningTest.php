<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

/** @param array<string,string> $files */
function factualProject(array $files): string
{
    $root = sys_get_temp_dir().'/brain-factual-'.uniqid();
    foreach ($files as $path => $contents) {
        $full = $root.'/'.$path;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0o777, true);
        }
        file_put_contents($full, $contents);
    }

    return $root;
}

function removeFactualProject(string $root): void
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
function factualOwnershipFiles(): array
{
    return [
        'composer.json' => <<<'JSON'
{"autoload":{"psr-4":{"App\\":"app/","Illuminate\\":"framework/"}}}
JSON,
        'vendor/composer/autoload_psr4.php' => <<<'PHP'
<?php
return [
    'App\\' => [__DIR__.'/../../app'],
    'Illuminate\\' => [__DIR__.'/../../framework'],
];
PHP,
        'framework/Foundation/Http/FormRequest.php' => <<<'PHP'
<?php
namespace Illuminate\Foundation\Http;
class FormRequest
{
    public function user(): object { return new \stdClass(); }
    public function validated(): array { return []; }
}
PHP,
        'framework/Database/Eloquent/Model.php' => <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent;
class Model {}
PHP,
        'app/Http/Requests/StoreRequest.php' => <<<'PHP'
<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreRequest extends FormRequest
{
    public function rules(): array { return ['email' => ['required', 'email']]; }
    public function localHelper(): string { return 'local'; }
}
PHP,
        'app/Data/LookupDto.php' => <<<'PHP'
<?php
namespace App\Data;
class LookupDto { public function get(): string { return 'value'; } }
PHP,
        'app/Data/Finder.php' => <<<'PHP'
<?php
namespace App\Data;
class Finder
{
    public function find(): ?object { return null; }
    public function first(): ?object { return null; }
}
PHP,
        'app/Exceptions/DomainProblem.php' => <<<'PHP'
<?php
namespace App\Exceptions;
class DomainProblem extends \RuntimeException {}
PHP,
        'app/Models/Record.php' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Record extends Model { public static function where(string $key, mixed $value): self { return new self(); } }
PHP,
        'app/Http/Controllers/FactualController.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
use App\Data\Finder;
use App\Data\LookupDto;
use App\Exceptions\DomainProblem;
use App\Http\Requests\StoreRequest;
use App\Models\Record;
use Throwable;
class FactualController
{
    public function show(StoreRequest $request, LookupDto $dto, Finder $finder, Throwable $problem): void
    {
        $this->privateHelper();
        $request->localHelper();
        $request->user();
        $request->validated();
        $dto->get();
        $finder->find();
        $finder->first();
        $problem->getMessage();
        new DomainProblem('failed');
        Record::where('active', true);
    }

    private function privateHelper(): void {}
}
PHP,
        'routes/web.php' => <<<'PHP'
<?php
use App\Http\Controllers\FactualController;
use Illuminate\Support\Facades\Route;
Route::get('/facts', [FactualController::class, 'show']);
PHP,
    ];
}

it('exports truthful callable ownership and rejects method-name model false positives', function () {
    $root = factualProject(factualOwnershipFiles());
    $routes = (new RouteAnalyzer)->analyze($root);
    $controllerAnalyzer = new ControllerAnalyzer;
    $controllers = $controllerAnalyzer->analyze($root, $routes);
    $edges = (new MethodTracer)->trace($controllers, $controllerAnalyzer->getPsr4Map(), $root);

    $edge = function (string $fqcn, string $method) use ($edges) {
        return array_values(array_filter($edges, fn ($edge) => $edge->calleeFqcn === $fqcn && $edge->calleeMethod === $method))[0] ?? null;
    };

    $controllerHelper = $edge('App\Http\Controllers\FactualController', 'privateHelper');
    $localRequestHelper = $edge('App\Http\Requests\StoreRequest', 'localHelper');
    $inheritedRequestHelper = $edge('App\Http\Requests\StoreRequest', 'user');
    $throwableMessage = $edge('Throwable', 'getMessage');
    $exceptionConstructor = $edge('App\Exceptions\DomainProblem', '__construct');
    $modelOperation = $edge('App\Models\Record', 'where');

    expect($controllerHelper)->not->toBeNull()
        ->receiverFqcn->toBe('App\Http\Controllers\FactualController')
        ->declaringFqcn->toBe('App\Http\Controllers\FactualController')
        ->ownerKind->toBe('controller')
        ->sourceScope->toBe('application')
        ->subtype->toBe('controller_method')
        ->and($localRequestHelper)->not->toBeNull()
        ->declaringFqcn->toBe('App\Http\Requests\StoreRequest')
        ->ownerKind->toBe('form_request')
        ->and($inheritedRequestHelper)->not->toBeNull()
        ->receiverFqcn->toBe('App\Http\Requests\StoreRequest')
        ->declaringFqcn->toBe('Illuminate\Foundation\Http\FormRequest')
        ->ownerKind->toBe('framework')
        ->sourceScope->toBe('framework')
        ->and($throwableMessage)->not->toBeNull()
        ->type->not->toBe('model')
        ->ownerKind->toBe('exception')
        ->and($exceptionConstructor)->not->toBeNull()
        ->type->not->toBe('model')
        ->ownerKind->toBe('exception')
        ->subtype->toBe('exception_constructor')
        ->and($modelOperation)->not->toBeNull()
        ->type->toBe('model')
        ->subtype->toBe('eloquent_operation');

    foreach ([['App\Data\LookupDto', 'get'], ['App\Data\Finder', 'find'], ['App\Data\Finder', 'first']] as [$fqcn, $method]) {
        expect($edge($fqcn, $method))->not->toBeNull()->type->not->toBe('model');
    }

    removeFactualProject($root);
});

it('keeps validation metadata on the validation artifact and adds portable provenance', function () {
    $root = factualProject(factualOwnershipFiles());
    $routes = (new RouteAnalyzer)->analyze($root);
    $controllerAnalyzer = new ControllerAnalyzer;
    $controllers = $controllerAnalyzer->analyze($root, $routes);
    $edges = (new MethodTracer)->trace($controllers, $controllerAnalyzer->getPsr4Map(), $root);
    $graph = (new GraphBuilder)->build(
        'factual',
        $routes,
        new MiddlewareRegistry([], [], []),
        $controllers,
        $edges,
        [],
        $root,
    );

    $nodes = $graph->nodes();
    $node = function (string $fqcn, string $method) use ($nodes) {
        return array_values(array_filter($nodes, fn ($node) => ($node->data['fqcn'] ?? null) === $fqcn && ($node->data['method'] ?? null) === $method))[0] ?? null;
    };
    $localHelper = $node('App\Http\Requests\StoreRequest', 'localHelper');
    $inheritedHelper = $node('App\Http\Requests\StoreRequest', 'user');
    $validation = $node('App\Http\Requests\StoreRequest', 'validated');

    expect($localHelper)->not->toBeNull()
        ->and($localHelper->data)->not->toHaveKey('validationRules')
        ->and($inheritedHelper)->not->toBeNull()
        ->and($inheritedHelper->data['declaringFqcn'])->toBe('Illuminate\Foundation\Http\FormRequest')
        ->and($inheritedHelper->data['file'])->toEndWith('/framework/Foundation/Http/FormRequest.php')
        ->and($inheritedHelper->data['relativeFile'])->toBe('framework/Foundation/Http/FormRequest.php')
        ->and($inheritedHelper->data)->not->toHaveKey('validationRules')
        ->and($validation)->not->toBeNull()
        ->type->toBe('validation_request')
        ->and($validation->data['validationRules'])->toBeNonEmptyArray()
        ->and($validation->data['file'])->toEndWith('/app/Http/Requests/StoreRequest.php')
        ->and($validation->data['relativeFile'])->toBe('app/Http/Requests/StoreRequest.php');

    foreach ($graph->edges() as $edge) {
        expect($graph->hasNode($edge->source))->toBeTrue()
            ->and($graph->hasNode($edge->target))->toBeTrue();
    }

    removeFactualProject($root);
});
