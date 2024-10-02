<?php

namespace CloakWP\BlockParser\Transformers;

use CloakWP\BlockParser\BlockParser;
use WP_Block;

abstract class AbstractBlockTransformer implements BlockTransformerInterface
{
  protected static string $type;
  protected BlockParser|null $parser = null;

  public function __construct(BlockParser|null $parser = null)
  {
    $this->parser = $parser;
  }

  abstract public function transform(WP_Block $block, int|null $postId = null): array;

  protected function removeUnwantedAttributes(array &$attrs): void
  {
    unset($attrs['data'], $attrs['name'], $attrs['mode']);
  }

  protected function formatBaseBlock(WP_Block $block, array $attrs): array
  {
    return [
      'name' => $block->name,
      'type' => static::$type,
      'attrs' => $attrs,
    ];
  }

  public static function getType(): string
  {
    return static::$type;
  }
}