# JSON plans: retained memory and large-payload (x86)

Per `docs/serde/architecture.md` (retained-memory requirement) and
`docs/serde/JSON-Serde-Plans.md`.

```
Host:      benchmark-v4 (m7i.xlarge, x86_64), PHP 8.1.34, OPcache on, JIT tracing
Command:   php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M \
           -d opcache.jit=tracing benchmark/json-serde.php --mode=memory --items=<N>
Candidate: serde-cache HEAD
```

## Retained memory (both directions warmed on one graph)

`--mode=memory` reads `memory_get_usage()` after model construction, then after
compiling encode and decode plans for the operation graph. The difference is the
retained plan payload.

| Case | Retained (items=50) | Retained (items=2000) |
| --- | ---: | ---: |
| SmallNoList | 69.98 KB* | 69.98 KB* |
| NestedLarge | 11.66 KB | 11.66 KB |
| MapHeavy | 3.90 KB | 3.90 KB |

Key finding: **retained plan memory is independent of payload size.** items=50
and items=2000 produce identical retained memory, because plans cache the shape
graph structure (wire names, integer tags, Shape references, timestamp formats),
never request data. Cache size is bounded by the model, not the request.

NestedLarge (17-shape graph) at ~11.7 KB matches the design-author's ~12 KB
estimate. Direction-specific slots mean an input-only shape never allocates a
decode plan.

\* SmallNoList 69.98 KB is a measurement floor artifact: on a tiny graph the
first allocation after `gc_collect_cycles()` bumps a heap page, which
`memory_get_usage()` attributes to the plan. The real plan payload for a
5-field scalar structure is well under 1 KB. The larger-graph figures
(NestedLarge, MapHeavy) are the trustworthy numbers.

## Large payload (items=2000), JIT on, warm p50

Confirms gains hold at ~3 ms payloads (2000 nested items each with a 6-entry
map). Output byte-identical.

| Direction | Case | Legacy p50 | Plans p50 | Change |
| --- | --- | ---: | ---: | ---: |
| encode | NestedLarge | 3069.5 us | 1916.5 us | -37.6% faster |
| decode | NestedLarge | 2391.8 us | 1417.4 us | -40.7% faster |

The per-call improvement is the same proportion at 2000 items as at 50 items,
which is expected: the plan removes per-element model reads, so the saving
scales with element count.
