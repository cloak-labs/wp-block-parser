<?php

namespace CloakWP\BlockParser\Html;

/** @internal */
final class TagProcessorHtmlNode implements HtmlNodeInterface
{
  public function __construct(private TagProcessorDocument $document, private ?int $id = null) {}

  public function select(string $selector = '*'): ?HtmlNodeInterface
  {
    foreach ($this->selectAll($selector) as $node) {
      return $node;
    }
    return null;
  }

  public function selectAll(string $selector): iterable
  {
    // Gutenberg's common tag/tag-list selectors need no CSS engine. Delegate
    // everything else, including invalid selectors, to the shared native DOM.
    if ($this->document->usesNativeSelections()
      || !preg_match('/^\s*(?:\*|[a-zA-Z][a-zA-Z0-9-]*)(?:\s*,\s*(?:\*|[a-zA-Z][a-zA-Z0-9-]*))*\s*$/D', $selector)
      || !$this->document->index()) {
      yield from $this->document->native($this->id)->selectAll($selector);
      return;
    }
    $tags = array_map('trim', explode(',', strtolower($selector)));
    $all = in_array('*', $tags, true);
    $end = $this->id === null ? count($this->document->elements) : $this->document->elements[$this->id]['end'];
    for ($i = $this->id ?? 0; $i < $end; $i++) {
      if ($all || in_array($this->document->elements[$i]['tag'], $tags, true)) {
        yield new self($this->document, $i);
      }
    }
  }

  public function attribute(string $name): ?string
  {
    return $this->id === null ? null : ($this->document->elements[$this->id]['attrs'][strtolower($name)] ?? null);
  }

  public function html(): string
  {
    // Native serialization is required for byte-compatible rich text. Source
    // slicing is an explicit experiment, as pQuery also has distinct HTML output.
    if (!$this->document->preserveSourceHtml || !$this->document->index()) {
      return $this->document->native($this->id)->html();
    }
    if ($this->id === null) {
      return $this->document->html;
    }
    $element = $this->document->elements[$this->id];
    return substr($this->document->html, $element['innerStart'], $element['innerEnd'] - $element['innerStart']);
  }

  public function text(): string
  {
    if ($this->document->usesNativeSelections() || !$this->document->index()) {
      return $this->document->native($this->id)->text();
    }
    if ($this->id === null) {
      return $this->document->text;
    }
    $element = $this->document->elements[$this->id];
    return substr($this->document->text, $element['textStart'], $element['textEnd'] - $element['textStart']);
  }

  public function tagName(): ?string
  {
    return $this->id === null ? null : $this->document->elements[$this->id]['tag'];
  }
}
