# Tag processor adapter experiment

Measured October 9, 2026. Keep `NativeHtmlAdapter` as the default: the tag processor is usable through a hybrid adapter, but this implementation did not improve BlockParser extraction speed.

## What was implemented

`TagProcessorHtmlAdapter` handles `*`, tag names and comma-separated tag lists using WordPress tokenization. A flat element index stores decoded attributes, subtree boundaries and text ranges so nested queries reuse their scope. This adds bookkeeping that a standalone `next_tag()` lookup does not need.

Advanced CSS uses a lazy native DOM. Tables, raw-text elements, foreign content, unsupported tags, unbalanced markup and known tree-repair cases also fall back. Native-compatible `html()` uses the shared DOM for canonical serialization. The optional `preserveSourceHtml: true` mode slices original inner markup, avoiding that DOM on supported fragments but changing entity spelling, tag case and attribute quoting. Fallback nodes still serialize natively.

WordPress documents its [token stream API](https://developer.wordpress.org/reference/classes/wp_html_tag_processor/) and its limitations. PHP 8.4 already uses the native [Lexbor HTML5 parser](https://wiki.php.net/rfc/domdocument_html5_parser) and has [CSS selector support](https://wiki.php.net/rfc/dom_additions_84). Avoiding a tree does not automatically overcome the cost of doing tokenization, indexing and extraction in PHP.

## Isolated extraction results

Docker PHP 8.4.24, installed WordPress 6.7.1, CLI OPcache enabled, Xdebug disabled. Each engine runs 30 measured batches of 1,000 extractions, after three warmup batches. Engine order rotates/reverses. Output parity is checked before measurement. WP bootstrap, HTTP, database access and schema registration are outside the measured loops.

Median microseconds per extraction, in the order **native / compatible tag adapter / source-slicing tag adapter**:

- simple: 7.75 / 20.69 / 13.15 µs.
- nested: 174.33 / 251.44 / 215.60 µs.
- image: 8.00 / 9.54 / 9.47 µs.
- text: 4.65 / 11.85 / 11.80 µs.
- css: 17.67 / 19.30 / 19.28 µs.
- table: 10.73 / 13.65 / 13.68 µs.
- stored: 0.09 / 0.09 / 0.09 µs.

A bare `IMG`/`src` lookup takes 4.22 µs with native and 2.92 µs with `WP_HTML_Tag_Processor`: about 1.44× faster. This excludes reusable node contexts, inner HTML, CSS fallback, schema validation and HTML5 recovery. It is a best-case primitive comparison, not a usable full adapter benchmark.

Even source slicing is slower in all workloads that extract HTML. Already-stored attributes avoid parsing in every engine. The scripts retain every batch average and p95; that percentile describes batch averages, not individual call latency.

## HTTP and real-page checks

`@cloakwp/benchmark` ran the same pinned authenticated workloads on the local ridgeform site: 30 measured rounds, three warmups, concurrency one, normal caching and connection reuse, PHP/query/memory profiling. Each extraction request repeats its fixture 100 times and returns the final attributes. Native, compatible tag, source tag and a repeated native run were sequential; real routes were then measured sequentially with native and compatible tag.

Median PHP milliseconds per amplified extraction request, in the order **native / compatible tag / source tag / repeated native**:

- simple: 80.19 / 86.37 / 85.50 / 88.13 ms.
- nested: 106.94 / 177.76 / 173.22 / 114.77 ms.
- image: 80.24 / 83.06 / 83.50 / 89.25 ms.
- text: 79.27 / 83.79 / 85.52 / 87.80 ms.
- css: 80.37 / 81.71 / 81.88 / 87.03 ms.
- table: 79.88 / 81.70 / 82.27 / 89.89 ms.
- stored: 78.68 / 78.75 / 78.93 / 88.52 ms.

The gallery regression is large: compatible tag takes 177.76 ms versus 106.94 ms for the first native run and 114.77 ms for repeated native. Source slicing takes 173.22 ms. All runs have zero request failures, stable payload hashes and identical compared outputs. Native-compatible mode also raises allocated request peak memory from 14 MiB to 16 MiB on the simple/gallery workloads; these are whole-request allocator peaks, not isolated engine memory estimates.

The repeated native run drifted by roughly 7–13% on most cases and had noisy p95 values. Small HTTP differences therefore do not establish an adapter effect. Local FPM has Xdebug debug/profile modes configured with trigger-only activation; its PHP overhead differs from the Xdebug-disabled CLI measurements.

Real route median PHP milliseconds, **native / compatible tag**:

- `rest:cloakwp:frontpage`: 189.54 / 191.16 ms.
- `rest:core:resolve-slug`: 48.13 / 47.41 ms.
- `rest:content:page:detail`: 178.95 / 180.69 ms.

Real-page changes are within about 1.5%, with identical payload hashes and no errors. These routes show no meaningful adapter benefit; ACF work and other request costs dominate.

An earlier HTTP run was rejected because unrelated workspace source files changed during measurement. It is excluded from these results. Successful runs tracked only the parser Git repository using `--source-path=packages/composer/cloakwp-block-parser`, and reported no parser source changes. Concurrent changes elsewhere in WordPress are not ruled out by that narrower tracking, so the isolated, interleaved measurements provide the stronger engine comparison.

## Reproduce and inspect

The fixed fixtures and isolated runner live beside this report. See [benchmark instructions](README.md#tag-processor-experiments) for setup and source-selection choices. Use the same pinned cases, target, authentication, profiling and runtime settings for every HTTP run. The temporary local MU hook and adapter-choice file were removed after this experiment.

Raw local reports (gitignored):

- [Isolated measurements](../../../../benchmarks/wordpress/results/tag-adapter-isolated-final.json).
- [Native HTTP](../../../../benchmarks/wordpress/results/tag-adapter-native-controlled.json), [compatible tag HTTP](../../../../benchmarks/wordpress/results/tag-adapter-tag-controlled.json), [source tag HTTP](../../../../benchmarks/wordpress/results/tag-adapter-source-controlled.json), [repeated native](../../../../benchmarks/wordpress/results/tag-adapter-native-repeat-controlled.json).
- [Real routes native](../../../../benchmarks/wordpress/results/tag-adapter-site-native-controlled.json), [real routes tag](../../../../benchmarks/wordpress/results/tag-adapter-site-tag-controlled.json).
- [Compatible comparison](../../../../benchmarks/wordpress/results/tag-adapter-compare-tag.json), [source comparison](../../../../benchmarks/wordpress/results/tag-adapter-compare-source.json), [native drift](../../../../benchmarks/wordpress/results/tag-adapter-compare-repeat.json), [real routes comparison](../../../../benchmarks/wordpress/results/tag-adapter-compare-site.json).

Compatibility was checked with the full suite on PHP 8.4 / WordPress 6.7.1 and PHP 8.5 / the Composer WordPress test dependency. The adapter remains explicitly selectable; no production default or site configuration was changed.
