# JSON Serde Plans: Phase Summary

Branch `serde-cache` (pushed to origin fork). All x86 numbers from
benchmark-v4 (m7i.xlarge, x86_64, PHP 8.1.34, OPcache on, JIT tracing).
Legacy vs plans compared in one process; output byte-identical in every case.

## Phase 0: Shared cache foundation

- **Commit:** `49970a87e`
- **What:** `AbstractModel` plan cache (`getCachedPlan`/`setCachedPlan`) with
  generation sync, `ShapeMap` generation tracking, `ShapePlanCache` slot
  registry, `clearResolvedModelCache` overrides, `Service::setDefinition`
  lifecycle fix.
- **Result:** No behavior change. Foundation only. Blocks all protocol work.

## Phase 1: Spec + benchmark harness

- **Commits:** `db69452c5` (spec + harness), `e6b46f9c4` (`--items` option)
- **What:** Spec at `.kiro/specs/json-serde-plans/`. Focused harness
  `benchmark/json-serde.php` comparing legacy vs plan paths with
  `--direction`, `--items`, `--iterations`.

## Phase 2: JSON encode plans

- **Commits:** `d8d4d8029` (implementation), `e6b46f9c4` (x86 results)
- **What:** `JsonShapeType`, `JsonEncodePlan`, `JsonEncodePlanProvider`,
  `JsonBody::formatPlan`. Plan cached per shape on `JSON_ENCODE`.
- **Tests:** 1252 serializer + compliance tests pass, wire output identical.
- **x86 warm p50 (encode):**

| Case | Legacy | Plans | Change |
| --- | ---: | ---: | ---: |
| SmallNoList | 1.21 us | 582 ns | **-52.1% faster** |
| NestedLarge | 76.36 us | 47.68 us | **-37.6% faster** |
| MapHeavy | 19.97 us | 12.91 us | **-35.3% faster** |

- **First-use:** collections faster (NestedLarge -57%); small/empty payloads
  pay a few-microsecond plan-compile cost that repays within a few warm calls.
- **Note:** the `PutItemRequest_Nested_L` regression in the design doc did not
  reproduce on x86 (nested-large is faster).

## Phase 3: JSON decode plans

- **Commits:** `39a073782` (implementation), `3ec175c90` (x86 results)
- **What:** `JsonDecodePlan`, `JsonDecodePlanProvider`, `JsonParser::parsePlan`.
  Ordered member list preserves result order; union flag for `Unknown`
  fallback. Plan cached per shape on `JSON_DECODE`.
- **Tests:** 677 parser tests pass, decoded output identical.
- **x86 warm p50 (decode):**

| Case | Legacy | Plans | Change |
| --- | ---: | ---: | ---: |
| SmallNoList | 4.15 us | 3.62 us | **-12.8% faster** |
| NestedLarge | 61.03 us | 35.19 us | **-42.3% faster** |
| MapHeavy | 14.74 us | 8.21 us | **-44.3% faster** |

- **First-use:** collections faster; empty payloads pay a few-microsecond
  plan-compile cost.

## Status of Rohan's ownership (foundation, JSON, XML)

- [x] Phase 0: shared cache foundation
- [x] JSON encode (Step R2)
- [x] JSON decode (Step R1)
- [ ] XML encode (Step R3) — not started
- [ ] XML decode (Step R4) — not started

JSON is complete. XML is still open under Rohan's ownership per the work plan.

## Additional evidence (2026-09-22)

- **Retained memory** (`json-memory-and-large-x86-2026-09-22.md`): plan cache is
  bounded by the model, not payload size (identical at items=50 and items=2000).
  NestedLarge ~11.7 KB both directions; MapHeavy ~3.9 KB.
- **Large payload** (same doc): at ~3 ms payloads (2000 items), encode warm p50
  -37.6%, decode -40.7%, output identical.
- **Full compliance corpus** (`compliance-corpus-x86-2026-09-22.md`): 70 cases,
  all 5 protocols, baseline (foundation) vs candidate. JSON RPC weighted p50
  **-35.9%**; REST-JSON flat (HTTP-binding bound, Lukas's area); control
  protocols within +-0.3% (no regression). Raw JSON preserved under
  `x86-compliance-corpus/`.
