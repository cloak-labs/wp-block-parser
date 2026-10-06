<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\Acf\GutenbergGroupNesting;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class GutenbergGroupNestingTest extends ParserTestCase
{
  public function testMergesFlattenedSubfieldsWhenFormattedGroupIsEmpty(): void
  {
    $photoType = [
      'name' => 'photo_type',
      'type' => 'group',
      'sub_fields' => [
        ['name' => 'include', 'type' => 'taxonomy'],
        ['name' => 'exclude', 'type' => 'taxonomy'],
      ],
    ];

    $nested = GutenbergGroupNesting::merge(
      ['include' => false, 'exclude' => false],
      'photo_type',
      $photoType,
      [
        'photo_type' => '',
        'photo_type_include' => '',
        'photo_type_exclude' => ['29', '17'],
      ],
    );

    $this->assertSame(['29', '17'], $nested['exclude']);
    $this->assertArrayNotHasKey('include', $nested);
  }

  public function testNestsTabGroupsAndInnerTaxonomyGroups(): void
  {
    $queryField = [
      'name' => 'query',
      'type' => 'group',
      'sub_fields' => [
        ['name' => 'per_page', 'type' => 'number'],
        [
          'name' => 'photo_type',
          'type' => 'group',
          'sub_fields' => [
            ['name' => 'include', 'type' => 'taxonomy'],
            ['name' => 'exclude', 'type' => 'taxonomy'],
          ],
        ],
      ],
    ];

    $nested = GutenbergGroupNesting::merge(
      [],
      'query',
      $queryField,
      [
        'query' => '',
        'query_per_page' => '20',
        'query_photo_type' => '',
        'query_photo_type_include' => '',
        'query_photo_type_exclude' => ['29', '17', '5'],
      ],
    );

    $this->assertSame('20', $nested['per_page']);
    $this->assertSame(['29', '17', '5'], $nested['photo_type']['exclude']);
  }

  public function testPrefersFormattedValuesWhenTheyArePopulated(): void
  {
    $photoType = [
      'name' => 'photo_type',
      'type' => 'group',
      'sub_fields' => [
        ['name' => 'exclude', 'type' => 'taxonomy'],
      ],
    ];

    $nested = GutenbergGroupNesting::merge(
      ['exclude' => [29, 17]],
      'photo_type',
      $photoType,
      ['photo_type_exclude' => ['1']],
    );

    $this->assertSame([29, 17], $nested['exclude']);
  }

  public function testHasFlattenedChildrenDetectsGutenbergKeys(): void
  {
    $this->assertTrue(GutenbergGroupNesting::hasFlattenedChildren('query', [
      'query',
      'query_photo_type_exclude',
      '_query',
    ]));
    $this->assertFalse(GutenbergGroupNesting::hasFlattenedChildren('query', [
      'query',
      '_query',
      'filters_show',
    ]));
  }

  public function testIsBlankTreatsAcfEmptyTaxonomyFalseAsBlank(): void
  {
    $this->assertTrue(GutenbergGroupNesting::isBlank(false));
    $this->assertTrue(GutenbergGroupNesting::isBlank(''));
    $this->assertTrue(GutenbergGroupNesting::isBlank([]));
    $this->assertFalse(GutenbergGroupNesting::isBlank(['29']));
    $this->assertFalse(GutenbergGroupNesting::isBlank(0));
  }

  public function testTrueFalseKeepsFormattedFalseInsteadOfRawZeroString(): void
  {
    $carousel = [
      'name' => 'carousel_options',
      'type' => 'group',
      'sub_fields' => [
        ['name' => 'overflow_visible', 'type' => 'true_false'],
        ['name' => 'loop', 'type' => 'true_false'],
      ],
    ];

    $nested = GutenbergGroupNesting::merge(
      ['overflow_visible' => false, 'loop' => true],
      'carousel_options',
      $carousel,
      [
        'carousel_options' => '',
        'carousel_options_overflow_visible' => '0',
        'carousel_options_loop' => '1',
      ],
    );

    $this->assertFalse($nested['overflow_visible']);
    $this->assertTrue($nested['loop']);
  }

  public function testTrueFalseCoercesRawGutenbergStringsWhenGroupFormatIsEmpty(): void
  {
    $carousel = [
      'name' => 'carousel_options',
      'type' => 'group',
      'sub_fields' => [
        ['name' => 'overflow_visible', 'type' => 'true_false'],
        ['name' => 'autoplay', 'type' => 'true_false'],
      ],
    ];

    $nested = GutenbergGroupNesting::merge(
      [],
      'carousel_options',
      $carousel,
      [
        'carousel_options_overflow_visible' => '0',
        'carousel_options_autoplay' => '1',
      ],
    );

    $this->assertFalse($nested['overflow_visible']);
    $this->assertTrue($nested['autoplay']);
  }

  #[DataProvider('booleanValues')]
  public function testOnlyExplicitAcfOnValuesBecomeTrue(mixed $value, bool $expected): void
  {
    $this->assertSame($expected, GutenbergGroupNesting::coerceTrueFalse($value));
  }

  public static function booleanValues(): array
  {
    return [
      [true, true], [1, true], ['1', true],
      [false, false], [0, false], ['0', false], ['', false], [null, false],
      ['false', false], ['true', false], ['yes', false], [2, false],
    ];
  }

  public function testGroupsWithoutDefinitionsPreserveFormattedData(): void
  {
    $formatted = ['count' => 0, 'enabled' => false, 'custom' => ['value' => 'Kept']];
    $this->assertSame($formatted, GutenbergGroupNesting::merge($formatted, 'settings', [], ['settings_custom' => 'Ignored']));
  }

  public function testInvalidSubfieldDefinitionsAndUnrelatedFlattenedPrefixesAreIgnored(): void
  {
    $field = ['sub_fields' => [null, ['name' => ''], ['name' => 'count', 'type' => 'number']]];
    $this->assertSame(['count' => 0], GutenbergGroupNesting::merge([], 'settings', $field, ['settings_count' => 0]));
    $this->assertFalse(GutenbergGroupNesting::hasFlattenedChildren('query', [null, 7, 'queryish_value', '_query_value']));
  }
}
