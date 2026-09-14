<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Acf;

use WP_Block;

/**
 * Applies post-field-transformation filters to a parsed ACF block.
 *
 * Fires after every ACF field has been formatted, and before the block-level
 * `cloakwp/block` filter.
 */
final class BlockDataFilters
{
  /**
   * @param array<string, mixed> $parsedBlock
   * @param list<array<string, mixed>> $fieldDefinitions Top-level ACF field objects, including nested `sub_fields`.
   * @param mixed $block Source block (typically WP_Block).
   */
  public static function apply(array $parsedBlock, array $fieldDefinitions, mixed $block, int|null $postId): array
  {
    if (!function_exists('apply_filters')) {
      return $parsedBlock;
    }

    /**
     * Filters a parsed ACF block after all fields are formatted.
     *
     * @param array<string, mixed> $parsedBlock
     * @param list<array<string, mixed>> $fieldDefinitions
     * @param mixed $block
     * @param int|null $postId
     */
    return apply_filters('cloakwp/block/data', $parsedBlock, $fieldDefinitions, $block, $postId);
  }
}
