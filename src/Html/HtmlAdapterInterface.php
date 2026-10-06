<?php

namespace CloakWP\BlockParser\Html;

interface HtmlAdapterInterface
{
  /** Parse UTF-8 block markup into a reusable selection context. */
  public function parse(string $html): HtmlNodeInterface;
}
