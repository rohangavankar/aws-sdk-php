# JSON encode plans: x86 before/after (merge-grade)

Per `docs/serde/benchmark-runbook.md`.

```
Benchmark host:     benchmark-v4 (php-bench-x86, i-0177ce6e446c17338)
Instance type:      m7i.xlarge
Architecture:       x86_64
PHP version:        8.1.34 (NTS, gcc)
JIT and OPcache:    OPcache enabled; JIT tracing, jit_buffer_size=64M
Benchmark command:  php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M \
                    -d opcache.jit=tracing benchmark/json-serde.php \
                    --direction=encode --items=<N> --iterations=300000

Baseline:           legacy path (JsonBody::buildLegacy) in the same binary
Candidate commit:   39a073782 (perf(serde): Compile JSON encode plans per shape)
Candidate tree:     clean except uncommitted --items harness option
```

Legacy and plans produced byte-identical wire output for every case.

## items=50 (representative collections), JIT on

| Case | Metric | Legacy | Plans | Change |
| --- | --- | ---: | ---: | ---: |
| SmallNoList | first-use | 4.12 us | 7.16 us | +73.5% slower |
| SmallNoList | p50 | 1.21 us | 582 ns | -52.1% faster |
| SmallNoList | p90 | 1.30 us | 633 ns | -51.3% faster |
| NestedLarge | first-use | 236.9 us | 101.4 us | -57.2% faster |
| NestedLarge | p50 | 76.36 us | 47.68 us | -37.6% faster |
| NestedLarge | p90 | 82.28 us | 51.83 us | -37.0% faster |
| MapHeavy | first-use | 24.74 us | 19.62 us | -20.7% faster |
| MapHeavy | p50 | 19.97 us | 12.91 us | -35.3% faster |
| MapHeavy | p90 | 21.25 us | 13.55 us | -36.3% faster |

## items=0 (empty collections, small-payload path), JIT on

| Case | Metric | Legacy | Plans | Change |
| --- | --- | ---: | ---: | ---: |
| SmallNoList | p50 | 1.22 us | 595 ns | -51.3% faster |
| NestedLarge | p50 | 629 ns | 436 ns | -30.7% faster |
| MapHeavy | p50 | 516 ns | 378 ns | -26.7% faster |
| SmallNoList | first-use | 4.57 us | 6.84 us | +49.5% slower |
| NestedLarge | first-use | 3.30 us | 7.46 us | +126.3% slower |
| MapHeavy | first-use | 3.03 us | 5.54 us | +83.0% slower |

## Notes

- Warm p50 improves 27 to 52 percent across all cases and both item counts.
- Nested-large first-use improves 57 percent at items=50 because plan
  compilation is amortized over the collection; at items=0 first-use regresses
  because plan compilation is paid with almost no encoding work to offset it.
  Absolute first-use cost is a few microseconds and repays within a few warm
  calls.
- The `PutItemRequest_Nested_L` regression from the design doc did not reproduce
  on x86: nested-large is 37 percent faster warm.
- Retained memory: not separately captured this run; the plan payload is one
  shallow plan object per shape per direction.
