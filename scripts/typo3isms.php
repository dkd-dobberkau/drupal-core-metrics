#!/usr/bin/env php
<?php
ini_set('memory_limit', '512M');

/**
 * @file
 * Analyze TYPO3 codebase for anti-patterns and API surface area.
 *
 * Two separate metrics:
 *
 * 1. ANTI-PATTERNS (per-function, density metric - should decrease)
 *    - Service Locator: GeneralUtility::makeInstance() calls that bypass dependency injection
 *    - Globals Access: Direct $GLOBALS['TYPO3_CONF_VARS'] access
 *    - Deep Arrays: Complex nested array structures (TCA, TypoScript configs)
 *
 * 2. API SURFACE AREA (codebase-level, count metric)
 *    - Hooks: Distinct hook names from $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']
 *    - Events: Distinct Symfony events subscribed to
 *    - Services: Distinct service types from Configuration/Services.yaml
 *    - Interface methods: Distinct public methods on interfaces
 *    - Global functions: Distinct procedural functions
 *
 * Usage: php typo3isms.php /path/to/typo3/sysext
 * Output: JSON with anti-patterns, surface area, and function-level metrics
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\Node;

/*
 * =============================================================================
 * CONFIGURATION
 * =============================================================================
 */

/**
 * TCA keys that are fundamental and don't count as "magic" vocabulary.
 * These are the essential structural keys in Type Configuration Arrays.
 */
const COMMON_TCA_KEYS = [
    'columns', 'types', 'palettes', 'ctrl', 'interface',
    'label', 'label_alt', 'title', 'type', 'config',
    'showitem', 'items', 'foreign_table', 'foreign_field',
    'default', 'eval', 'size', 'maxitems', 'minitems',
];

/**
 * Anti-pattern weights.
 */
const SERVICE_LOCATOR_WEIGHT = 1;
const GLOBALS_ACCESS_WEIGHT = 1;

/**
 * Hooks that are implicitly available but may not be detected via AST.
 * These are commonly used DataHandler and other core hooks.
 */
const IMPLICIT_HOOKS = [
    // DataHandler hooks
    'processDatamap_beforeStart',
    'processDatamap_preProcessFieldArray',
    'processDatamap_postProcessFieldArray',
    'processDatamap_afterDatabaseOperations',
    'processDatamap_afterAllOperations',
    'processCmdmap_preProcess',
    'processCmdmap_postProcess',
    'processCmdmap_deleteAction',
    'processCmdmap_afterFinish',
    // Backend hooks
    'BackendController_backgroundImages',
    'BackendController_beforeModuleAccess',
    'BackendUtility_getPagesTSconfigPreInclude',
    // Frontend hooks
    'tslib_fe_contentPostProc',
    'tslib_cObj_getImgResourcePostProc',
    // FormEngine hooks
    'getSingleFieldClass',
    'getFlexFormDsClass',
    // Other common hooks
    'checkFlexFormValue',
    'stdWrap',
    'postProcess',
];

/*
 * =============================================================================
 * FUNCTION METRICS TRACKER
 * =============================================================================
 */

/**
 * Tracks metrics per function/method (production code only).
 *
 * Each function has: name, file, loc, ccn, mi, antipatterns
 */
class FunctionMetricsTracker
{
    private array $functions = [];
    private ?string $currentFunction = null;
    private ?string $currentFile = null;

    public function enterFunction(string $name, string $file): void
    {
        $this->currentFunction = $name;
        $this->currentFile = $file;
        $key = $file . '::' . $name;
        $this->functions[$key] = [
            'name' => $name,
            'file' => $file,
            'loc' => 0,
            'ccn' => 1,
            'mi' => 100,
            'antipatterns' => 0,
        ];
    }

    public function leaveFunction(int $loc): void
    {
        if ($this->currentFunction === null) {
            return;
        }
        $key = $this->currentFile . '::' . $this->currentFunction;
        if (isset($this->functions[$key])) {
            $this->functions[$key]['loc'] = $loc;
            $this->calculateMi($key);
        }
        $this->currentFunction = null;
    }

