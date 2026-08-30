<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

class ConsoleCommandDefinition
{
    public function __construct(
        public string $signature,
        public string $description,
        public string $class,       // FQCN for class-based, '' for closures
        public string $file,
        public string $source,      // 'route' | 'class' | 'kernel'
        public string $canonicalName = '',
        public string $declaredSignature = '',
        public string $sourceScope = 'application',
        public ?int $line = null,
        /** Internal AST only; GraphBuilder never exports parser nodes. */
        public Node\Expr\Closure|Node\Expr\ArrowFunction|null $closureNode = null,
        /** @var array<string,string> */
        public array $closureUseMap = [],
    ) {
        $this->declaredSignature = $this->declaredSignature !== '' ? $this->declaredSignature : $this->signature;
        $this->canonicalName = $this->canonicalName !== ''
            ? $this->canonicalName
            : ConsoleAnalyzer::canonicalCommandName($this->declaredSignature);
    }
}

class ScheduleEntry
{
    public function __construct(
        public string $type,        // 'command' | 'job' | 'call'
        public string $target,      // command signature or job FQCN
        public string $frequency,   // 'daily' | 'hourly' | etc.
        public string $file,
        /** @var list<mixed> */
        public array $cadenceArguments = [],
        public ?string $cronExpression = null,
        /** @var list<array{method:string,arguments:list<mixed>}> */
        public array $modifiers = [],
        public string $rawInvocation = '',
        public string $canonicalTarget = '',
        /** @var list<mixed> */
        public array $invocationArguments = [],
        /** @var list<mixed> */
        public array $invocationOptions = [],
        /** @var list<mixed>|array<string,mixed> */
        public array $targetArguments = [],
        public ?int $line = null,
        public string $targetResolution = 'unresolved',
        public ?string $targetClass = null,
        /** Scope of the schedule definition itself; retained as the node sourceScope. */
        public string $sourceScope = 'application',
        /** Internal AST only; GraphBuilder never exports parser nodes. */
        public Node\Expr\Closure|Node\Expr\ArrowFunction|null $closureNode = null,
        /** @var array<string,string> */
        public array $closureUseMap = [],
        public string $definitionScope = 'application',
        public string $targetScope = 'unknown',
    ) {
        $this->rawInvocation = $this->rawInvocation !== '' ? $this->rawInvocation : $this->target;
        $this->canonicalTarget = $this->canonicalTarget !== ''
            ? $this->canonicalTarget
            : ($this->type === 'command' ? ConsoleAnalyzer::canonicalCommandName($this->target) : $this->target);
    }

    public function graphId(): string
    {
        $hasStructuredIdentity = $this->cadenceArguments !== []
            || $this->modifiers !== []
            || $this->targetArguments !== []
            || $this->rawInvocation !== $this->target
            || $this->canonicalTarget !== $this->target;

        if (! $hasStructuredIdentity) {
            return 'schedule::'.md5($this->type.$this->target.$this->frequency);
        }

        return 'schedule::'.md5((string) json_encode([
            $this->type,
            $this->canonicalTarget,
            $this->rawInvocation,
            $this->targetArguments,
            $this->frequency,
            $this->cadenceArguments,
            $this->modifiers,
        ], JSON_UNESCAPED_SLASHES));
    }
}

class ConsoleAnalyzer
{
    private const CADENCE_METHODS = [
        'everySecond', 'everyTwoSeconds', 'everyFiveSeconds', 'everyTenSeconds',
        'everyFifteenSeconds', 'everyTwentySeconds', 'everyThirtySeconds',
        'everyMinute', 'everyTwoMinutes', 'everyThreeMinutes', 'everyFourMinutes',
        'everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes', 'everyThirtyMinutes',
        'hourly', 'hourlyAt', 'everyOddHour', 'everyTwoHours', 'everyThreeHours',
        'everyFourHours', 'everySixHours', 'daily', 'dailyAt', 'twiceDaily',
        'twiceDailyAt', 'weekly', 'weeklyOn', 'monthly', 'monthlyOn',
        'twiceMonthly', 'lastDayOfMonth', 'quarterly', 'quarterlyOn',
        'yearly', 'yearlyOn', 'cron',
    ];

    private PhpFileParser $parser;

    /** @var string[] */
    private array $consoleRoutePaths;

    /** @var string[] */
    private array $classPaths;

    /** @var string[] */
    private array $kernelPaths;

