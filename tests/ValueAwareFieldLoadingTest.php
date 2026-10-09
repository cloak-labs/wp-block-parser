<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use ACF_Data;
use CloakWP\BlockParser\Acf\ValueAwareFieldLoading;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use CloakWP\BlockParser\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValueAwareFieldLoadingTest extends ParserTestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    require_once __DIR__ . '/Support/acf-data.php';
    $GLOBALS['acf_stores']['fields'] = new ACF_Data();
    add_filter('cloakwp/block_parser/value_aware_fields', '__return_true', 0);
  }

  private function loads(string $key, array $settings = []): bool
  {
    return apply_filters('cloakwp/acf/inner_blocks/load_layouts', true, ['key' => $key] + $settings);
  }

  public function testEmptyDefinitionsStayOutOfTheNormalCacheAndALaterBlockCanLoadThem(): void
  {
    $original = $GLOBALS['acf_stores']['fields'];
    $original->data = ['field_other' => ['layouts' => ['complete']]];
    $original->aliases = ['other' => 'field_other'];
    ValueAwareFieldLoading::run(['blocks' => [], '_blocks' => 'field_blocks'], function () use ($original) {
      $this->assertFalse($this->loads('field_blocks'));
      $this->assertNotSame($original, $GLOBALS['acf_stores']['fields']);
      $GLOBALS['acf_stores']['fields']->set('field_blocks', ['layouts' => []]);
    });
    $this->assertSame($original, $GLOBALS['acf_stores']['fields']);
    $this->assertArrayNotHasKey('field_blocks', $original->data);
    $this->assertSame(['other' => 'field_other'], $original->aliases);

    ValueAwareFieldLoading::run(['blocks' => ['acf/text'], '_blocks' => 'field_blocks'], function () {
      $this->assertTrue($this->loads('field_blocks'));
      $this->assertArrayNotHasKey('field_blocks', $GLOBALS['acf_stores']['fields']->data);
    });
    $this->assertTrue($this->loads('field_blocks'));
  }

  public function testFlattenedRowsAndNestedArraysLoadAFieldIfAnyOccurrenceIsPopulated(): void
  {
    ValueAwareFieldLoading::run([
      'rows_0_blocks' => [], '_rows_0_blocks' => 'field_blocks',
      'rows_1_blocks' => ['acf/text'], '_rows_1_blocks' => 'field_blocks',
      'nested' => [['blocks' => ['acf/image'], '_blocks' => 'field_nested']],
      'zero' => '0', '_zero' => 'field_zero',
      'false' => false, '_false' => 'field_false',
    ], function () {
      $this->assertTrue($this->loads('field_blocks'));
      $this->assertTrue($this->loads('field_nested'));
      $this->assertFalse($this->loads('field_zero'));
      $this->assertFalse($this->loads('field_false'));
      $this->assertFalse($this->loads('field_omitted'));
      $this->assertTrue($this->loads('field_default', ['default_value' => ['acf/text']]));
    });
  }

  public function testNestedParsingUsesItsOwnValuesAndRestoresTheOuterView(): void
  {
    $original = $GLOBALS['acf_stores']['fields'];
    ValueAwareFieldLoading::run([], function () {
      $outer = $GLOBALS['acf_stores']['fields'];
      $outer->set('field_blocks', ['layouts' => []]);
      ValueAwareFieldLoading::run(['blocks' => ['acf/text'], '_blocks' => 'field_blocks'], function () {
        $this->assertTrue($this->loads('field_blocks'));
        $this->assertArrayNotHasKey('field_blocks', $GLOBALS['acf_stores']['fields']->data);
      });
      $this->assertSame($outer, $GLOBALS['acf_stores']['fields']);
      $this->assertFalse($this->loads('field_blocks'));
    });
    $this->assertSame($original, $GLOBALS['acf_stores']['fields']);
  }

  public function testAnExceptionRestoresTheCacheAndRemovesTheLayoutFilter(): void
  {
    $original = $GLOBALS['acf_stores']['fields'];
    try {
      ValueAwareFieldLoading::run([], static fn() => throw new \RuntimeException('formatting failed'));
      $this->fail('The exception must propagate.');
    } catch (\RuntimeException $error) {
      $this->assertSame('formatting failed', $error->getMessage());
    }
    $this->assertSame($original, $GLOBALS['acf_stores']['fields']);
    $this->assertTrue($this->loads('field_blocks'));
  }

  public function testCustomStoresAndOptOutRetainNormalLoading(): void
  {
    $custom = new class extends ACF_Data {};
    $GLOBALS['acf_stores']['fields'] = $custom;
    ValueAwareFieldLoading::run([], function () use ($custom) {
      $this->assertSame($custom, $GLOBALS['acf_stores']['fields']);
      $this->assertTrue($this->loads('field_blocks'));
    });
    $GLOBALS['acf_stores']['fields'] = $original = new ACF_Data();
    add_filter('cloakwp/block_parser/value_aware_fields', '__return_false');
    ValueAwareFieldLoading::run([], function () use ($original) {
      $this->assertSame($original, $GLOBALS['acf_stores']['fields']);
      $this->assertTrue($this->loads('field_blocks'));
    });
  }

  public function testWithoutAnIntegrationOptInTheNormalCacheIsUsed(): void
  {
    remove_filter('cloakwp/block_parser/value_aware_fields', '__return_true', 0);
    $original = $GLOBALS['acf_stores']['fields'];
    ValueAwareFieldLoading::run([], function () use ($original) {
      $this->assertSame($original, $GLOBALS['acf_stores']['fields']);
      $this->assertTrue($this->loads('field_blocks'));
    });
  }

  public function testRequiredLayoutsCombineOccurrencesAndNestedSavedRows(): void
  {
    ValueAwareFieldLoading::run([
      'rows_0_blocks' => ['acf/text'], '_rows_0_blocks' => 'field_blocks',
      'rows_1_blocks' => [['acf_fc_layout' => 'acf/image']], '_rows_1_blocks' => 'field_blocks',
    ], function () {
      $field = ['key' => 'field_blocks'];
      $this->assertSame(['acf/text', 'acf/image'], ValueAwareFieldLoading::requiredLayouts(null, $field));
      $this->assertSame(['acf/image'], ValueAwareFieldLoading::requiredLayouts(['acf/image', 'acf/other'], $field));
      ValueAwareFieldLoading::run(['blocks' => ['acf/other'], '_blocks' => 'field_blocks'], function () use ($field) {
        $this->assertSame(['acf/other'], ValueAwareFieldLoading::requiredLayouts(null, $field));
      });
      $this->assertSame(['acf/text', 'acf/image'], ValueAwareFieldLoading::requiredLayouts(null, $field));
    });
    $this->assertNull(ValueAwareFieldLoading::requiredLayouts(null, ['key' => 'field_blocks']));
  }

  public function testUnknownShapesAndDefaultsRetainEveryLayout(): void
  {
    ValueAwareFieldLoading::run([
      'first' => ['acf/text'], '_first' => 'field_blocks',
      'second' => ['custom' => 'serialized'], '_second' => 'field_blocks',
      'default' => ['acf/text'], '_default' => 'field_default',
    ], function () {
      $this->assertNull(ValueAwareFieldLoading::requiredLayouts(null, ['key' => 'field_blocks']));
      $this->assertNull(ValueAwareFieldLoading::requiredLayouts(null, ['key' => 'field_default', 'default_value' => ['acf/image']]));
    });
  }

  public function testCachedParentProjectionPreservesNormalAndEagerDefinitions(): void
  {
    $layouts = [['name' => 'acf/text'], ['name' => 'acf/image']];
    $field = ['key' => 'field_blocks', 'cloakwp_inner_blocks' => true,
      'cloakwp_lazy_layouts' => true, 'layouts' => $layouts];
    $original = $GLOBALS['acf_stores']['fields'];
    $original->data = ['field_parent' => ['sub_fields' => [$field, $field + ['other' => true]]]];
    $original->data['field_parent']['sub_fields'][1]['cloakwp_lazy_layouts'] = false;
    ValueAwareFieldLoading::run(['blocks' => ['acf/image'], '_blocks' => 'field_blocks'], function () use ($layouts) {
      $children = $GLOBALS['acf_stores']['fields']->data['field_parent']['sub_fields'];
      $this->assertSame([['name' => 'acf/image']], $children[0]['layouts']);
      $this->assertSame($layouts, $children[1]['layouts']);
    });
    $this->assertSame($layouts, $original->data['field_parent']['sub_fields'][0]['layouts']);
  }

  public function testSiteSwitchesDoNotRestoreThePreviousSitesCachedFields(): void
  {
    $original = $GLOBALS['acf_stores']['fields'];
    $original->multisite = true;
    $original->data = ['field_site_one' => []];
    ValueAwareFieldLoading::run([], function () {
      TestEnvironment::$siteId = 2;
      $GLOBALS['acf_stores']['fields']->switch_site(2, 1);
      ValueAwareFieldLoading::run([], function () {
        $this->assertArrayNotHasKey('field_site_one', $GLOBALS['acf_stores']['fields']->data);
      });
    });
    $this->assertSame($original, $GLOBALS['acf_stores']['fields']);
    $this->assertSame([], $original->data);
    $this->assertSame(['field_site_one' => []], $original->site_data[1]);
  }
}
