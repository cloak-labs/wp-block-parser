<?php

declare(strict_types=1);

use CloakWP\BlockParser\Tests\Support\TestEnvironment;

// These doubles model ACF's boundary, not its implementation. ACF Pro is not
// distributed with this OSS suite. Each ACF test loads them in its own process.
function acf_register_block_type($args): void
{
}

function acf_get_block_id($data): string
{
  return 'block_test';
}

function acf_setup_meta($data, $blockId): void
{
  TestEnvironment::$acfMeta[$blockId] = $data;
}

function acf_get_field($key)
{
  TestEnvironment::$acfDefinitionRequests[] = $key;
  return TestEnvironment::$acfFields[$key] ?? false;
}

function acf_get_block_fields($payload)
{
  TestEnvironment::$acfBlockRequests[] = $payload;
  return TestEnvironment::$acfDefinitions;
}

function acf_get_valid_post_id($blockId): string
{
  return $blockId;
}

function acf_maybe_get_field($selector, $blockId)
{
  $key = TestEnvironment::$acfMeta[$blockId]['_' . $selector] ?? $selector;
  return TestEnvironment::$acfFields[$key] ?? false;
}

function acf_get_value($blockId, $field)
{
  return TestEnvironment::$acfMeta[$blockId][$field['name']] ?? null;
}

function acf_format_value($value, $blockId, $field)
{
  TestEnvironment::$acfFormatRequests[] = [$value, $blockId, $field];
  return TestEnvironment::$acfFormattedValues[$field['name']] ?? $value;
}