    public function addCcn(int $points): void
    {
        if ($this->currentFunction === null) {
            return;
        }
        $key = $this->currentFile . '::' . $this->currentFunction;
        if (isset($this->functions[$key])) {
            $this->functions[$key]['ccn'] += $points;
        }
    }

    public function addAntipatterns(int $score): void
    {
        if ($this->currentFunction === null) {
            return;
        }
        $key = $this->currentFile . '::' . $this->currentFunction;
        if (isset($this->functions[$key])) {
            $this->functions[$key]['antipatterns'] += $score;
        }
    }

    public function isInFunction(): bool
    {
        return $this->currentFunction !== null;
    }

    private function calculateMi(string $key): void
    {
        $f = &$this->functions[$key];
        $loc = max($f['loc'], 1);
        $ccn = max($f['ccn'], 1);

        $volume = $loc * 5;
        $mi = 171 - 5.2 * log($volume) - 0.23 * $ccn - 16.2 * log($loc);
        $f['mi'] = (int) max(0, min(100, $mi));
    }

    public function getFunctions(): array
    {
        return array_values($this->functions);
    }
}

/**
 * Tracks anti-pattern occurrence counts by category (codebase level).
 */
class AntipatternTracker
{
    private FunctionMetricsTracker $metrics;
    private int $serviceLocators = 0;
    private int $globalsAccess = 0;
    private int $deepArrays = 0;

    public function __construct(FunctionMetricsTracker $metrics)
    {
        $this->metrics = $metrics;
    }

    public function addServiceLocators(int $score): void
    {
        $this->serviceLocators += $score;
        $this->metrics->addAntipatterns($score);
    }

    public function addGlobalsAccess(int $score): void
    {
        $this->globalsAccess += $score;
        $this->metrics->addAntipatterns($score);
    }

    public function addDeepArrays(int $score): void
    {
        $this->deepArrays += $score;
        $this->metrics->addAntipatterns($score);
    }

    public function getCounts(): array
    {
        return [
            'serviceLocators' => $this->serviceLocators,
            'globalsAccess' => $this->globalsAccess,
            'deepArrays' => $this->deepArrays,
        ];
    }
}

/*
 * =============================================================================
 * SURFACE AREA COLLECTOR
 * =============================================================================
 */

/**
 * Collects distinct API surface area types across the codebase.
 */
class SurfaceAreaCollector
{
    public array $hooks = [];
    public array $events = [];
    public array $services = [];
    public array $interfaceMethods = [];
    public array $globalFunctions = [];

    public function addHook(string $pattern): void
    {
        $this->hooks[$pattern] = true;
    }

    public function addEvent(string $event): void
    {
        $this->events[$event] = true;
    }

    public function addService(string $name): void
    {
        $this->services[$name] = true;
    }

    public function addInterfaceMethod(string $interfaceMethod): void
    {
        $this->interfaceMethods[$interfaceMethod] = true;
    }

    public function addFunction(string $name): void
    {
        $this->globalFunctions[$name] = true;
    }

    /**
     * Add implicit hooks that may not be detected via AST analysis.
     */
    public function addImplicitHooks(): void
    {
        foreach (IMPLICIT_HOOKS as $hook) {
            $this->hooks[$hook] = true;
        }
    }

    public function getCounts(): array
    {
        return [
            'hooks' => count($this->hooks),
            'events' => count($this->events),
            'services' => count($this->services),
            'interfaceMethods' => count($this->interfaceMethods),
            'globalFunctions' => count($this->globalFunctions),
        ];
    }

    public function getLists(): array
    {
        return [
            'hooks' => array_keys($this->hooks),
            'events' => array_keys($this->events),
            'services' => array_keys($this->services),
            'interfaceMethods' => array_keys($this->interfaceMethods),
            'globalFunctions' => array_keys($this->globalFunctions),
        ];
    }
}

/*
 * =============================================================================
 * HELPER FUNCTIONS
 * =============================================================================
 */

function isTestFile(string $path): bool
{
    return str_starts_with($path, 'tests/')
        || str_contains($path, '/tests/')
        || str_contains($path, '/Tests/')
        || str_ends_with($path, 'Test.php')
        || str_ends_with($path, 'TestBase.php')
        || str_contains($path, '/Fixtures/');
}

