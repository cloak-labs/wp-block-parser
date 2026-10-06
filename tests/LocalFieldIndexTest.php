<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use ACF_Data;
use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Acf\IndexedLocalFieldStore;
use CloakWP\BlockParser\Acf\LocalFieldIndex;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use CloakWP\BlockParser\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LocalFieldIndexTest extends ParserTestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    require_once ABSPATH . WPINC . '/class-wp-list-util.php';
    require_once __DIR__ . '/Support/acf-data.php';
    unset($GLOBALS['acf_stores']);
  }

  private function store(): ACF_Data
  {
    $store = new ACF_Data();
    $store->data = [
      'field_title' => ['name' => 'title', 'parent' => 'group_hero'],
      7 => ['name' => 'label', 'parent' => 'field_links'],
      'field_link' => ['name' => 'link', 'parent' => 'field_links'],
      'orphan' => ['name' => 'orphan'],
      'numeric' => ['name' => 'numeric', 'parent' => 12],
    ];
    return $store;
  }

  public function testQueriesPreserveKeysOrderAndWordPressMatchingSemantics(): void
  {
    $store = $this->store();
    $indexed = new IndexedLocalFieldStore($store);
    foreach ([
      [['parent' => 'field_links'], 'AND'],
      [['parent' => 'group_hero'], 'AND'],
      [['parent' => 'missing'], 'AND'],
      [['parent' => '12'], 'AND'],
      [['parent' => '012'], 'AND'],
      [['name' => 'link'], 'AND'],
      [['parent' => 'field_links', 'name' => 'label'], 'OR'],
      [['parent' => 'field_links'], 'NOT'],
      [[], 'AND'],
    ] as [$args, $operator]) {
      $this->assertSame($store->query($args, $operator), $indexed->query($args, $operator));
    }
  }

  public function testEditsThroughEitherHandleInvalidateTheIndexIncludingSameSizeEdits(): void
  {
    $store = $this->store();
    $indexed = new IndexedLocalFieldStore($store);
    $this->assertCount(2, $indexed->query(['parent' => 'field_links']));
    $store->set('field_link', ['name' => 'changed', 'parent' => 'group_hero']);
    $this->assertCount(1, $indexed->query(['parent' => 'field_links']));
    $indexed->set('new', ['name' => 'added', 'parent' => 'field_links']);
    $this->assertSame($store->query(['parent' => 'field_links']), $indexed->query(['parent' => 'field_links']));
    $this->assertSame('added', $store->data['new']['name']);
    $indexed->remove('new');
    $this->assertCount(1, $indexed->query(['parent' => 'field_links']));
    // ACF switches multisite registries by replacing the data array.
    $store->data = ['other_site' => ['parent' => 'field_links']];
    $this->assertSame($store->data, $indexed->query(['parent' => 'field_links']));
    $store->reset();
    $this->assertSame([], $indexed->query(['parent' => 'field_links']));
  }

  public function testUnexpectedParentTypesFallBackToWordPressLooseComparison(): void
  {
    $store = $this->store();
    $store->set('boolean', ['parent' => true]);
    $indexed = new IndexedLocalFieldStore($store);
    $this->assertSame($store->query(['parent' => 'field_links']), $indexed->query(['parent' => 'field_links']));
  }

  public function testScopeRestoresOriginalStoreAndReusesIndexAcrossBlocks(): void
  {
    $GLOBALS['acf_stores']['local-fields'] = $store = $this->store();
    $first = LocalFieldIndex::run(function () use ($store) {
      $indexed = $GLOBALS['acf_stores']['local-fields'];
      $this->assertInstanceOf(IndexedLocalFieldStore::class, $indexed);
      $this->assertSame($store->query(['parent' => 'field_links']), $indexed->query(['parent' => 'field_links']));
      $this->assertSame($indexed, LocalFieldIndex::run(fn() => $GLOBALS['acf_stores']['local-fields']));
      return $indexed;
    });
    $this->assertSame($store, $GLOBALS['acf_stores']['local-fields']);
    $this->assertSame($first, LocalFieldIndex::run(fn() => $GLOBALS['acf_stores']['local-fields']));
    $this->assertSame($store, $GLOBALS['acf_stores']['local-fields']);
  }

  public function testScopeRestoresStoreWhenAFieldFilterThrows(): void
  {
    $GLOBALS['acf_stores']['local-fields'] = $store = $this->store();
    try {
      LocalFieldIndex::run(fn() => throw new \RuntimeException('field filter failed'));
      $this->fail('The exception must propagate.');
    } catch (\RuntimeException $error) {
      $this->assertSame('field filter failed', $error->getMessage());
    }
    $this->assertSame($store, $GLOBALS['acf_stores']['local-fields']);
  }

  public function testScopeHonorsOptOutAndCustomStores(): void
  {
    $GLOBALS['acf_stores']['local-fields'] = $store = $this->store();
    add_filter('cloakwp/block_parser/index_acf_fields', '__return_false');
    $this->assertSame($store, LocalFieldIndex::run(fn() => $GLOBALS['acf_stores']['local-fields']));
    remove_filter('cloakwp/block_parser/index_acf_fields', '__return_false');
    $custom = new class extends ACF_Data {};
    $GLOBALS['acf_stores']['local-fields'] = $custom;
    $this->assertSame($custom, LocalFieldIndex::run(fn() => $GLOBALS['acf_stores']['local-fields']));
    unset($GLOBALS['acf_stores']);
    $this->assertSame('no ACF', LocalFieldIndex::run(fn() => 'no ACF'));
  }

  public function testScopePreservesDeliberateStoreReplacementByAFilter(): void
  {
    $GLOBALS['acf_stores']['local-fields'] = $this->store();
    $replacement = $this->store();
    LocalFieldIndex::run(function () use ($replacement) {
      $GLOBALS['acf_stores']['local-fields'] = $replacement;
    });
    $this->assertSame($replacement, $GLOBALS['acf_stores']['local-fields']);
  }

  public function testParserScopesIndexAroundRenderingAndBlockFilters(): void
  {
    TestEnvironment::$isAdmin = false;
    $GLOBALS['acf_stores']['local-fields'] = $store = $this->store();
    $this->registerBlock('core/field-output', [], ['render_callback' => function () use ($store) {
      $current = $GLOBALS['acf_stores']['local-fields'];
      $this->assertInstanceOf(IndexedLocalFieldStore::class, $current);
      $this->assertSame($store->query(['parent' => 'field_links']), $current->query(['parent' => 'field_links']));
      return '<p>Fields</p>';
    }]);
    add_filter('cloakwp/block', function ($parsed) {
      $this->assertInstanceOf(IndexedLocalFieldStore::class, $GLOBALS['acf_stores']['local-fields']);
      return $parsed;
    });
    $result = (new BlockParser())->transformBlock($this->block('core/field-output'), 42);
    $this->assertSame('<p>Fields</p>', $result['rendered']);
    $this->assertSame($store, $GLOBALS['acf_stores']['local-fields']);
  }
}
