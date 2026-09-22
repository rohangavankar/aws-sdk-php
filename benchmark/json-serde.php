#!/usr/bin/env php
<?php
/**
 * Focused JSON serde before/after benchmark.
 *
 * Compares the legacy path against the compiled-plan path in a single process,
 * so before/after numbers come from identical shapes, values, PHP, and
 * settings. Encode compares JsonBody::buildLegacy() vs build(); decode compares
 * JsonParser::parseLegacy() vs parse().
 *
 * Run with a clean PHP to avoid Xdebug/JIT skew:
 *   php -n -d opcache.enable_cli=1 benchmark/json-serde.php
 *
 * Options:
 *   --implementation=legacy|plans   Which path to run (default: both)
 *   --direction=encode|decode       Serde direction (default: encode)
 *   --iterations=200000             Repeated-use sample count
 *   --items=50                      List/map entry count for collection cases.
 *                                   0 exercises the small scalar-only path.
 *   --case=NAME                     Run one payload case only
 *   --mode=time|memory              time = latency; memory = retained plan heap
 *                                   (both directions, one graph). Default time.
 *
 * Local runs are dev-grade for iterating. Merge evidence requires the x86
 * m7i.xlarge runbook flow in docs/serde/benchmark-runbook.md.
 *
 * @internal
 */

require __DIR__ . '/../vendor/autoload.php';

use Aws\Api\Service;
use Aws\Api\Serializer\JsonBody;
use Aws\Api\Parser\JsonParser;

$opts = getopt('', ['implementation:', 'direction:', 'iterations:', 'items:', 'case:', 'mode:']);
$implementation = $opts['implementation'] ?? 'both';
$direction      = $opts['direction'] ?? 'encode';
$iterations     = (int) ($opts['iterations'] ?? 200000);
$items          = isset($opts['items']) ? (int) $opts['items'] : 50;
$onlyCase       = $opts['case'] ?? null;
$mode           = $opts['mode'] ?? 'time';

if (!in_array($mode, ['time', 'memory'], true)) {
    fwrite(STDERR, "Invalid --mode. Use time or memory.\n");
    exit(1);
}

if (!in_array($implementation, ['legacy', 'plans', 'both'], true)) {
    fwrite(STDERR, "Invalid --implementation. Use legacy, plans, or both.\n");
    exit(1);
}

