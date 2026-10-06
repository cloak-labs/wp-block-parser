<?php

namespace CloakWP\BlockParser;

use CloakWP\BlockParser\Transformers\BlockTransformerInterface;
use CloakWP\BlockParser\Transformers\CoreBlockTransformer;
use CloakWP\BlockParser\Transformers\ACFBlockTransformer;
use CloakWP\HookModifiers;
use CloakWP\BlockParser\Html\HtmlAdapterInterface;
use CloakWP\BlockParser\Html\NativeHtmlAdapter;
use CloakWP\BlockParser\Acf\LocalFieldIndex;
use WP_Block;
use WP_Post;
use CloakWP\BlockParser\Profiler;

class BlockParser
{
  protected array $transformers = [];
  private static $initialized = false;
  protected HtmlAdapterInterface $htmlAdapter;

  public function __construct(?HtmlAdapterInterface $htmlAdapter = null)
  {
    $this->htmlAdapter = $htmlAdapter ?? apply_filters('cloakwp/block_parser/html_adapter', new NativeHtmlAdapter());
    if (!self::$initialized) {
      // Run the following code only ONCE, no matter how many instances of BlockParser are created
      HookModifiers::make(['name', 'type'])
        ->forFilter('cloakwp/block')
        ->register();

      HookModifiers::make(['name', 'type', 'blockName'])
        ->forFilter('cloakwp/block/field')
        ->modifiersArgPosition(2)
        ->register();

      self::$initialized = true;
    }

    $this->registerDefaultTransformers();
  }

  public function getHtmlAdapter(): HtmlAdapterInterface
  {
    return $this->htmlAdapter;
  }

  protected function registerDefaultTransformers(): void
  {
    $this->registerTransformer(CoreBlockTransformer::class);

    if (function_exists('acf_register_block_type')) {
      $this->registerTransformer(ACFBlockTransformer::class);
    }
  }

  public function registerTransformer(string $transformerClass): void
  {
    if (!is_subclass_of($transformerClass, BlockTransformerInterface::class)) {
      throw new \InvalidArgumentException("Transformer must implement BlockTransformerInterface");
    }

    $type = $transformerClass::getType();
    $this->transformers[$type] = new $transformerClass($this);
  }

  public function parseBlocksFromPost(WP_Post|int $post): array
  {
    if (Profiler::isEnabled()) {
      Profiler::start();
    }

    $post = get_post($post);

    // if the post is not found, return an empty array. This is helpful when, for example, a Synced Pattern is used on a page but got deleted. Without this early return, a critical error would prevent the editor page from loading.
    if (!$post) {
      if (Profiler::isEnabled()) Profiler::end();
      return [];
    }

    $blocks = parse_blocks($post->post_content);

    $result = array_values(
      $this->transformBlocks($blocks, $post->ID)
    );

    if (Profiler::isEnabled()) {
      Profiler::end();
    }

    return $result;
  }

  public function transformBlock(array $block, int $postId): array
  {
    return LocalFieldIndex::run(fn() => $this->transformBlockWithFieldIndex($block, $postId));
  }

  private function transformBlockWithFieldIndex(array $block, int $postId): array
  {
    $wpBlock = new WP_Block($block);
    $blockName = $block['blockName'] ?? '';

    if (Profiler::isEnabled()) {
      Profiler::setCurrentBlock($blockName);
    }

    $renderStart = null;
    if (Profiler::isEnabled()) {
      $renderStart = microtime(true);
    }

    if (!is_admin()) {
      global $post;
      if (!empty($post) && $postId != $post->ID) {
        // somehow (likely while processing the last block) the global $post got set to something else, so we need to reset it manually before calling render(), otherwise Block Bindings will not be resolved correctly
        $post = get_post($postId);
        setup_postdata($post);
      }

      if ($this->shouldRenderForAttributes($wpBlock, $postId)) {
        $wpBlock->render(); // Resolve bindings before extracting structured attributes.
      }
    }

    if (Profiler::isEnabled() && $renderStart !== null) {
      Profiler::addRenderMs((microtime(true) - $renderStart) * 1000);
    }

    $transformStart = null;
    if (Profiler::isEnabled()) {
      $transformStart = microtime(true);
    }

    $blockType = $this->determineBlockType($wpBlock);
    $transformer = $this->transformers[$blockType] ?? $this->transformers['core'];
    $parsedBlock = $transformer->transform($wpBlock, $postId);

    $innerMs = 0.0;
    if (!empty($block['innerBlocks'])) {
      $innerStart = Profiler::isEnabled() ? microtime(true) : null;
      $parsedBlock['innerBlocks'] = $this->transformBlocks($block['innerBlocks'], $postId);
      if (Profiler::isEnabled() && $innerStart !== null) {
        $innerMs = (microtime(true) - $innerStart) * 1000;
        Profiler::addInnerBlocksMs($innerMs, $blockName);
      }
    }

    if (Profiler::isEnabled() && $transformStart !== null) {
      Profiler::addTransformOtherMs((microtime(true) - $transformStart) * 1000, $blockName);
      Profiler::clearCurrentBlock();
    }

    return apply_filters('cloakwp/block', $parsedBlock, $wpBlock, $postId);
  }

  /**
   * Rendering a container also renders its descendants. Structured output only
   * needs that work when bindings compute attributes; requested HTML is handled
   * by the transformer. Integrations that mutate attributes during rendering
   * can opt individual blocks into this preliminary render.
   */
  protected function shouldRenderForAttributes(WP_Block $block, int $postId): bool
  {
    return (bool) apply_filters(
      'cloakwp/block/render_for_attributes',
      !empty($block->parsed_block['attrs']['metadata']['bindings']),
      $block,
      $postId
    );
  }

  protected function transformBlocks(array $blocks, int $postId): array
  {
    return array_reduce(
      array_filter($blocks, fn($block) => !empty($block['blockName'])),
      function ($carry, $block) use ($postId) {
        $result = $this->transformBlock($block, $postId);
        if ($result === []) {
          return $carry;
        }
        if ($this->isArrayOfBlocks($result)) {
          // handle WP Synced Patterns, which at this point appear as nested arrays of blocks which must be flattened:
          $carry = array_merge($carry, $result);
        } else {
          $carry[] = $result;
        }
        return $carry;
      },
      []
    );
  }

  protected function determineBlockType(WP_Block $block): string
  {
    if (isset($block->block_type->attributes['data']) || str_starts_with($block->name, 'acf/'))
      return 'acf';
    return 'core';
  }

  private function isArrayOfBlocks(array $block): bool
  {
    return is_array($block) && isset($block[0]) && is_array($block[0]);
  }
}
