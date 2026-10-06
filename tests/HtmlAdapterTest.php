<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Helpers\AttributeParser;
use CloakWP\BlockParser\Html\NativeHtmlAdapter;
use CloakWP\BlockParser\Html\PQueryHtmlAdapter;
use CloakWP\BlockParser\Tests\Support\CountingHtmlAdapter;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use CloakWP\BlockParser\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

final class HtmlAdapterTest extends ParserTestCase
{
  public static function adapters(): array
  {
    return ['native' => [NativeHtmlAdapter::class], 'pquery' => [PQueryHtmlAdapter::class]];
  }

  #[DataProvider('adapters')]
  public function testEmptyDocumentsHaveNoElements(string $adapterClass): void
  {
    $dom = (new $adapterClass())->parse('');
    $this->assertNull($dom->select());
    $this->assertSame([], iterator_to_array($dom->selectAll('*')));
    $this->assertSame('', $dom->html());
    $this->assertSame('', $dom->text());
    $this->assertNull($dom->attribute('id'));
    $this->assertNull($dom->tagName());
  }

  public function testNativeDocumentReadsCoverAllFragmentRootsWithoutCommentText(): void
  {
    $dom = (new NativeHtmlAdapter())->parse('<!-- hidden --><p>One</p><p>Two <em>words</em></p>');
    $this->assertSame('OneTwo words', $dom->text());
    $this->assertSame('<!-- hidden --><p>One</p><p>Two <em>words</em></p>', $dom->html());
  }

  #[DataProvider('adapters')]
  public function testSelectorsAndNodeReadsHaveASharedContract(string $adapterClass): void
  {
    $dom = (new $adapterClass())->parse('<!-- lead --><h2 id="first">First</h2><div class="gallery">'
      . '<figure><img src="one.jpg" alt="One"><figcaption>One <em>photo</em></figcaption></figure>'
      . '<figure><img src="two.jpg"></figure></div>');
    $this->assertSame('first', $dom->select()->attribute('id'));
    $this->assertSame('h2', $dom->select('h1,h2,h3')->tagName());
    $this->assertSame('two.jpg', $dom->select('.gallery > figure:nth-child(2) img')->attribute('src'));
    $this->assertSame('One', $dom->select('figure img[alt]')->attribute('alt'));
    $this->assertNull($dom->select('aside'));
    $this->assertNull($dom->select('img')->attribute('title'));
    $this->assertSame('One <em>photo</em>', $dom->select('figcaption')->html());
    $this->assertSame('One photo', $dom->select('figcaption')->text());
    $figures = iterator_to_array($dom->selectAll('figure'));
    $this->assertCount(2, $figures);
    $this->assertSame('one.jpg', $figures[0]->select('img')->attribute('src'));
    $this->assertSame('two.jpg', $figures[1]->select('img')->attribute('src'));
    // Query fields can select the matched element itself, e.g. an img's src.
    $img = $figures[0]->select('img');
    $this->assertSame($img->attribute('src'), $img->select('img')->attribute('src'));
    $this->assertCount(1, iterator_to_array($img->selectAll('img')));
  }

  #[DataProvider('adapters')]
  public function testCoreTableSchemaExtractsNestedCellsWithoutReparsing(string $adapterClass): void
  {
    $metadata = json_decode(file_get_contents(ABSPATH . WPINC . '/blocks/table/block.json'), true);
    $adapter = new CountingHtmlAdapter(new $adapterClass());
    $html = '<figure><table><thead><tr><th scope="col">Title</th></tr></thead>'
      . '<tbody><tr><td data-align="right">One <strong>cell</strong></td><td colspan="2">Two</td></tr></tbody>'
      . '<tfoot><tr><th scope="row">Total</th></tr></tfoot></table><figcaption>Table caption</figcaption></figure>';
    $attrs = (new AttributeParser($adapter))->getAttributes($metadata['attributes'], [], $html);
    $this->assertSame([
      'hasFixedLayout' => true,
      'caption' => 'Table caption',
      'head' => [['cells' => [['content' => 'Title', 'tag' => 'th', 'scope' => 'col']]]],
      'body' => [['cells' => [
        ['content' => 'One <strong>cell</strong>', 'tag' => 'td', 'align' => 'right'],
        ['content' => 'Two', 'tag' => 'td', 'colspan' => '2'],
      ]]],
      'foot' => [['cells' => [['content' => 'Total', 'tag' => 'th', 'scope' => 'row']]]],
    ], $attrs);
    $this->assertSame([$html], $adapter->parsed);
  }

