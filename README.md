# CloakWP Block Parser

CloakWP Block Parser is a PHP library designed to parse and transform Gutenberg and ACF blocks into structured objects/JSON. This package is part of the CloakWP ecosystem, but can be used independently or as a dependency of your own plugins/themes/packages.

## Features

- Parse Gutenberg (core) blocks into objects/JSON
- Parse Advanced Custom Fields (ACF) blocks into objects/JSON
- Extensible architecture for custom block transformers
- Filters for modifying parsed block data

## Motivation

WordPress block content is stored as HTML strings in the database rather than as structured data (e.g. JSON). This is particularly a problem for decoupled/headless projects where you may wish to render things your own way; to do so, you need the blocks in JSON/structured object form -- turns out this is surprisingly difficult to achieve. CloakWP Block Parser simplifies this process by providing a clean and organized way to parse and transform blocks content into structured data.

## Installation

You can install this package via Composer:

```bash
composer require cloakwp/block-parser
```

Requires **PHP 8.4+** with the **DOM extension**. HTML extraction uses PHP's
native `Dom\HTMLDocument` HTML5 parser and CSS selectors. WordPress's
`parse_blocks()` continues to parse block delimiters and nesting.

## Example Output

<details>
 <summary>Structured data</summary>
 
```json
[
  {
    "name": "core/paragraph",
    "type": "core",
    "attrs": {
      "content": "Contact us via phone <a href=\"tel:123-456-7890\">(123) 456-7890</a> or email <a href=\"mailto:info@example.com\">info@example.com</a>.",
      "dropCap": false
    }
  },
  {
    "name": "acf/hero",
    "type": "acf",
    "attrs": {
      "style": {
        "spacing": {
          "margin": {
            "bottom": "var:preset|spacing|60"
          }
        }
      },
      "className": "pb-8 md:pb-10",
      "align": "full",
      "backgroundColor": "bg-root-dim"
    },
    // ACF field data:
    "data": {
      "hero_style": "image_right",
      "image": {
        "medium": {
          "src": "http://localhost/app/uploads/sites/8/2024/08/example-300x200.jpeg",
          "width": 300,
          "height": 200
        },
        "large": {
          "src": "http://localhost/app/uploads/sites/8/2024/08/example-1024x683.jpeg",
          "width": 1024,
          "height": 683
        },
        "full": {
          "src": "http://localhost/app/uploads/sites/8/2024/08/example.jpeg",
          "width": 1620,
          "height": 1080
        },
        "alt": "example alt description",
        "caption": "example caption"
      },
      "eyebrow": "WordPress Experts",
      "h1": "Build your dream website.",
      "subtitle": "Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.",
      "cta_buttons": false,
      "show_social_proof": false
    }
  }
]
```
</details>

## Usage

### Basic Usage

```php
use CloakWP\BlockParser\BlockParser;

$postId = 123;
$blockParser = new BlockParser();
$blockData = $blockParser->parseBlocksFromPost($postId);
```

### HTML adapters

The default `NativeHtmlAdapter` parses UTF-8 HTML once per block, only when an
attribute needs HTML. Nested `query` sources reuse node contexts. Stored values,
defaults, and post meta do not create a DOM.

Inject an implementation of `HtmlAdapterInterface` to select an engine for one
parser, including its nested blocks and synced patterns:

```php
use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Html\NativeHtmlAdapter;
use CloakWP\BlockParser\Html\PQueryHtmlAdapter;

$parser = new BlockParser(new NativeHtmlAdapter());
$legacyParser = new BlockParser(new PQueryHtmlAdapter());
```

pQuery is optional in production. Install `tburry/pquery:^1.1` explicitly to use
`PQueryHtmlAdapter`; it is already included in development dependencies for
tests and comparisons. The same adapter can be passed to
`new AttributeParser($adapter)` for standalone extraction.

For parser instances created by integrations, choose the default through a filter:

```php
add_filter('cloakwp/block_parser/html_adapter', fn() => new PQueryHtmlAdapter());
```

Explicit constructor injection takes precedence over the filter. The filter
also applies to standalone `AttributeParser` instances and must return an
`HtmlAdapterInterface`. `BlockParser::getHtmlAdapter()` exposes the chosen adapter
to custom transformers.

