<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use CloakWP\BlockParser\Tests\Support\TestEnvironment;
use CloakWP\BlockParser\Transformers\ACFBlockTransformer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_Block;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ACFBlockTransformerTest extends ParserTestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    require_once __DIR__ . '/Support/acf.php';
    $this->registerBlock('acf/hero', ['data' => ['type' => 'object']]);
  }

  private function field(string $name, string $type = 'text', array $extra = []): array
  {
    return TestEnvironment::$acfFields['field_' . $name] = $extra + [
      'ID' => 1, 'key' => 'field_' . $name, 'name' => $name, 'type' => $type, 'parent' => 'group_hero',
    ];
  }

  private function transform(array $data, array $attrs = []): array
  {
    return (new ACFBlockTransformer())->transform($this->wpBlock('acf/hero', ['data' => $data] + $attrs), 42);
  }

  public function testFormatsFieldValuesAndRemovesAcfEditorMetadataFromAttributes(): void
  {
    $this->field('title');
    $this->field('photo', 'image');
    TestEnvironment::$acfFormattedValues['photo'] = ['url' => 'photo.jpg', 'alt' => 'A photo', 'caption' => ''];
    $result = $this->transform([
      'title' => 'Hero title', '_title' => 'field_title', 'photo' => 7, '_photo' => 'field_photo',
      'custom' => ['keep' => 'unregistered value'],
    ], ['name' => 'acf/hero', 'mode' => 'preview', 'align' => 'full', 'className' => 'hero']);

    $this->assertSame([
      'name' => 'acf/hero', 'type' => 'acf', 'attrs' => ['align' => 'full', 'className' => 'hero'],
      'data' => ['title' => 'Hero title', 'photo' => ['url' => 'photo.jpg', 'alt' => 'A photo'], 'custom' => ['keep' => 'unregistered value']],
    ], $result);
    $this->assertSame('photo', TestEnvironment::$acfFormatRequests[0][2]['name']);
    $this->assertCount(1, TestEnvironment::$acfFormatRequests);
  }

  #[DataProvider('emptyFieldValues')]
  public function testEmptyLeafFieldsAreOmittedWithoutLoadingTheirDefinitions(mixed $value): void
  {
    $this->field('unused', 'flexible_content');
    $result = $this->transform(['unused' => $value, '_unused' => 'field_unused']);
    $this->assertSame([], $result['data']);
    $this->assertSame([], TestEnvironment::$acfDefinitionRequests);
    $this->assertSame([], TestEnvironment::$acfFormatRequests);
  }

  public static function emptyFieldValues(): array
  {
    return ['null' => [null], 'empty string' => [''], 'empty array' => [[]]];
  }

  public function testZeroFalseAndNumericStringsArePreserved(): void
  {
    foreach (['count', 'enabled', 'string_zero'] as $name) $this->field($name);
    $result = $this->transform([
      'count' => 0, '_count' => 'field_count', 'enabled' => false, '_enabled' => 'field_enabled',
      'string_zero' => '0', '_string_zero' => 'field_string_zero',
    ]);
    $this->assertSame(['count' => 0, 'enabled' => false, 'string_zero' => '0'], $result['data']);
  }

  public function testNestedGroupsConsumeFlattenedChildrenAndRetainFormattedValues(): void
  {
    $this->field('query', 'group', ['sub_fields' => [
      ['name' => 'per_page', 'type' => 'number'],
      ['name' => 'filters', 'type' => 'group', 'sub_fields' => [
        ['name' => 'excluded', 'type' => 'taxonomy'],
        ['name' => 'enabled', 'type' => 'true_false'],
      ]],
    ]]);
    $this->field('query_per_page', 'number', ['parent' => 'field_query']);
    $this->field('query_filters', 'group', ['parent' => 'field_query']);
    $this->field('query_filters_excluded', 'taxonomy', ['parent' => 'field_query_filters']);
    $this->field('query_filters_enabled', 'true_false', ['parent' => 'field_query_filters']);
    TestEnvironment::$acfFormattedValues['query'] = ['per_page' => 12, 'filters' => ['excluded' => false, 'enabled' => false]];
    $result = $this->transform([
      'query' => '', '_query' => 'field_query',
      'query_per_page' => '20', '_query_per_page' => 'field_query_per_page',
      'query_filters' => '', '_query_filters' => 'field_query_filters',
      'query_filters_excluded' => ['29'], '_query_filters_excluded' => 'field_query_filters_excluded',
      'query_filters_enabled' => '0', '_query_filters_enabled' => 'field_query_filters_enabled',
    ]);

    $this->assertSame(['query' => ['per_page' => 12, 'filters' => ['excluded' => ['29'], 'enabled' => false]]], $result['data']);
    $this->assertCount(1, TestEnvironment::$acfFormatRequests);
    $this->assertSame('query', TestEnvironment::$acfFormatRequests[0][2]['name']);
  }

  public function testSubfieldsWithMissingParentsDoNotLeakIntoTopLevelData(): void
  {
    $this->field('group_child', 'text', ['parent' => 'field_deleted_parent']);
    $result = $this->transform(['group_child' => 'Child', '_group_child' => 'field_group_child']);
    $this->assertSame([], $result['data']);
  }

  public function testTabsAndAccordionsAreRemovedFromNestedFormattingDefinitions(): void
  {
    $this->field('tab', 'tab');
    $this->field('accordion', 'accordion');
    $this->field('settings', 'group', ['sub_fields' => [
      ['name' => 'label', 'type' => 'text'],
      ['name' => 'tab', 'type' => 'tab'],
      ['name' => 'nested', 'type' => 'group', 'sub_fields' => [
        ['name' => 'accordion', 'type' => 'accordion'],
        ['name' => 'value', 'type' => 'text'],
      ]],
    ]]);
    TestEnvironment::$acfFormattedValues['settings'] = ['label' => 'Shown', 'nested' => ['value' => 'Kept']];
    $result = $this->transform([
      'tab' => 'layout', '_tab' => 'field_tab', 'accordion' => 'layout', '_accordion' => 'field_accordion',
      'settings' => 'present', '_settings' => 'field_settings',
    ]);
    $field = TestEnvironment::$acfFormatRequests[0][2];
    $this->assertSame(['label', 'nested'], array_column($field['sub_fields'], 'name'));
    $this->assertSame(['value'], array_column($field['sub_fields'][2]['sub_fields'], 'name'));
    $this->assertSame(['settings' => ['label' => 'Shown', 'nested' => ['value' => 'Kept']]], $result['data']);
  }

  #[DataProvider('formattingTypes')]
  public function testComplexFieldsUseAcfFormattingWhileSimpleFieldsKeepTheirSavedValue(string $type, mixed $raw, bool $formatted): void
  {
    $this->field('value', $type);
    TestEnvironment::$acfFormattedValues['value'] = ['resolved' => 'formatted'];
    $result = $this->transform(['value' => $raw, '_value' => 'field_value']);

    $this->assertSame($formatted ? ['resolved' => 'formatted'] : $raw, $result['data']['value']);
    $this->assertCount($formatted ? 1 : 0, TestEnvironment::$acfFormatRequests);
  }

  public static function formattingTypes(): array
  {
    $cases = [];
    foreach (['repeater', 'flexible_content', 'relationship', 'page_link', 'post_object', 'gallery', 'file'] as $type) {
      $cases[$type] = [$type, 7, true];
    }
    $cases['image id'] = ['image', 7, true];
    $cases['already formatted image'] = ['image', ['url' => 'photo.jpg'], false];
    $cases['text'] = ['text', 'Copy', false];
    return $cases;
  }

  #[DataProvider('trueFalseValues')]
  public function testTrueFalseFieldsAlwaysReturnBooleans(mixed $raw, bool $expected): void
  {
    $this->field('enabled', 'true_false');
    $result = $this->transform(['enabled' => $raw, '_enabled' => 'field_enabled']);
    $this->assertSame($expected, $result['data']['enabled']);
  }

  public static function trueFalseValues(): array
  {
    return ['off string' => ['0', false], 'on string' => ['1', true], 'off bool' => [false, false], 'on bool' => [true, true], 'zero' => [0, false], 'one' => [1, true]];
  }

  public function testFieldFiltersCanTransformValuesBeforeTheDataAndBlockFiltersRun(): void
  {
    $this->field('title');
    $events = [];
    add_filter('cloakwp/block/field/type=text', function ($value, array $field) use (&$events) {
      $events[] = 'field';
      $this->assertSame('title', $field['name']);
      return strtoupper($value);
    }, 10, 2);
    $emptyDefinition = ['name' => 'query', 'type' => 'group', 'sub_fields' => [['name' => 'selection', 'type' => 'radio']]];
    TestEnvironment::$acfDefinitions = ['query' => $emptyDefinition];
    add_filter('cloakwp/block/data', function (array $parsed, array $definitions, WP_Block $source, ?int $postId) use (&$events, $emptyDefinition) {
      $events[] = 'data';
      $this->assertSame('TITLE', $parsed['data']['title']);
      $this->assertSame([$emptyDefinition], $definitions);
      $this->assertSame('acf/hero', $source->name);
      $this->assertSame(42, $postId);
      $parsed['data']['items'] = [['id' => 7]];
      return $parsed;
    }, 10, 4);
    add_filter('cloakwp/block', function (array $parsed) use (&$events) {
      $events[] = 'block';
      $this->assertSame([['id' => 7]], $parsed['data']['items']);
      return $parsed;
    }, 20);

    $result = (new BlockParser())->transformBlock($this->block('acf/hero', ['data' => ['title' => 'Title', '_title' => 'field_title']]), 42);
    $this->assertSame(['field', 'data', 'block'], $events);
    $this->assertSame('TITLE', $result['data']['title']);
    $this->assertSame('acf/hero', TestEnvironment::$acfBlockRequests[0]['name']);
  }

  public function testMissingFieldDefinitionsAreSkippedButUnregisteredValuesRemain(): void
  {
    $result = $this->transform(['deleted' => 'Old', '_deleted' => 'field_missing', 'custom' => 'Kept', '_private' => 'not-an-acf-key']);
    $this->assertSame(['custom' => 'Kept', '_private' => 'not-an-acf-key'], $result['data']);
  }

  public function testFieldNameAndBlockNameModifiersTargetTheDocumentedContext(): void
  {
    $this->field('title');
    $this->registerBlock('acf/footer', ['data' => ['type' => 'object']]);
    $hits = [];
    add_filter('cloakwp/block/field/name=title', function ($value) use (&$hits) {
      $hits[] = 'title';
      return $value . ' by name';
    });
    add_filter('cloakwp/block/field/blockName=acf/hero', function ($value, array $field) use (&$hits) {
      $hits[] = 'hero';
      $this->assertSame('title', $field['name']);
      return $value . ' in hero';
    }, 10, 2);
    $parser = new BlockParser();
    $data = ['title' => 'Copy', '_title' => 'field_title'];
    $hero = $parser->transformBlock($this->block('acf/hero', ['data' => $data]), 42);
    $footer = $parser->transformBlock($this->block('acf/footer', ['data' => $data]), 42);

    $this->assertSame('Copy by name in hero', $hero['data']['title']);
    $this->assertSame('Copy by name', $footer['data']['title']);
    $this->assertSame(['title', 'hero', 'title'], $hits);
  }

  public function testNestedFormattedDataDropsEmptyStringsAndNullsButPreservesBooleansAndZero(): void
  {
    $this->field('rows', 'repeater');
    TestEnvironment::$acfFormattedValues['rows'] = [[
      'title' => 'Row', 'empty' => '', 'null' => null, 'zero' => 0, 'string_zero' => '0', 'false' => false,
      'nested' => ['empty' => '', 'value' => 'Kept'],
    ]];
    $result = $this->transform(['rows' => 1, '_rows' => 'field_rows']);
    $this->assertSame([[
      'title' => 'Row', 'zero' => 0, 'string_zero' => '0', 'false' => false, 'nested' => ['value' => 'Kept'],
    ]], $result['data']['rows']);
  }

  public function testDataSchemaClassifiesNonAcfNamedBlocksAsAcf(): void
  {
    $this->registerBlock('example/hero', ['data' => ['type' => 'object']]);
    $result = (new BlockParser())->transformBlock($this->block('example/hero', ['data' => ['custom' => 'value']]), 42);
    $this->assertSame('acf', $result['type']);
    $this->assertSame(['custom' => 'value'], $result['data']);
  }

  public function testBlocksWithoutSavedFieldDataReturnAnEmptyDataArray(): void
  {
    $result = (new ACFBlockTransformer())->transform($this->wpBlock('acf/hero'), 42);
    $this->assertSame([], $result['data']);
  }
}
