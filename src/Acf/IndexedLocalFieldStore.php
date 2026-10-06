<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Acf;

/**
 * Accelerates ACF's repeated parent queries without changing field loading.
 * Loaded only when ACF_Data is available. All public state is shared with the
 * original store, including writes made through a previously retained handle.
 *
 * @internal
 */
final class IndexedLocalFieldStore extends \ACF_Data
{
  private ?array $indexedData = null;
  private array $children = [];
  private bool $indexable = true;

  public function __construct(\ACF_Data $store)
  {
    foreach (['cid', 'data', 'aliases', 'multisite', 'site_data', 'site_aliases'] as $property) {
      $this->{$property} =& $store->{$property};
    }
  }

  public function query($args, $operator = 'AND')
  {
    if ($operator !== 'AND' || !is_array($args) || count($args) !== 1
      || !isset($args['parent']) || !is_string($args['parent'])
      || $args['parent'] === '' || is_numeric($args['parent'])
    ) {
      return parent::query($args, $operator);
    }

    // PHP compares unchanged arrays by identity. A write through either store
    // triggers rebuilding, including same-size edits and multisite switches.
    if ($this->indexedData !== $this->data) {
      $this->children = [];
      $this->indexable = true;
      foreach ($this->data as $key => $field) {
        if (!is_array($field)) {
          $this->indexable = false;
          break;
        }
        if (!isset($field['parent'])) continue;
        if (!is_string($field['parent']) && !is_int($field['parent'])) {
          $this->indexable = false;
          break;
        }
        $this->children[$field['parent']][$key] = $field;
      }
      $this->indexedData = $this->data;
    }

    return $this->indexable
      ? ($this->children[$args['parent']] ?? [])
      : parent::query($args, $operator);
  }
}
