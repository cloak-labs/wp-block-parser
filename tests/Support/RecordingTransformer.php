<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests\Support;

use CloakWP\BlockParser\Transformers\AbstractBlockTransformer;
use WP_Block;

class RecordingTransformer extends AbstractBlockTransformer
{
  protected static string $type = 'core';

  public function transform(WP_Block $block, ?int $postId = null): array
  {
    return $this->formatBaseBlock($block, $block->attributes) + [
      'postId' => $postId,
      'hasParser' => $this->parser !== null,
    ];
  }
}
