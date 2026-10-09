<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\Html\NativeHtmlAdapter;
use CloakWP\BlockParser\Html\NativeHtmlNode;
use CloakWP\BlockParser\Html\TagProcessorHtmlAdapter;
use CloakWP\BlockParser\Html\TagProcessorHtmlNode;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TagProcessorHtmlAdapterTest extends ParserTestCase
{
  public function testStreamingTextAndAttributesPreserveEntitiesWhitespaceAndBooleanValues(): void
  {
    $dom = (new TagProcessorHtmlAdapter())->parse('<!-- hidden --><p title="caf&eacute; &amp; tea">'
      . "Café ☕ &amp; &lt;script&gt;safe&lt;/script&gt;\r\n <em>words</em></p><input disabled>");
    $this->assertInstanceOf(TagProcessorHtmlNode::class, $dom);
    $this->assertSame('café & tea', $dom->select('p')->attribute('TITLE'));
    $this->assertSame("Café ☕ & <script>safe</script>\n words", $dom->text());
    $this->assertSame($dom->text(), $dom->select('p')->text());
    $this->assertSame('', $dom->select('input')->attribute('disabled'));
    $this->assertNull($dom->select('input')->attribute('missing'));
  }

  public function testCanonicalHtmlAndCssFallbackRetainTheOriginalNodeContext(): void
  {
    $html = "<div><figure id='one'><img src='one.jpg'><figcaption>&copy; <EM>One</EM></figcaption></figure>"
      . "<figure id='two'><img src='two.jpg'><figcaption>Two</figcaption></figure></div>";
    $dom = (new TagProcessorHtmlAdapter())->parse($html);
    $native = (new NativeHtmlAdapter())->parse($html);
    $figures = iterator_to_array($dom->selectAll('figure'));
    $this->assertSame($native->html(), $dom->html());
    $this->assertSame('two.jpg', $figures[1]->select('img[src]')->attribute('src'));
    $this->assertSame('two', $figures[1]->select('#two')->attribute('id'));
    $this->assertNull($figures[1]->select('#one'));
    $this->assertSame($native->select('figcaption')->html(), $figures[0]->select('figcaption')->html());
    $this->assertSame(['div', 'figure', 'img', 'figcaption', 'em', 'figure', 'img', 'figcaption'],
      array_map(fn($node) => $node->tagName(), iterator_to_array($dom->selectAll('*,figure,img'))));
  }

  public static function repairedFragments(): array
  {
    return array_map(fn($html) => [$html], [
      '<ul><li>First<li>Second</ul>',
      '<p>A<div>B</div>C</p>',
      '<a href="one">A<a href="two">B</a>C</a>',
      '<h1>A<h2>B</h2>C</h1>',
      '<b><i>A</b>B</i>',
      '<table>Outside<tr><td>Cell</td></tr></table>',
      '<svg><title>Icon</title><path d="M0 0"/></svg>',
      '<textarea>\n&lt;em&gt;Text</textarea><script>if (a < b) {}</script>',
      "<pre>\nA\nB</pre>",
      '<p>Incomplete',
      '<p>Text</p><img src="unfinished',
      '<!DOCTYPE html><html><body>Full document</body></html>',
      "<p>Null\0byte</p>",
    ]);
  }

  #[DataProvider('repairedFragments')]
  public function testHtml5RecoveryUsesNativeForTheWholeFragment(string $html): void
  {
    $dom = (new TagProcessorHtmlAdapter())->parse($html);
    $native = (new NativeHtmlAdapter())->parse($html);
    foreach ($dom->selectAll('*') as $node) {
      $this->assertInstanceOf(NativeHtmlNode::class, $node);
    }
    $this->assertSame($native->html(), $dom->html());
    $this->assertSame($native->text(), $dom->text());
    $this->assertSame(array_map(fn($node) => $node->html(), iterator_to_array($native->selectAll('*'))),
      array_map(fn($node) => $node->html(), iterator_to_array($dom->selectAll('*'))));
  }

  public function testSourcePreservingModeIsExplicitAndKeepsOriginalInnerMarkup(): void
  {
    $html = "<p>&copy; <EM title='x'>One</EM></p>";
    $this->assertSame('&copy; <EM title=\'x\'>One</EM>',
      (new TagProcessorHtmlAdapter(preserveSourceHtml: true))->parse($html)->select('p')->html());
    $this->assertSame((new NativeHtmlAdapter())->parse($html)->select('p')->html(),
      (new TagProcessorHtmlAdapter())->parse($html)->select('p')->html());
  }

  public function testInvalidSelectorsStillThrow(): void
  {
    $this->expectException(\DOMException::class);
    (new TagProcessorHtmlAdapter())->parse('<p>Text</p>')->select('[');
  }
}