  #[DataProvider('adapters')]
  public function testEmptyQueryRowsAreOmittedWithoutTurningJsonArraysIntoObjects(string $adapterClass): void
  {
    $schema = ['source' => 'query', 'selector' => 'figure', 'query' => [
      'url' => ['source' => 'attribute', 'selector' => 'img', 'attribute' => 'src'],
    ]];
    $result = (new AttributeParser(new $adapterClass()))->getAttribute($schema,
      '<div><figure></figure><figure><img src="one.jpg"></figure><figure></figure><figure><img src="two.jpg"></figure></div>');
    $this->assertSame([['url' => 'one.jpg'], ['url' => 'two.jpg']], $result);
    $this->assertSame('[{"url":"one.jpg"},{"url":"two.jpg"}]', json_encode($result));
  }

  #[DataProvider('adapters')]
  public function testNestedQueryMetaUsesTheSamePostAndOnlyOneHtmlParse(string $adapterClass): void
  {
    TestEnvironment::$meta[42]['rating'] = '7';
    $adapter = new CountingHtmlAdapter(new $adapterClass());
    $schema = ['source' => 'query', 'selector' => 'ul', 'query' => [
      'items' => ['source' => 'query', 'selector' => 'li', 'query' => [
        'text' => ['source' => 'text', 'selector' => 'li'],
        'rating' => ['type' => 'integer', 'source' => 'meta', 'meta' => 'rating'],
      ]],
    ]];
    $this->assertSame([['items' => [['text' => 'One', 'rating' => 7], ['text' => 'Two', 'rating' => 7]]]],
      (new AttributeParser($adapter))->getAttribute($schema, '<ul><li>One</li><li>Two</li></ul>', 42));
    $this->assertCount(1, $adapter->parsed);
    $this->assertSame([[42, 'rating', true], [42, 'rating', true]], TestEnvironment::$metaRequests);
  }

  public function testDefaultsMetaAndStoredAttributesAvoidHtmlParsing(): void
  {
    TestEnvironment::$meta[42]['rating'] = '7';
    $adapter = new CountingHtmlAdapter();
    $parser = new AttributeParser($adapter);
    $this->assertSame([
      'content' => 'Stored', 'enabled' => false, 'count' => 0, 'items' => [], 'rating' => 7, 'default' => 'Fallback',
    ], $parser->getAttributes([
      'content' => ['source' => 'rich-text', 'selector' => 'p'],
      'enabled' => ['source' => 'attribute', 'selector' => 'input', 'attribute' => 'checked'],
      'count' => ['type' => 'integer', 'default' => 4],
      'items' => ['source' => 'query', 'selector' => 'li', 'query' => []],
      'rating' => ['type' => 'integer', 'source' => 'meta', 'meta' => 'rating'],
      'default' => ['type' => 'string', 'default' => 'Fallback'],
      'unknown' => ['source' => 'unsupported'],
    ], ['content' => 'Stored', 'enabled' => false, 'count' => 0, 'items' => []], '<p>Ignored</p>', 42));
    $this->assertNull($parser->getAttribute(['source' => 'text', 'selector' => 'p'], ' '));
    $this->assertSame([], $adapter->parsed);
  }

  public function testOneBatchParsesOnceButSeparateCallsUseFreshHtml(): void
  {
    $adapter = new CountingHtmlAdapter();
    $parser = new AttributeParser($adapter);
    $schema = [
      'text' => ['source' => 'text', 'selector' => 'p'],
      'id' => ['source' => 'attribute', 'selector' => 'p', 'attribute' => 'id'],
    ];
    $this->assertSame(['text' => 'One', 'id' => 'one'], $parser->getAttributes($schema, [], ['<p id="one">', null, 'One</p>']));
    $this->assertSame(['text' => 'Two', 'id' => 'two'], $parser->getAttributes($schema, [], '<p id="two">Two</p>'));
    $this->assertSame(['<p id="one">One</p>', '<p id="two">Two</p>'], $adapter->parsed);
  }

