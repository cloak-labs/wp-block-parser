<?php

namespace CloakWP\BlockParser;

use CloakWP\BlockParser\Transformers\BlockTransformerInterface;
use CloakWP\BlockParser\Transformers\CoreBlockTransformer;
use CloakWP\BlockParser\Transformers\ACFBlockTransformer;
use CloakWP\HookModifiers;
use WP_Block;
use WP_Post;

class BlockParser
{
  protected array $transformers = [];
  private static $initialized = false;

  public function __construct()
  {
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
    $post = get_post($post);
    $blocks = parse_blocks($post->post_content);

    return array_values(
      $this->transformBlocks($blocks, $post->ID)
    );
  }

  public function transformBlock(array $block, int $postId): array
  {
    $wpBlock = new WP_Block($block);

    if (!is_admin()) {
      global $post;
      if ($postId != $post->ID) {
        // somehow (likely while processing the last block) the global $post got set to something else, so we need to reset it manually before calling render(), otherwise Block Bindings will not be resolved correctly
        $post = get_post($postId);
        setup_postdata($post);
      }

      $wpBlock->render(); // triggers processing of Block Bindings and Interactivity directives etc.
    }

    $blockType = $this->determineBlockType($wpBlock);
    $transformer = $this->transformers[$blockType] ?? $this->transformers['core'];
    $parsedBlock = $transformer->transform($wpBlock, $postId);

    if (!empty($block['innerBlocks'])) {
      $parsedBlock['innerBlocks'] = $this->transformBlocks($block['innerBlocks'], $postId);
    }

    return apply_filters('cloakwp/block', $parsedBlock, $wpBlock, $postId);
  }

  protected function transformBlocks(array $blocks, int $postId): array
  {
    return array_reduce(
      array_filter($blocks, fn($block) => !empty($block['blockName'])),
      function ($carry, $block) use ($postId) {
        $result = $this->transformBlock($block, $postId);
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
