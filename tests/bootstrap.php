<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_readable($autoload)) {
  throw new RuntimeException('Install test dependencies with composer install before running the suite.');
}
require $autoload;

// Load WordPress's real block, hook, HTML, shortcode and REST schema APIs without
// booting wp-settings.php, connecting to a database, or loading any plugins.
$core = getenv('WP_TESTS_CORE_PATH') ?: dirname(__DIR__) . '/vendor/roots/wordpress-no-content';
if (!is_readable($core . '/wp-includes/blocks.php')) {
  throw new RuntimeException('WordPress test dependency is missing. Run composer install.');
}
define('ABSPATH', rtrim($core, '/') . '/');
define('WPINC', 'wp-includes');
define('WP_DEBUG', false);

foreach ([
  'plugin.php',
  'class-wp-error.php',
  'functions.php',
  'formatting.php',
  'kses.php',
  'rest-api.php',
  'shortcodes.php',
  'class-wp-post.php',
  'class-wp-block-parser.php',
  'class-wp-block-type.php',
  'class-wp-block-type-registry.php',
  'class-wp-block-list.php',
  'class-wp-block.php',
  'class-wp-block-supports.php',
  'class-wp-block-bindings-source.php',
  'class-wp-block-bindings-registry.php',
  'block-bindings.php',
  'blocks.php',
  'class-wp-http-response.php',
  'rest-api/class-wp-rest-response.php',
  'class-wp-token-map.php',
  'html-api/html5-named-character-references.php',
  'html-api/class-wp-html-attribute-token.php',
  'html-api/class-wp-html-span.php',
  'html-api/class-wp-html-doctype-info.php',
  'html-api/class-wp-html-text-replacement.php',
  'html-api/class-wp-html-decoder.php',
  'html-api/class-wp-html-tag-processor.php',
  'html-api/class-wp-html-unsupported-exception.php',
  'html-api/class-wp-html-active-formatting-elements.php',
  'html-api/class-wp-html-open-elements.php',
  'html-api/class-wp-html-token.php',
  'html-api/class-wp-html-stack-event.php',
  'html-api/class-wp-html-processor-state.php',
  'html-api/class-wp-html-processor.php',
] as $file) {
  // Doctype metadata was introduced after WordPress 6.7.
  if (is_readable(ABSPATH . WPINC . '/' . $file)) {
    require_once ABSPATH . WPINC . '/' . $file;
  }
}

require_once __DIR__ . '/Support/wordpress.php';
