<?php

namespace CloakWP\BlockParser\Transformers;

use WP_Block;
use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Helpers\AttributeParser;
use CloakWP\BlockParser\Profiler;

class CoreBlockTransformer extends AbstractBlockTransformer
{
  protected static string $type = 'core';

  protected AttributeParser $attributeParser;

  public function __construct(BlockParser|null $parser = null)
  {
    parent::__construct($parser);
    $this->attributeParser = new AttributeParser();
  }

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

    $parseStart = Profiler::isEnabled() ? microtime(true) : null;

    // Parse all attributes in one pass (single DOM parse per block instead of per-attribute)
    $blockAttrs = $this->attributeParser->getAttributes(
      $blockTypeAttrs,
      $blockAttrs,
      $block->inner_html ?? $block->inner_content ?? '',
      $postId
    );

    if (Profiler::isEnabled() && $parseStart !== null) {
      Profiler::addParseAttrsMs((microtime(true) - $parseStart) * 1000);
    }

    $this->removeUnwantedAttributes($blockAttrs);

    return $blockAttrs;
  }

  protected function shouldIncludeRendered(array $formattedBlock): bool
  {
    return apply_filters('cloakwp/block/include_rendered', true, $formattedBlock);
  }
}