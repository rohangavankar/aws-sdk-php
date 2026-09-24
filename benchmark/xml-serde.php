#!/usr/bin/env php
<?php
/**
 * Focused XML serde before/after benchmark (encode).
 *
 * Compares the legacy XmlBody::format() path against the compiled-plan
 * XmlBody::build() path in a single process, so before/after numbers come from
 * identical shapes, values, PHP, and settings. Encode compares
 * XmlBody::buildLegacy() vs build().
 *
 * XML decode is a later step and is not covered here.
 *
 * Run with a clean PHP to avoid Xdebug/JIT skew:
 *   php -n -d opcache.enable_cli=1 benchmark/xml-serde.php
 *
 * Options:
 *   --implementation=legacy|plans   Which path to run (default: both)
 *   --iterations=200000             Repeated-use sample count
 *   --items=50                      List/map entry count for collection cases
 *   --mode=time|memory              time = latency; memory = retained heap
 *   --case=NAME                     Run one payload case only
 *
 * Local runs are dev-grade. Merge evidence requires the x86 m7i.xlarge runbook
 * flow in docs/serde/benchmark-runbook.md.
 *
 * @internal
 */

require __DIR__ . '/../vendor/autoload.php';

use Aws\Api\Service;
use Aws\Api\Serializer\XmlBody;

$opts = getopt('', ['implementation:', 'iterations:', 'items:', 'mode:', 'case:']);
$implementation = $opts['implementation'] ?? 'both';
$iterations     = (int) ($opts['iterations'] ?? 200000);
$items          = isset($opts['items']) ? (int) $opts['items'] : 50;
$mode           = $opts['mode'] ?? 'time';
$onlyCase       = $opts['case'] ?? null;

