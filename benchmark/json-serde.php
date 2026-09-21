#!/usr/bin/env php
<?php
/**
 * Focused JSON serde before/after benchmark.
 *
 * Compares the legacy JsonBody::format() path against the compiled-plan
 * JsonBody::build() path in a single process, so before/after numbers come
 * from identical shapes, args, PHP, and settings.
 *
 * Encode is implemented. Decode is added in a later step (--direction=decode
 * will report as pending until then).
 *
 * Run with a clean PHP to avoid Xdebug/JIT skew:
 *   php -n -d opcache.enable_cli=1 benchmark/json-serde.php
 *
 * Options:
 *   --implementation=legacy|plans   Which encode path to run (default: both)
 *   --direction=encode|decode       Serde direction (default: encode)
 *   --iterations=200000             Repeated-use sample count
 *   --case=NAME                     Run one payload case only
 *
 * Local runs are dev-grade for iterating. Merge evidence requires the x86
 * m7i.xlarge runbook flow in docs/serde/benchmark-runbook.md.
 *
 * @internal
 */

require __DIR__ . '/../vendor/autoload.php';

use Aws\Api\Service;
use Aws\Api\Serializer\JsonBody;

$opts = getopt('', ['implementation:', 'direction:', 'iterations:', 'case:']);
$implementation = $opts['implementation'] ?? 'both';
$direction      = $opts['direction'] ?? 'encode';
$iterations     = (int) ($opts['iterations'] ?? 200000);
$onlyCase       = $opts['case'] ?? null;

if (!in_array($implementation, ['legacy', 'plans', 'both'], true)) {
    fwrite(STDERR, "Invalid --implementation. Use legacy, plans, or both.\n");
    exit(1);
}

if ($direction === 'decode') {
    fwrite(STDERR, "Decode benchmark is not implemented yet (JSON decode plans are a later step).\n");
    exit(2);
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
        'SmallNoList'  => ['name' => 'SmallNoList',  'input' => ['shape' => 'SmallInput']],
        'NestedLarge'  => ['name' => 'NestedLarge',  'input' => ['shape' => 'NestedInput']],
        'MapHeavy'     => ['name' => 'MapHeavy',     'input' => ['shape' => 'MapInput']],
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

$nestedItems = [];
for ($i = 0; $i < 50; $i++) {
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
for ($i = 0; $i < 200; $i++) {
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
 * Builds a fresh JsonBody + input shape for a case. A fresh Service means an
 * uncached shape graph, so the first plan build is measured as first-use.
 */
function makeRunner(array $model, string $operation): array
{
    $service = new Service($model, function () { return []; });
    $body = new JsonBody($service);
    $shape = $service->getOperation($operation)->getInput();
    return [$body, $shape];
}

// ---------------------------------------------------------------------------
// Run.
// ---------------------------------------------------------------------------

$opcacheEnabled = function_exists('opcache_get_status')
    && !empty(@opcache_get_status(false)['opcache_enabled']);
$xdebug = extension_loaded('xdebug');

echo "\n  JSON serde before/after benchmark (encode)\n";
echo "  PHP           : " . PHP_VERSION . "\n";
echo "  Arch          : " . php_uname('m') . "\n";
echo "  OPcache       : " . ($opcacheEnabled ? 'ENABLED' : 'DISABLED') . "\n";
echo "  Xdebug        : " . ($xdebug ? 'LOADED (timings unreliable)' : 'not loaded') . "\n";
echo "  Iterations    : " . number_format($iterations) . "\n";
echo "  Note          : local dev-grade, not merge evidence\n";
echo str_repeat('-', 78) . "\n";

$impls = $implementation === 'both' ? ['legacy', 'plans'] : [$implementation];
$results = [];

foreach ($cases as $name => $case) {
    $op = $case['operation'];
    $args = $case['args'];

    foreach ($impls as $impl) {
        $method = $impl === 'legacy' ? 'buildLegacy' : 'build';

        // Correctness: legacy and plans must produce identical wire output.
        [$vBody, $vShape] = makeRunner($model, $op);
        $wire = $vBody->$method($vShape, $args);

        $stats = measure(function (bool $fresh) use ($model, $op, $args, $method, &$sharedBody, &$sharedShape) {
            if ($fresh) {
                [$sharedBody, $sharedShape] = makeRunner($model, $op);
            }
            $start = hrtime(true);
            $sharedBody->$method($sharedShape, $args);
            return hrtime(true) - $start;
        }, $iterations);

        $results[$name][$impl] = ['stats' => $stats, 'wire' => $wire];
    }
}

foreach ($results as $name => $byImpl) {
    echo "\n  Case: $name\n";

    if (isset($byImpl['legacy'], $byImpl['plans'])) {
        if ($byImpl['legacy']['wire'] !== $byImpl['plans']['wire']) {
            echo "  !! WIRE MISMATCH between legacy and plans — output differs !!\n";
        } else {
            echo "  wire output identical (" . strlen($byImpl['legacy']['wire']) . " bytes)\n";
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
