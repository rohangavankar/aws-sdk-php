# Tasks: JSON Serde Plans

Workflow per step: benchmark before, make the change, build and run tests,
benchmark after, record before/after numbers, commit and push. No PRs.
Branch: `serde-cache` (pushed to origin fork).

- [x] 0. Shared cache foundation (committed `49970a87e`)
  - `AbstractModel` cache + generation sync, `ShapeMap` generation,
    `ShapePlanCache` slots, `clearResolvedModelCache` overrides, `Service`
    lifecycle fix.
  - _Requirements: 3, 4_

- [x] 1. Benchmark harness `benchmark/json-serde.php` (`db69452c5`, `--items` in `e6b46f9c4`)
  - legacy vs plans in one process; `--direction`, `--items`, `--iterations`.
  - _Requirements: 5.1_

- [x] 2. Encode step (`d8d4d8029`), x86 numbers (`e6b46f9c4`)
  - Plan + provider + `JsonBody::formatPlan`. 1252 serializer/compliance tests
    pass, wire identical. x86 warm p50 -27 to -52%.
  - `PutItemRequest_Nested_L` regression did not reproduce (nested-large faster).
  - _Requirements: 2, 3, 4, 5_

- [x] 3. Decode step (`39a073782`), x86 numbers (`3ec175c90`)
  - Plan + provider + `JsonParser::parsePlan`, ordered members, union flag. 677
    parser tests pass, output identical. x86 warm p50 -13 to -44%.
  - _Requirements: 1, 3, 4, 5_

## x86 benchmark environment

- Host `benchmark-v4` = `php-bench-x86` = `i-0177ce6e446c17338`
  (m7i.xlarge, Amazon Linux 2023, x86_64).
- PHP 8.1.34, OPcache on, JIT tracing (`-d opcache.jit_buffer_size=64M`).
- The instance has no outbound internet (only SSM egress). Provisioning used a
  reverse SSH tunnel through the local Mac's proxy. SSH-over-SSM depends on a
  live Midway session (`mwinit`).

## Follow-ups

- Retained-memory measurement (warm both directions on one graph).
- Confirm cold behavior on the supported deployment architectures.
- Re-run the full `scripts/benchmarks/serde_benchmark.php` matrix for control
  protocols once XML/HTTP land.