if (!in_array($implementation, ['legacy', 'plans', 'both'], true)) {
    fwrite(STDERR, "Invalid --implementation. Use legacy, plans, or both.\n");
    exit(1);
}
if (!in_array($mode, ['time', 'memory'], true)) {
    fwrite(STDERR, "Invalid --mode. Use time or memory.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Inline rest-xml model exercising the XML-specific features: namespaces,
// attributes, flattened vs wrapped lists, nested maps, timestamps, blobs.
// ---------------------------------------------------------------------------

$model = [
    'metadata' => [
        'protocol'      => 'rest-xml',
        'apiVersion'    => '2024-01-01',
        'endpointPrefix'=> 'bench',
        'serviceId'     => 'Bench',
        'signatureVersion' => 'v4',
    ],
    'operations' => [
        'SmallNoList' => ['name' => 'SmallNoList', 'input' => ['shape' => 'SmallInput']],
        'NestedLarge' => ['name' => 'NestedLarge', 'input' => ['shape' => 'NestedInput']],
        'MapHeavy'    => ['name' => 'MapHeavy',    'input' => ['shape' => 'MapInput']],
    ],
    'shapes' => [
        // Scalar-only structure with an attribute and a namespace.
        'SmallInput' => [
            'type' => 'structure',
            'xmlNamespace' => ['uri' => 'http://bench.example/ns'],
            'members' => [
                'Id'      => ['shape' => 'StringType', 'xmlAttribute' => true, 'locationName' => 'id'],
                'Name'    => ['shape' => 'StringType', 'locationName' => 'thing_name'],
                'Count'   => ['shape' => 'IntType'],
                'Enabled' => ['shape' => 'BoolType'],
                'When'    => ['shape' => 'TimestampType'],
            ],
        ],
        // Nested structure with a wrapped list of structures each with a map.
        'NestedInput' => [
            'type' => 'structure',
            'members' => [
                'RequestId' => ['shape' => 'StringType'],
                'Items'     => ['shape' => 'ItemList'],
            ],
        ],
        'ItemList' => ['type' => 'list', 'member' => ['shape' => 'Item', 'locationName' => 'Item']],
        'Item' => [
            'type' => 'structure',
            'members' => [
                'Key'        => ['shape' => 'StringType'],
                'Value'      => ['shape' => 'StringType'],
                'Attributes' => ['shape' => 'StringMap'],
                'Score'      => ['shape' => 'IntType'],
            ],
        ],
        // Map-heavy input (wrapped map).
        'MapInput' => [
            'type' => 'structure',
            'members' => ['Table' => ['shape' => 'StringMap']],
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
// Representative payloads.
// ---------------------------------------------------------------------------

$nestedItems = [];
for ($i = 0; $i < $items; $i++) {
    $attrs = [];
    for ($j = 0; $j < 6; $j++) {
        $attrs["attr_$j"] = "value_{$i}_{$j}";
    }
    $nestedItems[] = [
        'Key' => "key_$i", 'Value' => "value_$i", 'Attributes' => $attrs, 'Score' => $i,
    ];
}

$bigMap = [];
for ($i = 0; $i < $items * 4; $i++) {
    $bigMap["field_$i"] = "value_$i";
}

$cases = [
    'SmallNoList' => ['operation' => 'SmallNoList', 'args' => [
        'Id' => 'abc-123', 'Name' => 'benchmark', 'Count' => 42,
        'Enabled' => true, 'When' => 1700000000,
    ]],
    'NestedLarge' => ['operation' => 'NestedLarge', 'args' => [
        'RequestId' => 'req-987', 'Items' => $nestedItems,
    ]],
    'MapHeavy' => ['operation' => 'MapHeavy', 'args' => ['Table' => $bigMap]],
];

if ($onlyCase !== null) {
    if (!isset($cases[$onlyCase])) {
        fwrite(STDERR, "Unknown case: $onlyCase\n");
        exit(1);
    }
    $cases = [$onlyCase => $cases[$onlyCase]];
}

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

function percentile(array $sorted, float $p): int
{
    $n = count($sorted);
    if ($n === 0) return 0;
    $i = (int) floor($n * $p);
    if ($i >= $n) $i = $n - 1;
    return (int) $sorted[$i];
}
function fmtNs($ns): string
{
    return $ns >= 1000 ? number_format($ns / 1000, 3) . ' us' : number_format($ns, 0) . ' ns';
}
function fmtBytes($b): string
{
    if ($b >= 1048576) return number_format($b / 1048576, 2) . ' MB';
    if ($b >= 1024) return number_format($b / 1024, 2) . ' KB';
    return number_format($b, 0) . ' B';
}
function pct(float $before, float $after): string
{
    if ($before <= 0) return 'n/a';
    $c = ($after / $before - 1) * 100;
    return sprintf('%+.1f%% %s', $c, $c < 0 ? 'faster' : 'slower');
}
function measure(callable $fn, int $iterations): array
{
    $first = $fn(true);
    $t = [];
    for ($i = 0; $i < $iterations; $i++) $t[] = $fn(false);
    sort($t);
    return ['first' => $first, 'p50' => percentile($t, 0.50), 'p90' => percentile($t, 0.90)];
}
function makeRunner(array $model, string $operation): array
{
    $service = new Service($model, function () { return []; });
    $body = new XmlBody($service);
    $shape = $service->getOperation($operation)->getInput();
    return [$body, $shape];
}

$opcache = function_exists('opcache_get_status') && !empty(@opcache_get_status(false)['opcache_enabled']);
$xdebug = extension_loaded('xdebug');

echo "\n  XML serde before/after benchmark (encode)\n";
echo "  PHP           : " . PHP_VERSION . "\n";
echo "  Arch          : " . php_uname('m') . "\n";
echo "  OPcache       : " . ($opcache ? 'ENABLED' : 'DISABLED') . "\n";
echo "  Xdebug        : " . ($xdebug ? 'LOADED (timings unreliable)' : 'not loaded') . "\n";
echo "  Mode          : $mode\n";
echo "  Iterations    : " . number_format($iterations) . "\n";
echo "  Items         : $items\n";
echo str_repeat('-', 78) . "\n";

// Memory mode: retained plan heap for the encode graph.
if ($mode === 'memory') {
    foreach ($cases as $name => $case) {
        $op = $case['operation'];
        $args = $case['args'];
        [$body, $shape] = makeRunner($model, $op);
        gc_collect_cycles();
        $before = memory_get_usage();
        $body->build($shape, $args);   // compiles + caches plans
        gc_collect_cycles();
        $after = memory_get_usage();
        printf("  %-14s retained: %s (encode graph)\n", $name, fmtBytes($after - $before));
    }
    echo "\n" . str_repeat('-', 78) . "\n  Done.\n\n";
    exit(0);
}

$impls = $implementation === 'both' ? ['legacy', 'plans'] : [$implementation];
$results = [];

foreach ($cases as $name => $case) {
    $op = $case['operation'];
    $args = $case['args'];

    foreach ($impls as $impl) {
        $method = $impl === 'legacy' ? 'buildLegacy' : 'build';

        // Correctness: legacy and plans must produce identical XML.
        [$vBody, $vShape] = makeRunner($model, $op);
        $out = $vBody->$method($vShape, $args);
        $hash = md5($out);

        $stats = measure(function (bool $fresh) use ($model, $op, $args, $method, &$sBody, &$sShape) {
            if ($fresh) {
                [$sBody, $sShape] = makeRunner($model, $op);
            }
            $start = hrtime(true);
            $sBody->$method($sShape, $args);
            return hrtime(true) - $start;
        }, $iterations);

        $results[$name][$impl] = ['stats' => $stats, 'hash' => $hash, 'out' => $out];
    }
}

foreach ($results as $name => $byImpl) {
    echo "\n  Case: $name\n";
    if (isset($byImpl['legacy'], $byImpl['plans'])) {
        echo $byImpl['legacy']['hash'] === $byImpl['plans']['hash']
            ? "  output identical between legacy and plans (" . strlen($byImpl['legacy']['out']) . " bytes)\n"
            : "  !! OUTPUT MISMATCH between legacy and plans !!\n";
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
