<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\Acf\FieldDefinitionTree;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;

final class FieldDefinitionTreeTest extends ParserTestCase
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

  public function testSkipsMalformedDefinitionsAndKeepsTheLastDuplicateName(): void
  {
    $fields = [
      false, null, 'invalid', [], ['name' => ''], ['name' => 7],
      ['name' => 'title', 'type' => 'text'],
      ['name' => 'title', 'type' => 'textarea'],
      ['name' => 'group', 'type' => 'group', 'sub_fields' => [null, ['name' => 'label', 'type' => 'text']]],
    ];
    $original = $fields;
    $this->assertSame([
      'title' => ['name' => 'title', 'type' => 'textarea'],
      'group' => ['name' => 'group', 'type' => 'group', 'sub_fields' => ['label' => ['name' => 'label', 'type' => 'text']]],
    ], FieldDefinitionTree::mapByName($fields));
    $this->assertSame($original, $fields);
    $this->assertSame([], FieldDefinitionTree::asList([]));
  }
}
