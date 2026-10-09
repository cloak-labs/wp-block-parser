<?php

namespace CloakWP\BlockParser\Html;

/**
 * Experimental, opt-in streaming adapter; native DOM remains the default.
 *
 * PHP 8.4 / WordPress 6.7 benchmarks found this adapter slower than native:
 * paragraph rich-text extraction took ~21 µs versus ~8 µs, and nested gallery
 * queries took ~251 µs versus ~174 µs. A bare tag/attribute lookup was faster,
 * but nesting/scope bookkeeping and native fallback for CSS selectors, HTML5
 * recovery and canonical serialization outweighed that gain. Even slicing
 * source HTML instead of native serialization remained slower.
 *
 * Retained for explicit selection and future optimization experiments; these
 * results describe the current implementation, not a limit on tokenization.
 * See benchmarks/tag-processor-results.md for methodology and reproduction.
 */
final class TagProcessorHtmlAdapter implements HtmlAdapterInterface
{
  public function __construct(private bool $preserveSourceHtml = false)
  {
    if (!method_exists(\WP_HTML_Tag_Processor::class, 'next_token')) {
      throw new \LogicException('The tag processor adapter requires WordPress 6.5 or newer.');
    }
  }

  public function parse(string $html): HtmlNodeInterface
  {
    $document = new TagProcessorDocument($html, $this->preserveSourceHtml);
    return new TagProcessorHtmlNode($document);
  }
}