    /**
     * @param  string[]  $consoleRoutePaths  Glob patterns for closure-command route files (basename must contain "console").
     * @param  string[]  $classPaths  Glob patterns for directories containing Command classes.
     * @param  string[]  $kernelPaths  Glob patterns pointing to Console Kernel file(s).
     */
    public function __construct(
        array $consoleRoutePaths = ['routes/*/*.php'],
        array $classPaths = ['app/Console/Commands/*/*.php'],
        array $kernelPaths = ['app/Console/Kernel.php'],
    ) {
        $this->parser = new PhpFileParser;
        $this->consoleRoutePaths = $consoleRoutePaths ?: ['routes/*/*.php'];
        $this->classPaths = $classPaths ?: ['app/Console/Commands/*/*.php'];
        $this->kernelPaths = $kernelPaths ?: ['app/Console/Kernel.php'];
    }

    /**
     * @return array{commands: ConsoleCommandDefinition[], schedule: ScheduleEntry[]}
     */
    public function analyze(string $projectRoot): array
    {
        $commands = [];
        $schedule = [];
        $root = rtrim($projectRoot, '/');

        // 1. Closure-based commands: files containing "console" in their basename
        foreach ($this->consoleRoutePaths as $pattern) {
            $baseDir = $this->resolveBaseDir($root, $pattern);
            foreach ($this->findFilesContaining($baseDir, 'console') as $file) {
                $result = $this->parseConsoleRouteFile($file);
                $commands = array_merge($commands, $result['commands']);
                $schedule = array_merge($schedule, $result['schedule']);
            }
        }

        // 2. Command classes
        foreach ($this->classPaths as $pattern) {
            $commandsDir = $this->resolveBaseDir($root, $pattern);
            if (is_dir($commandsDir)) {
                $commands = array_merge($commands, $this->scanCommandClasses($commandsDir));
            }
        }

        // 3. Kernel.php — $commands property + schedule() method
        foreach ($this->kernelPaths as $pattern) {
            foreach ($this->resolveKernelFiles($root, $pattern) as $kernelFile) {
                $result = $this->parseKernel($kernelFile);
                $commands = array_merge($commands, $result['commands']);
                $schedule = array_merge($schedule, $result['schedule']);
            }
        }

        // Laravel 11+ may register schedules directly in bootstrap/app.php via withSchedule().
        // The same fluent-chain parser works there without introducing a separate subsystem.
        $bootstrap = $root.'/bootstrap/app.php';
        if (is_file($bootstrap)) {
            $schedule = array_merge($schedule, $this->parseScheduleFile($bootstrap));
        }

        // Deduplicate: class/route-sourced entries win over kernel entries.
        // Kernel.php usually re-lists classes already found in Commands/.
        // Index by canonical command name. The declared signature remains available separately.
        $bySignature = [];
        $byFqcn = [];

        // Pass 1: index non-kernel commands (they carry the real signature + description)
        foreach ($commands as $cmd) {
            if ($cmd->source === 'kernel') {
                continue;
            }
            $bySignature[$cmd->canonicalName] = $cmd;
            if ($cmd->class) {
                $byFqcn[$cmd->class] = $cmd;
            }
        }

        // Pass 2: add kernel entries only when not already covered
        foreach ($commands as $cmd) {
            if ($cmd->source !== 'kernel') {
                continue;
            }
            if (isset($byFqcn[$cmd->class]) || isset($byFqcn[$cmd->signature])) {
                continue;
            }
            $classFile = $this->resolveApplicationClassFile($cmd->class, $root);
            if ($classFile === null) {
                // A kernel registration alone does not prove a local implementation. Package
                // and unresolved commands stay schedule metadata rather than fake app nodes.
                continue;
            }
            $parsed = $this->parser->parse($classFile);
            $resolved = $parsed['ast'] !== null
                ? $this->extractCommandDefinition($parsed['ast'], $classFile)
                : null;
            if ($resolved === null || isset($bySignature[$resolved->canonicalName])) {
                continue;
            }
            $resolved->source = 'kernel';
            $bySignature[$resolved->canonicalName] = $resolved;
        }

        $commands = array_values($bySignature);
        $this->resolveScheduleTargets($schedule, $commands, $root);

        return ['commands' => $commands, 'schedule' => $schedule];
    }

    // ── Console route file ────────────────────────────────────────────────────

