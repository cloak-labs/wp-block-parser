<?php

declare(strict_types=1);

/** CLI-only extraction timings, excluding WP bootstrap/HTTP; requires dev dependencies. */
require dirname(__DIR__) . '/tests/bootstrap.php';
require ABSPATH . WPINC . '/version.php';

use CloakWP\BlockParser\Helpers\AttributeParser;
use CloakWP\BlockParser\Html\NativeHtmlAdapter;
use CloakWP\BlockParser\Html\TagProcessorHtmlAdapter;

$iterations = max(1, (int) ($argv[1] ?? 500));
$rounds = max(20, (int) ($argv[2] ?? 25));
$adapters = [
  'native' => new NativeHtmlAdapter(),
  'tag-processor' => new TagProcessorHtmlAdapter(),
  'tag-processor-source' => new TagProcessorHtmlAdapter(preserveSourceHtml: true),
];
$parsers = array_map(fn($adapter) => new AttributeParser($adapter), $adapters);
$fixtures = require __DIR__ . '/fixtures.php';
$samples = [];

foreach ($fixtures as $name => $fixture) {
  $expected = $parsers['native']->getAttributes($fixture['schema'], $fixture['attrs'], $fixture['html']);
  foreach ($parsers as $parser) {
    if ($expected !== $parser->getAttributes($fixture['schema'], $fixture['attrs'], $fixture['html'])) {
      throw new RuntimeException("Fixture output mismatch: $name");
    }
  }
}

// Interleave/reverse engines across rounds; discard three batch warmups.
for ($round = -3; $round < $rounds; $round++) {
  $names = array_keys($parsers);
  $rotation = ($round + 3) % count($names);
  $names = array_merge(array_slice($names, $rotation), array_slice($names, 0, $rotation));
  if ($round % 2 === 0) {
    $names = array_reverse($names);
  }
  foreach ($fixtures as $name => $fixture) {
    foreach ($names as $engine) {
      $start = hrtime(true);
      for ($i = 0; $i < $iterations; $i++) {
        $parsers[$engine]->getAttributes($fixture['schema'], $fixture['attrs'], $fixture['html']);
      }
      if ($round >= 0) {
        $samples[$name][$engine][] = (hrtime(true) - $start) / 1000 / $iterations;
      }
    }
  }
}

$summarize = static function (array $values): array {
  sort($values);
  $n = count($values);
  return ['medianUs' => ($values[intdiv($n - 1, 2)] + $values[intdiv($n, 2)]) / 2,
    'p95Us' => $values[(int) ceil($n * .95) - 1], 'batchMeanUs' => $values];
};
$results = [];
foreach ($samples as $fixture => $engines) {
  $results[$fixture] = array_map($summarize, $engines);
}

// Best-case ceiling: one attribute lookup with no node contract, selectors,
// inner HTML, scope indexing, schema validation or HTML5 recovery.
$primitive = [];
for ($round = -3; $round < $rounds; $round++) {
  $engines = $round % 2 === 0 ? ['native', 'wp-tag-processor'] : ['wp-tag-processor', 'native'];
  foreach ($engines as $engine) {
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
      if ($engine === 'native') {
        $value = $adapters['native']->parse($fixtures['image']['html'])->select('img')->attribute('src');
      } else {
        $processor = new WP_HTML_Tag_Processor($fixtures['image']['html']);
        $processor->next_tag('IMG');
        $value = $processor->get_attribute('src');
      }
      if ($value !== 'photo.jpg') {
        throw new RuntimeException('Primitive output mismatch');
      }
    }
    if ($round >= 0) {
      $primitive[$engine][] = (hrtime(true) - $start) / 1000 / $iterations;
    }
  }
}

echo json_encode(['php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'] ?? null,
  'opcacheCli' => ini_get('opcache.enable_cli'),
  'xdebugModes' => function_exists('xdebug_info') ? xdebug_info('mode') : [],
  'iterationsPerBatch' => $iterations, 'rounds' => $rounds, 'warmupBatches' => 3,
  'measurement' => 'Microseconds per extraction; samples are batch averages, p95 is across batch averages.',
  'outputParity' => true, 'results' => $results, 'primitive' => array_map($summarize, $primitive)],
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
