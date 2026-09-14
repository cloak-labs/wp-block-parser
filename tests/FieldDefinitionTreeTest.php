<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\Acf\FieldDefinitionTree;
use PHPUnit\Framework\TestCase;

final class FieldDefinitionTreeTest extends TestCase
{
  public function testMapByNamePreservesNestedSubFields(): void
  {
    $fields = [
      [
        'name' => 'card_style',
        'type' => 'radio',
      ],
      [
        'name' => 'query',
        'type' => 'group',
        'cloakwp_query_id' => 'q_1',
        'sub_fields' => [
          ['name' => 'selection', 'type' => 'radio'],
          [
            'name' => 'taxonomies',
            'type' => 'group',
            'sub_fields' => [
              ['name' => 'category', 'type' => 'taxonomy'],
            ],
          ],
        ],
      ],
    ];

    $map = FieldDefinitionTree::mapByName($fields);

    $this->assertSame('radio', $map['card_style']['type']);
    $this->assertSame('q_1', $map['query']['cloakwp_query_id']);
    $this->assertSame('radio', $map['query']['sub_fields']['selection']['type']);
    $this->assertSame('taxonomy', $map['query']['sub_fields']['taxonomies']['sub_fields']['category']['type']);
  }

  public function testAsListAcceptsNameKeyedMaps(): void
  {
    $list = FieldDefinitionTree::asList([
      'query' => ['name' => 'query', 'type' => 'group'],
    ]);

    $this->assertCount(1, $list);
    $this->assertSame('query', $list[0]['name']);
  }
}
