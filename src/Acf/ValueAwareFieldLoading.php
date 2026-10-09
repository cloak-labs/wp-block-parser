<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Acf;

/** @internal */
final class ValueAwareFieldLoading
{
  private static ?array $usedFields = null;
  /** @var array<string, list<string>|null>|null Unknown value shapes require every layout. */
  private static ?array $usedLayouts = null;
  private static ?object $baseStore = null;
  private static int $baseSiteId = 0;

  public static function run(array $data, callable $callback): mixed
  {
    $store = $GLOBALS['acf_stores']['fields'] ?? null;
    if (!class_exists('ACF_Data', false) || !is_object($store)
      || get_class($store) !== 'ACF_Data'
      || !apply_filters('cloakwp/block_parser/value_aware_fields', false)
    ) {
      return $callback();
    }

    $previousFields = self::$usedFields;
    $previousLayouts = self::$usedLayouts;
    $siteId = get_current_blog_id();
    self::$usedFields = [];
    self::$usedLayouts = [];
    self::collectValues($data);
    if ($previousFields === null) {
      self::$baseStore = $store;
      self::$baseSiteId = $siteId;
      add_filter('cloakwp/acf/inner_blocks/load_layouts', [self::class, 'shouldLoadLayouts'], 10, 2);
      add_filter('cloakwp/acf/inner_blocks/required_layouts', [self::class, 'requiredLayouts'], 10, 2);
    }

    // Empty layouts belong to this block's view, never ACF's normal cache.
    // Nested parsing starts from the complete cache too, not its caller's view.
    $scopedStore = clone self::$baseStore;
    if ($siteId !== self::$baseSiteId) {
      $scopedStore->switch_site($siteId, self::$baseSiteId);
    }
    // A normal ACF read may already have cached complete parent definitions.
    // Project those into this value's view without changing the original cache.
    foreach ($scopedStore->data as $key => $field) {
      if (is_array($field)) {
        $scopedStore->data[$key] = self::projectField($field);
      }
    }
    $GLOBALS['acf_stores']['fields'] = $scopedStore;
    try {
      return $callback();
    } finally {
      if (($GLOBALS['acf_stores']['fields'] ?? null) === $scopedStore) {
        if (get_current_blog_id() !== $siteId) {
          $store->switch_site(get_current_blog_id(), $siteId);
        }
        $GLOBALS['acf_stores']['fields'] = $store;
      }
      self::$usedFields = $previousFields;
      self::$usedLayouts = $previousLayouts;
      if ($previousFields === null) {
        remove_filter('cloakwp/acf/inner_blocks/load_layouts', [self::class, 'shouldLoadLayouts'], 10);
        remove_filter('cloakwp/acf/inner_blocks/required_layouts', [self::class, 'requiredLayouts'], 10);
        self::$baseStore = null;
      }
    }
  }

  public static function shouldLoadLayouts(bool $load, array $field): bool
  {
    return $load && (!empty($field['default_value']) || isset(self::$usedFields[$field['key']]));
  }

  /** @return list<string>|null Null retains the complete definition. */
  public static function requiredLayouts(?array $required, array $field): ?array
  {
    if (!empty($field['default_value']) || !array_key_exists($field['key'], self::$usedLayouts ?? [])) {
      return $required;
    }
    $used = self::$usedLayouts[$field['key']];
    return $used === null ? $required : ($required === null ? $used : array_values(array_intersect($required, $used)));
  }

  private static function projectField(array $field): array
  {
    if (!empty($field['cloakwp_inner_blocks']) && !empty($field['cloakwp_lazy_layouts'])) {
      $required = self::requiredLayouts(null, $field);
      if (!self::shouldLoadLayouts(true, $field)) {
        $field['layouts'] = [];
      } elseif ($required !== null) {
        $field['layouts'] = array_values(array_filter($field['layouts'] ?? [],
          static fn(array $layout): bool => in_array($layout['name'], $required, true)));
      }
    }
    foreach (['sub_fields', 'layouts'] as $children) {
      foreach ($field[$children] ?? [] as $key => $child) {
        if (is_array($child)) {
          $field[$children][$key] = self::projectField($child);
        }
      }
    }
    return $field;
  }

  private static function collectValues(array $data): void
  {
    foreach ($data as $name => $value) {
      if (is_string($name) && str_starts_with($name, '_')
        && is_string($value) && str_starts_with($value, 'field_')
      ) {
        $saved = $data[substr($name, 1)] ?? null;
        if ($saved !== null && $saved !== '' && $saved !== false
          && $saved !== [] && $saved !== 0 && $saved !== '0'
        ) {
          self::$usedFields[$value] = true;
          $layouts = self::layoutNames($saved);
          if (!array_key_exists($value, self::$usedLayouts)) {
            self::$usedLayouts[$value] = $layouts;
          } else {
            $previous = self::$usedLayouts[$value];
            self::$usedLayouts[$value] = $layouts === null || $previous === null
              ? null : array_values(array_unique([...$previous, ...$layouts]));
          }
        }
      } elseif (is_array($value)) {
        self::collectValues($value);
      }
    }
  }

  /** Native Gutenberg layout lists and nested ACF rows; other shapes stay complete. */
  private static function layoutNames(mixed $value): ?array
  {
    if (!is_array($value) || !array_is_list($value)) {
      return null;
    }
    $names = [];
    foreach ($value as $row) {
      $name = is_array($row) ? ($row['acf_fc_layout'] ?? null) : $row;
      if (!is_string($name) || $name === '') {
        return null;
      }
      $names[$name] = true;
    }
    return array_keys($names);
  }
}
