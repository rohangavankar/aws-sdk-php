# Requirements: JSON Serde Plans

## Introduction

The AWS SDK for PHP V3 serializers and parsers re-interpret C2J model arrays on
every request and response. `JsonBody::format()` re-reads shape types, checks
whether each key is modeled, resolves member shapes, derives wire names, and
reads timestamp traits on every call. `JsonParser::parse()` repeats the same
work on every response. This effort computes JSON encode and decode instructions
once per shape, caches them on the shape, and reuses them, with no public API
change.

Scope for this spec: modeled JSON body encoding (`Aws\Api\Serializer\JsonBody`)
and decoding (`Aws\Api\Parser\JsonParser`). REST HTTP bindings, XML, and Query
serialization are out of scope (owned separately per the work plan). Error
parser traits are out of scope.

Source of truth: `docs/serde/architecture.md`, `docs/serde/JSON-Serde-Plans.md`,
and `docs/serde/work-plan.md` (workspace root `php-fork-dev/docs/serde/`).

Ownership: Rohan owns the shared foundation, JSON, and XML.

## Current state (as built)

- The shared cache foundation (PR 0) is committed on branch
  `serde-plan-cache-foundation`: `AbstractModel::getCachedPlan`/`setCachedPlan`
  with generation sync, `ShapeMap` generation tracking, `ShapePlanCache` slot
  registry, `clearResolvedModelCache()` overrides on the shape subclasses,
  `Operation`, and `Service`, and the `Service::setDefinition()` lifecycle fix.
- JSON encode (Step R2) exists in the working tree but is uncommitted and
  unverified: `JsonShapeType`, `JsonEncodePlan`, `JsonEncodePlanProvider`, and
  the `JsonBody::formatPlan()` wiring.
- JSON decode (Step R1) does not exist. The work plan requires decode to merge
  before encode.

## Requirements

### Requirement 1: JSON decode plans (Step R1)

**User story:** As an SDK maintainer, I want JSON response parsing to use a
compiled per-shape decode plan, so that response parsing avoids repeated model
reads while producing byte-identical results.

#### Acceptance criteria

1. WHEN `JsonParser` parses a response for a shape THEN the system SHALL obtain
   a `JsonDecodePlan` from a `JsonDecodePlanProvider` keyed on
   `ShapePlanCache::JSON_DECODE`.
2. WHEN a decode plan is compiled for a structure THEN the system SHALL preserve
   modeled member order in the produced result.
3. WHEN a response omits a modeled member THEN the system SHALL omit it from the
   result exactly as the current parser does.
4. WHEN a map value is null THEN the system SHALL omit that entry during
   decoding.
5. WHEN a union response contains an unmodeled member THEN the system SHALL
   return it under `Unknown`.
6. WHEN a timestamp has no decode format THEN the system SHALL default to `null`
   matching `DateTimeResult` behavior.
7. WHEN the same shape is decoded again in the same graph generation THEN the
   system SHALL reuse the cached plan without recompiling.
8. WHEN any JSON, JSON RPC, or REST-JSON protocol compliance test runs THEN the
   system SHALL pass with no fixture changes caused by behavior drift.

### Requirement 2: JSON encode plans (Step R2)

**User story:** As an SDK maintainer, I want JSON request serialization to use a
compiled per-shape encode plan, so that request building avoids repeated model
reads while producing identical wire output.

#### Acceptance criteria

1. WHEN `JsonBody::build()` serializes a shape THEN the system SHALL obtain a
   `JsonEncodePlan` from a `JsonEncodePlanProvider` keyed on
   `ShapePlanCache::JSON_ENCODE`.
2. WHEN a structure has no non-null members THEN the system SHALL encode it as a
   JSON object, not a JSON array.
3. WHEN a map is empty THEN the system SHALL encode it as a JSON object.
4. WHEN a list is empty THEN the system SHALL encode it as a JSON array.
5. WHEN a structure member is null THEN the system SHALL omit it during encoding.
6. WHEN a blob is encoded THEN the system SHALL base64 encode it.
7. WHEN a timestamp has no encode format THEN the system SHALL default to
   `unixTimestamp`.
8. WHEN a document shape is encoded THEN the system SHALL return it without
   modeled traversal.
9. WHEN encoding a value THEN the system SHALL NOT introduce references into
   input arrays through formatting.
10. WHEN the `PutItemRequest_Nested_L` case is benchmarked THEN the system SHALL
    either resolve the previously observed encode regression or document it with
    an explicit tradeoff decision.

### Requirement 3: Cache reuse and invalidation

**User story:** As an SDK maintainer, I want cached plans to invalidate when the
model mutates, so that custom definitions and direct model mutation stay correct.

#### Acceptance criteria

1. WHEN a child member's `locationName` changes after plan construction THEN the
   system SHALL rebuild the affected encode and decode plans.
2. WHEN a structure's `members` definition is replaced after plan construction
   THEN the system SHALL rebuild the affected plans on next access.
3. WHEN a model object has no `ShapeMap` (mock construction) THEN plan access and
   mutation SHALL remain safe.
4. WHEN plans are used THEN plan construction SHALL occur once per model object,
   slot, and graph generation.

### Requirement 4: Compatibility and internal API

**User story:** As an SDK consumer, I want no change to the public contract, so
that existing clients keep working.

#### Acceptance criteria

1. WHEN plans are added THEN the system SHALL NOT change public client, command,
   result, or service APIs.
2. WHEN plan classes are added THEN the system SHALL keep every plan class and
   accessor internal (`@internal`).
3. WHEN wire output is produced THEN existing wire names and timestamp defaults
   SHALL remain unchanged.

### Requirement 5: Performance evidence

**User story:** As a reviewer, I want merge-grade benchmark numbers, so that I
can confirm a repeatable gain without regressions.

#### Acceptance criteria

1. WHEN a JSON PR is proposed for merge THEN the system SHALL report first-use
   latency, repeated-use p50 and p90, and retained memory.
2. WHEN benchmarks are reported THEN they SHALL come from the assigned x86
   instance following `docs/serde/benchmark-runbook.md`, not a local dev machine.
3. WHEN benchmarks are compared THEN baseline and candidate SHALL use identical
   PHP, JIT, OPcache, and iteration settings.
4. WHEN non-trivial JSON response cases are measured THEN they SHALL retain a
   repeatable p50 improvement.
5. WHEN map-heavy request cases are measured THEN they SHALL retain a repeatable
   improvement.
6. WHEN any representative request case regresses beyond benchmark noise THEN the
   regression SHALL carry an explicit documented tradeoff decision.
