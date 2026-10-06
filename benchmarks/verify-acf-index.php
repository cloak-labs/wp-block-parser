<?php

/** Optional integration check against an installed ACF Pro, via wp eval-file. */
if (!defined('WP_CLI') || !WP_CLI || !function_exists('acf_get_block_fields')) {
  throw new RuntimeException('Run through WP-CLI on a local WordPress site with ACF Pro and BlockParser installed.');
}
$environment = defined('WP_ENV') ? WP_ENV : wp_get_environment_type();
if (!in_array($environment, ['local', 'development'], true)) {
  throw new RuntimeException('This check is restricted to local/development installations.');
}

// Exercise groups, repeaters, flexible layouts and clones with ACF's real loaders.
// These definitions exist only in this CLI process; nothing is saved to the DB.
acf_add_local_field_group([
  'key' => 'group_block_parser_index_check', 'title' => 'Index check',
  'fields' => [
    ['key' => 'field_bp_index_title', 'name' => 'title', 'type' => 'text'],
    ['key' => 'field_bp_index_group', 'name' => 'group', 'type' => 'group', 'sub_fields' => [
      ['key' => 'field_bp_index_link', 'name' => 'link', 'type' => 'link'],
    ]],
    ['key' => 'field_bp_index_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => [
      ['key' => 'field_bp_index_enabled', 'name' => 'enabled', 'type' => 'true_false'],
    ]],
    ['key' => 'field_bp_index_flex', 'name' => 'flex', 'type' => 'flexible_content', 'layouts' => [
      ['key' => 'layout_bp_index_first', 'name' => 'first', 'label' => 'First', 'sub_fields' => [
        ['key' => 'field_bp_index_heading', 'name' => 'heading', 'type' => 'text'],
      ]],
      ['key' => 'layout_bp_index_second', 'name' => 'second', 'label' => 'Second', 'sub_fields' => [
        ['key' => 'field_bp_index_count', 'name' => 'count', 'type' => 'number'],
      ]],
    ]],
    ['key' => 'field_bp_index_clone', 'name' => 'cloned', 'type' => 'clone',
      'clone' => ['field_bp_index_group'], 'display' => 'seamless', 'prefix_name' => 1],
  ],
]);

$loadCalls = 0;
$loadFieldsCalls = 0;
$fieldFilter = static function ($field) use (&$loadCalls) {
  $loadCalls++;
  $field['block_parser_integration_marker'] = true;
  return $field;
};
$fieldsFilter = static function ($fields) use (&$loadFieldsCalls) {
  $loadFieldsCalls++;
  return $fields;
};
add_filter('acf/load_field', $fieldFilter, PHP_INT_MAX);
add_filter('acf/load_fields', $fieldsFilter, PHP_INT_MAX);

$names = $args ?? [];
if ($names === []) {
  $names = array_keys(array_filter(
    WP_Block_Type_Registry::get_instance()->get_all_registered(),
    static fn($block) => str_starts_with($block->name, 'acf/')
  ));
}
$loaded = acf_get_store('fields');
$originalData = $loaded->data;
$originalAliases = $loaded->aliases;
$originalLocal = acf_get_store('local-fields');
$collect = static function () use ($names) {
  $out = ['fixture' => acf_get_fields('group_block_parser_index_check')];
  foreach ($names as $name) {
    $out[$name] = acf_get_block_fields(['name' => $name]);
    if ($out[$name] === []) {
      throw new RuntimeException("No fields found for {$name}; register its field groups in the CLI context first.");
    }
  }
  return $out;
};

try {
  $loaded->reset();
  $before = $collect();
  $beforeCalls = [$loadCalls, $loadFieldsCalls];
  $loadCalls = $loadFieldsCalls = 0;
  $loaded->reset();
  $after = \CloakWP\BlockParser\Acf\LocalFieldIndex::run($collect);
  if ($before !== $after || $beforeCalls !== [$loadCalls, $loadFieldsCalls]) {
    throw new RuntimeException('Full field definitions or ACF filter call counts changed.');
  }
  if (acf_get_store('local-fields') !== $originalLocal) {
    throw new RuntimeException('The original ACF store was not restored.');
  }
  WP_CLI::log(json_encode([
    'identical' => true, 'blocks' => count($names),
    'load_field_calls' => $loadCalls, 'load_fields_calls' => $loadFieldsCalls,
    'definitions_sha256' => hash('sha256', serialize($after)),
    'store_restored' => true,
  ], JSON_PRETTY_PRINT));
} finally {
  $loaded->data = $originalData;
  $loaded->aliases = $originalAliases;
  remove_filter('acf/load_field', $fieldFilter, PHP_INT_MAX);
  remove_filter('acf/load_fields', $fieldsFilter, PHP_INT_MAX);
}