function countLinesOfCode(string $code): int
{
    $lines = explode("\n", $code);
    $count = 0;
    $inBlockComment = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;
        if ($inBlockComment) {
            if (str_contains($trimmed, '*/')) $inBlockComment = false;
            continue;
        }
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) continue;
        if (str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '/**')) {
            if (!str_contains($trimmed, '*/')) $inBlockComment = true;
            continue;
        }
        if (str_starts_with($trimmed, '*')) continue;
        $count++;
    }
    return $count;
}

function findPhpFiles(string $directory): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if ($file->isFile()
            && $file->getExtension() === 'php'
            // /vendor: third-party dependencies
            && !str_contains($path, '/vendor/')
            // /node_modules: frontend dependencies
            && !str_contains($path, '/node_modules/')
            // Build artifacts
            && !str_contains($path, '/Build/')
            && !str_contains($path, '/.Build/')) {
            $files[] = $path;
        }
    }
    return $files;
}

/**
 * Parse Configuration/Services.yaml files to extract service types.
 */
function collectServices(string $directory, SurfaceAreaCollector $surfaceArea): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()
            && ($file->getFilename() === 'Services.yaml' || $file->getFilename() === 'Services.yml')
            && !str_contains($file->getPathname(), '/vendor/')
            && !str_contains($file->getPathname(), '/tests/')
            && !str_contains($file->getPathname(), '/Tests/')) {

            $content = file_get_contents($file->getPathname());

            // Match service definitions under services: key
            // Format: TYPO3\CMS\Core\SomeClass: or service.name:
            if (preg_match_all('/^  ([A-Z][A-Za-z0-9\\\\]+|[a-z][a-z0-9_.]+):\s*$/m', $content, $matches)) {
                foreach ($matches[1] as $serviceId) {
                    // For FQCN services, extract the namespace prefix
                    if (str_contains($serviceId, '\\')) {
                        $parts = explode('\\', $serviceId);
                        // Use first 3 parts: TYPO3\CMS\Core
                        $serviceType = implode('\\', array_slice($parts, 0, min(3, count($parts))));
                    } else {
                        // For dot-notation, extract top-level
                        $serviceType = explode('.', $serviceId)[0];
                    }
                    $surfaceArea->addService($serviceType);
                }
            }
        }
    }
}

/*
 * =============================================================================
 * AST VISITORS - ANTI-PATTERNS (contribute to per-function score)
 * =============================================================================
 */

/**
 * SERVICE LOCATOR - GeneralUtility::makeInstance() and ObjectManager->get() calls (anti-pattern)
 */
class ServiceLocatorVisitor extends NodeVisitorAbstract
{
    private AntipatternTracker $tracker;

    public function __construct(AntipatternTracker $tracker)
    {
        $this->tracker = $tracker;
    }

    public function enterNode(Node $node): ?int
    {
        // Detect GeneralUtility::makeInstance()
        if ($node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier) {
            $className = $node->class->toString();
            $methodName = $node->name->toString();

            if (($className === 'GeneralUtility' || str_ends_with($className, '\\GeneralUtility'))
                && $methodName === 'makeInstance') {
                $this->tracker->addServiceLocators(SERVICE_LOCATOR_WEIGHT);
            }
            return null;
        }

        // Detect ObjectManager->get() calls
        if ($node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && $node->name->name === 'get') {
            // Check if it's ObjectManager->get() pattern
            if ($node->var instanceof Node\Expr\MethodCall
                || ($node->var instanceof Node\Expr\Variable && $node->var->name === 'objectManager')) {
                $this->tracker->addServiceLocators(SERVICE_LOCATOR_WEIGHT);
            }
        }

        return null;
    }
}

/**
 * GLOBALS ACCESS - Direct $GLOBALS['TYPO3_CONF_VARS'] access (anti-pattern)
 */
class GlobalsAccessVisitor extends NodeVisitorAbstract
{
    private AntipatternTracker $tracker;

    public function __construct(AntipatternTracker $tracker)
    {
        $this->tracker = $tracker;
    }

