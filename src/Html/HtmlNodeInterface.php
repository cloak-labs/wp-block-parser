<?php

namespace CloakWP\BlockParser\Html;

interface HtmlNodeInterface
{
  /** Select the first matching element, including this node when it is an element. */
  public function select(string $selector = '*'): ?HtmlNodeInterface;

  /** @return iterable<HtmlNodeInterface> Matching elements in document order, including this element. */
  public function selectAll(string $selector): iterable;

  public function attribute(string $name): ?string;
  public function html(): string;
  public function text(): string;
  public function tagName(): ?string;
}
