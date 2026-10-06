<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use CloakWP\BlockParser\Tests\Support\TestEnvironment;
use CloakWP\BlockParser\Transformers\CoreBlockTransformer;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Block;

final class CoreBlockTransformerTest extends ParserTestCase
{
  public function testCombinesCommentAttributesHtmlSourcesDefaultsAndSupportedAnchors(): void
  {
    $this->registerBlock('example/card', [
      'title' => ['type' => 'string', 'source' => 'text', 'selector' => 'h2'],
      'url' => ['type' => 'string', 'source' => 'attribute', 'selector' => 'a', 'attribute' => 'href'],
      'featured' => ['type' => 'boolean', 'default' => false],
    ], ['supports' => ['anchor' => true]]);
    add_filter('cloakwp/block/include_rendered', '__return_false');

    $block = $this->wpBlock('example/card', [
      'className' => 'card', 'data' => ['internal' => 1], 'name' => 'internal', 'mode' => 'preview',
    ], '<article id="card-one"><h2>Hello <em>there</em></h2><a href="/details">Details</a></article>');
    $result = (new CoreBlockTransformer())->transform($block, 42);

    $this->assertSame([
      'name' => 'example/card', 'type' => 'core', 'attrs' => [
        'className' => 'card', 'featured' => false, 'title' => 'Hello there', 'url' => '/details', 'anchor' => 'card-one',
      ],
    ], $result);
  }

  public function testOnlyAddsAnAnchorWhenTheBlockSupportsIt(): void
  {
    add_filter('cloakwp/block/include_rendered', '__return_false');
    $result = (new CoreBlockTransformer())->transform($this->wpBlock('core/paragraph', [], '<p id="intro">Intro.</p>'), 42);
    $this->assertArrayNotHasKey('anchor', $result['attrs']);
  }

  public function testRenderedOutputUsesDynamicRenderingThenShortcodes(): void
  {
    $this->registerBlock('example/dynamic', [], [
      'render_callback' => static fn() => '<p>[greeting]</p>',
    ]);
    add_shortcode('greeting', static fn() => 'Hello from shortcode');

    $result = (new CoreBlockTransformer())->transform($this->wpBlock('example/dynamic'), 42);
    $this->assertSame('<p>Hello from shortcode</p>', $result['rendered']);
  }

  public function testRenderedOutputCanBeDisabledForOnlySelectedBlocks(): void
  {
    $seen = [];
    add_filter('cloakwp/block/include_rendered', function (bool $include, array $parsed) use (&$seen) {
      $seen[] = $parsed;
      return $parsed['name'] !== 'core/paragraph';
    }, 10, 2);
    $transformer = new CoreBlockTransformer();
    $paragraph = $transformer->transform($this->wpBlock('core/paragraph', [], '<p>Copy.</p>'), 42);
    $heading = $transformer->transform($this->wpBlock('core/heading', [], '<h2>Title.</h2>'), 42);

    $this->assertArrayNotHasKey('rendered', $paragraph);
    $this->assertSame('<h2>Title.</h2>', $heading['rendered']);
    $this->assertSame('Copy.', $seen[0]['attrs']['content']);
    $this->assertArrayNotHasKey('rendered', $seen[0]);
  }

  public function testSyncedPatternWithoutAReferenceRemainsACoreBlock(): void
  {
    $result = (new CoreBlockTransformer(new BlockParser()))->transform($this->wpBlock('core/block'), 42);
    $this->assertSame(['name' => 'core/block', 'type' => 'core', 'attrs' => [], 'rendered' => ''], $result);
  }

  #[DataProvider('imageDimensions')]
  public function testIntrinsicImageDimensionsNeverReplaceLayoutDimensions(array $input, array $expected, string $size, bool $lookup): void
  {
    $this->registerBlock('core/image');
    TestEnvironment::$attachments[7][$size] = ['photo.jpg', 1600, 900, true];
    add_filter('cloakwp/block/include_rendered', '__return_false');
    $result = (new CoreBlockTransformer())->transform($this->wpBlock('core/image', $input), 42);

    $this->assertSame($expected, $result['attrs']);
    $this->assertSame($lookup ? [[7, $size]] : [], TestEnvironment::$attachmentRequests);
  }

  public static function imageDimensions(): array
  {
    return [
      'unresized full image' => [['id' => 7], ['id' => 7, 'intrinsicWidth' => 1600, 'intrinsicHeight' => 900], 'full', true],
      'requested size' => [['id' => 7, 'sizeSlug' => 'large'], ['id' => 7, 'sizeSlug' => 'large', 'intrinsicWidth' => 1600, 'intrinsicHeight' => 900], 'large', true],
      'numeric id string' => [['id' => '7'], ['id' => '7', 'intrinsicWidth' => 1600, 'intrinsicHeight' => 900], 'full', true],
      'both resized dimensions' => [['id' => 7, 'width' => 300, 'height' => 200], ['id' => 7, 'width' => 300, 'height' => 200], 'full', false],
      'only width resized' => [['id' => 7, 'width' => 300], ['id' => 7, 'width' => 300, 'intrinsicHeight' => 900], 'full', true],
      'only height resized' => [['id' => 7, 'height' => 200], ['id' => 7, 'height' => 200, 'intrinsicWidth' => 1600], 'full', true],
      'zero and empty dimensions' => [['id' => 7, 'width' => 0, 'height' => ''], ['id' => 7, 'width' => 0, 'height' => '', 'intrinsicWidth' => 1600, 'intrinsicHeight' => 900], 'full', true],
      'no id' => [[], [], 'full', false],
      'invalid id' => [['id' => 0], ['id' => 0], 'full', false],
    ];
  }

  public function testMissingAttachmentLeavesAttributesUnchanged(): void
  {
    $this->registerBlock('core/image');
    $result = (new CoreBlockTransformer())->transform($this->wpBlock('core/image', ['id' => 404]), 42);
    $this->assertSame(['id' => 404], $result['attrs']);
  }

  public function testImageMetadataIsNotRequestedForOtherBlockTypes(): void
  {
    $result = (new CoreBlockTransformer())->transform($this->wpBlock('core/group', ['id' => 7]), 42);
    $this->assertSame(['id' => 7], $result['attrs']);
    $this->assertSame([], TestEnvironment::$attachmentRequests);
  }
}