if (!in_array($direction, ['encode', 'decode'], true)) {
    fwrite(STDERR, "Invalid --direction. Use encode or decode.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Inline JSON-RPC model with representative encode payloads.
// ---------------------------------------------------------------------------

$model = [
    'metadata' => [
        'protocol'      => 'json',
        'protocolVersion' => '1.0',
        'jsonVersion'   => '1.0',
        'targetPrefix'  => 'Bench',
        'apiVersion'    => '2024-01-01',
        'endpointPrefix'=> 'bench',
        'serviceId'     => 'Bench',
        'signatureVersion' => 'v4',
    ],
    'operations' => [
        'SmallNoList'  => ['name' => 'SmallNoList',  'input' => ['shape' => 'SmallInput'],  'output' => ['shape' => 'SmallInput']],
        'NestedLarge'  => ['name' => 'NestedLarge',  'input' => ['shape' => 'NestedInput'], 'output' => ['shape' => 'NestedInput']],
        'MapHeavy'     => ['name' => 'MapHeavy',     'input' => ['shape' => 'MapInput'],    'output' => ['shape' => 'MapInput']],
    ],
    'shapes' => [
        // Small, scalar-only structure, no lists (worst case for plans: the
        // per-shape plan lookup overhead is not amortized over collections).
        'SmallInput' => [
            'type' => 'structure',
            'members' => [
                'Id'      => ['shape' => 'StringType'],
                'Name'    => ['shape' => 'StringType'],
                'Count'   => ['shape' => 'IntType'],
                'Enabled' => ['shape' => 'BoolType'],
                'When'    => ['shape' => 'TimestampType'],
            ],
        ],
        // Nested structure with a list of structures each carrying a map.
        'NestedInput' => [
            'type' => 'structure',
            'members' => [
                'RequestId' => ['shape' => 'StringType'],
                'Items'     => ['shape' => 'ItemList'],
            ],
        ],
        'ItemList' => ['type' => 'list', 'member' => ['shape' => 'Item']],
        'Item' => [
            'type' => 'structure',
            'members' => [
                'Key'        => ['shape' => 'StringType'],
                'Value'      => ['shape' => 'StringType'],
                'Attributes' => ['shape' => 'StringMap'],
                'Score'      => ['shape' => 'IntType'],
            ],
        ],
        // Map-heavy input: a single large string->string map.
        'MapInput' => [
            'type' => 'structure',
            'members' => [
                'Table' => ['shape' => 'StringMap'],
            ],
        ],
        'StringMap' => [
            'type' => 'map',
            'key'   => ['shape' => 'StringType'],
            'value' => ['shape' => 'StringType'],
        ],
        'StringType'    => ['type' => 'string'],
        'IntType'       => ['type' => 'integer'],
        'BoolType'      => ['type' => 'boolean'],
        'TimestampType' => ['type' => 'timestamp'],
    ],
];

// ---------------------------------------------------------------------------
// Representative argument payloads.
// ---------------------------------------------------------------------------

// --items controls collection sizes. 0 yields empty collections so the run
// exercises the small scalar-only path (matches the runbook's --items=0 case).
$nestedItems = [];
for ($i = 0; $i < $items; $i++) {
    $attrs = [];
    for ($j = 0; $j < 6; $j++) {
        $attrs["attr_$j"] = "value_{$i}_{$j}";
    }
    $nestedItems[] = [
        'Key'        => "key_$i",
        'Value'      => "value_$i",
        'Attributes' => $attrs,
        'Score'      => $i,
    ];
}

$bigMap = [];
for ($i = 0; $i < $items * 4; $i++) {
    $bigMap["field_$i"] = "value_$i";
}

$cases = [
    'SmallNoList' => [
        'operation' => 'SmallNoList',
        'args' => [
            'Id'      => 'abc-123',
            'Name'    => 'benchmark',
            'Count'   => 42,
            'Enabled' => true,
            'When'    => 1700000000,
        ],
    ],
    'NestedLarge' => [
        'operation' => 'NestedLarge',
        'args' => [
            'RequestId' => 'req-987',
            'Items'     => $nestedItems,
        ],
    ],
    'MapHeavy' => [
        'operation' => 'MapHeavy',
        'args' => ['Table' => $bigMap],
    ],
];

if ($onlyCase !== null) {
    if (!isset($cases[$onlyCase])) {
        fwrite(STDERR, "Unknown case: $onlyCase. Known: " . implode(', ', array_keys($cases)) . "\n");
        exit(1);
    }
    $cases = [$onlyCase => $cases[$onlyCase]];
}

// ---------------------------------------------------------------------------
// Stats helpers.
// ---------------------------------------------------------------------------

function percentile(array $sorted, float $p): int
{
    $n = count($sorted);
    if ($n === 0) {
        return 0;
    }
    $idx = (int) floor($n * $p);
    if ($idx >= $n) {
        $idx = $n - 1;
    }
    return (int) $sorted[$idx];
}

function fmtNs($ns): string
{
    if ($ns >= 1000) {
        return number_format($ns / 1000, 3) . ' us';
    }
    return number_format($ns, 0) . ' ns';
}

function fmtBytes($bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return number_format($bytes, 0) . ' B';
}

function pct(float $before, float $after): string
{
    if ($before <= 0) {
        return 'n/a';
    }
    $change = ($after / $before - 1) * 100;
    $faster = $change < 0;
    return sprintf('%+.1f%% %s', $change, $faster ? 'faster' : 'slower');
}

/**
 * Times a closure: first-use latency, then repeated-use p50/p90.
 *
 * @return array{first:int,p50:int,p90:int}
 */
function measure(callable $fn, int $iterations): array
{
    // First-use: fresh Service so plan compilation is paid here.
    $first = $fn(true);

    $timings = [];
    for ($i = 0; $i < $iterations; $i++) {
        $timings[] = $fn(false);
    }
    sort($timings);

    return [
        'first' => $first,
        'p50'   => percentile($timings, 0.50),
        'p90'   => percentile($timings, 0.90),
    ];
}

/**
 * Builds a fresh worker + shape for a case. A fresh Service means an uncached
 * shape graph, so the first plan build is measured as first-use.
 *
 * Encode returns [JsonBody, inputShape]; decode returns [JsonParser, outputShape].
 */
function makeRunner(array $model, string $operation, string $direction): array
{
    $service = new Service($model, function () { return []; });
    $op = $service->getOperation($operation);
    if ($direction === 'encode') {
        return [new JsonBody($service), $op->getInput()];
    }
    return [new JsonParser(), $op->getOutput()];
}

// ---------------------------------------------------------------------------
// Run.
// ---------------------------------------------------------------------------

$opcacheEnabled = function_exists('opcache_get_status')
    && !empty(@opcache_get_status(false)['opcache_enabled']);
$xdebug = extension_loaded('xdebug');

echo "\n  JSON serde before/after benchmark ($direction)\n";
echo "  PHP           : " . PHP_VERSION . "\n";
echo "  Arch          : " . php_uname('m') . "\n";
echo "  OPcache       : " . ($opcacheEnabled ? 'ENABLED' : 'DISABLED') . "\n";
echo "  Xdebug        : " . ($xdebug ? 'LOADED (timings unreliable)' : 'not loaded') . "\n";
echo "  Mode          : " . $mode . "\n";
echo "  Iterations    : " . number_format($iterations) . "\n";
echo "  Items         : " . $items . "\n";
echo str_repeat('-', 78) . "\n";

// ---------------------------------------------------------------------------
// Retained-memory mode.
//
// Measures the heap a warmed plan graph retains, per the runbook: read memory
// after model construction, then after plan creation. The difference is the
// retained plan payload. Warms encode then decode on the same Service so the
// number reflects both direction slots on one graph.
// ---------------------------------------------------------------------------
if ($mode === 'memory') {
    foreach ($cases as $name => $case) {
        $op = $case['operation'];
        $args = $case['args'];

        // Build the model graph and resolve shapes, but do not compile plans.
        $service = new Service($model, function () { return []; });
        $body   = new JsonBody($service);
        $parser = new JsonParser();
        $inShape  = $service->getOperation($op)->getInput();
        $outShape = $service->getOperation($op)->getOutput();
        $decodeInput = json_decode($body->build($inShape, $args), true);
        // The line above compiled encode plans as a side effect, so rebuild a
        // clean graph for an honest before/after around plan creation.
        $service = new Service($model, function () { return []; });
        $body   = new JsonBody($service);
        $parser = new JsonParser();
        $inShape  = $service->getOperation($op)->getInput();
        $outShape = $service->getOperation($op)->getOutput();

        gc_collect_cycles();
        $before = memory_get_usage();

        // Compile and cache plans for both directions across the whole graph.
        $body->build($inShape, $args);
        $parser->parse($outShape, $decodeInput);

        gc_collect_cycles();
        $after = memory_get_usage();

        printf("  %-14s retained: %s (both directions, one graph)\n",
            $name, fmtBytes($after - $before));
    }
    echo "\n" . str_repeat('-', 78) . "\n  Done.\n\n";
    exit(0);
}

$impls = $implementation === 'both' ? ['legacy', 'plans'] : [$implementation];
$results = [];

foreach ($cases as $name => $case) {
    $op = $case['operation'];
    $args = $case['args'];

    // For decode, the input is a parsed JSON array shaped like the wire body.
    // Derive it once by encoding the args and decoding back to an array.
    if ($direction === 'decode') {
        [$encBody, $encShape] = makeRunner($model, $op, 'encode');
        $input = json_decode($encBody->build($encShape, $args), true);
    } else {
        $input = $args;
    }

    foreach ($impls as $impl) {
        if ($direction === 'encode') {
            $method = $impl === 'legacy' ? 'buildLegacy' : 'build';
        } else {
            $method = $impl === 'legacy' ? 'parseLegacy' : 'parse';
        }

        // Correctness: legacy and plans must produce identical output.
        [$vWorker, $vShape] = makeRunner($model, $op, $direction);
        $out = $vWorker->$method($vShape, $input);
        $outHash = md5(serialize($out));

        $stats = measure(function (bool $fresh) use ($model, $op, $input, $method, $direction, &$sharedWorker, &$sharedShape) {
            if ($fresh) {
                [$sharedWorker, $sharedShape] = makeRunner($model, $op, $direction);
            }
            $start = hrtime(true);
            $sharedWorker->$method($sharedShape, $input);
            return hrtime(true) - $start;
        }, $iterations);

        $results[$name][$impl] = ['stats' => $stats, 'hash' => $outHash, 'wire' => $out];
    }
}

foreach ($results as $name => $byImpl) {
    echo "\n  Case: $name\n";

    if (isset($byImpl['legacy'], $byImpl['plans'])) {
        if ($byImpl['legacy']['hash'] !== $byImpl['plans']['hash']) {
            echo "  !! OUTPUT MISMATCH between legacy and plans — results differ !!\n";
        } else {
            echo "  output identical between legacy and plans\n";
        }
    }

    printf("  %-8s %14s %14s %14s\n", 'impl', 'first-use', 'p50', 'p90');
    foreach ($byImpl as $impl => $r) {
        $s = $r['stats'];
        printf("  %-8s %14s %14s %14s\n", $impl, fmtNs($s['first']), fmtNs($s['p50']), fmtNs($s['p90']));
    }

    if (isset($byImpl['legacy'], $byImpl['plans'])) {
        $l = $byImpl['legacy']['stats'];
        $p = $byImpl['plans']['stats'];
        echo "  change (plans vs legacy):\n";
        printf("    first-use : %s\n", pct($l['first'], $p['first']));
        printf("    p50       : %s\n", pct($l['p50'], $p['p50']));
        printf("    p90       : %s\n", pct($l['p90'], $p['p90']));
    }
}

echo "\n" . str_repeat('-', 78) . "\n  Done.\n\n";
