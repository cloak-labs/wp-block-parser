<?php

namespace CloakWP\BlockParser\Transformers;

use WP_Block;

/**
 * Class ACFBlockTransformer
 * 
 * This class is responsible for transforming ACF blocks into a structured array format.
 */
class ACFBlockTransformer extends AbstractBlockTransformer
{
  /** @var string The type of block this transformer handles */
  protected static string $type = 'acf';

  /**
   * Transform an ACF block's data into a more use-able, structured form
   *
   * @param WP_Block $block The WordPress block object
   * @return array The transformed block data
   */
  public function transform(WP_Block $block, int|null $postId = null): array
  {
    $attrs = $block->attributes;
    $acfFields = $this->transformFields($attrs['data'] ?? [], $block);

    $this->removeUnwantedAttributes($attrs);

    return array_merge(
      $this->formatBaseBlock($block, $attrs),
      ['data' => $acfFields]
    );
  }

  /**
   * Transform non-formatted ACF fields into a more use-able, structured form
   *
   * @param array $fields The raw ACF fields
   * @param WP_Block $block The WordPress block object
   * @return array The transformed fields
   */
  protected function transformFields(array $fields, WP_Block $block): array
  {
    $parsedFields = [];
    $blockId = acf_get_block_id($block->attributes['data']);

    if (is_array($block->attributes['data'])) {
      acf_setup_meta($block->attributes['data'], $blockId);
    }

    $fieldIds = [];
    $fieldObjects = [];
    foreach ($fields as $key => $value) {
      if ($this->isFieldKey($key, $value)) {
        $fieldObject = get_field_object($value);
        if ($fieldObject && isset($fieldObject['ID'])) {
          // we use an associative array to deduplicate Id values (important to then run array_keys() below)
          $fieldIds[$fieldObject['ID']] = true;
          $fieldObjects[$key] = $fieldObject;
        }
      }
    }
    $fieldIds = array_keys($fieldIds);

    foreach ($fields as $key => $value) {
      if ($this->isFieldKey($key, $value)) {
        $fieldName = ltrim($key, '_');
        $fieldObject = $fieldObjects[$key] ?? [];
        $fieldValue = $fields[$fieldName];

        if (!$this->isSubField($fieldObject, $fieldIds) && !$this->isExcludedFieldType($fieldObject)) {
          $value = $this->formatFieldValue($fieldName, $fieldValue, $fieldObject, $blockId);
          if ($value !== null && $value !== '') {
            $parsedFields[$fieldName] = $value;
          }
        }
      } elseif (!isset($fields['_' . $key])) {
        $parsedFields[$key] = $value;
      }
    }

    return $parsedFields;
  }

  /**
   * Check if a given key-value pair represents an ACF field key
   *
   * @param string $key The field key
   * @param mixed $value The field value
   * @return bool True if it's a field key, false otherwise
   */
  protected function isFieldKey(string $key, $value): bool
  {
    return str_starts_with($key, '_') && str_starts_with($value, 'field_');
  }

  /**
   * Check if a field is a sub-field of another field
   *
   * @param array $fieldObject The field object
   * @return bool True if it's a sub-field, false otherwise
   */
  protected function isSubField(array $fieldObject, array $blockFieldIds): bool
  {
    return isset($fieldObject['parent']) && (in_array($fieldObject['parent'], $blockFieldIds) || str_starts_with($fieldObject['parent'], "field_"));
  }

  /**
   * Check if a field is a type that should be excluded from the parsed result (usually layout-related fields that hold no structured data, such as Accordions and Tabs)
   *
   * @param array $fieldObject The field object
   * @return bool True if it's an excluded field type, false otherwise
   */
  protected function isExcludedFieldType(array $fieldObject): bool
  {
    return in_array($fieldObject['type'] ?? '', ['accordion', 'tab']);
  }

  /**
   * This is a similar function to ACF's built-in get_field(), but it allows us
   * to exclude certain field types using the isExcludedFieldType() method from this class.
   */
  protected function getField(string $selector, string $block_id): mixed
  {
    // filter block_id
    $block_id = acf_get_valid_post_id($block_id);

    // get field
    $field = (array) acf_maybe_get_field($selector, $block_id);

    // recursively filter sub_fields
    $field = $this->filterExcludedSubFields($field);

    // get value for field
    $value = acf_get_value($block_id, $field);

    return acf_format_value($value, $block_id, $field);
  }

  /**
   * Recursively filter out invalid sub_fields
   *
   * @param array $field The field object
   * @return array The field object with filtered sub_fields
   */
  protected function filterExcludedSubFields(array $field): array
  {
    if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
      $field['sub_fields'] = array_filter($field['sub_fields'], function ($subField) {
        return !$this->isExcludedFieldType($subField);
      });

      // Recursively filter sub_fields of sub_fields
      foreach ($field['sub_fields'] as &$subField) {
        $subField = $this->filterExcludedSubFields($subField);
      }
    }

    return $field;
  }

  /**
   * Remove empty properties from an associative array
   *
   * @param array $object The object to remove empty properties from
   * @return array The object with empty properties removed
   */
  protected function removeEmptyProperties(array $object): array
  {
    foreach ($object as $key => $value) {
      if (is_array($value)) {
        $object[$key] = $this->removeEmptyProperties($value);
      } elseif (is_bool($value) || is_numeric($value) || $value === '0') {
        continue;
      } elseif (empty($value) || $value == "") {
        unset($object[$key]);
      }
    }

    return $object;
  }

  /**
   * Format the value of an ACF field
   *
   * @param string $fieldName The name of the field
   * @param mixed $fieldValue The raw value of the field
   * @param array $fieldObject The field object
   * @param string $blockId The ID of the block
   * @return mixed The formatted field value
   */
  protected function formatFieldValue(string $fieldName, $fieldValue, array $fieldObject, string $blockId)
  {
    $fieldType = $fieldObject['type'] ?? '';

    if ($this->requiresFormatting($fieldType, $fieldValue)) {
      $fieldValue = $this->getField($fieldName, $blockId);
    }

    $fieldValue = apply_filters('cloakwp/block/field', $fieldValue, $fieldObject, [
      'type' => $fieldType,
      'name' => $fieldName,
      'blockName' => $fieldObject['name'] ?? '',
    ]);

    return is_array($fieldValue) ? $this->removeEmptyProperties($fieldValue) : $fieldValue;
  }

  /**
   * Check if a field requires additional formatting
   *
   * @param string $fieldType The type of the field
   * @param mixed $fieldValue The value of the field
   * @return bool True if the field requires formatting, false otherwise
   */
  protected function requiresFormatting(string $fieldType, $fieldValue): bool
  {
    $typesRequiringFormatting = ['repeater', 'group', 'flexible_content', 'relationship', 'page_link', 'post_object', 'true_false', 'gallery'];
    return in_array($fieldType, $typesRequiringFormatting) || ($fieldType === 'image' && is_int($fieldValue));
  }
}