    private function parseConsoleRouteFile(string $file): array
    {
        $parsed = $this->parser->parse($file);
        if (! $parsed || ! $parsed['ast']) {
            return ['commands' => [], 'schedule' => []];
        }

        $commands = [];
        $schedule = [];

        $traverser = new NodeTraverser;
        $visitor = new class($file, $parsed['useMap'] ?? []) extends NodeVisitorAbstract
        {
            public array $commands = [];

            public array $schedule = [];

            /** @param array<string,string> $useMap */
            public function __construct(private string $file, private array $useMap) {}

            public function enterNode(Node $node): ?int
            {
                if (! $node instanceof Node\Expr\StaticCall) {
                    return null;
                }
                if (! $node->class instanceof Node\Name) {
                    return null;
                }

                $class = PhpFileParser::resolvedName($node->class) ?? $node->class->toString();
                $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

                // Artisan::command('signature', closure)
                if ($class === 'Illuminate\\Support\\Facades\\Artisan' && $method === 'command') {
                    $sig = $this->strArg($node->args[0] ?? null);
                    if ($sig !== null) {
                        $closure = $node->args[1]->value ?? null;
                        $this->commands[] = new ConsoleCommandDefinition(
                            signature: $sig,
                            description: '',
                            class: '',
                            file: $this->file,
                            source: 'route',
                            line: $node->getStartLine(),
                            closureNode: $closure instanceof Node\Expr\Closure || $closure instanceof Node\Expr\ArrowFunction
                                ? $closure
                                : null,
                            closureUseMap: $this->useMap,
                        );
                    }
                }

                return null;
            }

            private function strArg(?Node $node): ?string
            {
                if ($node === null) {
                    return null;
                }
                $val = $node instanceof Node\Arg ? $node->value : $node;

                return $val instanceof Node\Scalar\String_ ? $val->value : null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return ['commands' => $visitor->commands, 'schedule' => $this->scheduleEntriesFromAst($parsed['ast'], $file, $parsed['useMap'] ?? [])];
    }

    // ── Command classes ───────────────────────────────────────────────────────

    private function scanCommandClasses(string $dir): array
    {
        $commands = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $parsed = $this->parser->parse($entry->getPathname());
            if (! $parsed || ! $parsed['ast']) {
                continue;
            }

            $cmd = $this->extractCommandDefinition($parsed['ast'], $entry->getPathname());
            if ($cmd !== null) {
                $commands[] = $cmd;
            }
        }

        return $commands;
    }

    private function extractCommandDefinition(array $ast, string $file): ?ConsoleCommandDefinition
    {
        $traverser = new NodeTraverser;
        $visitor = new class($file) extends NodeVisitorAbstract
        {
            public ?ConsoleCommandDefinition $result = null;

            private ?string $namespace = null;

            private ?string $className = null;

            private ?string $signature = null;

            private ?string $description = null;

            private ?int $line = null;

            public function __construct(private string $file) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString();
                }
                if ($node instanceof Node\Stmt\Class_) {
                    $this->className = $node->name?->toString();
                    $this->line = $node->getStartLine();
                    foreach ($node->attrGroups as $group) {
                        foreach ($group->attrs as $attribute) {
                            $name = PhpFileParser::resolvedName($attribute->name) ?? $attribute->name->toString();
                            $value = $attribute->args[0]->value ?? null;
                            if (! $value instanceof Node\Scalar\String_) {
                                continue;
                            }
                            if ($name === 'Illuminate\\Console\\Attributes\\Signature') {
                                $this->signature = $value->value;
                            }
                            if ($name === 'Illuminate\\Console\\Attributes\\Description') {
                                $this->description = $value->value;
                            }
                        }
                    }
                }
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        $name = $prop->name->toString();
                        if ($name === 'signature' && $prop->default instanceof Node\Scalar\String_) {
                            $this->signature = $prop->default->value;
                        }
                        if ($name === 'description' && $prop->default instanceof Node\Scalar\String_) {
                            $this->description = $prop->default->value;
                        }
                    }
                }