    public function enterNode(Node $node): ?int
    {
        // Detect $GLOBALS['TYPO3_CONF_VARS'] or $GLOBALS['TSFE'] etc.
        if ($node instanceof Node\Expr\ArrayDimFetch
            && $node->var instanceof Node\Expr\Variable
            && $node->var->name === 'GLOBALS'
            && $node->dim instanceof Node\Scalar\String_) {
            $key = $node->dim->value;
            // Count access to TYPO3 globals as anti-pattern
            if (in_array($key, ['TYPO3_CONF_VARS', 'TSFE', 'BE_USER', 'LANG', 'TCA'], true)) {
                $this->tracker->addGlobalsAccess(GLOBALS_ACCESS_WEIGHT);
            }
        }

        return null;
    }
}

/**
 * DEEP ARRAYS - Array access beyond 2 levels (anti-pattern)
 */
class DeepArrayVisitor extends NodeVisitorAbstract
{
    private AntipatternTracker $tracker;

    public function __construct(AntipatternTracker $tracker)
    {
        $this->tracker = $tracker;
    }

    public function enterNode(Node $node): ?int
    {
        if (!($node instanceof Node\Expr\ArrayDimFetch)) {
            return null;
        }

        $depth = 1;
        $current = $node->var;
        while ($current instanceof Node\Expr\ArrayDimFetch) {
            $depth++;
            $current = $current->var;
        }

        if ($depth > 2) {
            $this->tracker->addDeepArrays($depth - 2);
        }

        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
    }
}

/**
 * DEEP ARRAYS - Array literals beyond 2 levels (anti-pattern)
 */
class DeepArrayLiteralVisitor extends NodeVisitorAbstract
{
    private AntipatternTracker $tracker;
    private int $currentDepth = 0;

    public function __construct(AntipatternTracker $tracker)
    {
        $this->tracker = $tracker;
    }

    public function resetDepth(): void
    {
        $this->currentDepth = 0;
    }

    public function enterNode(Node $node): ?int
    {
        if (!($node instanceof Node\Expr\Array_)) {
            return null;
        }

        $this->currentDepth++;
        if ($this->currentDepth > 2) {
            $this->tracker->addDeepArrays($this->currentDepth - 2);
        }
        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\Expr\Array_) {
            $this->currentDepth--;
        }
        return null;
    }
}

/*
 * =============================================================================
 * AST VISITORS - SURFACE AREA (collect distinct types)
 * =============================================================================
 */

/**
 * HOOKS - Collect distinct hooks from $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'] (surface area)
 *
 * Detects hook registrations via:
 * - $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][...] assignments
 * - SignalSlotDispatcher connections (legacy)
 * - Event listener registrations
 */
class HookTypeVisitor extends NodeVisitorAbstract
{
    private SurfaceAreaCollector $surfaceArea;

    public function __construct(SurfaceAreaCollector $surfaceArea)
    {
        $this->surfaceArea = $surfaceArea;
    }

    public function enterNode(Node $node): ?int
    {
        // Detect $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][...]
        if ($node instanceof Node\Expr\ArrayDimFetch) {
            $hookPath = $this->extractScOptionsPath($node);
            if ($hookPath) {
                $this->surfaceArea->addHook($hookPath);
            }
        }

        // Detect SignalSlotDispatcher->connect() (legacy)
        if ($node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && $node->name->name === 'connect') {
            // Try to extract signal name from arguments
            if (count($node->args) >= 2) {
                $signalArg = $node->args[1]->value ?? null;
                if ($signalArg instanceof Node\Scalar\String_) {
                    $this->surfaceArea->addHook('signal:' . $signalArg->value);
                }
            }
        }

        return null;
    }

    /**
     * Extract hook path from $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][...][...] chain.
     */
    private function extractScOptionsPath(Node\Expr\ArrayDimFetch $node): ?string
    {
        $keys = [];
        $current = $node;

        // Walk up the chain collecting keys
        while ($current instanceof Node\Expr\ArrayDimFetch) {
            if ($current->dim instanceof Node\Scalar\String_) {
                array_unshift($keys, $current->dim->value);
            } elseif ($current->dim instanceof Node\Expr\ClassConstFetch) {
                // Handle Class::class references
                if ($current->dim->class instanceof Node\Name) {
                    array_unshift($keys, $current->dim->class->toString());
                }
            }
            $current = $current->var;
        }

        // Check if this is $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']
        if ($current instanceof Node\Expr\Variable
            && $current->name === 'GLOBALS'
            && count($keys) >= 3
            && $keys[0] === 'TYPO3_CONF_VARS'
            && $keys[1] === 'SC_OPTIONS') {
            // Return the hook identifier (everything after SC_OPTIONS)
            return implode('/', array_slice($keys, 2));
        }

        return null;
    }
}

