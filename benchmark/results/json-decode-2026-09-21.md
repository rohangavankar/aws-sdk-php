# JSON decode plans: before/after (local dev-grade)

Date: 2026-09-21
Host: local (arm64, macOS), PHP 8.1.34, Xdebug off, OPcache off, interpreter.
Harness: `benchmark/json-serde.php --direction=decode --iterations=200000`.
Comparison: `JsonParser::parseLegacy()` (old `parse()` path) vs
`JsonParser::parse()` (compiled plan path), identical shape and parsed input in
one process. Decode input is the encoded body decoded back to an array.

**Not merge evidence.** Merge numbers require the x86 `m7i.xlarge` runbook flow
in `docs/serde/benchmark-runbook.md`.

Decoded output was identical (md5 of serialized result) between legacy and plans
for every case.

| Case | Metric | Legacy | Plans | Change |
| --- | --- | ---: | ---: | ---: |
| SmallNoList | first-use | 8.46 us | 6.58 us | -22.2% faster |
| SmallNoList | p50 | 3.38 us | 2.67 us | -21.0% faster |
| SmallNoList | p90 | 3.58 us | 2.75 us | -23.2% faster |
| NestedLarge | first-use | 89.79 us | 69.71 us | -22.4% faster |
| NestedLarge | p50 | 94.58 us | 64.83 us | -31.5% faster |
| NestedLarge | p90 | 99.83 us | 69.38 us | -30.5% faster |
| MapHeavy | first-use | 23.42 us | 14.17 us | -39.5% faster |
| MapHeavy | p50 | 23.25 us | 11.46 us | -50.7% faster |
| MapHeavy | p90 | 23.75 us | 11.83 us | -50.2% faster |

## Notes

- Warm p50 improves 21 to 51 percent across all cases. Map-heavy decode gains
  the most, matching the encode pattern: integer-tag dispatch and cached value
  descriptors avoid re-resolving the value shape on every entry.
- No small-payload first-use regression here, unlike encode. Decode already
  builds a fresh result array, so the plan's ordered member list adds little
  cold-path cost.
- Tests: all `tests/Api/Parser/` pass (677 tests), decode output preserved
  including modeled member order, union Unknown fallback, null map skipping,
  and timestamp/blob handling.
