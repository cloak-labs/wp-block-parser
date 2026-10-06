<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Acf;

/** @internal */
final class LocalFieldIndex
{
  private static ?\WeakMap $stores = null;

  /**
   * ACF's native field loaders still run, including clone/layout expansion and
   * acf/load_field(s) filters. Only its local registry's parent lookup changes.
   * Unsupported/custom stores retain their original behavior.
   */
  public static function run(callable $callback): mixed
  {
    $store = $GLOBALS['acf_stores']['local-fields'] ?? null;
    if (!class_exists('ACF_Data', false) || !is_object($store)
      || get_class($store) !== 'ACF_Data'
      || !apply_filters('cloakwp/block_parser/index_acf_fields', true)
    ) {
      return $callback();
    }

    self::$stores ??= new \WeakMap();
    $indexed = self::$stores[$store] ??= new IndexedLocalFieldStore($store);
    $GLOBALS['acf_stores']['local-fields'] = $indexed;
    try {
      return $callback();
    } finally {
      // Do not undo a deliberate registry replacement made by a callback.
      if (($GLOBALS['acf_stores']['local-fields'] ?? null) === $indexed) {
        $GLOBALS['acf_stores']['local-fields'] = $store;
      }
    }
  }
}
