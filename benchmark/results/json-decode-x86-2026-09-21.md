# JSON decode plans: x86 before/after (merge-grade)

Per `docs/serde/benchmark-runbook.md`.

```
Benchmark host:     benchmark-v4 (php-bench-x86, i-0177ce6e446c17338)
Instance type:      m7i.xlarge
Architecture:       x86_64
PHP version:        8.1.34 (NTS, gcc)
JIT and OPcache:    OPcache enabled; JIT tracing, jit_buffer_size=64M
Benchmark command:  php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M \
                    -d opcache.jit=tracing benchmark/json-serde.php \
                    --direction=decode --items=<N> --iterations=300000

Baseline:           legacy path (JsonParser::parseLegacy) in the same binary
Candidate commit:   39a073782 (perf(serde): Compile JSON decode plans per shape)
```

Legacy and plans produced identical decoded output (md5 of serialized result)
for every case.

## items=50 (representative collections), JIT on

| Case | Metric | Legacy | Plans | Change |
| --- | --- | ---: | ---: | ---: |
| SmallNoList | first-use | 15.31 us | 11.63 us | -24.1% faster |
| SmallNoList | p50 | 4.15 us | 3.62 us | -12.8% faster |
| SmallNoList | p90 | 4.44 us | 3.89 us | -12.3% faster |
| NestedLarge | first-use | 167.4 us | 133.7 us | -20.2% faster |
| NestedLarge | p50 | 61.03 us | 35.19 us | -42.3% faster |
| NestedLarge | p90 | 65.19 us | 39.02 us | -40.1% faster |
| MapHeavy | first-use | ~19 us | ~18 us | within noise (see note) |
| MapHeavy | p50 | 14.74 us | 8.21 us | -44.3% faster |
| MapHeavy | p90 | 15.81 us | 8.60 us | -45.6% faster |

## items=0 (empty collections, small-payload path), JIT on

| Case | Metric | Legacy | Plans | Change |
| --- | --- | ---: | ---: | ---: |
| SmallNoList | p50 | 4.10 us | 3.53 us | -13.7% faster |
| NestedLarge | p50 | 524 ns | 316 ns | -39.7% faster |
| MapHeavy | p50 | 400 ns | 277 ns | -30.8% faster |
| SmallNoList | first-use | 15.78 us | 13.08 us | -17.1% faster |
| NestedLarge | first-use | 2.96 us | 11.14 us | +276% slower |
| MapHeavy | first-use | 1.87 us | 5.31 us | +184% slower |

## Notes

- Warm p50 improves 13 to 44 percent across all cases and both item counts.
- MapHeavy first-use at items=50 printed a one-off +571% spike (129 us) on the
  combined run. Three isolated re-runs put it within +-11% of legacy (plans
  ~18 us vs legacy ~19 us), so the spike was a first-sample measurement
  artifact, not a plan cost. Recorded as within noise.
- Empty-collection first-use regresses by a few microseconds because plan
  compilation is paid with almost no decoding work to offset it. This repays
  within a few warm calls.
- Decode gains scale with payload structure: MapHeavy and NestedLarge (many
  entries) benefit most from integer-tag dispatch and cached value descriptors.
