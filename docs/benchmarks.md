# Benchmark protocol

PAM Native Nitro performance claims must be reproducible on physical devices. A
headline multiplier is not published from desktop JSON microbenchmarks.

## Required workloads

Measure at least:

- cold database open and last-20-message query;
- 1,000 and 10,000 row initial cache hydration;
- 100-row incremental synchronization;
- indexed chat timeline pagination;
- update of one optimistic message;
- process memory after reading 20 rows from a 100,000-row database.

Report median, p95, device, operating-system version, build mode, dataset
shape, and exact commit. Exclude APK installation and Android process launch
from database-only timing.

## Comparison rules

Compare equivalent indexed schemas, durability settings, result sizes, and
device state. WatermelonDB should run its current production build with its
recommended native adapter. PAM Native Nitro should run a release build.

The repository currently states an engineering target of at least 10× over
full JSON-cache hydration. It does not claim to be 10× faster than WatermelonDB
until the cross-framework device suite demonstrates that result.

Run the desktop baseline with:

```bash
composer benchmark
```

Desktop results expose serialization costs only; they are never substituted
for native mobile measurements.
