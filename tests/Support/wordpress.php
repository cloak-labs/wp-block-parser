<?php

declare(strict_types=1);

use CloakWP\BlockParser\Tests\Support\TestEnvironment;

function get_post($post): ?WP_Post
{
  return $post instanceof WP_Post ? $post : (TestEnvironment::$posts[$post] ?? null);
}

function get_post_meta($postId, $key, $single = false)
{
  TestEnvironment::$metaRequests[] = [$postId, $key, $single];
  return TestEnvironment::$meta[$postId][$key] ?? '';
}

function wp_get_attachment_image_src($id, $size = 'thumbnail', $icon = false)
{
  TestEnvironment::$attachmentRequests[] = [$id, $size];
  return TestEnvironment::$attachments[$id][$size] ?? false;
}

function is_admin(): bool
{
  return TestEnvironment::$isAdmin;
}

function setup_postdata($post): bool
{
  TestEnvironment::$setupPostdata[] = $post;
  return true;
}

function is_wp_error($value): bool
{
  return $value instanceof WP_Error;
}

// Translation is a site service; error strings don't need a loaded locale here.
function __($text, $domain = 'default'): string
{
  return $text;
}

// Blocks without assets still inspect site asset queues in WordPress 6.9+.
// Asset delivery is outside the parser's contract and requires a booted site.
function wp_styles(): object
{
  return (object) ['queue' => []];
}

function wp_scripts(): object
{
  return (object) ['queue' => []];
}

function wp_script_modules(): object
{
  return new class {
    public function get_queue(): array
    {
      return [];
    }
  };
}

// absint() lives in load.php, whose site bootstrap helpers are deliberately omitted.
function absint($value): int
{
  return abs((int) $value);
}