/**
 * EVENTS - Collect distinct Symfony events from EventSubscriberInterface (surface area)
 */
class EventSubscriberVisitor extends NodeVisitorAbstract
{
    private SurfaceAreaCollector $surfaceArea;
    private bool $inSubscriber = false;

    public function __construct(SurfaceAreaCollector $surfaceArea)
    {
        $this->surfaceArea = $surfaceArea;
    }

    public function setCurrentFile(string $file): void
    {
        $this->inSubscriber = false;
    }

    public function enterNode(Node $node): ?int
    {
        // Check if class implements EventSubscriberInterface
        if ($node instanceof Node\Stmt\Class_ && $node->implements) {
            foreach ($node->implements as $interface) {
                $name = $interface->toString();
                if ($name === 'EventSubscriberInterface'
                    || str_ends_with($name, '\\EventSubscriberInterface')) {
                    $this->inSubscriber = true;
                    break;
                }
            }
        }

        // Check for EventListenerInterface or ListenerProvider attributes
        if ($node instanceof Node\Stmt\Class_) {
            foreach ($node->attrGroups as $attrGroup) {
                foreach ($attrGroup->attrs as $attr) {
                    $attrName = $attr->name->toString();
                    if (str_contains($attrName, 'AsEventListener') || str_contains($attrName, 'EventListener')) {
                        // Extract event class from attribute
                        if (!empty($attr->args)) {
                            $firstArg = $attr->args[0]->value ?? null;
                            if ($firstArg instanceof Node\Expr\ClassConstFetch
                                && $firstArg->class instanceof Node\Name) {
                                $this->surfaceArea->addEvent($firstArg->class->toString());
                            } elseif ($firstArg instanceof Node\Scalar\String_) {
                                $this->surfaceArea->addEvent($firstArg->value);
                            }
                        }
                    }
                }
            }
        }

        // Look for getSubscribedEvents method and extract event names
        if ($this->inSubscriber && $node instanceof Node\Stmt\ClassMethod
            && $node->name->toString() === 'getSubscribedEvents') {
            $this->extractEventsFromMethod($node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->inSubscriber = false;
        }
        return null;
    }

    private function extractEventsFromMethod(Node\Stmt\ClassMethod $method): void
    {
        $this->findArrayKeys($method->stmts ?? []);
    }

    private function findArrayKeys(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            // Array dimension access: $events[EVENT_KEY] or $events[EVENT_KEY][]
            if ($node instanceof Node\Expr\ArrayDimFetch && $node->dim !== null) {
                $this->extractEventFromKey($node->dim);
            }

            // Array item in literal: [EVENT_KEY => ...]
            if ($node instanceof Node\Expr\ArrayItem && $node->key !== null) {
                $this->extractEventFromKey($node->key);
            }

            // Recurse into child nodes
            foreach ($node->getSubNodeNames() as $name) {
                $subNode = $node->$name;
                if (is_array($subNode)) {
                    $this->findArrayKeys($subNode);
                } elseif ($subNode instanceof Node) {
                    $this->findArrayKeys([$subNode]);
                }
            }
        }
    }

    private function extractEventFromKey(Node $key): void
    {
        // ClassConstFetch: SomeEvent::class
        if ($key instanceof Node\Expr\ClassConstFetch
            && $key->class instanceof Node\Name
            && $key->name instanceof Node\Identifier) {
            $constName = $key->name->toString();
            if ($constName === 'class') {
                $this->surfaceArea->addEvent($key->class->toString());
            } else {
                $className = $key->class->toString();
                $this->surfaceArea->addEvent($className . '::' . $constName);
            }
        }
        // String literal
        elseif ($key instanceof Node\Scalar\String_) {
            $this->surfaceArea->addEvent($key->value);
        }
    }
}

