<?php

namespace CloakWP\BlockParser\Html;

use Dom\HTMLDocument;

final class NativeHtmlAdapter implements HtmlAdapterInterface
{
  public function parse(string $html): HtmlNodeInterface
  {
    // Keep fragment roots selectable without implied html/head/body elements.
    $document = $html === ''
      ? HTMLDocument::createEmpty('UTF-8')
      : HTMLDocument::createFromString($html, LIBXML_HTML_NOIMPLIED | LIBXML_NOERROR, 'UTF-8');
    return new NativeHtmlNode($document);
  }
}
