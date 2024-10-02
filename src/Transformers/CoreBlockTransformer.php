<?php

namespace CloakWP\BlockParser\Transformers;

use WP_Block;
use CloakWP\BlockParser\Helpers\AttributeParser;

class CoreBlockTransformer extends AbstractBlockTransformer
{
  protected static string $type = 'core';

  public function transform(WP_Block $block, int|null $postId = null): array
  {
    $attrs = $this->parseAttributes($block, $postId);

    $formattedBlock = $this->formatBaseBlock($block, $attrs);

    if ($formattedBlock['name'] == 'core/block' && isset($attrs['ref'])) {
      /** === Synced Patterns ===
       * A synced pattern will only have a 'ref' attribute referencing its post ID. So
       * we need to parse that separate post's blocks and return the result. BlockParser's
       * transformBlocks method will handle flattening the nested array of blocks.
       */
      $formattedBlock = $this->parser->parseBlocksFromPost($attrs['ref']);
    } else if ($this->shouldIncludeRendered($formattedBlock)) {
      $formattedBlock['rendered'] = do_shortcode($block->render());
    }

    return $formattedBlock;
  }

  protected function parseAttributes(WP_Block $block, int $postId): array
  {
    $blockAttrs = $block->attributes;
    $blockTypeAttrs = $block->block_type->attributes;
    $supports = $block->block_type->supports;

    // Manually add anchor attribute if supported:
    if ($supports && isset($supports['anchor']) && $supports['anchor']) {
      $blockTypeAttrs['anchor'] = [
        'type' => 'string',
        'default' => '',
        'source' => 'attribute',
        'attribute' => 'id',
        'selector' => '*'
      ];
    }

    $attributeParser = new AttributeParser();

    foreach ($blockTypeAttrs as $key => $attribute) {
      if (!isset($blockAttrs[$key]) || $blockAttrs[$key] == "") {
        $attrValue = $attributeParser->getAttribute($attribute, $block->inner_html, $postId);
        if ($attrValue !== null) {
          $blockAttrs[$key] = $attrValue;
        }
      }
    }

    $this->removeUnwantedAttributes($blockAttrs);

    return $blockAttrs;
  }

  protected function shouldIncludeRendered(array $formattedBlock): bool
  {
    return apply_filters('cloakwp/block/include_rendered', true, $formattedBlock);
  }
}