An adapter implements `parse(string): HtmlNodeInterface`. Nodes provide
`select()`, `selectAll()`, `attribute()`, `html()`, `text()`, and `tagName()`.
Selections return elements in document order and include the current element
when it matches, so fields inside `query` sources can read the query row itself.
Omitting a selector reads the first element. Missing matches/attributes return
null, while empty values are omitted by `AttributeParser`.

Both adapters support `attribute`, `html`, `rich-text`, `text`, `tag`, and nested
`query` sources. Table cells retain their context, including `th` tags and rich
text without selectors. Nested meta sources retain the post ID, and omitted
query rows produce dense JSON arrays.

Native HTML5 recovery and serialization can differ from pQuery on malformed
markup, entity escaping, whitespace, and advanced selectors. Native markup
preserves entity escaping and `text()` preserves text-node whitespace; pQuery
retains its legacy serialization and whitespace behavior. Review payload changes
when switching an existing installation. Invalid native CSS selectors throw
`DOMException` rather than silently producing missing attributes.

### Extending

<details>
 <summary>Custom Transformers</summary>

The BlockParser uses the built-in core function, `parse_blocks()`, to initially parse the blocks, but unfortunately this function doesn't do the full job. So, we extend the basic built-in parsing with block "transformers".

By default, the BlockParser uses the following transformers:

- CoreBlockTransformer (for Gutenberg core blocks)
- ACFBlockTransformer (for ACF blocks)

You can extend the BlockParser by registering your own custom block transformers for certain block types, or to override the default transformers:

```php
use CloakWP\BlockParser\Transformers\AbstractBlockTransformer;

// Extend the abstract base to inherit getType() and the parser constructor.
// The static $type property selects the block type this transformer handles:
class MyCustomACFBlockTransformer extends AbstractBlockTransformer
{
  protected static string $type = 'acf'; // this will override the default ACFBlockTransformer

  public function transform(WP_Block $block, int|null $postId = null): array
  {
    // your custom data transformation code here -- whatever you return here will be the final block data
  }
}

// now register the transformer with your BlockParser instance:
$blockParser = new BlockParser();
$blockParser->registerTransformer(MyCustomACFBlockTransformer::class);
```

If in the above example you want to add a transformer for some custom block type, you just specify a custom value for the static `$type` property, and then extend the `BlockParser` class and override the `determineBlockType()` method to add your logic for determining when a block is of your custom type; for example:

```php
class MyCustomBlockParser extends BlockParser
{
  protected function determineBlockType(WP_Block $block): string
  {
    if ($block->name === 'my-plugin/custom-block') {
      return 'custom';
    }

    return parent::determineBlockType($block);
  }
}

class MyCustomBlockTransformer extends AbstractBlockTransformer
{
  protected static string $type = 'custom';

  public function transform(WP_Block $block, int|null $postId = null): array
  {
    // ..
  }
}

$postId = 123;
$blockParser = new MyCustomBlockParser();
$blockParser->registerTransformer(MyCustomBlockTransformer::class);

// Blocks named 'my-plugin/custom-block' now use MyCustomBlockTransformer.
$blockData = $blockParser->parseBlocksFromPost($postId);
```

</details>

<details>
 <summary>Filter Hooks</summary>

Besides creating custom transformers, you can also modify parsed block data using filters. These filters are applied after the block has been transformed by the appropriate transformer, but before the block is returned:

```php
add_filter('cloakwp/block', function(array $parsedBlock, WP_Block $wpBlock) {
  // modify $parsedBlock here
  return $parsedBlock;
}, 10, 2);
```

The `cloakwp/block` filter accepts two modifiers, `name` and `type`, for more granular targeting:

```php
add_filter('cloakwp/block/name=core/paragraph', function(array $parsedBlock, WP_Block $wpBlock) {
  // modify $parsedBlock here
  return $parsedBlock;
}, 10, 2);

add_filter('cloakwp/block/type=acf', function(array $parsedBlock, WP_Block $wpBlock) {
  // modify $parsedBlock here
  return $parsedBlock;
}, 10, 2);
```

After every ACF field on a block has been formatted — and **before** `cloakwp/block` — you can transform the complete parsed block together with its field definitions:

```php
add_filter('cloakwp/block/data', function (array $parsedBlock, array $fieldDefinitions, WP_Block $wpBlock, ?int $postId) {
  // $fieldDefinitions is the ACF field-object tree for the block (nested `sub_fields` included),
  // even for fields Gutenberg omitted because they were empty.
  return $parsedBlock;
}, 10, 4);
```