                return null;
            }

            public function afterTraverse(array $nodes): ?int
            {
                if ($this->className && $this->signature !== null) {
                    $fqcn = $this->namespace
                        ? $this->namespace.'\\'.$this->className
                        : $this->className;

                    $this->result = new ConsoleCommandDefinition(
                        signature: $this->signature,
                        description: $this->description ?? '',
                        class: $fqcn,
                        file: $this->file,
                        source: 'class',
                        line: $this->line,
                    );
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->result;
    }

    // ── Kernel.php ────────────────────────────────────────────────────────────

    private function parseKernel(string $file): array
    {
        $parsed = $this->parser->parse($file);
        if (! $parsed || ! $parsed['ast']) {
            return ['commands' => [], 'schedule' => []];
        }

        $useMap = $parsed['useMap'] ?? [];
        $commands = [];
        $schedule = [];

        $traverser = new NodeTraverser;
        $visitor = new class($file, $useMap) extends NodeVisitorAbstract
        {
            public array $commands = [];

            public array $schedule = [];

            public function __construct(
                private string $file,
                private array $useMap,
            ) {}

            public function enterNode(Node $node): ?int
            {
                // protected $commands = [FooCommand::class, ...]
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        if ($prop->name->toString() !== 'commands') {
                            continue;
                        }
                        if (! $prop->default instanceof Node\Expr\Array_) {
                            continue;
                        }

                        foreach ($prop->default->items as $item) {
                            if (! $item) {
                                continue;
                            }
                            $fqcn = $this->resolveClassConst($item->value);
                            if ($fqcn) {
                                $this->commands[] = new ConsoleCommandDefinition(
                                    signature: $fqcn,
                                    description: '',
                                    class: $fqcn,
                                    file: $this->file,
                                    source: 'kernel',
                                );
                            }
                        }
                    }
                }

                return null;
            }

            private function resolveClassConst(Node $node): string
            {
                if ($node instanceof Node\Expr\ClassConstFetch
                    && $node->class instanceof Node\Name
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'class') {
                    return $this->resolveClass($node->class->toString());
                }

                return '';
            }

            private function resolveClass(string $name): string
            {
                return $this->useMap[$name] ?? $name;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return [
            'commands' => $visitor->commands,
            'schedule' => $this->scheduleEntriesFromAst($parsed['ast'], $file, $useMap),
        ];
    }

    /** @return ScheduleEntry[] */
    private function parseScheduleFile(string $file): array
    {
        $parsed = $this->parser->parse($file);
        if (! $parsed || ! $parsed['ast']) {
            return [];
        }

        return $this->scheduleEntriesFromAst($parsed['ast'], $file, $parsed['useMap'] ?? []);
    }

    /**
     * Read complete fluent schedule expressions at statement level so cadence calls and
     * modifiers outside the target call are available together.
     *
     * @param  Node[]  $ast
     * @param  array<string,string>  $useMap
     * @return ScheduleEntry[]
     */
    private function scheduleEntriesFromAst(array $ast, string $file, array $useMap): array
    {
        $traverser = new NodeTraverser;
        $collector = new class extends NodeVisitorAbstract
        {
            /** @var Node\Expr[] */
            public array $expressions = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Expression) {
                    $this->expressions[] = $node->expr;
                }

                return null;
            }
        };
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        $entries = [];
        foreach ($collector->expressions as $expression) {
            $entry = $this->scheduleEntryFromExpression($expression, $file, $useMap);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @param array<string,string> $useMap */
    private function scheduleEntryFromExpression(Node\Expr $expression, string $file, array $useMap): ?ScheduleEntry
    {
        $current = $expression;
        $outerCalls = [];
        while ($current instanceof Node\Expr\MethodCall) {
            if (! $current->name instanceof Node\Identifier) {
                return null;
            }
            $outerCalls[] = [
                'method' => $current->name->toString(),
                'args' => $current->args,
                'line' => $current->getStartLine(),
            ];
            $current = $current->var;
        }

        $rootMethod = '';
        $rootArgs = [];
        $rootLine = $expression->getStartLine();

        $staticClass = $current instanceof Node\Expr\StaticCall && $current->class instanceof Node\Name
            ? (PhpFileParser::resolvedName($current->class) ?? $useMap[$current->class->toString()] ?? $current->class->toString())
            : '';
        if ($current instanceof Node\Expr\StaticCall
            && $current->name instanceof Node\Identifier
            && $staticClass === 'Illuminate\\Support\\Facades\\Schedule') {
            $rootMethod = $current->name->toString();
            $rootArgs = $current->args;
            $rootLine = $current->getStartLine();
        } elseif ($current instanceof Node\Expr\Variable
            && $current->name === 'schedule'
            && $outerCalls !== []) {
            $root = array_pop($outerCalls);
            $rootMethod = $root['method'];
            $rootArgs = $root['args'];
            $rootLine = $root['line'];
        } else {
            return null;
        }

        if (! in_array($rootMethod, ['command', 'job', 'call'], true)) {
            return null;
        }

        $chain = array_reverse($outerCalls);
        $frequency = '';
        $cadenceArguments = [];
        $modifiers = [];
        foreach ($chain as $call) {
            $arguments = array_map(fn (Node\Arg $arg): mixed => $this->staticValue($arg->value, $useMap), $call['args']);
            if ($frequency === '' && in_array($call['method'], self::CADENCE_METHODS, true)) {
                $frequency = $call['method'];
                $cadenceArguments = $arguments;
            } else {
                $modifiers[] = ['method' => $call['method'], 'arguments' => $arguments];
            }
        }

        $target = 'Closure';
        $rawInvocation = 'Closure';
        $canonicalTarget = 'Closure';
        $targetArguments = [];
        $invocationArguments = [];
        $invocationOptions = [];
        $closureNode = null;

        if ($rootMethod === 'command') {
            $first = $rootArgs[0]->value ?? null;
            if (! $first instanceof Node\Scalar\String_) {
                return null;
            }
            $target = $first->value;
            $rawInvocation = $target;
            $canonicalTarget = self::canonicalCommandName($target);
            $tokens = $this->commandInvocationTokens($target);
            foreach (array_slice($tokens, 1) as $token) {
                if (str_starts_with($token, '--')) {
                    $invocationOptions[] = $token;
                } else {
                    $invocationArguments[] = $token;
                }
            }
            if (isset($rootArgs[1])) {
                $value = $this->staticValue($rootArgs[1]->value, $useMap);
                $targetArguments = is_array($value) ? $value : [$value];
                foreach ($targetArguments as $key => $value) {
                    if (is_string($key) && str_starts_with($key, '--')) {
                        $invocationOptions[] = [$key => $value];
                    } else {
                        $invocationArguments[] = is_int($key) ? $value : [$key => $value];
                    }
                }
            }
        } elseif ($rootMethod === 'job') {
            $target = $this->classTarget($rootArgs[0]->value ?? null, $useMap);
            if ($target === '') {
                return null;
            }
            $rawInvocation = $this->prettyExpression($rootArgs[0]->value);
            $canonicalTarget = $target;
        } elseif ($rootMethod === 'call') {
            $candidate = $rootArgs[0]->value ?? null;
            if ($candidate instanceof Node\Expr\Closure || $candidate instanceof Node\Expr\ArrowFunction) {
                $closureNode = $candidate;
            }
        }

        $cronExpression = $frequency === 'cron' && is_string($cadenceArguments[0] ?? null)
            ? $cadenceArguments[0]
            : null;

        return new ScheduleEntry(
            type: $rootMethod,
            target: $target,
            frequency: $frequency,
            file: $file,
            cadenceArguments: $cadenceArguments,
            cronExpression: $cronExpression,
            modifiers: $modifiers,
            rawInvocation: $rawInvocation,
            canonicalTarget: $canonicalTarget,
            invocationArguments: $invocationArguments,
            invocationOptions: $invocationOptions,
            targetArguments: $targetArguments,
            line: $rootLine,
            closureNode: $closureNode,
            closureUseMap: $useMap,
        );
    }

    /** @param array<string,string> $useMap */
    private function classTarget(?Node $node, array $useMap): string
    {
        $class = null;
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $class = $node->class;
        } elseif ($node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'class') {
            $class = $node->class;
        }
        if ($class === null) {
            return '';
        }

        $written = $class->toString();

        return PhpFileParser::resolvedName($class) ?? $useMap[$written] ?? $written;
    }

    /** @param array<string,string> $useMap */
    private function staticValue(Node $node, array $useMap): mixed
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }
        if ($node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => $this->prettyExpression($node),
            };
        }
        if ($node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'class') {
            $written = $node->class->toString();

            return (PhpFileParser::resolvedName($node->class) ?? $useMap[$written] ?? $written).'::class';
        }
        if ($node instanceof Node\Expr\Array_) {
            $result = [];
            foreach ($node->items as $item) {
                if ($item === null) {
                    continue;
                }
                $value = $this->staticValue($item->value, $useMap);
                if ($item->key === null) {
                    $result[] = $value;
                } else {
                    $key = $this->staticValue($item->key, $useMap);
                    if (is_int($key) || is_string($key)) {
                        $result[$key] = $value;
                    }
                }
            }

            return $result;
        }

        return $this->prettyExpression($node);
    }

    private function prettyExpression(?Node $node): string
    {
        if (! $node instanceof Node\Expr) {
            return '';
        }

        return (new Standard)->prettyPrintExpr($node);
    }

    /** @return string[] */
    private function commandInvocationTokens(string $invocation): array
    {
        $tokens = str_getcsv(trim($invocation), ' ', '"', '\\');

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }

    public static function canonicalCommandName(string $signatureOrInvocation): string
    {
        $trimmed = trim($signatureOrInvocation);
        if ($trimmed === '') {
            return '';
        }

        return preg_split('/\s+/', $trimmed, 2)[0];
    }

    /**
     * @param  ScheduleEntry[]  $schedule
     * @param  ConsoleCommandDefinition[]  $commands
     */
    private function resolveScheduleTargets(array $schedule, array $commands, string $root): void
    {
        $byName = [];
        foreach ($commands as $command) {
            $byName[$command->canonicalName] = $command;
        }

        foreach ($schedule as $entry) {
            $entry->definitionScope = SourceProvenance::scope('', $entry->file, $root);
            $entry->sourceScope = $entry->definitionScope;

            if ($entry->type === 'command') {
                $command = $byName[$entry->canonicalTarget] ?? null;
                if ($command !== null && $command->sourceScope === 'application') {
                    $entry->targetResolution = 'local';
                    $entry->targetClass = $command->class !== '' ? $command->class : null;
                    $entry->targetScope = 'application';
                }

                continue;
            }
            if ($entry->type === 'job') {
                $file = $this->resolveApplicationClassFile($entry->canonicalTarget, $root);
                if ($file !== null) {
                    $entry->targetResolution = 'local';
                    $entry->targetClass = $entry->canonicalTarget;
                    $entry->targetScope = 'application';
                }

                continue;
            }

            $entry->targetResolution = 'local';
            $entry->targetScope = $entry->definitionScope;
        }
    }

    private function resolveApplicationClassFile(string $fqcn, string $root): ?string
    {
        $composer = $root.'/composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            foreach (['autoload', 'autoload-dev'] as $section) {
                foreach ($data[$section]['psr-4'] ?? [] as $namespace => $paths) {
                    $namespace = rtrim((string) $namespace, '\\');
                    if (! str_starts_with($fqcn, $namespace.'\\')) {
                        continue;
                    }
                    $relative = str_replace('\\', '/', substr($fqcn, strlen($namespace) + 1)).'.php';
                    foreach ((array) $paths as $path) {
                        $file = $root.'/'.trim((string) $path, '/').'/'.$relative;
                        if (is_file($file)) {
                            return $file;
                        }
                    }
                }
            }
        }

        return null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function findFilesContaining(string $dir, string $keyword): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile()
                && $entry->getExtension() === 'php'
                && str_contains(strtolower($entry->getBasename()), $keyword)) {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    /**
     * Resolves kernel file(s) from a pattern.
     * Patterns without wildcards are treated as literal paths.
     * Patterns with wildcards scan the resolved base dir for matching .php files.
     *
     * @return string[]
     */
    private function resolveKernelFiles(string $root, string $pattern): array
    {
        if (! str_contains($pattern, '*') && ! str_contains($pattern, '?') && ! str_contains($pattern, '[')) {
            $path = $root.'/'.ltrim($pattern, '/');

            return file_exists($path) ? [$path] : [];
        }

        $baseDir = $this->resolveBaseDir($root, $pattern);
        if (! is_dir($baseDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    private function resolveBaseDir(string $root, string $pattern): string
    {
        $segments = explode('/', ltrim($pattern, '/'));
        $fixed = [];

        foreach ($segments as $segment) {
            if (str_contains($segment, '*') || str_contains($segment, '?') || str_contains($segment, '[')) {
                break;
            }
            $fixed[] = $segment;
        }

        if (! empty($fixed) && str_ends_with(end($fixed), '.php')) {
            array_pop($fixed);
        }

        $subPath = implode('/', $fixed);

        return $subPath !== '' ? $root.'/'.$subPath : $root;
    }
}