/**
 * INTERFACE METHODS - Collect distinct public methods on interfaces (surface area)
 */
class InterfaceMethodVisitor extends NodeVisitorAbstract
{
    private SurfaceAreaCollector $surfaceArea;
    private ?string $currentInterface = null;

    public function __construct(SurfaceAreaCollector $surfaceArea)
    {
        $this->surfaceArea = $surfaceArea;
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentInterface = null;
    }

    public function enterNode(Node $node): ?int
    {
        // Track when we enter an interface declaration
        if ($node instanceof Node\Stmt\Interface_) {
            $this->currentInterface = $node->name ? $node->name->toString() : null;
            return null;
        }

        // Count public methods within interfaces
        if ($this->currentInterface !== null && $node instanceof Node\Stmt\ClassMethod) {
            if ($node->isPublic() || !$node->isPrivate() && !$node->isProtected()) {
                $methodName = $node->name->toString();
                $this->surfaceArea->addInterfaceMethod($this->currentInterface . '::' . $methodName);
            }
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Interface_) {
            $this->currentInterface = null;
        }
        return null;
    }
}

/**
 * GLOBAL FUNCTIONS - Collect distinct procedural functions (surface area)
 */
class GlobalFunctionVisitor extends NodeVisitorAbstract
{
    private SurfaceAreaCollector $surfaceArea;

    public function __construct(SurfaceAreaCollector $surfaceArea)
    {
        $this->surfaceArea = $surfaceArea;
    }

    public function enterNode(Node $node): ?int
    {
        if (!($node instanceof Node\Stmt\Function_)) {
            return null;
        }

        $functionName = $node->name->toString();

        // Skip internal functions (prefixed with _)
        if (str_starts_with($functionName, '_')) {
            return null;
        }

        $this->surfaceArea->addFunction($functionName);

        return null;
    }
}

/**
 * FUNCTION/METHOD TRACKER - Tracks entry/exit of functions and methods
 */
class FunctionBoundaryVisitor extends NodeVisitorAbstract
{
    private FunctionMetricsTracker $metrics;
    private string $currentFile = '';
    private ?string $currentClassName = null;
    private ?int $functionStartLine = null;
    private ?int $functionEndLine = null;
    private string $code = '';

    public function __construct(FunctionMetricsTracker $metrics)
    {
        $this->metrics = $metrics;
    }

    public function setContext(string $file, string $code): void
    {
        $this->currentFile = $file;
        $this->currentClassName = null;
        $this->code = $code;
    }

    public function enterNode(Node $node): ?int
    {
        // Track class name for method naming
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) {
            $this->currentClassName = $node->name ? $node->name->toString() : 'anonymous';
        }

        // Enter a function or method
        if ($node instanceof Node\Stmt\Function_) {
            $name = $node->name->toString();
            $this->functionStartLine = $node->getStartLine();
            $this->functionEndLine = $node->getEndLine();
            $this->metrics->enterFunction($name, $this->currentFile);
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $className = $this->currentClassName ?? 'Unknown';
            $name = $className . '::' . $node->name->toString();
            $this->functionStartLine = $node->getStartLine();
            $this->functionEndLine = $node->getEndLine();
            $this->metrics->enterFunction($name, $this->currentFile);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // Leave class
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) {
            $this->currentClassName = null;
        }

        // Leave a function or method
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $loc = $this->countFunctionLoc();
            $this->metrics->leaveFunction($loc);
            $this->functionStartLine = null;
            $this->functionEndLine = null;
        }

        return null;
    }

    private function countFunctionLoc(): int
    {
        if ($this->functionStartLine === null || $this->functionEndLine === null) {
            return 0;
        }

        $lines = explode("\n", $this->code);
        $count = 0;
        $inBlockComment = false;

        for ($i = $this->functionStartLine - 1; $i < $this->functionEndLine && $i < count($lines); $i++) {
            $trimmed = trim($lines[$i]);
            if ($trimmed === '') continue;
            if ($inBlockComment) {
                if (str_contains($trimmed, '*/')) $inBlockComment = false;
                continue;
            }
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) continue;
            if (str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '/**')) {
                if (!str_contains($trimmed, '*/')) $inBlockComment = true;
                continue;
            }
            if (str_starts_with($trimmed, '*')) continue;
            $count++;
        }
        return $count;
    }
}

