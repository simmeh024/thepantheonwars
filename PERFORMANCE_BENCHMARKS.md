# Performance benchmarks

Use the same label immediately before and after any optimization so results
are comparable. Run public endpoint checks from the repository root:

```powershell
.\tools\benchmark-performance.ps1 -Label before-visitor-optimization
.\tools\benchmark-performance.ps1 -Label after-visitor-optimization
```

The script runs each endpoint five times, prints min/average/max response
time, stores raw JSON under `benchmarks/`, and captures the API's `Server-Timing`
header. `app` is end-to-end PHP time; `db` is aggregate database time and its
description is the query count. Compare both cold and repeat runs, and retain
the generated JSON as the benchmark record.

For browser page-load measurements, use Chrome DevTools Lighthouse in an
incognito window with cache disabled. Capture Mobile and Desktop runs for the
home page, Worlds, Books, Community, and the authenticated Admin Home page.
Record Performance, LCP, CLS, INP, total transferred bytes, request count, and
the test date/network preset beside the matching JSON label. Repeat the same
profile after the change; do not compare different network presets.

Use System Status > SQL Performance for slow-query counts, average duration,
the slowest query, and total-cost fingerprints. The dashboard retains the
operational view while the script provides reproducible before/after response
timings. Re-run `analytics_explain_validation.sql` for any index change and
record its `r_rows`, selected key, and total time alongside the benchmark.
