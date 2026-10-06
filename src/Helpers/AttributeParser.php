<?php

namespace CloakWP\BlockParser\Helpers;

use CloakWP\BlockParser\Html\HtmlAdapterInterface;
use CloakWP\BlockParser\Html\HtmlNodeInterface;
use CloakWP\BlockParser\Html\NativeHtmlAdapter;

class AttributeParser
{
  protected HtmlAdapterInterface $htmlAdapter;

  public function __construct(?HtmlAdapterInterface $htmlAdapter = null)
  {
    $this->htmlAdapter = $htmlAdapter ?? apply_filters('cloakwp/block_parser/html_adapter', new NativeHtmlAdapter());
  }

  /**
   * Merge sourced attributes into stored attributes, parsing HTML at most once.
   * Stored values, defaults and meta-only schemas need no HTML processing.
   *
   * @param array<string, array> $blockTypeAttrs
   * @param array<string, mixed> $blockAttrs
   * @param string|array $html Block inner HTML or inner_content fragments
   * @return array<string, mixed>
   */
  public function getAttributes(array $blockTypeAttrs, array $blockAttrs, string|array $html, int $postId = 0): array
  {
    $htmlString = trim(is_array($html) ? implode('', array_filter($html, fn($v) => $v !== null)) : $html);
    $dom = null;
    foreach ($blockTypeAttrs as $key => $attribute) {
      if (isset($blockAttrs[$key]) && $blockAttrs[$key] !== '') {
        continue;
      }
      $value = $this->getAttributeWithDom($attribute, $dom, $htmlString, $postId);
      if ($value !== null) {
        $blockAttrs[$key] = $value;
      }
    }
    return $blockAttrs;
  }

  public function getAttribute(array $attribute, string $html, int $postId = 0): mixed
  {
    $dom = null;
    return $this->getAttributeWithDom($attribute, $dom, trim($html), $postId);
  }

  protected function getAttributeWithDom(array $attribute, ?HtmlNodeInterface &$dom, string $html, int $postId): mixed
  {
    $value = null;
    $source = $attribute['source'] ?? null;
    if ($source === 'meta') {
      $value = $this->handleMetaSource($attribute, $postId);
    } elseif (in_array($source, ['attribute', 'html', 'rich-text', 'text', 'tag', 'query'], true)) {
      if ($dom === null && $html !== '') {
        $dom = $this->htmlAdapter->parse($html);
      }
      if ($dom !== null) {
        if ($source === 'query') {
          $value = $this->handleQuerySource($attribute, $dom, $postId);
        } else {
          $node = $dom->select($attribute['selector'] ?? '*');
          $value = match ($source) {
            'attribute' => $node?->attribute($attribute['attribute']),
            'html', 'rich-text' => $node?->html(),
            'text' => $node?->text(),
            'tag' => $node?->tagName(),
          };
        }
      }
    }

    if ($value === null && isset($attribute['default'])) {
      $value = $attribute['default'];
    }

    $builtinTypes = ['array', 'object', 'string', 'number', 'integer', 'boolean', 'null'];
    if (
      isset($attribute['type']) &&
      (
        (is_string($attribute['type']) && in_array($attribute['type'], $builtinTypes, true)) ||
        (is_array($attribute['type']) && count(array_diff($attribute['type'], $builtinTypes)) === 0)
      ) &&
      rest_validate_value_from_schema($value, $attribute) === true
    ) {
      $value = rest_sanitize_value_from_schema($value, $attribute);
    }
    return $value === '' || $value === [] ? null : $value;
  }

  protected function handleQuerySource(array $attribute, HtmlNodeInterface $dom, int $postId): ?array
  {
    $result = [];
    foreach ($dom->selectAll($attribute['selector'] ?? '*') as $node) {
      $row = [];
      foreach ($attribute['query'] as $key => $schema) {
        // Keep the node context: serializing/reparsing loses table cells and
        // repeats work for every field in a gallery, list or table query.
        $value = $this->getAttributeWithDom($schema, $node, '', $postId);
        if ($value !== null) {
          $row[$key] = $value;
        }
      }
      if ($row !== []) {
        $result[] = $row;
      }
    }
    return $result !== [] ? $result : null;
  }

  protected function handleMetaSource(array $attribute, int $postId): mixed
  {
    $value = isset($attribute['meta']) ? get_post_meta($postId, $attribute['meta'], true) : null;
    return $value !== '' ? $value : null;
  }
}
