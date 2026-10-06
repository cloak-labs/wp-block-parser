<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests\Support;

use CloakWP\BlockParser\Html\HtmlAdapterInterface;
use CloakWP\BlockParser\Html\HtmlNodeInterface;
use CloakWP\BlockParser\Html\NativeHtmlAdapter;

final class CountingHtmlAdapter implements HtmlAdapterInterface
{
  public array $parsed = [];

  public function __construct(private HtmlAdapterInterface $adapter = new NativeHtmlAdapter()) {}

  public function parse(string $html): HtmlNodeInterface
  {
    $this->parsed[] = $html;
    return $this->adapter->parse($html);
  }
}
