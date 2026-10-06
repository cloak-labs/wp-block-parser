<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Tests\Support\CustomTransformer;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use CloakWP\BlockParser\Tests\Support\RecordingTransformer;
use CloakWP\BlockParser\Tests\Support\TestEnvironment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Block;

final class BlockParserTest extends ParserTestCase
{
  public function testParsesSerializedContentAndPreservesNestedBlockOrder(): void
  {
    TestEnvironment::post(42, $this->fixture('nested-blocks'));
    add_filter('cloakwp/block/include_rendered', '__return_false');

    $result = (new BlockParser())->parseBlocksFromPost(42);

    $this->assertSame([
      ['name' => 'core/heading', 'type' => 'core', 'attrs' => ['level' => 3, 'content' => 'Welcome <em>home</em>']],
      ['name' => 'core/group', 'type' => 'core', 'attrs' => ['className' => 'feature'], 'innerBlocks' => [
        ['name' => 'core/paragraph', 'type' => 'core', 'attrs' => ['dropCap' => false, 'content' => 'Contact <a href="mailto:hello@example.com">our team</a>.']],
        ['name' => 'core/group', 'type' => 'core', 'attrs' => [], 'innerBlocks' => [
          ['name' => 'core/paragraph', 'type' => 'core', 'attrs' => ['dropCap' => true, 'content' => 'A second level.']],
        ]],
      ]],
    ], $result);
    $this->assertTrue(array_is_list($result));
  }

  public function testAcceptsAPostObject(): void
  {
    $post = TestEnvironment::post(42, '<!-- wp:paragraph --><p>Object input.</p><!-- /wp:paragraph -->');
    $this->assertSame('Object input.', (new BlockParser())->parseBlocksFromPost($post)[0]['attrs']['content']);
  }

  public function testMissingPostsAndContentWithoutNamedBlocksReturnEmptyLists(): void
  {
    $parser = new BlockParser();
    $this->assertSame([], $parser->parseBlocksFromPost(404));
    $this->assertSame([], $parser->parseBlocksFromPost(TestEnvironment::post(1, '')));
    $this->assertSame([], $parser->parseBlocksFromPost(TestEnvironment::post(2, '<p>Classic content.</p>')));
  }

  public function testSyncedPatternsFlattenInPlaceIncludingNestedPatterns(): void
  {
    TestEnvironment::post(1, $this->fixture('synced-pattern'));
    TestEnvironment::post(20, '<!-- wp:paragraph --><p>Pattern one.</p><!-- /wp:paragraph --><!-- wp:block {"ref":30} /-->');
    TestEnvironment::post(30, '<!-- wp:paragraph --><p>Pattern two.</p><!-- /wp:paragraph -->');
    $result = (new BlockParser())->parseBlocksFromPost(1);

    $this->assertSame(['Before.', 'Pattern one.', 'Pattern two.', 'After.'], array_column(array_column($result, 'attrs'), 'content'));
    $this->assertSame(['core/paragraph', 'core/paragraph', 'core/paragraph', 'core/paragraph'], array_column($result, 'name'));
    $this->assertTrue(array_is_list($result));
  }

  public function testPatternsInsideGroupsFlattenWithinTheirParent(): void
  {
    TestEnvironment::post(1, '<!-- wp:group --><div><!-- wp:block {"ref":20} /--></div><!-- /wp:group -->');
    TestEnvironment::post(20, '<!-- wp:paragraph --><p>One.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Two.</p><!-- /wp:paragraph -->');

    $result = (new BlockParser())->parseBlocksFromPost(1);
    $this->assertSame(['One.', 'Two.'], array_column(array_column($result[0]['innerBlocks'], 'attrs'), 'content'));
  }

  public function testDeletedOrEmptySyncedPatternsDoNotLeavePhantomBlocks(): void
  {
    TestEnvironment::post(1, $this->fixture('synced-pattern'));
    $parser = new BlockParser();
    foreach ([null, ''] as $content) {
      if ($content !== null) TestEnvironment::post(20, $content);
      $result = $parser->parseBlocksFromPost(1);
      $this->assertCount(2, $result);
      $this->assertSame(['Before.', 'After.'], array_column(array_column($result, 'attrs'), 'content'));
    }
  }

  public function testCoreTransformerCanBeReplacedPerParserInstance(): void
  {
    $parser = new BlockParser();
    $parser->registerTransformer(RecordingTransformer::class);
    $block = $this->block('core/paragraph', [], '<p>Original.</p>');

    $this->assertSame([
      'name' => 'core/paragraph', 'type' => 'core', 'attrs' => ['dropCap' => false], 'postId' => 42, 'hasParser' => true,
    ], $parser->transformBlock($block, 42));
    $this->assertSame('Original.', (new BlockParser())->transformBlock($block, 42)['attrs']['content']);
  }

