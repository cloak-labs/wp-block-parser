<?php

namespace CloakWP\BlockParser\Html;

/** @internal Flat element spans, not a DOM or a replacement HTML5 tree builder. */
final class TagProcessorDocument
{
  public array $elements = [];
  public string $text = '';
  private ?HtmlNodeInterface $native = null;
  private ?array $nativeElements = null;
  private ?bool $indexed = null;

  // Other elements use native recovery (tables, raw text, foreign content, etc.).
  private const PHRASING = ['span', 'a', 'em', 'strong', 'b', 'i', 'u', 's', 'small',
    'mark', 'sub', 'sup', 'code', 'kbd', 'samp', 'var', 'abbr', 'cite', 'q', 'time', 'img', 'br', 'input'];
  private const FLOW = ['div', 'section', 'aside', 'article', 'header', 'footer', 'main', 'nav',
    'figure', 'figcaption', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'hr'];
  private const VOID = ['img', 'br', 'hr', 'input'];

  public function __construct(public readonly string $html, public readonly bool $preserveSourceHtml) {}

  public function usesNativeSelections(): bool
  {
    return $this->native !== null && $this->indexed !== true;
  }

  public function index(): bool
  {
    return $this->indexed ??= $this->buildIndex();
  }

  private function buildIndex(): bool
  {
    // Null handling differs between lexical text and HTML5 tree construction.
    if (str_contains($this->html, "\0")) {
      return false;
    }
    $processor = new class($this->html) extends \WP_HTML_Tag_Processor {
      public function span(): array
      {
        // Token offsets are private. One reusable bookmark exposes the span
        // through WP's protected extension surface without reflection/regex HTML.
        $this->set_bookmark('cloakwp-span');
        $span = $this->bookmarks['cloakwp-span'];
        $this->release_bookmark('cloakwp-span');
        return [$span->start, $span->length];
      }
    };
    $stack = [];
    while ($processor->next_token()) {
      $type = $processor->get_token_type();
      if ($type === '#text') {
        $this->text .= $processor->get_modifiable_text();
        continue;
      }
      if ($type === '#comment') {
        continue;
      }
      if ($type !== '#tag') {
        return false;
      }
      $tag = strtolower($processor->get_tag());
      if (!in_array($tag, self::PHRASING, true) && !in_array($tag, self::FLOW, true)) {
        return false;
      }
      [$start, $length] = $processor->span();
      if ($processor->is_tag_closer()) {
        $id = array_pop($stack);
        if ($id === null || $this->elements[$id]['tag'] !== $tag) {
          return false; // Includes omitted end tags and adoption-agency repairs.
        }
        $this->elements[$id]['innerEnd'] = $start;
        $this->elements[$id]['end'] = count($this->elements);
        $this->elements[$id]['textEnd'] = strlen($this->text);
        continue;
      }
      $parent = $stack === [] ? null : $stack[array_key_last($stack)];
      // Conservatively reject balanced markup that HTML5 would still repair.
      foreach ($stack as $ancestor) {
        $ancestorTag = $this->elements[$ancestor]['tag'];
        if (($tag === 'a' && $ancestorTag === 'a')
          || ((in_array($ancestorTag, self::PHRASING, true) || $ancestorTag === 'p'
            || preg_match('/^h[1-6]$/D', $ancestorTag)) && !in_array($tag, self::PHRASING, true))) {
          return false;
        }
      }
      if (($tag === 'li' && !in_array($this->elements[$parent]['tag'] ?? '', ['ul', 'ol'], true))
        || (in_array($tag, ['dt', 'dd'], true) && ($this->elements[$parent]['tag'] ?? '') !== 'dl')) {
        return false;
      }
      $attrs = [];
      foreach ($processor->get_attribute_names_with_prefix('') ?? [] as $name) {
        $value = $processor->get_attribute($name);
        $attrs[$name] = $value === true ? '' : $value;
      }
      $id = count($this->elements);
      $this->elements[] = ['tag' => $tag, 'attrs' => $attrs, 'parent' => $parent,
        'innerStart' => $start + $length, 'innerEnd' => $start + $length,
        'end' => $id + 1, 'textStart' => strlen($this->text), 'textEnd' => strlen($this->text)];
      if (!in_array($tag, self::VOID, true)) {
        $stack[] = $id;
      }
    }
    return $stack === [] && !$processor->paused_at_incomplete_token();
  }

  public function native(?int $id = null): HtmlNodeInterface
  {
    $this->native ??= (new NativeHtmlAdapter())->parse($this->html);
    if ($id === null) {
      return $this->native;
    }
    // The streaming subset rejects constructs that change element order/tree.
    $this->nativeElements ??= iterator_to_array($this->native->selectAll('*'));
    return $this->nativeElements[$id];
  }
}
