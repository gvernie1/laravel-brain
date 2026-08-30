<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\MethodTracer;

/** @param array<string, string> $files */
function constructorTruthProject(array $files): string
{
    $root = sys_get_temp_dir().'/brain-constructors-'.uniqid('', true);
    foreach ($files as $relative => $contents) {
        $file = $root.'/'.$relative;
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }
        file_put_contents($file, $contents);
    }

    return $root;
}

function removeConstructorTruthProject(string $root): void
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

it('emits constructor lifecycles only when a declared or inherited constructor exists', function () {
    $root = constructorTruthProject([
        'app/Services/Runner.php' => <<<'PHP'
<?php
namespace App\Services;
class Runner
{
    public function run(): void
    {
        $plain = new PlainObject;
        $plain->execute();
        new DeclaredConstructor;
        new InheritedConstructor;
    }
}
PHP,
        'app/Services/PlainObject.php' => '<?php namespace App\Services; class PlainObject { public function execute(): void {} }',
        'app/Services/DeclaredConstructor.php' => '<?php namespace App\Services; class DeclaredConstructor { public function __construct() {} }',
        'app/Services/ConstructorParent.php' => '<?php namespace App\Services; class ConstructorParent { public function __construct() {} }',
        'app/Services/InheritedConstructor.php' => '<?php namespace App\Services; class InheritedConstructor extends ConstructorParent {}',
    ]);

    try {
        $edges = (new MethodTracer)->traceMethod(
            'App\Services\Runner',
            'run',
            ['App' => [$root.'/app']],
            $root,
        );

        $edge = static fn (string $fqcn, string $method) => array_values(array_filter(
            $edges,
            static fn ($edge): bool => $edge->calleeFqcn === $fqcn && $edge->calleeMethod === $method,
        ))[0] ?? null;

        expect($edge('App\Services\PlainObject', '__construct'))->toBeNull()
            ->and($edge('App\Services\PlainObject', 'execute'))->not->toBeNull()
            ->and($edge('App\Services\DeclaredConstructor', '__construct'))->not->toBeNull()
            ->declaringFqcn->toBe('App\Services\DeclaredConstructor')
            ->and($edge('App\Services\InheritedConstructor', '__construct'))->not->toBeNull()
            ->receiverFqcn->toBe('App\Services\InheritedConstructor')
            ->declaringFqcn->toBe('App\Services\ConstructorParent');
    } finally {
        removeConstructorTruthProject($root);
    }
});
