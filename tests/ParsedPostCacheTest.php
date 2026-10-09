<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use CloakWP\BlockParser\Tests\Support\RecordingTransformer;
use CloakWP\BlockParser\Tests\Support\TestEnvironment;
use RuntimeException;

final class ParsedPostCacheTest extends ParserTestCase
{
  public function testCachedConsumersShareOneTransformationAndOrdinaryParsingRemainsUncached(): void
  {
    $post = TestEnvironment::post(42, '<!-- wp:paragraph --><p>Once.</p><!-- /wp:paragraph -->');
    $calls = 0;
    add_filter('cloakwp/block', static function ($block) use (&$calls) {
      $calls++;
      return $block;
    });
    $parser = new BlockParser();
    $first = $parser->parseBlocksFromPostCached($post);
    self::assertSame($first, $parser->parseBlocksFromPostCached(42));
    self::assertSame(1, $calls);
    $parser->parseBlocksFromPost(42);
    self::assertSame(2, $calls);
    $parser->clearParsedPostCache();
    $parser->parseBlocksFromPostCached(42);
    self::assertSame(3, $calls);
  }

  public function testContentChangesAndTransformerChangesInvalidateCachedOutput(): void
  {
    $post = TestEnvironment::post(42, '<!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph -->');
    $parser = new BlockParser();
    self::assertSame('Before.', $parser->parseBlocksFromPostCached(42)[0]['attrs']['content']);
    $post->post_content = '<!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph -->';
    self::assertSame('After.', $parser->parseBlocksFromPostCached(42)[0]['attrs']['content']);
    $parser->registerTransformer(RecordingTransformer::class);
    self::assertSame(42, $parser->parseBlocksFromPostCached(42)[0]['postId']);
  }

  public function testEachPostHasItsOwnCachedTree(): void
  {
    TestEnvironment::post(1, '<!-- wp:paragraph --><p>First.</p><!-- /wp:paragraph -->');
    TestEnvironment::post(2, '<!-- wp:paragraph --><p>Second.</p><!-- /wp:paragraph -->');
    $parser = new BlockParser();
    self::assertSame('First.', $parser->parseBlocksFromPostCached(1)[0]['attrs']['content']);
    self::assertSame('Second.', $parser->parseBlocksFromPostCached(2)[0]['attrs']['content']);
    self::assertSame('First.', $parser->parseBlocksFromPostCached(1)[0]['attrs']['content']);
    self::assertSame([], $parser->parseBlocksFromPostCached(404));
  }

  public function testCyclicPatternsTerminateWithoutDroppingUnrelatedContent(): void
  {
    TestEnvironment::post(1, '<!-- wp:paragraph --><p>Parent.</p><!-- /wp:paragraph --><!-- wp:block {"ref":20} /-->');
    TestEnvironment::post(20, '<!-- wp:paragraph --><p>Pattern.</p><!-- /wp:paragraph --><!-- wp:block {"ref":1} /-->');
    $parser = new BlockParser();
    self::assertSame(['Parent.', 'Pattern.'], array_column(array_column($parser->parseBlocksFromPostCached(1), 'attrs'), 'content'));
    self::assertSame(['Pattern.', 'Parent.'], array_column(array_column($parser->parseBlocksFromPostCached(20), 'attrs'), 'content'));
  }

  public function testReferencedPostAndTermWritesInvalidateCachedTrees(): void
  {
    TestEnvironment::post(1, '<!-- wp:block {"ref":20} /-->');
    $pattern = TestEnvironment::post(20, '<!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph -->');
    $parser = new BlockParser();
    self::assertSame('Before.', $parser->parseBlocksFromPostCached(1)[0]['attrs']['content']);
    $pattern->post_content = '<!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph -->';
    wp_cache_set('last_changed', 'updated-post', 'posts');
    self::assertSame('After.', $parser->parseBlocksFromPostCached(1)[0]['attrs']['content']);
    $calls = 0;
    add_filter('cloakwp/block', static function ($block) use (&$calls) {
      $calls++;
      return $block;
    });
    wp_cache_set('last_changed', 'updated-term', 'terms');
    $parser->parseBlocksFromPostCached(1);
    self::assertGreaterThan(0, $calls);
  }

  public function testSamePostIdsOnDifferentSitesCannotShareCachedOutput(): void
  {
    TestEnvironment::post(1, '<!-- wp:paragraph --><p>Shared content.</p><!-- /wp:paragraph -->');
    add_filter('cloakwp/block', static function ($block) {
      $block['site'] = get_current_blog_id();
      return $block;
    });
    $parser = new BlockParser();
    self::assertSame(1, $parser->parseBlocksFromPostCached(1)[0]['site']);
    TestEnvironment::$siteId = 2;
    self::assertSame(2, $parser->parseBlocksFromPostCached(1)[0]['site']);
    TestEnvironment::$siteId = 1;
    self::assertSame(1, $parser->parseBlocksFromPostCached(1)[0]['site']);
  }

  public function testAnExceptionDoesNotLeaveThePostMarkedAsParsing(): void
  {
    TestEnvironment::post(1, '<!-- wp:paragraph --><p>Retry.</p><!-- /wp:paragraph -->');
    $fail = static function (): never {
      throw new RuntimeException('Formatting failed.');
    };
    add_filter('cloakwp/block', $fail);
    $parser = new BlockParser();
    try {
      $parser->parseBlocksFromPostCached(1);
      self::fail('The formatter should throw.');
    } catch (RuntimeException $error) {
      self::assertSame('Formatting failed.', $error->getMessage());
    }
    remove_filter('cloakwp/block', $fail);
    self::assertSame('Retry.', $parser->parseBlocksFromPostCached(1)[0]['attrs']['content']);
  }
}
