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
use Aws\Api\Parser\XmlParser;

$opts = getopt('', ['implementation:', 'direction:', 'iterations:', 'items:', 'mode:', 'case:']);
$implementation = $opts['implementation'] ?? 'both';
$direction      = $opts['direction'] ?? 'encode';
$iterations     = (int) ($opts['iterations'] ?? 200000);
$items          = isset($opts['items']) ? (int) $opts['items'] : 50;
$mode           = $opts['mode'] ?? 'time';
$onlyCase       = $opts['case'] ?? null;

if (!in_array($implementation, ['legacy', 'plans', 'both'], true)) {
    fwrite(STDERR, "Invalid --implementation. Use legacy, plans, or both.\n");
    exit(1);
}
if (!in_array($direction, ['encode', 'decode'], true)) {
    fwrite(STDERR, "Invalid --direction. Use encode or decode.\n");
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
        'SmallNoList' => ['name' => 'SmallNoList', 'input' => ['shape' => 'SmallInput'],  'output' => ['shape' => 'SmallInput']],
        'NestedLarge' => ['name' => 'NestedLarge', 'input' => ['shape' => 'NestedInput'], 'output' => ['shape' => 'NestedInput']],
        'MapHeavy'    => ['name' => 'MapHeavy',    'input' => ['shape' => 'MapInput'],    'output' => ['shape' => 'MapInput']],
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
function makeRunner(array $model, string $operation, string $direction): array
{
    $service = new Service($model, function () { return []; });
    $op = $service->getOperation($operation);
    if ($direction === 'encode') {
        return [new XmlBody($service), $op->getInput()];
    }
    return [new XmlParser(), $op->getOutput()];
}

$opcache = function_exists('opcache_get_status') && !empty(@opcache_get_status(false)['opcache_enabled']);
$xdebug = extension_loaded('xdebug');

echo "\n  XML serde before/after benchmark ($direction)\n";
echo "  PHP           : " . PHP_VERSION . "\n";
echo "  Arch          : " . php_uname('m') . "\n";
echo "  OPcache       : " . ($opcache ? 'ENABLED' : 'DISABLED') . "\n";
echo "  Xdebug        : " . ($xdebug ? 'LOADED (timings unreliable)' : 'not loaded') . "\n";
echo "  Mode          : $mode\n";
echo "  Iterations    : " . number_format($iterations) . "\n";
echo "  Items         : $items\n";
echo str_repeat('-', 78) . "\n";

// Builds the decode input (SimpleXMLElement) for a case by encoding the args
// once and reparsing. Strips the XML declaration the same way the SDK does.
function decodeInput(array $model, string $op, array $args): \SimpleXMLElement
{
    $service = new Service($model, function () { return []; });
    $body = new XmlBody($service);
    $xml = $body->build($service->getOperation($op)->getInput(), $args);
    return new \SimpleXMLElement($xml);
}

// Memory mode: retained plan heap for the graph in the chosen direction.
if ($mode === 'memory') {
    foreach ($cases as $name => $case) {
        $op = $case['operation'];
        $args = $case['args'];
        [$worker, $shape] = makeRunner($model, $op, $direction);
        $input = $direction === 'encode' ? $args : decodeInput($model, $op, $args);
        gc_collect_cycles();
        $before = memory_get_usage();
        $direction === 'encode' ? $worker->build($shape, $input) : $worker->parse($shape, $input);
        gc_collect_cycles();
        $after = memory_get_usage();
        printf("  %-14s retained: %s (%s graph)\n", $name, fmtBytes($after - $before), $direction);
    }
    echo "\n" . str_repeat('-', 78) . "\n  Done.\n\n";
    exit(0);
}

$impls = $implementation === 'both' ? ['legacy', 'plans'] : [$implementation];
$results = [];

foreach ($cases as $name => $case) {
    $op = $case['operation'];
    $args = $case['args'];
    // Decode input: encode the args once, parse to SimpleXML once, and reuse
    // that node every iteration. XmlParser only reads the node (isset, ->{name},
    // (string) casts, attributes(), children()); it does not mutate it, so a
    // single parsed element is safe to reuse and keeps the SimpleXML parse cost
    // out of the timed decode loop.
    $xmlNode = null;
    if ($direction === 'decode') {
        $s = new Service($model, function () { return []; });
        $b = new XmlBody($s);
        $xmlNode = new \SimpleXMLElement($b->build($s->getOperation($op)->getInput(), $args));
    }

    foreach ($impls as $impl) {
        if ($direction === 'encode') {
            $method = $impl === 'legacy' ? 'buildLegacy' : 'build';
        } else {
            $method = $impl === 'legacy' ? 'parseLegacy' : 'parse';
        }

        // Correctness: legacy and plans must produce identical output.
        [$vWorker, $vShape] = makeRunner($model, $op, $direction);
        $vInput = $direction === 'encode' ? $args : $xmlNode;
        $out = $vWorker->$method($vShape, $vInput);
        $hash = md5(serialize($out));

        $stats = measure(function (bool $fresh) use ($model, $op, $args, $method, $direction, $xmlNode, &$sWorker, &$sShape) {
            if ($fresh) {
                [$sWorker, $sShape] = makeRunner($model, $op, $direction);
            }
            $input = $direction === 'encode' ? $args : $xmlNode;
            $start = hrtime(true);
            $sWorker->$method($sShape, $input);
            return hrtime(true) - $start;
        }, $iterations);

        $results[$name][$impl] = [
            'stats' => $stats,
            'hash'  => $hash,
            'out'   => $direction === 'encode' ? $out : '',
        ];
    }
}

foreach ($results as $name => $byImpl) {
    echo "\n  Case: $name\n";
    if (isset($byImpl['legacy'], $byImpl['plans'])) {
        if ($byImpl['legacy']['hash'] !== $byImpl['plans']['hash']) {
            echo "  !! OUTPUT MISMATCH between legacy and plans !!\n";
        } elseif ($byImpl['legacy']['out'] !== '') {
            echo "  output identical between legacy and plans (" . strlen($byImpl['legacy']['out']) . " bytes)\n";
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