  public function testExplicitAdapterPropagatesThroughNestedBlocksAndSyncedPatterns(): void
  {
    $adapter = new CountingHtmlAdapter();
    add_filter('cloakwp/block_parser/html_adapter', static function () {
      throw new \LogicException('Explicit adapters should bypass the default filter.');
    });
    add_filter('cloakwp/block/include_rendered', '__return_false');
    TestEnvironment::post(7, '<!-- wp:paragraph --><p>Pattern</p><!-- /wp:paragraph -->');
    $parser = new BlockParser($adapter);
    $this->assertSame($adapter, $parser->getHtmlAdapter());
    $result = $parser->parseBlocksFromPost(TestEnvironment::post(42,
      '<!-- wp:group --><div><!-- wp:paragraph --><p>Nested</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
      . '<!-- wp:block {"ref":7} /-->'));
    $this->assertSame('Nested', $result[0]['innerBlocks'][0]['attrs']['content']);
    $this->assertSame('Pattern', $result[1]['attrs']['content']);
    $this->assertSame(['<p>Nested</p>', '<p>Pattern</p>'], $adapter->parsed);
  }

  public function testGlobalFilterSelectsAnAdapterForParserAndStandaloneExtraction(): void
  {
    $adapter = new CountingHtmlAdapter();
    add_filter('cloakwp/block_parser/html_adapter', static fn() => $adapter);
    $this->assertSame($adapter, (new BlockParser())->getHtmlAdapter());
    $this->assertSame('Selected', (new AttributeParser())->getAttribute(['source' => 'text', 'selector' => 'p'], '<p>Selected</p>'));
    $this->assertSame(['<p>Selected</p>'], $adapter->parsed);
  }

  public function testNativeIsTheDefault(): void
  {
    remove_all_filters('cloakwp/block_parser/html_adapter');
    $this->assertInstanceOf(NativeHtmlAdapter::class, (new BlockParser())->getHtmlAdapter());
  }

  public function testNativePreservesUtf8AndEscapesEntitiesWhenSerializingMarkup(): void
  {
    $dom = (new NativeHtmlAdapter())->parse('<p title="caf&eacute; &amp; tea">Café ☕ &amp; &lt;script&gt;safe&lt;/script&gt;</p>');
    $node = $dom->select('p');
    $this->assertSame('café & tea', $node->attribute('title'));
    $this->assertSame('Café ☕ & <script>safe</script>', $node->text());
    $this->assertSame('Café ☕ &amp; &lt;script&gt;safe&lt;/script&gt;', $node->html());
    $this->assertNull($node->select('script'));
  }

  public function testNativePreservesTextWhitespaceAndRecoversHtml5Markup(): void
  {
    $dom = (new NativeHtmlAdapter())->parse("<ul><li>First<li>Second</ul><p> A\n  B <em>C</em></p>");
    $this->assertSame(['First', 'Second'], array_map(fn($node) => $node->text(), iterator_to_array($dom->selectAll('li'))));
    $this->assertSame(" A\n  B C", $dom->select('p')->text());
  }

  public function testInvalidNativeSelectorsFailExplicitly(): void
  {
    $this->expectException(\DOMException::class);
    (new NativeHtmlAdapter())->parse('<p>Text</p>')->select('[');
  }

  #[DataProvider('adapters')]
  public function testBenchmarkFixturesHaveTheSameOutputs(string $adapterClass): void
  {
    $fixtures = require dirname(__DIR__) . '/benchmarks/fixtures.php';
    $native = new AttributeParser(new NativeHtmlAdapter());
    $parser = new AttributeParser(new $adapterClass());
    foreach ($fixtures as $fixture) {
      $expected = $native->getAttributes($fixture['schema'], $fixture['attrs'], $fixture['html']);
      $this->assertSame($expected, $parser->getAttributes($fixture['schema'], $fixture['attrs'], $fixture['html']));
    }
  }
}
