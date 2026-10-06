<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\Helpers\AttributeParser;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use CloakWP\BlockParser\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

final class AttributeParserTest extends ParserTestCase
{
  #[DataProvider('htmlSources')]
  public function testExtractsHtmlSources(array $schema, string $html, mixed $expected): void
  {
    $this->assertSame($expected, (new AttributeParser())->getAttribute($schema, $html));
  }

  public static function htmlSources(): array
  {
    return [
      'element attribute' => [['source' => 'attribute', 'selector' => 'img', 'attribute' => 'src'], '<figure><img src="photo.jpg" alt="Photo"></figure>', 'photo.jpg'],
      'inner markup' => [['source' => 'html', 'selector' => 'p'], '<p>Hello <strong>world</strong>.</p>', 'Hello <strong>world</strong>.'],
      'rich text preserves links' => [['source' => 'rich-text', 'selector' => 'p'], '<p>Call <a href="tel:123">123</a>.</p>', 'Call <a href="tel:123">123</a>.'],
      'text strips tags' => [['source' => 'text', 'selector' => 'p'], '<p>Hello <em>world</em>.</p>', 'Hello world.'],
      'root attribute' => [['source' => 'attribute', 'attribute' => 'id'], '<h2 id="intro">Intro</h2>', 'intro'],
      'root html' => [['source' => 'html'], '<p>Root <em>content</em></p>', 'Root <em>content</em>'],
      'root text' => [['source' => 'text'], '<p>Root <em>content</em></p>', 'Root content'],
      'missing element' => [['source' => 'attribute', 'selector' => 'img', 'attribute' => 'src'], '<p>No image</p>', null],
      'missing attribute' => [['source' => 'attribute', 'selector' => 'img', 'attribute' => 'alt'], '<img src="photo.jpg">', null],
      'unknown source' => [['source' => 'unknown'], '<p>Ignored</p>', null],
      'blank html' => [['source' => 'html', 'selector' => 'p'], '   ', null],
    ];
  }

  public function testBatchExtractionMergesHtmlFragmentsAndPreservesExplicitFalsyValues(): void
  {
    $attrs = (new AttributeParser())->getAttributes([
      'title' => ['source' => 'text', 'selector' => 'h2'],
      'body' => ['source' => 'html', 'selector' => 'p'],
      'count' => ['type' => 'integer', 'default' => 10],
      'enabled' => ['type' => 'boolean', 'default' => true],
      'items' => ['type' => 'array', 'default' => ['fallback']],
      'unspecified' => ['type' => 'string'],
    ], [
      'title' => 'Authored title', 'body' => '', 'count' => 0, 'enabled' => false, 'items' => [], 'custom' => 'keep',
    ], ['<h2>HTML title</h2>', null, '<p>Body <em>copy</em>.</p>']);

    $this->assertSame([
      'title' => 'Authored title', 'body' => 'Body <em>copy</em>.', 'count' => 0, 'enabled' => false, 'items' => [], 'custom' => 'keep',
    ], $attrs);
  }

  #[DataProvider('defaultValues')]
  public function testDefaultsAndWordPressSchemaSanitization(array $schema, mixed $expected): void
  {
    $this->assertSame($expected, (new AttributeParser())->getAttribute($schema, ''));
  }

  public static function defaultValues(): array
  {
    return [
      'string' => [['type' => 'string', 'default' => 'fallback'], 'fallback'],
      'false' => [['type' => 'boolean', 'default' => false], false],
      'zero' => [['type' => 'integer', 'default' => 0], 0],
      'integer string' => [['type' => 'integer', 'default' => '42'], 42],
      'number string' => [['type' => 'number', 'default' => '2.5'], 2.5],
      'boolean string' => [['type' => 'boolean', 'default' => 'false'], false],
      'union type' => [['type' => ['integer', 'null'], 'default' => '5'], 5],
      'empty string omitted' => [['type' => 'string', 'default' => ''], null],
      'empty array omitted' => [['type' => 'array', 'default' => []], null],
      'missing integer has no fabricated zero' => [['type' => 'integer'], null],
      'missing number has no fabricated zero' => [['type' => 'number'], null],
      'missing boolean has no fabricated false' => [['type' => 'boolean'], null],
      'missing string' => [['type' => 'string'], null],
      'plugin schema type bypasses REST' => [['type' => 'rich-text', 'default' => '<em>copy</em>'], '<em>copy</em>'],
    ];
  }

  public function testQuerySourcesExtractEachMatchingRow(): void
  {
    $schema = [
      'type' => 'array', 'source' => 'query', 'selector' => 'figure',
      'query' => [
        'url' => ['type' => 'string', 'source' => 'attribute', 'selector' => 'img', 'attribute' => 'src'],
        'caption' => ['type' => 'string', 'source' => 'html', 'selector' => 'figcaption'],
      ],
    ];
    $html = '<div><figure><img src="one.jpg"><figcaption>One <em>photo</em></figcaption></figure>'
      . '<figure><img src="two.jpg"></figure><figure></figure></div>';

    $this->assertSame([
      ['url' => 'one.jpg', 'caption' => 'One <em>photo</em>'],
      ['url' => 'two.jpg'],
    ], (new AttributeParser())->getAttribute($schema, $html));
    $this->assertNull((new AttributeParser())->getAttribute($schema, '<p>No rows.</p>'));
  }

  public function testQuerySourcesCanContainNestedQueries(): void
  {
    $schema = ['source' => 'query', 'selector' => 'ul', 'query' => [
      'items' => ['source' => 'query', 'selector' => 'li', 'query' => [
        'text' => ['source' => 'text'],
      ]],
    ]];
    $this->assertSame([['items' => [['text' => 'One'], ['text' => 'Two']]]],
      (new AttributeParser())->getAttribute($schema, '<ul><li>One</li><li>Two</li></ul>'));
  }

  public function testMetaSourcesUseTheRequestedPostEvenWhenHtmlIsEmpty(): void
  {
    TestEnvironment::$meta[42]['rating'] = '7';
    $schema = ['type' => 'integer', 'source' => 'meta', 'meta' => 'rating', 'default' => 3];
    $parser = new AttributeParser();

    $this->assertSame(['rating' => 7], $parser->getAttributes(['rating' => $schema], [], '', 42));
    $this->assertSame(3, $parser->getAttribute($schema, '', 99));
    $this->assertSame([[42, 'rating', true], [99, 'rating', true]], TestEnvironment::$metaRequests);
  }
}