Use this hook to inject derived data (for example resolving a Query group into `data.items`) without replacing a block-level `cloakwp/block` callback. `cloakwp/block` runs after this filter.

You can also filter ACF field values within ACF blocks using the `cloakwp/block/field` filter:

```php
add_filter('cloakwp/block/field', function(mixed $fieldValue, array $fieldObject) {
  // modify $fieldValue here
  return $fieldValue;
}, 10, 2);
```

The `cloakwp/block/field` filter accepts three modifiers, `name` (i.e. ACF field name), `type` (i.e. ACF field type), and `blockName` (i.e. ACF block name), for more granular targeting:

```php

add_filter('cloakwp/block/field/name=my_acf_field', function(mixed $fieldValue, array $fieldObject) {
  // modify $fieldValue here
  return $fieldValue;
}, 10, 2);

add_filter('cloakwp/block/field/type=image', function(mixed $fieldValue, array $fieldObject) {
  // modify $fieldValue here
  return $fieldValue;
}, 10, 2);

add_filter('cloakwp/block/field/blockName=acf/hero-section', function(mixed $fieldValue, array $fieldObject) {
  // modify $fieldValue here
  return $fieldValue;
}, 10, 2);
```

</details>

## Tests

From this package's directory, using PHP 8.4+ and Composer:

```bash
composer install
composer test
composer test:pquery
```

Run a focused test or check that tests are independent of execution order:

```bash
composer test -- --filter BlockParserTest
composer test -- --order-by=random --random-order-seed=42
```

The suite covers serialized Gutenberg content, nested blocks, synced patterns
(including deleted references), custom transformers, WordPress block bindings,
hook modifiers and filter order, HTML/query/meta attribute sources, image
dimensions, ACF formatting and nested groups, and profiling response headers.

WordPress core is a development-only Composer dependency. The bootstrap loads
its real parser, block objects, hooks, REST schema validation, HTML processing,
and shortcodes without starting a site. In-memory doubles provide posts, meta,
attachments, asset queues, and ACF APIs. ACF tests run in separate processes so
their functions cannot affect tests for installations without ACF. No database,
Docker, or ACF Pro license is needed. These tests verify this package's handling
of ACF results; they do not test ACF Pro's own formatting or a full site's plugins.

`tests/Fixtures/` contains saved Gutenberg markup, and `tests/Support/` contains
the isolated test environment. To check against another local WordPress version,
set `WP_TESTS_CORE_PATH` to its core directory (the directory containing
`wp-includes`) before running `composer test`.

GitHub Actions runs both adapters with PHPUnit 11 on PHP 8.4 and 8.5, plus PHPUnit
12 on PHP 8.4. Warnings, risky tests, empty suites, and deprecations originating in this
package fail the run. Dependency deprecations are excluded from that check.

With Xdebug or PCOV enabled, generate coverage for `src/`:

```bash
composer test -- --coverage-text --coverage-html coverage/html
```

## Performance comparisons

Structured parsing avoids preliminary HTML rendering unless a block has WordPress
bindings. HTML requested through `cloakwp/block/include_rendered` is still rendered
by its transformer. If a custom render callback computes attributes that your
integration needs, opt that block into the preliminary render:

```php
add_filter('cloakwp/block/render_for_attributes', function ($render, $block, $postId) {
  return $render || $block->name === 'my-plugin/computed-attributes';
}, 10, 3);
```

ACF's local registry repeatedly scans every registered field to find each parent's
children. BlockParser indexes those parent lookups while transforming a block,
including rendering, nested blocks, and block filters. ACF still loads complete
definitions, expands clones and flexible layouts, and runs its normal field and
value filters. The original registry is restored afterward, including on exceptions.
The index is reused within the request and refreshed when registry data changes.
It does not cache parsed post data or REST responses.

This optimization uses ACF's internal `ACF_Data` registry. Custom store subclasses
and unsupported query shapes use ACF's original behavior. To disable it for an
integration or compare the same routes with the original lookups:

```php
add_filter('cloakwp/block_parser/index_acf_fields', '__return_false');
```

See [benchmarks/README.md](benchmarks/README.md) for fixed extraction workloads
and pinned before/after comparisons with `@cloakwp/benchmark`. They supplement
real content routes without changing site content or runtime settings.
