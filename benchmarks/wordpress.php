<?php

/** Development-only REST workloads for @cloakwp/benchmark; never load in production. */
if (!defined('ABSPATH')) {
  return;
}
$environment = defined('WP_ENV') ? WP_ENV : wp_get_environment_type();
if (!in_array($environment, ['local', 'development'], true)) {
  return;
}

// This ignored file lets the same pinned URLs exercise both adapters.
$choice = is_readable(__DIR__ . '/.html-adapter') ? trim(file_get_contents(__DIR__ . '/.html-adapter')) : 'native';
$adapterClass = $choice === 'pquery'
  ? \CloakWP\BlockParser\Html\PQueryHtmlAdapter::class
  : \CloakWP\BlockParser\Html\NativeHtmlAdapter::class;
if (class_exists($adapterClass)) {
  add_filter('cloakwp/block_parser/html_adapter', fn() => new $adapterClass(), PHP_INT_MAX);
}

add_action('rest_api_init', function () {
  register_rest_route('block-parser-benchmark/v1', '/attributes', [
    'methods' => 'GET',
    'permission_callback' => fn() => current_user_can('manage_options'),
    'args' => ['fixture' => ['default' => 'simple', 'enum' => ['simple', 'nested', 'stored']]],
    'callback' => function (\WP_REST_Request $request) {
      $fixtures = require __DIR__ . '/fixtures.php';
      $fixture = $fixtures[$request['fixture']];
      $parser = new \CloakWP\BlockParser\Helpers\AttributeParser();
      $result = [];
      // Enough repetitions to expose extraction costs alongside WP bootstrap.
      for ($i = 0; $i < 100; $i++) {
        $result = $parser->getAttributes($fixture['schema'], $fixture['attrs'], $fixture['html']);
      }
      return ['iterations' => 100, 'attributes' => $result];
    },
  ]);
});
