<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests\Support;

use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Profiler;
use CloakWP\BlockParser\Html\PQueryHtmlAdapter;
use ReflectionProperty;
use WP_Block_Type_Registry;
use WP_Post;

/** In-memory doubles only for APIs that normally require a running site or ACF. */
final class TestEnvironment
{
  public static array $posts = [];
  public static array $meta = [];
  public static array $metaRequests = [];
  public static array $attachments = [];
  public static array $attachmentRequests = [];
  public static array $setupPostdata = [];
  public static bool $isAdmin = true;
  public static array $acfFields = [];
  public static array $acfDefinitions = [];
  public static array $acfDefinitionRequests = [];
  public static array $acfBlockRequests = [];
  public static array $acfMeta = [];
  public static array $acfFormattedValues = [];
  public static array $acfFormatRequests = [];

  public static function reset(): void
  {
    self::$posts = self::$meta = self::$metaRequests = [];
    self::$attachments = self::$attachmentRequests = self::$setupPostdata = [];
    self::$isAdmin = true;
    self::$acfFields = self::$acfDefinitions = self::$acfDefinitionRequests = [];
    self::$acfBlockRequests = self::$acfMeta = self::$acfFormattedValues = self::$acfFormatRequests = [];
    $GLOBALS['post'] = null;
    $GLOBALS['wp_filter'] = $GLOBALS['wp_filters'] = $GLOBALS['wp_actions'] = $GLOBALS['wp_current_filter'] = [];
    $GLOBALS['shortcode_tags'] = [];
    if (getenv('BLOCK_PARSER_HTML_ADAPTER') === 'pquery') {
      add_filter('cloakwp/block_parser/html_adapter', static fn() => new PQueryHtmlAdapter());
    }

    $registry = WP_Block_Type_Registry::get_instance();
    foreach (array_keys($registry->get_all_registered()) as $name) {
      $registry->unregister($name);
    }
    foreach (array_keys(get_all_registered_block_bindings_sources()) as $name) {
      unregister_block_bindings_source($name);
    }

    // Production registration is deliberately once per request. Each test models
    // a fresh request, including when PHPUnit runs tests in a different order.
    self::setStatic(BlockParser::class, 'initialized', false);
    foreach (['run' => null, 'depth' => 0, 'dispatchRegistered' => false, 'currentBlockName' => null, 'lastResult' => null] as $property => $value) {
      self::setStatic(Profiler::class, $property, $value);
    }
  }

  private static function setStatic(string $class, string $name, mixed $value): void
  {
    (new ReflectionProperty($class, $name))->setValue(null, $value);
  }

  public static function post(int $id, string $content): WP_Post
  {
    return self::$posts[$id] = new WP_Post((object) ['ID' => $id, 'post_content' => $content]);
  }
}
