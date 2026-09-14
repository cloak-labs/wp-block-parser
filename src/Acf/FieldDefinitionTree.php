<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Acf;

/**
 * Helpers for walking ACF field-definition trees (including nested sub_fields).
 */
final class FieldDefinitionTree
{
  /**
   * Index field objects by name at every nesting level.
   *
   * Nested `sub_fields` lists become name-keyed maps so consumers can look up
   * `query.taxonomies.category` without scanning arrays.
   *
   * @param list<array<string, mixed>>|array<string, array<string, mixed>> $fields
   * @return array<string, array<string, mixed>>
   */
  public static function mapByName(array $fields): array
  {
    $map = [];

    foreach (self::asList($fields) as $field) {
      if (!is_array($field)) {
        continue;
      }

      $name = $field['name'] ?? null;
      if (!is_string($name) || $name === '') {
        continue;
      }

      if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
        $field['sub_fields'] = self::mapByName($field['sub_fields']);
      }

      $map[$name] = $field;
    }

    return $map;
  }

  /**
   * @param list<array<string, mixed>>|array<string, array<string, mixed>> $fields
   * @return list<array<string, mixed>>
   */
  public static function asList(array $fields): array
  {
    if ($fields === []) {
      return [];
    }

    return array_is_list($fields) ? $fields : array_values($fields);
  }
}
