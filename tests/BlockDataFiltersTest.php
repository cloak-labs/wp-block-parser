<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\Acf\BlockDataFilters;
use PHPUnit\Framework\TestCase;

final class BlockDataFiltersTest extends TestCase
{
  protected function setUp(): void
  {
    $GLOBALS['wp_filters'] = [];
  }

  protected function tearDown(): void
  {
    $GLOBALS['wp_filters'] = [];
  }

  public function testApplyPassesParsedBlockDefinitionsSourceBlockAndPostId(): void
  {
    $captured = [];

    add_filter('cloakwp/block/data', function (array $parsedBlock, array $fieldDefinitions, mixed $block, mixed $postId) use (&$captured) {
      $captured = [
        'parsed' => $parsedBlock,
        'definitions' => $fieldDefinitions,
        'block' => $block,
        'postId' => $postId,
      ];

      $parsedBlock['data']['members'] = [['id' => 1]];

      return $parsedBlock;
    }, 10, 4);

    $parsed = [
      'name' => 'acf/team',
      'type' => 'acf',
      'data' => ['card_style' => 'default'],
    ];
    $definitions = [
      [
        'name' => 'query',
        'type' => 'group',
        'cloakwp_query_id' => 'q_test',
        'sub_fields' => [
          ['name' => 'selection', 'type' => 'radio'],
        ],
      ],
    ];
    $source = (object) ['name' => 'acf/team'];

    $result = BlockDataFilters::apply($parsed, $definitions, $source, 42);

    $this->assertSame($parsed, $captured['parsed']);
    $this->assertSame('q_test', $captured['definitions'][0]['cloakwp_query_id']);
    $this->assertSame('selection', $captured['definitions'][0]['sub_fields'][0]['name']);
    $this->assertSame($source, $captured['block']);
    $this->assertSame(42, $captured['postId']);
    $this->assertSame([['id' => 1]], $result['data']['members']);
    $this->assertSame('default', $result['data']['card_style']);
  }

  public function testApplyRunsBeforeASimulatedBlockValueCallback(): void
  {
    add_filter('cloakwp/block/data', function (array $parsedBlock) {
      $parsedBlock['data']['members'] = [['id' => 7, 'name' => 'Ada']];
      return $parsedBlock;
    }, 10, 4);

    $parsed = BlockDataFilters::apply(
      ['name' => 'acf/team', 'data' => ['card_style' => 'stills']],
      [],
      (object) ['name' => 'acf/team'],
      1,
    );

    $afterBlockFilter = apply_filters('cloakwp/block', $parsed, (object) ['name' => 'acf/team'], 1);
    $afterBlockFilter['data']['layout'] = 'grid';

    $this->assertSame([['id' => 7, 'name' => 'Ada']], $afterBlockFilter['data']['members']);
    $this->assertSame('grid', $afterBlockFilter['data']['layout']);
  }
}
