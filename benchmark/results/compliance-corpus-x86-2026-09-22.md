# Full compliance-corpus benchmark (x86)

The runbook's primary benchmark: `scripts/benchmarks/serde_benchmark.php` over
the C2J protocol compliance cases across all 5 protocols. This exercises real
service models and many operations, unlike the focused `benchmark/json-serde.php`.

```
Host:            benchmark-v4 (m7i.xlarge, x86_64)
PHP:             8.1.34, OPcache on, JIT tracing (jit_buffer_size=64M)
Command:         php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M \
                 -d opcache.jit=tracing scripts/benchmarks/serde_benchmark.php \
                 --min-iterations=10000 --max-iterations=10000 \
                 --max-seconds=30 --warmup-pct=50
Cases:           70 per run

Baseline:        foundation commit 49970a87e (pre JSON plans), legacy path
Baseline result: serde-2026-09-22_150747.JIT
Candidate:       serde-cache HEAD ea2a691df (JSON encode + decode plans)
Candidate result: serde-2026-09-22_150533.JIT
```

Raw result JSON for both runs is preserved under
`benchmark/results/x86-compliance-corpus/`.

## Weighted p50 by protocol group

Weighted p50 = (sum candidate p50 / sum baseline p50 - 1) * 100 across the
group's cases. Negative is faster.

| Group | Cases | Weighted p50 |
| --- | ---: | ---: |
| JSON RPC | 21 | **-35.9% faster** |
| REST-JSON | 10 | -0.1% (flat) |
| REST-XML (control) | 10 | +0.3% |
| Query (control) | 10 | +0.2% |
| CBOR (control) | 19 | +0.1% |

## Interpretation

- **JSON RPC -35.9%** is the target result. JSON RPC serialization and parsing
  run entirely through `JsonBody` and `JsonParser`, which the plans replace. The
  aggregate blends serialize and deserialize cases; the decode gains (see the
  focused decode results, warm p50 -13 to -44%) dominate.
- **REST-JSON flat (-0.1%)** is expected. REST-JSON request/response time is
  dominated by HTTP bindings (labels, headers, URI, payload selection), which
  are Lukas's workstream and unchanged here. The JSON body codec is a small
  fraction of a REST-JSON operation, so body-plan gains do not move the
  weighted total. The design doc notes REST-JSON's large gains arrive after the
  HTTP binding plans land.
- **Controls within +-0.3%** confirm no unintended regression in protocols the
  JSON plans do not touch (REST-XML, Query, CBOR). This is the control-movement
  check the runbook requires.

## Caveats

- The harness measures warmed performance only (reused serializers/parsers). It
  does not measure first-use, plan-compile cost, or retained memory; those are
  covered by the focused benchmark and the memory results doc.
- Case ids can repeat across serialize and deserialize; the per-group weighted
  figure aggregates both directions for that protocol.
- Run once per side. The runbook recommends a second run to confirm; the control
  groups sitting within noise is a good signal the single run is stable, but a
  confirming run is a reasonable follow-up before final sign-off.
