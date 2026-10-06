<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use CloakWP\BlockParser\Tests\Support\TestEnvironment;
use WP_Block;

final class RenderingTest extends ParserTestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    TestEnvironment::$isAdmin = false;
  }

  public function testStructuredOutputDoesNotRenderContainersOrDynamicDescendants(): void
  {
    $renders = 0;
    $this->registerBlock('example/expensive', ['title' => ['type' => 'string']], [
      'render_callback' => function () use (&$renders) {
        $renders++;
        return '<p>Expensive HTML</p>';
      },
    ]);
    add_filter('cloakwp/block/include_rendered', '__return_false');
    add_filter('render_block', function ($html) use (&$renders) {
      $renders++;
      return $html;
    });
    $wanted = TestEnvironment::post(42,
      '<!-- wp:group --><div><!-- wp:example/expensive {"title":"Saved"} /-->'
      . '<!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph --></div><!-- /wp:group -->');
    $GLOBALS['post'] = TestEnvironment::post(99, '');
    $result = (new BlockParser())->parseBlocksFromPost($wanted);

    $this->assertSame(0, $renders);
    $this->assertSame('Saved', $result[0]['innerBlocks'][0]['attrs']['title']);
    $this->assertSame('Text.', $result[0]['innerBlocks'][1]['attrs']['content']);
    $this->assertSame($wanted, $GLOBALS['post']);
    $this->assertSame([$wanted], TestEnvironment::$setupPostdata);
  }

  public function testRequestedDynamicHtmlRendersOnceAndStillExpandsShortcodes(): void
  {
    $renders = 0;
    $this->registerBlock('example/dynamic', [], ['render_callback' => function () use (&$renders) {
      $renders++;
      return '<p>[greeting]</p>';
    }]);
    add_shortcode('greeting', fn() => 'Hello');
    $result = (new BlockParser())->transformBlock($this->block('example/dynamic'), 42);
    $this->assertSame('<p>Hello</p>', $result['rendered']);
    $this->assertSame(1, $renders);
  }

  public function testOnlyBoundDescendantsRenderWhenHtmlIsDisabled(): void
  {
    $bindings = 0;
    $renders = [];
    register_block_bindings_source('example/title', [
      'label' => 'Title',
      'get_value_callback' => function () use (&$bindings) {
        $bindings++;
        return 'Bound to ' . $GLOBALS['post']->ID;
      },
    ]);
    add_filter('cloakwp/block/include_rendered', '__return_false');
    add_filter('render_block', function ($html, $block) use (&$renders) {
      $renders[] = $block['blockName'];
      return $html;
    }, 10, 2);
    $GLOBALS['post'] = TestEnvironment::post(42,
      '<!-- wp:group --><div>'
      . '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"example/title"}}}} --><p>Fallback</p><!-- /wp:paragraph -->'
      . '<!-- wp:paragraph --><p>Saved sibling</p><!-- /wp:paragraph -->'
      . '</div><!-- /wp:group -->');
    $result = (new BlockParser())->parseBlocksFromPost(42);

    $this->assertSame('Bound to 42', $result[0]['innerBlocks'][0]['attrs']['content']);
    $this->assertSame('Saved sibling', $result[0]['innerBlocks'][1]['attrs']['content']);
    $this->assertSame(1, $bindings);
    $this->assertSame(['core/paragraph'], $renders);
  }

  public function testRenderTimeAttributeMutationsCanOptIntoPreliminaryRendering(): void
  {
    $this->registerBlock('example/mutating', ['title' => ['type' => 'string']]);
    add_filter('cloakwp/block/include_rendered', '__return_false');
    add_filter('cloakwp/block/render_for_attributes', function ($render, WP_Block $block, int $postId) {
      $this->assertFalse($render);
      $this->assertSame(42, $postId);
      return $block->name === 'example/mutating';
    }, 10, 3);
    add_filter('render_block_example/mutating', function ($html, $parsed, WP_Block $block) {
      $block->attributes['title'] = strtoupper($block->attributes['title']);
      return $html;
    }, 10, 3);
    $result = (new BlockParser())->transformBlock($this->block('example/mutating', ['title' => 'Saved']), 42);
    $this->assertSame('SAVED', $result['attrs']['title']);
  }
}