/**
 * CYCLOMATIC COMPLEXITY - Tracks CCN per function/method
 */
class CcnVisitor extends NodeVisitorAbstract
{
    private FunctionMetricsTracker $metrics;

    public function __construct(FunctionMetricsTracker $metrics)
    {
        $this->metrics = $metrics;
    }

    public function enterNode(Node $node): ?int
    {
        // Only count if we're inside a function
        if (!$this->metrics->isInFunction()) {
            return null;
        }

        $points = 0;

        if ($node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\Case_
            || $node instanceof Node\Stmt\Catch_
            || $node instanceof Node\Stmt\Do_) {
            $points = 1;
        }
        elseif ($node instanceof Node\Expr\BinaryOp\BooleanAnd
            || $node instanceof Node\Expr\BinaryOp\BooleanOr
            || $node instanceof Node\Expr\BinaryOp\LogicalAnd
            || $node instanceof Node\Expr\BinaryOp\LogicalOr) {
            $points = 1;
        }
        elseif ($node instanceof Node\Expr\Ternary
            || $node instanceof Node\Expr\BinaryOp\Coalesce) {
            $points = 1;
        }

        if ($points > 0) {
            $this->metrics->addCcn($points);
        }
        return null;
    }
}

/*
 * =============================================================================
 * MAIN EXECUTION
 * =============================================================================
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php typo3isms.php /path/to/typo3/sysext\n");
    exit(1);
}

$coreDirectory = $argv[1];
if (!is_dir($coreDirectory)) {
    fwrite(STDERR, "Error: Directory not found: $coreDirectory\n");
    exit(1);
}

// Set up parser and trackers
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$functionMetrics = new FunctionMetricsTracker();
$antipatterns = new AntipatternTracker($functionMetrics);
$surfaceArea = new SurfaceAreaCollector();

// Function boundary visitor must be first to set up function context
$functionBoundaryVisitor = new FunctionBoundaryVisitor($functionMetrics);
$ccnVisitor = new CcnVisitor($functionMetrics);

// Anti-pattern visitors
$serviceLocatorVisitor = new ServiceLocatorVisitor($antipatterns);
$globalsAccessVisitor = new GlobalsAccessVisitor($antipatterns);
$deepArrayVisitor = new DeepArrayVisitor($antipatterns);
$deepArrayLiteralVisitor = new DeepArrayLiteralVisitor($antipatterns);

// Surface area visitors
$hookTypeVisitor = new HookTypeVisitor($surfaceArea);
$eventSubscriberVisitor = new EventSubscriberVisitor($surfaceArea);
$interfaceMethodVisitor = new InterfaceMethodVisitor($surfaceArea);
$globalFunctionVisitor = new GlobalFunctionVisitor($surfaceArea);

// Single traverser with function boundary visitor first
$traverser = new NodeTraverser();
$traverser->addVisitor($functionBoundaryVisitor);
$traverser->addVisitor($ccnVisitor);
$traverser->addVisitor($serviceLocatorVisitor);
$traverser->addVisitor($globalsAccessVisitor);
$traverser->addVisitor($deepArrayVisitor);
$traverser->addVisitor($deepArrayLiteralVisitor);
$traverser->addVisitor($hookTypeVisitor);
$traverser->addVisitor($eventSubscriberVisitor);
$traverser->addVisitor($interfaceMethodVisitor);
$traverser->addVisitor($globalFunctionVisitor);

// Track total LOC per file for codebase totals
$productionLoc = 0;
$testLoc = 0;

// Process all files
$files = findPhpFiles($coreDirectory);
$parseErrors = 0;

foreach ($files as $filePath) {
    try {
        $code = file_get_contents($filePath);
        $relativePath = str_replace($coreDirectory . '/', '', $filePath);
        $isTest = isTestFile($relativePath);
        $loc = countLinesOfCode($code);

        if ($isTest) {
            // Test files: only count LOC, skip all other analysis
            $testLoc += $loc;
            continue;
        }

        $productionLoc += $loc;

        $ast = $parser->parse($code);
        if ($ast !== null) {
            $functionBoundaryVisitor->setContext($relativePath, $code);
            $deepArrayLiteralVisitor->resetDepth();
            $eventSubscriberVisitor->setCurrentFile($relativePath);
            $interfaceMethodVisitor->setCurrentFile($relativePath);
            $traverser->traverse($ast);
        }
    } catch (Exception $e) {
        $parseErrors++;
    }
}

// Collect service types from Configuration/Services.yaml files
collectServices($coreDirectory, $surfaceArea);

// Add implicit hooks
$surfaceArea->addImplicitHooks();

// Get function data (production only - tests were skipped earlier)
$functions = $functionMetrics->getFunctions();

/**
 * Calculate aggregates from function data.
 */
