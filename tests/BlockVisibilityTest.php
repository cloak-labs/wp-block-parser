<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use CloakWP\BlockParser\Tests\Support\TestEnvironment;

final class BlockVisibilityTest extends ParserTestCase
{
  public function testHiddenContainerExcludesItsEntireSubtreeBeforeRendering(): void
  {
    $renders = 0;
    $this->registerBlock('example/private', [], [
      'render_callback' => function () use (&$renders) {
        $renders++;
        return 'Private content';
      },
    ]);
    $post = TestEnvironment::post(42,
      '<!-- wp:group {"metadata":{"blockVisibility":false}} --><div>'
      . '<!-- wp:example/private /--></div><!-- /wp:group -->'
      . '<!-- wp:paragraph --><p>Published sibling</p><!-- /wp:paragraph -->');

    $blocks = (new BlockParser())->parseBlocksFromPost($post);

    $this->assertCount(1, $blocks);
    $this->assertSame('Published sibling', $blocks[0]['attrs']['content']);
    $this->assertSame(0, $renders);
  }

  public function testHiddenPatternReferenceDoesNotExpandReferencedContent(): void
  {
    TestEnvironment::post(99, '<!-- wp:paragraph --><p>Private pattern</p><!-- /wp:paragraph -->');
    $block = $this->block('core/block', ['ref' => 99, 'metadata' => ['blockVisibility' => false]]);

    $this->assertSame([], (new BlockParser())->transformBlock($block, 42));
  }

  public function testHiddenChildrenOfVisibleContainersAreRemovedWithoutChangingSiblingShape(): void
  {
    $post = TestEnvironment::post(42,
      '<!-- wp:group --><div>'
      . '<!-- wp:paragraph {"metadata":{"blockVisibility":false}} --><p>Hidden</p><!-- /wp:paragraph -->'
      . '<!-- wp:paragraph --><p>Visible</p><!-- /wp:paragraph -->'
      . '</div><!-- /wp:group -->');
    add_filter('cloakwp/block/include_rendered', '__return_false');

    $blocks = (new BlockParser())->parseBlocksFromPost($post);

    $this->assertSame([0], array_keys($blocks[0]['innerBlocks']));
    $this->assertSame('Visible', $blocks[0]['innerBlocks'][0]['attrs']['content']);
  }

  public function testViewportHidingDoesNotRemoveContentFromTheStructuredTree(): void
  {
    $metadata = ['blockVisibility' => ['viewport' => ['mobile' => false, 'tablet' => true, 'desktop' => true]]];
    $this->registerBlock('example/viewport', [
      'content' => ['type' => 'string', 'source' => 'rich-text', 'selector' => 'p'],
      'metadata' => ['type' => 'object'],
    ]);
    $block = $this->block('example/viewport', ['metadata' => $metadata], '<p>Viewport content</p>');
    $result = (new BlockParser())->transformBlock($block, 42);

    $this->assertSame('Viewport content', $result['attrs']['content']);
    $this->assertSame($metadata, $result['attrs']['metadata']);
  }
}
