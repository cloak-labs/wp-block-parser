<?php

namespace CloakWP\BlockParser\Transformers;

use WP_Block;
use CloakWP\BlockParser\Acf\GutenbergGroupNesting;
use CloakWP\BlockParser\Profiler;

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
    $acfStart = Profiler::isEnabled() ? microtime(true) : null;

    $attrs = $block->attributes;
    $acfFields = $this->transformFields($attrs['data'] ?? [], $block);

    $this->removeUnwantedAttributes($attrs);

    $result = array_merge(
      $this->formatBaseBlock($block, $attrs),
      ['data' => $acfFields]
    );

    if (Profiler::isEnabled() && $acfStart !== null) {
      Profiler::addAcfTransformMs((microtime(true) - $acfStart) * 1000);
    }

    return $result;
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
    $useProfiler = Profiler::isEnabled();
    $parsedFields = [];
    $blockId = acf_get_block_id($block->attributes['data']);

    if (is_array($block->attributes['data'])) {
      acf_setup_meta($block->attributes['data'], $blockId);
    }

    $allBlockFieldKeys = [];
    $fieldObjects = [];
    $getObjectsStart = $useProfiler ? microtime(true) : null;

    // ===== FIRST LOOP 
    //$fields contains both field name-key pairs and field name-value pairs -- we only care about the former. This loop is purposely separate from the next $fields loop, to speed up lookups of potential parent field names.
    $nameKeyPairs = [];
    $nameValuePairs = [];
    foreach ($fields as $name => $key) {  
      if ($this->isFieldNameKeyPair($name, $key)) {
        $nameKeyPairs[ltrim($name, '_')] = $key;
      } else {
        $nameValuePairs[$name] = $key;
      }
    } 
    
    // ===== SECOND LOOP 
    // This loop retrieves the field objects for all fields in the block that actually have values.
    foreach ($nameKeyPairs as $fieldName => $fieldKey) {
      $fieldValue = $nameValuePairs[$fieldName] ?? null;

      // Gutenberg stores group subfields as `{parent}_{child}` with an empty parent
      // value (""). Skip only true leaf empties — not group parents with children.
      if ($this->isEmptyFieldValue($fieldValue)
        && !GutenbergGroupNesting::hasFlattenedChildren($fieldName, array_keys($nameValuePairs))
      ) {
        $allBlockFieldKeys[$fieldKey] = true;
        continue;
      }

      // Definition only: acf_get_field($fieldKey) avoids load_value/format_value. Profiler times this as "definitions".
      $defStart = $useProfiler ? microtime(true) : null;
      $fieldObject = function_exists('acf_get_field') ? acf_get_field($fieldKey) : get_field_object($fieldKey, false, false, false);
      // $fieldObject = get_field_object($value);
      if ($useProfiler && $defStart !== null) {
        Profiler::addAcfGetFieldDefinitionsMs((microtime(true) - $defStart) * 1000, $block->name);
      }

      if ($fieldObject && isset($fieldObject['ID'])) {
        // we use an associative array to deduplicate field keys (important to then run array_keys() below)
        $allBlockFieldKeys[$fieldKey] = true;
        $fieldObjects[$fieldName] = $fieldObject;
      }
    }

    if ($useProfiler && $getObjectsStart !== null) {
      Profiler::addAcfGetFieldObjectsMs((microtime(true) - $getObjectsStart) * 1000, $block->name);
    }

    $allBlockFieldKeys = array_keys($allBlockFieldKeys);
    $formatStart = $useProfiler ? microtime(true) : null;

    // ===== THIRD LOOP 
    // Iterate over resolved field objects to (maybe) apply formatting to the field values.
    foreach ($fieldObjects as $fieldName => $fieldObject) {
      $fieldValue = $nameValuePairs[$fieldName] ?? null;

      if ($this->isExcludedFieldType($fieldObject)) {
        continue;
      }

      if ($this->isSubField($fieldObject, $allBlockFieldKeys)) {
        if (!isset($fields['_' . $fieldName])) {
          $parsedFields[$fieldName] = $fieldValue; //! maybe delete?
        }
        continue;
      }

      if ($useProfiler) {
        $isFlexibleContent = ($fieldObject['type'] ?? '') === 'flexible_content';
        $acfInnerStart = ($useProfiler && $isFlexibleContent) ? microtime(true) : null;
      }

      // Format the field value!
      $formatted = $this->formatFieldValue($fieldName, $fieldValue, $fieldObject, $blockId);

      if ($useProfiler && $acfInnerStart !== null) {
        Profiler::addInnerBlocksMs((microtime(true) - $acfInnerStart) * 1000, $block->name);
      }

      if (($fieldObject['type'] ?? '') === 'group') {
        $formatted = GutenbergGroupNesting::merge(
          is_array($formatted) ? $formatted : [],
          $fieldName,
          $fieldObject,
          $nameValuePairs
        );
      }

      if (!$this->isEmptyFieldValue($formatted)) {
        $parsedFields[$fieldName] = $formatted;
      }
    }

    // Copy leftover name-value pairs that have no ACF field key (not registered, or stripped).
    // Gutenberg-flattened group subfields that still have `_name` keys are handled by GutenbergGroupNesting instead.
    foreach ($nameValuePairs as $name => $value) {
      if (isset($nameKeyPairs[$name])) continue; // this is the value half of an ACF field; we already added the formatted value from the format loop
      $parsedFields[$name] = $value;
    }

    if ($useProfiler && $formatStart !== null) {
      Profiler::addAcfFormatFieldsMs((microtime(true) - $formatStart) * 1000, $block->name);
    }

    return $parsedFields;
  }

  /**
   * Check if a given name-key pair represents an ACF field. eg. '_field_name' => 'field_123456' (i.e. how ACF natively stores fields on blocks)
   *
   * @param string $key The field key
   * @param mixed $value The field value
   * @return bool True if it's a field key, false otherwise
   */
  protected function isFieldNameKeyPair(string $name, mixed $key): bool
  {
    if (is_string($name) && is_string($key)) {
      return str_starts_with($name, '_') && str_starts_with($key, 'field_');
    }

    return false;
  }

  /**
   * True when the stored value is considered empty so we can skip resolving the field definition.
   * Used to avoid loading heavy definitions (e.g. InnerBlocks/Flexible Content with many layouts) when the block doesn't use the field.
   *
   * Side effect of treating '' as empty: those fields are omitted from the parsed output (key absent) rather than included as "".
   * Group parents with Gutenberg-flattened children are still resolved (see GutenbergGroupNesting).
   */
  protected function isEmptyFieldValue($value): bool
  {
    if ($value === null || $value === '') {
      return true;
    }
    if (is_array($value) && empty($value)) {
      return true;
    }
    return false;
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
    $typesRequiringFormatting = ['repeater', 'group', 'flexible_content', 'relationship', 'page_link', 'post_object', 'true_false', 'gallery', 'file'];
    return in_array($fieldType, $typesRequiringFormatting) || ($fieldType === 'image' && is_int($fieldValue));
  }
}