  public function testCustomClassificationUsesARegisteredTransformerAndFallsBackToCore(): void
  {
    $this->registerBlock('example/widget');
    $parser = new class extends BlockParser {
      protected function determineBlockType(WP_Block $block): string
      {
        return $block->name === 'example/widget' ? 'custom' : parent::determineBlockType($block);
      }
    };
    $block = $this->block('example/widget', ['label' => 'Widget']);
    $this->assertSame('core', $parser->transformBlock($block, 42)['type']);

    $parser->registerTransformer(CustomTransformer::class);
    $this->assertSame([
      'name' => 'example/widget', 'type' => 'custom', 'attrs' => ['label' => 'Widget'], 'postId' => 42, 'hasParser' => true,
    ], $parser->transformBlock($block, 42));
  }

  #[DataProvider('invalidTransformers')]
  public function testRejectsClassesThatDoNotImplementTheTransformerInterface(string $class): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Transformer must implement BlockTransformerInterface');
    (new BlockParser())->registerTransformer($class);
  }

  public static function invalidTransformers(): array
  {
    return [['stdClass'], ['MissingTransformer']];
  }

  public function testAcfNamedBlocksFallBackToCoreWhenAcfIsNotInstalled(): void
  {
    $this->assertFalse(function_exists('acf_register_block_type'));
    $result = (new BlockParser())->transformBlock($this->block('acf/hero', ['className' => 'hero']), 42);
    $this->assertSame('core', $result['type']);
    $this->assertSame(['className' => 'hero'], $result['attrs']);
  }

  public function testUnregisteredPluginBlocksKeepTheirSavedAttributesAndMarkup(): void
  {
    $result = (new BlockParser())->transformBlock($this->block('deleted-plugin/card', ['title' => 'Saved title'], '<article>Saved markup.</article>'), 42);
    $this->assertSame([
      'name' => 'deleted-plugin/card', 'type' => 'core', 'attrs' => ['title' => 'Saved title'], 'rendered' => '<article>Saved markup.</article>',
    ], $result);
  }

  public function testFiltersSeeTransformedChildrenSourceBlockAndPostId(): void
  {
    TestEnvironment::post(42, $this->fixture('nested-blocks'));
    $seen = [];
    add_filter('cloakwp/block', function (array $parsed, WP_Block $source, int $postId) use (&$seen) {
      $seen[] = [$parsed, $source->name, $postId];
      $parsed['filtered'] = true;
      return $parsed;
    }, 20, 3);

    $result = (new BlockParser())->parseBlocksFromPost(42);
    $this->assertCount(5, $seen);
    $this->assertSame(['core/heading', 'core/paragraph', 'core/paragraph', 'core/group', 'core/group'], array_column($seen, 1));
    $this->assertSame([42, 42, 42, 42, 42], array_column($seen, 2));
    $this->assertTrue($seen[4][0]['innerBlocks'][0]['filtered']);
    $this->assertTrue($result[1]['filtered']);
  }

  public function testModifierHooksAreRegisteredOnceAndTargetOnlyMatchingBlocks(): void
  {
    $parser = new BlockParser();
    new BlockParser();
    $hits = [];
    add_filter('cloakwp/block/name=core/paragraph', function (array $parsed) use (&$hits) {
      $hits[] = 'name';
      $parsed['byName'] = true;
      return $parsed;
    });
    add_filter('cloakwp/block/type=core', function (array $parsed) use (&$hits) {
      $hits[] = 'type';
      $parsed['byType'] = true;
      return $parsed;
    });
    $paragraph = $parser->transformBlock($this->block('core/paragraph', [], '<p>Text.</p>'), 42);
    $heading = $parser->transformBlock($this->block('core/heading', [], '<h2>Title.</h2>'), 42);

    $this->assertSame(['name', 'type', 'type'], $hits);
    $this->assertTrue($paragraph['byName']);
    $this->assertTrue($paragraph['byType']);
    $this->assertArrayNotHasKey('byName', $heading);
  }

  public function testFrontendRenderingResolvesBindingsBeforeTransformationAndRestoresPostContext(): void
  {
    TestEnvironment::$isAdmin = false;
    $wanted = TestEnvironment::post(42, '');
    $GLOBALS['post'] = TestEnvironment::post(99, '');
    register_block_bindings_source('example/title', [
      'label' => 'Test title',
      'get_value_callback' => static fn() => 'Bound to post ' . $GLOBALS['post']->ID,
    ]);
    add_filter('cloakwp/block/include_rendered', '__return_false');
    $block = $this->block('core/paragraph', ['metadata' => ['bindings' => ['content' => ['source' => 'example/title']]]], '<p>Saved fallback.</p>');

    $result = (new BlockParser())->transformBlock($block, 42);
    $this->assertSame('Bound to post 42', $result['attrs']['content']);
    $this->assertSame($wanted, $GLOBALS['post']);
    $this->assertSame([$wanted], TestEnvironment::$setupPostdata);
  }

  public function testAdminParsingDoesNotRenderBindingsWhenRenderedOutputIsDisabled(): void
  {
    $renders = 0;
    add_filter('render_block', function (string $html) use (&$renders) {
      $renders++;
      return $html;
    });
    add_filter('cloakwp/block/include_rendered', '__return_false');
    $result = (new BlockParser())->transformBlock($this->block('core/paragraph', [], '<p>Saved.</p>'), 42);
    $this->assertSame('Saved.', $result['attrs']['content']);
    $this->assertSame(0, $renders);
  }
}
