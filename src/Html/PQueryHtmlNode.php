<?php

namespace CloakWP\BlockParser\Html;

final class PQueryHtmlNode implements HtmlNodeInterface
{
  public function __construct(private \pQuery\DomNode $node, private bool $includeSelf = false) {}

  public function select(string $selector = '*'): ?HtmlNodeInterface
  {
    foreach ($this->selectAll($selector) as $match) {
      return $match;
    }
    return null;
  }

  public function selectAll(string $selector): iterable
  {
    foreach ($this->node->select($selector, false, true, $this->includeSelf) as $match) {
      // pQuery's universal selector also returns text/comment nodes.
      if ($match::NODE_TYPE === \pQuery\DomNode::NODE_ELEMENT) {
        yield new self($match, true);
      }
    }
  }

  public function attribute(string $name): ?string
  {
    $value = $this->node->attr($name);
    return $value === false || $value === null ? null : (string) $value;
  }

  public function html(): string
  {
    return $this->node->html();
  }

  public function text(): string
  {
    return $this->node->text();
  }

  public function tagName(): ?string
  {
    return $this->includeSelf ? strtolower($this->node->tagName()) : null;
  }
}
