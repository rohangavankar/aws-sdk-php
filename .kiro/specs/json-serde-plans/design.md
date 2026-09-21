# Design: JSON Serde Plans

## Overview

Compile per-shape JSON encode and decode instructions once, cache them on the
shape, and reuse them on later calls. No public API changes. Wire output stays
byte-identical. This design documents what is built and what remains, grounded
in the current source.

Reference docs at workspace root `php-fork-dev/docs/serde/`:
`architecture.md` (shared contract), `JSON-Serde-Plans.md` (JSON layouts),
`work-plan.md` (step order), `benchmark-runbook.md` (measurement).

## Where serde runs

Every call flows through a `HandlerList`. Request serialization runs in the
`build` step (command to PSR-7 request). Response parsing runs as the handler
result resolves. `JsonBody` runs inside `build`; `JsonParser` runs on the way
back. Plans change how fast those two stages run, never the pipeline shape, the
wire output, or user middleware.

## Foundation (built, committed)

The shared cache foundation lives on `AbstractModel` and `ShapeMap`. Naming note:
the committed code uses `getCachedPlan($slot)` / `setCachedPlan($slot, $plan)`
on `AbstractModel`. The `architecture.md` draft referred to these as
`getSerdePlan` / `cacheSerdePlan`; the shipped names are the `Cached` variants.

- `AbstractModel::$cachedPlans` (array keyed by slot) and `$planGeneration`.
- `getCachedPlan($slot)`: syncs generation, returns cached plan or null.
- `setCachedPlan($slot, $plan)`: syncs generation, stores, returns the plan.
- `syncPlanGeneration()`: clears cached plans when `ShapeMap` generation moved.
- `invalidateResolvedModel()`: called from `offsetSet`/`offsetUnset`; runs
  `clearResolvedModelCache()`, clears cached plans, increments generation.
- `clearResolvedModelCache()`: overridden by `StructureShape` (drops
  `$members`), `ListShape`, `MapShape`, `Operation`, and `Service`.
- `ShapeMap`: monotonically increasing `generation`, with
  `getGeneration()`/`incrementGeneration()`.
- `Service::setDefinition()`: new `ShapeMap`, clears operation cache;
  `getOperation()` returns a stable cached operation per graph.
- `ShapePlanCache`: slot registry. `JSON_ENCODE = 0`, `JSON_DECODE = 1`, plus
  reserved HTTP/XML/Query slots.

## JsonShapeType

Maps model strings to integer tags once at compile time, replacing string
switches in hot loops: `SCALAR=0, STRUCTURE=1, LIST=2, MAP=3, BLOB=4,
TIMESTAMP=5, DOCUMENT=6`. A `structure` with `document => true` maps to
`DOCUMENT`.

## Encode plan (Step R2, in working tree)

`JsonEncodePlan` fields: `type`, `members`, `value`, `timestampFormat`.

Structure member descriptor, keyed by SDK member name:

```
members[sdkName] = [
    M_WIRE=0     => wire (locationName) key,
    M_TYPE=1     => JsonShapeType tag,
    M_SHAPE=2    => child Shape (lazy composite plan lookup),
    M_TSFORMAT=3 => timestamp format or null,
]
```

List/map value descriptor:

```
value = [
    V_TYPE=0     => JsonShapeType tag,
    V_SHAPE=1    => element/value Shape,
    V_TSFORMAT=2 => timestamp format or null,
]
```

`JsonEncodePlanProvider::get()` returns the cached plan or compiles once via
`setCachedPlan(ShapePlanCache::JSON_ENCODE, ...)`. `JsonBody::build()` fetches
the plan and calls `formatPlan()`. `formatPlan()` dispatches on the integer tag:
structure looks up each input key in `plan->members`, list/map reuse
`plan->value`, blob base64-encodes, timestamp uses the cached format, document
and scalar pass through. Composite children fetch their own plan lazily via
`formatByType()`. Empty structure and empty map return `new \stdClass` so
`json_encode` emits `{}`; empty list stays an array.

## Decode plan (Step R1, not yet built)

`JsonDecodePlan` fields: `type`, `members`, `value`, `timestampFormat`, `union`.

Structure members preserve modeled order (ordered list, not name-keyed):

```
members[] = [
    0 => sdkName,
    1 => wireName,
    2 => JsonShapeType tag,
    3 => Shape,
    4 => timestamp format or null,
]
```

List/map reuse the same three-field value descriptor as encode.
`JsonDecodePlanProvider` caches on `ShapePlanCache::JSON_DECODE`. `JsonParser`
iterates the ordered modeled-member list and checks the decoded JSON array for
each cached wire name, preserving V3 result order. No second wire-name hash
table unless a sparse-response benchmark shows a repeatable win.

## Behavior preserved

Empty structures/maps as JSON objects; empty lists as arrays; null structure
members omitted on encode; null map values omitted on decode; documents returned
without modeled traversal; unknown union members under `Unknown`; blob base64;
encode timestamp default `unixTimestamp`; decode timestamp default `null`
(`DateTimeResult`); modeled member order on decode; no references introduced into
input arrays.

## Invalidation

Plans attach to shapes and key off the `ShapeMap` generation. Mutating a
member's `locationName` or replacing a structure's `members` increments the
generation, and the next `getCachedPlan` clears stale plans. Mocks without a
`ShapeMap` stay safe (the sync and invalidate paths null-check the map).

## Benchmarking approach (local, dev-grade)

A focused harness `benchmark/json-serde.php` with an `--implementation=legacy|plans`
toggle measures before (legacy `format`/`parse` path) vs after (plan path) in
one process, avoiding stash/unstash. It reports first-use, repeated-use p50/p90,
and retained memory on representative payloads (small no-list, nested large,
map-heavy). Local Mac numbers are dev-grade for iterating and are not merge
evidence. Merge-grade numbers require the x86 `m7i.xlarge` runbook flow.

## Testing

`tests/Api/Serializer/JsonBodyTest.php`, `tests/Api/Parser/JsonParserTest.php`,
`JsonRpcParserTest`, `RestJsonParserTest`, serializer and parser
`ComplianceTest`. Plus focused tests for each descriptor variant and for
invalidation (locationName change, members replacement, mock-without-ShapeMap).

## Non-goals

No Smithy schema swap, no typed input/output objects, no wire behavior change,
no error parsing moved into plans, no eager whole-model compilation, no
build-time generated plans yet, no protocol-neutral shared plan object.
