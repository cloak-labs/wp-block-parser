<?php

namespace CloakWP\BlockParser\Helpers;

use pQuery;

class AttributeParser
{
  /**
   * Parse attributes for multiple block-type attributes in one pass.
   * Parses the block HTML once and reuses the DOM for all attributes (major perf win).
   *
   * @param array<string, array> $blockTypeAttrs Block type attribute definitions
   * @param array<string, mixed> $blockAttrs Existing block attributes (missing/empty will be filled from HTML)
   * @param string|array $html Block inner HTML (string) or inner_content array
   * @param int $postId Post ID for meta sources
   * @return array<string, mixed> Merged attributes with parsed values
   */
  public function getAttributes(array $blockTypeAttrs, array $blockAttrs, string|array $html, int $postId = 0): array
  {
    $htmlString = is_array($html) ? implode('', array_filter($html, fn($v) => $v !== null)) : $html;
    $htmlString = trim($htmlString);
    $dom = $htmlString !== '' ? pQuery::parseStr($htmlString) : null;

    foreach ($blockTypeAttrs as $key => $attribute) {
      if (isset($blockAttrs[$key]) && $blockAttrs[$key] !== '') {
        continue;
      }
      $attrValue = $this->getAttributeWithDom($attribute, $dom, $htmlString, $postId);
      if ($attrValue !== null) {
        $blockAttrs[$key] = $attrValue;
      }
    }

    return $blockAttrs;
  }

  public function getAttribute(array $attribute, string $html, int $postId = 0)
  {
    return $this->getAttributeWithDom($attribute, null, $html, $postId);
  }

  /**
   * @param \pQuery\Dom|null $dom Pre-parsed DOM (avoids re-parsing when parsing many attributes from same HTML)
   */
  protected function getAttributeWithDom(array $attribute, $dom, string $html, int $postId): mixed
  {
    $value = null;

    if (isset($attribute['source'])) {
      $value = $this->getAttributeBySource($attribute, $html, $postId, $dom);
    }

    if (is_null($value) && isset($attribute['default'])) {
      $value = $attribute['default'];
    }

    // Only run validation/sanitization if the type is a built-in type supported by WP REST schema
    $builtinTypes = ['array', 'object', 'string', 'number', 'integer', 'boolean', 'null'];
    if (
      isset($attribute['type']) &&
      (
        (is_string($attribute['type']) && in_array($attribute['type'], $builtinTypes, true)) ||
        (is_array($attribute['type']) && count(array_diff($attribute['type'], $builtinTypes)) === 0)
      ) &&
      rest_validate_value_from_schema($value, $attribute)
    ) {
      $value = rest_sanitize_value_from_schema($value, $attribute);
    }

    // Remove empty string or empty array values
    if ($value === '' || (is_array($value) && empty($value))) {
      return null;
    }

    return $value;
  }

  /**
   * @param \pQuery\Dom|null $dom Pre-parsed DOM; when null, $html is parsed
   */
  protected function getAttributeBySource(array $attribute, string $html, int $postId, $dom = null): mixed
  {
    $source = $attribute['source'];
    if ($dom === null) {
      $dom = trim($html) !== '' ? pQuery::parseStr(trim($html)) : null;
    }
    if ($dom === null) {
      return $this->getAttributeWithoutSelector($attribute, null, $source, $postId);
    }

    if (isset($attribute['selector'])) {
      return $this->getAttributeWithSelector($attribute, $dom, $source);
    }

    return $this->getAttributeWithoutSelector($attribute, $dom, $source, $postId);
  }

  protected function getAttributeWithSelector(array $attribute, $dom, string $source): mixed
  {
    $selector = $attribute['selector'];

    switch ($source) {
      case 'attribute':
        return $dom->query($selector)->attr($attribute['attribute']);
      case 'html':
        return $dom->query($selector)->html();
      case 'rich-text':
        return $dom->query($selector)->html();
      case 'text':
        return $dom->query($selector)->text();
      case 'query':
        return $this->handleQuerySource($attribute, $dom);
    }

    return null;
  }

  /**
   * @param \pQuery\Dom|null $dom
   */
  protected function getAttributeWithoutSelector(array $attribute, $dom, string $source, int $postId): mixed
  {
    if ($source === 'meta') {
      return $this->handleMetaSource($attribute, $postId);
    }
    if ($dom === null) {
      return null;
    }
    $node = $dom->query();

    switch ($source) {
      case 'attribute':
        return $node->attr($attribute['attribute']);
      case 'html':
        return $node->html();
      case 'text':
        return $node->text();
    }

    return null;
  }

  protected function handleQuerySource(array $attribute, $dom): ?array
  {
    $result = [];
    $nodes = $dom->query($attribute['selector'])->getIterator();

    foreach ($nodes as $index => $node) {
      $nodeResult = [];
      foreach ($attribute['query'] as $key => $subAttribute) {
        $value = $this->getAttribute($subAttribute, $node->toString());
        if ($value !== null) {
          $nodeResult[$key] = $value;
        }
      }
      if (!empty($nodeResult)) {
        $result[$index] = $nodeResult;
      }
    }

    return !empty($result) ? $result : null;
  }

  protected function handleMetaSource(array $attribute, int $postId): mixed
  {
    $value = isset($attribute['meta']) ? get_post_meta($postId, $attribute['meta'], true) : null;
    return $value !== '' ? $value : null;
  }
}
