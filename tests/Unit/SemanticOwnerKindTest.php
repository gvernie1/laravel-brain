<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\CallChainEdge;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

/** @param array<string, string> $files */
function semanticOwnerProject(array $files): string
{
    $root = sys_get_temp_dir().'/brain-owner-'.uniqid('', true);
    foreach ($files as $relative => $contents) {
        $file = $root.'/'.$relative;
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }
        file_put_contents($file, $contents);
    }

    return $root;
}

function removeSemanticOwnerProject(string $root): void
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

it('keeps structurally known semantic ownership orthogonal to vendor provenance', function () {
    $root = semanticOwnerProject([
        'composer.json' => <<<'JSON'
{"autoload":{"psr-4":{"App\\":"app/","Vendor\\Pkg\\":"vendor/acme/pkg/src/"}}}
JSON,
        'vendor/composer/autoload_psr4.php' => <<<'PHP'
<?php
return [
    'App\\' => [dirname(__DIR__, 2).'/app'],
    'Vendor\\Pkg\\' => [dirname(__DIR__).'/acme/pkg/src'],
];
PHP,
        'app/Services/Sink.php' => '<?php namespace App\Services; class Sink { public function accept(): void {} }',
        'vendor/acme/pkg/src/AbstractTransport.php' => '<?php namespace Vendor\Pkg; abstract class AbstractTransport { public function send(): void {} }',
        'vendor/acme/pkg/src/TransportContract.php' => '<?php namespace Vendor\Pkg; interface TransportContract { public function send(): void; }',
    ]);

    try {
        $edges = [
            new CallChainEdge(
                'Vendor\Pkg\AbstractTransport',
                'send',
                'App\Services\Sink',
                'accept',
                'service',
            ),
            new CallChainEdge(
                'App\Services\Sink',
                'accept',
                'Vendor\Pkg\TransportContract',
                'send',
                'interface',
                receiverFqcn: 'Vendor\Pkg\TransportContract',
                declaringFqcn: 'Vendor\Pkg\TransportContract',
                ownerKind: 'package',
                sourceScope: 'vendor',
                receiverScope: 'vendor',
                declaringScope: 'vendor',
            ),
        ];

        $graph = (new GraphBuilder)->build(
            'semantic-owner',
            [],
            new MiddlewareRegistry([], [], []),
            [],
            $edges,
            [],
            $root,
        );
        $nodes = [];
        foreach ($graph->nodes() as $node) {
            $nodes[$node->id] = $node;
        }

        $abstract = $nodes['vendor_pkg_abstracttransport::send'];
        $interface = $nodes['vendor_pkg_transportcontract::send'];
        expect($abstract->type)->toBe('abstract_class')
            ->and($abstract->data)->toMatchArray([
                'ownerKind' => 'abstract_class',
                'receiverScope' => 'vendor',
                'declaringScope' => 'vendor',
            ])
            ->and($interface->type)->toBe('interface')
            ->and($interface->data)->toMatchArray([
                'ownerKind' => 'interface',
                'receiverScope' => 'vendor',
                'declaringScope' => 'vendor',
            ]);
    } finally {
        removeSemanticOwnerProject($root);
    }
});
