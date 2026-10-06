# BlockParser benchmarks

Use the local `@cloakwp/benchmark` HTTP runner to compare the same requests and
payloads with different adapters. `wordpress.php` registers authenticated,
development-only REST routes. `fixtures.php` defines fixed simple, nested-query,
and already-stored workloads; each request performs 100 extractions and returns
only the final result so its JSON remains stable.

Load `wordpress.php` from a temporary MU plugin in a local WordPress installation
with this package installed. Set WordPress's environment type or Bedrock's
`WP_ENV` to `local`/`development` through your existing configuration. Never
install this workload plugin on production. The runner authenticates an existing
administrator; it creates no posts.

In the agency workspace, create `wp/public/app/mu-plugins/000-wp-benchmark-block-parser.php`:

```php
<?php
require '/var/www/local-packages/cloakwp-block-parser/benchmarks/wordpress.php';
```

From the workspace root, with the existing Docker backend running:

```sh
pnpm benchmark:wp list --site=hyland --suite=all --json
printf 'pquery\n' > packages/composer/cloakwp-block-parser/benchmarks/.html-adapter
pnpm benchmark:wp run --site=hyland \
  --cases=packages/composer/cloakwp-block-parser/benchmarks/cases.json --only-custom \
  --runs=25 --warmup=3 --write-plan=/tmp/block-parser-plan.json \
  --output=/tmp/block-parser-pquery.json

printf 'native\n' > packages/composer/cloakwp-block-parser/benchmarks/.html-adapter
pnpm benchmark:wp run --site=hyland --cases=/tmp/block-parser-plan.json --only-custom \
  --runs=25 --warmup=3 --output=/tmp/block-parser-native.json
pnpm benchmark:wp compare /tmp/block-parser-pquery.json /tmp/block-parser-native.json \
  --metric=phpMs --json
```

The ignored `.html-adapter` file selects the global default without changing
URLs, auth, source files, or payloads. Removing the file restores native DOM.
Capture a separate baseline before changing extraction code and replay its
pinned plan afterward to distinguish the refactor's effect from the engine
choice. Also measure relevant real REST routes with the same procedure.

Keep edits paused during measurements and keep targets, authentication, profile,
cache policy, concurrency, warmups, fixture content, and runtime unchanged.
Inspect request errors, payload hashes, medians, and p95 before interpreting
results. `phpMs` includes WP bootstrap, 100 extractions, and serialization;
these are amplified HTTP workloads, not isolated per-parse timings. Already
stored attributes should avoid parsing with either adapter. Native HTML5 recovery
and pQuery serialization may differ on other content, so fixture parity alone
does not establish identical output for every site.

Remove the temporary MU plugin and `.html-adapter` file when finished. Keep
generated reports outside the package or in ignored `benchmarks/results/`.
For another WordPress installation, use the portable `wp-bench` CLI and its
WP-CLI configuration with these same custom cases; read the benchmark package's
README for setup and comparison requirements.

## Real REST routes and ACF lookups

Discover representative frontpage, page-list, page-detail and resolve-slug cases,
capture a baseline before editing, and replay the pinned plan. For example:

```sh
pnpm benchmark:wp list --site=hyland --suite=all --json
pnpm benchmark:wp run --site=hyland --suite=all \
  --case='rest:content:page:*,rest:cloakwp:frontpage,rest:core:resolve-slug' \
  --runs=25 --warmup=3 --write-plan=/tmp/parser-rest-plan.json \
  --output=/tmp/parser-rest-before.json
# Edit and test BlockParser; pause edits before replaying.
pnpm benchmark:wp run --site=hyland --suite=all \
  --cases=/tmp/parser-rest-plan.json --only-custom --runs=25 --warmup=3 \
  --output=/tmp/parser-rest-after.json
pnpm benchmark:wp compare /tmp/parser-rest-before.json /tmp/parser-rest-after.json \
  --metric=phpMs --json
```

Use `cloakwp/block_parser/index_acf_fields` to compare indexed and original ACF
lookups without changing the route or HTML engine. The index remains local to
BlockParser. Field definitions and ACF callbacks still run in full.

On a local/development site with ACF Pro installed, an optional CLI integration
check compares complete field trees and `acf/load_field(s)` call counts, including
group, repeater, flexible-content and clone fixtures. It restores loaded field
cache state and creates no database records:

```sh
wp eval-file /path/to/cloakwp-block-parser/benchmarks/verify-acf-index.php
# Optionally include registered site blocks:
wp eval-file /path/to/cloakwp-block-parser/benchmarks/verify-acf-index.php acf/hero acf/cards
```

Ensure the site's block field groups are registered in the CLI context before
including their names; some themes register them only for REST or editor requests.
The ordinary PHPUnit suite needs no ACF installation and checks registry updates,
same-size edits, scope restoration, exceptions, custom stores and opt-out behavior.

Inspect changed payloads directly. Avoiding discarded renders can change
WordPress's generated `wp-container-…-is-layout-N` class counters in later
`content.rendered` fields. Check that differences are limited to those counters
and that all structured block values and content remain intact.
