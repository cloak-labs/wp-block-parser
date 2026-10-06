<?php

namespace CloakWP\BlockParser\Html;

use Dom\Element;
use Dom\HTMLDocument;

final class NativeHtmlNode implements HtmlNodeInterface
{
  public function __construct(private HTMLDocument|Element $node) {}

  public function select(string $selector = '*'): ?HtmlNodeInterface
  {
    if ($this->node instanceof Element && $this->node->matches($selector)) {
      return $this;
    }
    $match = $this->node->querySelector($selector);
    return $match ? new self($match) : null;
  }

  public function selectAll(string $selector): iterable
  {
    if ($this->node instanceof Element && $this->node->matches($selector)) {
      yield $this;
    }
    foreach ($this->node->querySelectorAll($selector) as $match) {
      yield new self($match);
    }
  }

  public function attribute(string $name): ?string
  {
    return $this->node instanceof Element && $this->node->hasAttribute($name)
      ? $this->node->getAttribute($name)
      : null;
  }

  public function html(): string
  {
    $document = $this->node instanceof HTMLDocument ? $this->node : $this->node->ownerDocument;
    $html = '';
    foreach ($this->node->childNodes as $child) {
      $html .= $document->saveHtml($child);
    }
    return $html;
  }

  public function text(): string
  {
    if ($this->node instanceof Element) {
      return $this->node->textContent;
    }
    // Document::textContent is null. Read its text/element children, excluding
    // comments and doctypes just as Element::textContent does.
    $text = '';
    foreach ($this->node->childNodes as $child) {
      if ($child instanceof Element || $child instanceof \Dom\Text) {
        $text .= $child->textContent;
      }
    }
    return $text;
  }

  public function tagName(): ?string
  {
    return $this->node instanceof Element ? strtolower($this->node->localName) : null;
  }
}