function calculateAggregates(array $functions, int $totalLoc): array
{
    if (empty($functions)) {
        return [
            'loc' => $totalLoc,
            'functions' => 0,
            'ccn' => ['avg' => 0, 'p95' => 0],
            'mi' => ['avg' => 0, 'p5' => 0],
            'antipatterns' => 0,
        ];
    }

    $count = count($functions);
    $ccnValues = array_column($functions, 'ccn');
    $miValues = array_column($functions, 'mi');
    $locValues = array_column($functions, 'loc');
    $antipatternValues = array_column($functions, 'antipatterns');

    // CCN average
    $ccnAvg = array_sum($ccnValues) / $count;

    // CCN 95th percentile (higher = worse)
    sort($ccnValues);
    $p95Index = (int) ceil(0.95 * $count) - 1;
    $ccnP95 = $ccnValues[max(0, min($p95Index, $count - 1))];

    // MI weighted average (by LOC)
    $totalFuncLoc = array_sum($locValues);
    $weightedMi = 0;
    foreach ($functions as $f) {
        $weightedMi += $f['mi'] * $f['loc'];
    }
    $avgMi = $totalFuncLoc > 0 ? $weightedMi / $totalFuncLoc : 0;

    // MI 5th percentile (lower = worse, so P5 shows the worst functions)
    sort($miValues);
    $p5Index = (int) floor(0.05 * $count);
    $miP5 = $miValues[max(0, min($p5Index, $count - 1))];

    // Antipatterns density (per 1000 LOC)
    $totalAntipatterns = array_sum($antipatternValues);
    $antipatternsDensity = $totalLoc > 0 ? ($totalAntipatterns / $totalLoc) * 1000 : 0;

    return [
        'loc' => $totalLoc,
        'functions' => $count,
        'ccn' => [
            'avg' => round($ccnAvg, 1),
            'p95' => $ccnP95,
        ],
        'mi' => [
            'avg' => round($avgMi, 1),
            'p5' => $miP5,
        ],
        'antipatterns' => round($antipatternsDensity, 1),
    ];
}

/**
 * Get top N hotspots sorted by CCN.
 */
function getHotspots(array $functions, int $limit = 50): array
{
    usort($functions, fn($a, $b) => $b['ccn'] - $a['ccn']);
    $hotspots = array_slice($functions, 0, $limit);

    // Return only the fields needed for output
    return array_map(fn($f) => [
        'name' => $f['name'],
        'file' => $f['file'],
        'ccn' => $f['ccn'],
        'loc' => $f['loc'],
        'mi' => $f['mi'],
        'antipatterns' => $f['antipatterns'],
    ], $hotspots);
}

// Totals for commit analysis (sum-based metrics are always meaningful)
$ccnSum = array_sum(array_column($functions, 'ccn'));
$miDebtSum = array_sum(array_map(fn($f) => 100 - $f['mi'], $functions));

// Output JSON
$aggregates = calculateAggregates($functions, $productionLoc);
$output = [
    'production' => $aggregates,
    'testLoc' => $testLoc,
    'ccnSum' => $ccnSum,
    'miDebtSum' => $miDebtSum,
    'hotspots' => getHotspots($functions),
    'surfaceArea' => $surfaceArea->getCounts(),
    'surfaceAreaLists' => $surfaceArea->getLists(),
    'antipatterns' => $antipatterns->getCounts(),
    'parseErrors' => $parseErrors,
];

echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
