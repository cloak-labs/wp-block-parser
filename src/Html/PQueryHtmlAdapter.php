<?php

namespace CloakWP\BlockParser\Html;

final class PQueryHtmlAdapter implements HtmlAdapterInterface
{
  public function __construct()
  {
    if (!class_exists(\pQuery::class)) {
      throw new \LogicException('Install tburry/pquery to use the pQuery HTML adapter.');
    }
  }

  public function parse(string $html): HtmlNodeInterface
  {
    return new PQueryHtmlNode(\pQuery::parseStr($html));
  }
}
