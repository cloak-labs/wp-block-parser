<?php

namespace CloakWP\BlockParser\Acf;

/**
 * Gutenberg stores ACF groups as flattened keys (`query_photo_type_exclude`)
 * with an empty parent value (`query` => ""). This rebuilds the nested
 * `{ query: { photo_type: { exclude } } }` shape from those keys.
 *
 * ACF's format_value on an empty group parent often returns false / empty
 * subfields; flattened values are merged in so populated subfields are not lost.
 */
final class GutenbergGroupNesting
{
  /**
   * @param array<string, mixed> $formatted
   * @param array<string, mixed> $fieldObject
   * @param array<string, mixed> $nameValuePairs
   * @return array<string, mixed>
   */
  public static function merge(
    array $formatted,
    string $prefix,
    array $fieldObject,
    array $nameValuePairs
  ): array {
    $out = [];
    $subFields = $fieldObject['sub_fields'] ?? [];
    if (!is_array($subFields) || $subFields === []) {
      return $formatted;
    }

    foreach ($subFields as $sub) {
      if (!is_array($sub)) {
        continue;
      }
      $name = (string) ($sub['name'] ?? '');
      if ($name === '') {
        continue;
      }

      $flatKey = $prefix . '_' . $name;
      $current = $formatted[$name] ?? null;
      $raw = $nameValuePairs[$flatKey] ?? null;

      if (($sub['type'] ?? '') === 'group') {
        $childFormatted = is_array($current) ? $current : [];
        $nested = self::merge($childFormatted, $flatKey, $sub, $nameValuePairs);
        if ($nested !== []) {
          $out[$name] = $nested;
        }
        continue;
      }

      if (!self::isBlank($current)) {
        $out[$name] = $current;
        continue;
      }

      if (!self::isBlank($raw)) {
        $out[$name] = $raw;
      }
    }

    return $out;
  }

  /**
   * True when a Gutenberg field name looks like a flattened child of $parentName.
   */
  public static function hasFlattenedChildren(string $parentName, array $fieldNames): bool
  {
    $prefix = $parentName . '_';
    foreach ($fieldNames as $name) {
      if (!is_string($name) || $name === $parentName) {
        continue;
      }
      if (str_starts_with($name, $prefix)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Empty taxonomy/group values ACF stores as false or "".
   */
  public static function isBlank(mixed $value): bool
  {
    if ($value === null || $value === '' || $value === false) {
      return true;
    }

    return is_array($value) && $value === [];
  }
}
