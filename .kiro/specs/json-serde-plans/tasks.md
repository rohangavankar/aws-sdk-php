# Tasks: JSON Serde Plans

Workflow per step: benchmark before, make the change, build and run tests,
benchmark after, record before/after numbers, commit. No PRs. Branch: `serde-cache`.
Benchmarks are local dev-grade on this Mac (not merge evidence).

- [x] 0. Shared cache foundation (committed on the branch)
  - `AbstractModel` cache + generation sync, `ShapeMap` generation,
    `ShapePlanCache` slots, `clearResolvedModelCache` overrides, `Service`
    lifecycle fix.
  - _Requirements: 3, 4_

- [ ] 1. Scaffold the before/after benchmark harness
  - Add `benchmark/json-serde.php` with `--implementation=legacy|plans`.
  - Measure first-use, repeated-use p50/p90, retained memory on small,
    nested-large, and map-heavy payloads.
  - _Requirements: 5.1_

- [ ] 2. Encode step (Step R2)
  - [ ] 2.1 Capture baseline (legacy) encode numbers.
  - [ ] 2.2 Confirm the encode change builds and JSON encode/compliance tests pass.
  - [ ] 2.3 Capture plan encode numbers, record before/after.
  - [ ] 2.4 Investigate or document the `PutItemRequest_Nested_L` regression.
  - [ ] 2.5 Commit encode.
  - _Requirements: 2, 3, 4, 5_

- [ ] 3. Decode step (Step R1)
  - [ ] 3.1 Add `JsonDecodePlan` and `JsonDecodePlanProvider` (slot `JSON_DECODE`).
  - [ ] 3.2 Wire `JsonParser` to the decode plan, preserving modeled order.
  - [ ] 3.3 Add descriptor-variant and invalidation tests.
  - [ ] 3.4 Confirm build and JSON/JSON RPC/REST-JSON parser + compliance tests pass.
  - [ ] 3.5 Capture before/after decode numbers, record them.
  - [ ] 3.6 Commit decode.
  - _Requirements: 1, 3, 4, 5_
