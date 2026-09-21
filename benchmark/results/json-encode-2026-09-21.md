# JSON encode plans: before/after (local dev-grade)

Date: 2026-09-21
Host: local (arm64, macOS), PHP 8.1.34, Xdebug off, OPcache off, interpreter.
Harness: `benchmark/json-serde.php --iterations=200000`.
Comparison: `JsonBody::buildLegacy()` (old `format()` path) vs `JsonBody::build()`
(compiled plan path), identical Service, shape, and args in one process.

**Not merge evidence.** Merge numbers require the x86 `m7i.xlarge` runbook flow
in `docs/serde/benchmark-runbook.md`. These local numbers are for iterating.

Wire output was byte-identical between legacy and plans for every case.

| Case | Bytes | Metric | Legacy | Plans | Change |
| --- | ---: | --- | ---: | ---: | ---: |
| SmallNoList | 79 | first-use | 3.50 us | 5.71 us | +63.1% slower |
| SmallNoList | 79 | p50 | 1.88 us | 1.00 us | -46.7% faster |
| SmallNoList | 79 | p90 | 2.50 us | 1.17 us | -53.3% faster |
| NestedLarge | 9643 | first-use | 128.96 us | 91.38 us | -29.1% faster |
| NestedLarge | 9643 | p50 | 114.33 us | 83.71 us | -26.8% faster |
| NestedLarge | 9643 | p90 | 120.08 us | 86.79 us | -27.7% faster |
| MapHeavy | 4591 | first-use | 22.96 us | 16.58 us | -27.8% faster |
| MapHeavy | 4591 | p50 | 23.33 us | 14.75 us | -36.8% faster |
| MapHeavy | 4591 | p90 | 23.96 us | 14.75 us | -37.4% faster |

## Notes

- Warm p50/p90 improve 27 to 53 percent across all cases.
- Small scalar-only payloads pay a first-use penalty (~2 us absolute) for plan
  compilation, matching the documented small-payload cold-path tradeoff. Warm
  p50 still improves 47 percent, so the penalty is repaid within a few calls.
- The `PutItemRequest_Nested_L` encode regression documented in
  `docs/serde/JSON-Serde-Plans.md` did not reproduce here: the nested-large case
  is 27 percent faster warm. That earlier figure was the design author's local
  illustrative number. Confirm on the x86 instance with the compliance corpus
  before drawing a merge conclusion.
- Tests: `tests/Api/Serializer/` + `ComplianceTest` all pass (1252 tests), wire
  behavior preserved.
