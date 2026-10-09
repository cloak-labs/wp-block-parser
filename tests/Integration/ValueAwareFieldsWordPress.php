<?php

declare(strict_types=1);

// wp eval-file tests/Integration/ValueAwareFieldsWordPress.php --use-include --url=<site>
use CloakWP\ACF\{Block, BlockRegistry};
use CloakWP\ACF\Fields\{InnerBlocks, Query};
use CloakWP\BlockParser\Transformers\ACFBlockTransformer;
use CloakWP\BlockParser\Acf\ValueAwareFieldLoading;
use Extended\ACF\Fields\{Group, Number, Repeater, Text};
use Extended\ACF\Location;

if (!function_exists('acf_get_field')) {
  throw new RuntimeException('Run through WP-CLI with ACF and CloakWP active.');
}
$checks = 0;
$same = static function ($expected, $actual, string $message) use (&$checks): void {
  if ($expected !== $actual) {
    throw new RuntimeException($message . ': ' . var_export([$expected, $actual], true));
  }
  $checks++;
};

foreach (['child', 'other'] as $suffix) {
BlockRegistry::getInstance()->addBlock(new class($suffix) extends Block {
  public function __construct(private string $suffix) {}

  public function getFieldGroupSettings(): array
  {
    return [
      'name' => 'acf/value-aware-' . $this->suffix, 'key' => 'block_value_aware_' . $this->suffix,
      'title' => 'Value-aware child',
      'fields' => [Text::make('Title'), Number::make('Count')],
    ];
  }
});
}
$queryCalls = 0;
$settings = register_extended_field_group([
  'title' => 'Value-aware test', 'key' => 'value_aware_test',
  'location' => [Location::where('block', 'acf/value-aware-container')],
  'fields' => [
    Group::make('Grid')->fields([
      Text::make('Label'),
      InnerBlocks::make('Blocks')->include(['acf/value-aware-child', 'acf/value-aware-other']),
    ]),
    Repeater::make('Entries')->fields([
      InnerBlocks::make('Blocks')->include(['acf/value-aware-child', 'acf/value-aware-other']),
    ]),
    Query::make()->postType('page')->withLimit(1, 1)->into('items')
      ->resolveUsing(static function () use (&$queryCalls): array {
        $queryCalls++;
        return [(object) ['ID' => 123]];
      })
      ->mapUsing(static fn($post): array => ['id' => $post->ID]),
    InnerBlocks::make('Unused')->include(['acf/value-aware-child', 'acf/value-aware-other']),
    InnerBlocks::make('Eager')->include(['acf/value-aware-child', 'acf/value-aware-other'])->lazyLayouts(false),
  ],
]);
[$grid, $entries] = $settings['fields'];
[$label, $blocks] = $grid['sub_fields'];
$entryBlocks = $entries['sub_fields'][0];
$unused = $settings['fields'][3];
$definitions = [];
add_filter('cloakwp/block/data', static function ($parsed, $fields) use (&$definitions) {
  $definitions = $fields;
  return $parsed;
}, 10, 2);
register_block_type('acf/value-aware-container', ['attributes' => ['data' => ['type' => 'object']]]);
$transform = static fn(array $data): array => (new ACFBlockTransformer())->transform(new WP_Block([
  'blockName' => 'acf/value-aware-container', 'attrs' => ['data' => $data], 'innerBlocks' => [],
  'innerHTML' => '', 'innerContent' => [],
]), 3);

$original = acf_get_store('fields');
$originalData = $original->get();
$originalAliases = $original->aliases;
$data = [
  'grid' => '', '_grid' => $grid['key'],
  'grid_label' => 'A grid', '_grid_label' => $label['key'],
  'grid_blocks' => [], '_grid_blocks' => $blocks['key'],
];
$empty = $transform($data);
$same('A grid', $empty['data']['grid']['label'], 'Flattened group value changed');
$same([['id' => 123]], $empty['data']['items'], 'Omitted Query defaults were not discovered');
$same(1, $queryCalls, 'Omitted Query did not run');
$same([], acf_get_local_field($blocks['key'])['layouts'], 'Empty group InnerBlocks expanded');
$same([], acf_get_local_field($entryBlocks['key'])['layouts'], 'Omitted repeater InnerBlocks expanded');
$same($original, acf_get_store('fields'), 'Normal field cache was replaced');
$same($originalData, $original->get(), 'Partial definitions escaped into the normal cache');
$same($originalAliases, $original->aliases, 'Normal aliases changed');
$same([], acf_get_local_field($unused['key'])['layouts'], 'Unused root InnerBlocks expanded');
$same([], $definitions[0]['sub_fields'][1]['layouts'], 'Empty group exposed full layouts to the parser');
$same(2, count($definitions[4]['layouts']), 'Eager field opt-out was ignored');

// Reuse the same field key in a later populated block in this request.
$data['grid_blocks'] = ['acf/value-aware-child'];
$data['grid_blocks_0_title'] = 'Hello';
$data['grid_blocks_0_count'] = '0';
// A saved Gutenberg row also contains each child's field-key reference.
$children = ValueAwareFieldLoading::run($data, static fn() => acf_get_field($blocks['key'])['layouts'][0]['sub_fields']);
$data['_grid_blocks_0_title'] = $children[0]['key'];
$data['_grid_blocks_0_count'] = $children[1]['key'];
$populated = $transform($data);
$same('acf/value-aware-child', $populated['data']['grid']['blocks'][0]['name'], 'A later populated field lost its layout');
$same('Hello', $populated['data']['grid']['blocks'][0]['data']['title'], 'Nested title changed');
$same('0', $populated['data']['grid']['blocks'][0]['data']['count'], 'Nested zero changed');
$same(1, count(acf_get_local_field($blocks['key'])['layouts']), 'Populated group layouts were not imported');
$same([], acf_get_local_field($entryBlocks['key'])['layouts'], 'An unrelated field expanded');
$same($originalData, $original->get(), 'Populated parsing changed the normal field cache');

// Imported layouts must still be skipped in a subsequent empty occurrence.
$emptyData = ['grid' => '', '_grid' => $grid['key'],
  'grid_label' => 'Another grid', '_grid_label' => $label['key']];
$emptyAgain = $transform($emptyData);
$same('Another grid', $emptyAgain['data']['grid']['label'], 'Later empty group lost its ordinary fields');
$same([], $definitions[0]['sub_fields'][1]['layouts'], 'An already imported empty field expanded again');

// A repeater shares one child definition across its empty and populated rows.
$data['entries'] = 2;
$data['_entries'] = $entries['key'];
$data['entries_0_blocks'] = [];
$data['_entries_0_blocks'] = $entryBlocks['key'];
$data['entries_1_blocks'] = ['acf/value-aware-child'];
$data['_entries_1_blocks'] = $entryBlocks['key'];
$data['entries_1_blocks_0_title'] = 'Second row';
$data['entries_1_blocks_0_count'] = '7';
$children = ValueAwareFieldLoading::run($data, static fn() => acf_get_field($entryBlocks['key'])['layouts'][0]['sub_fields']);
$data['_entries_1_blocks_0_title'] = $children[0]['key'];
$data['_entries_1_blocks_0_count'] = $children[1]['key'];
$mixed = $transform($data);
$same('Second row', $mixed['data']['entries'][1]['blocks'][0]['data']['title'], 'A populated repeater row was skipped');
$same('7', $mixed['data']['entries'][1]['blocks'][0]['data']['count'], 'Repeater formatting changed');
$same(1, count(acf_get_local_field($entryBlocks['key'])['layouts']), 'Repeater layouts were not imported');

// Another occurrence can import another layout without changing generated keys.
$firstKeys = array_column($children, 'key');
$otherData = ['grid_blocks' => ['acf/value-aware-other'], '_grid_blocks' => $blocks['key']];
$other = ValueAwareFieldLoading::run($otherData, static fn() => acf_get_field($blocks['key']));
$same(['acf/value-aware-other'], array_column($other['layouts'], 'name'), 'A later layout was not selected');
$same(2, count(acf_get_local_field($blocks['key'])['layouts']), 'Progressive imports lost an earlier layout');
$first = ValueAwareFieldLoading::run($data, static fn() => acf_get_field($entryBlocks['key']));
$same($firstKeys, array_column($first['layouts'][0]['sub_fields'], 'key'), 'Repeated exports changed generated keys');
$bothData = ['first' => ['acf/value-aware-child'], '_first' => $entryBlocks['key'],
  'second' => [['acf_fc_layout' => 'acf/value-aware-other']], '_second' => $entryBlocks['key']];
$both = ValueAwareFieldLoading::run($bothData, static fn() => acf_get_field($entryBlocks['key']));
$same(['acf/value-aware-child', 'acf/value-aware-other'], array_column($both['layouts'], 'name'), 'Shared row definitions lost a layout');

// Ordinary ACF reads outside parsing still return the complete definition.
$complete = acf_get_field($grid['key']);
$same(2, count($complete['sub_fields'][1]['layouts']), 'A normal read saw a partial field');
$same(2, count(acf_get_field($unused['key'])['layouts']), 'A normal read could not expand an unused field');
// Complete cached parent definitions are projected only inside parsing.
$projected = ValueAwareFieldLoading::run($otherData, static fn() => acf_get_field($grid['key']));
$same(['acf/value-aware-other'], array_column($projected['sub_fields'][1]['layouts'], 'name'), 'A cached parent exposed unused layouts');
$same(2, count(acf_get_field($grid['key'])['sub_fields'][1]['layouts']), 'Projected cache damaged normal reads');
// A complete local definition without a normal cache entry also respects selection.
acf_get_store('fields')->remove($blocks['key']);
$projected = ValueAwareFieldLoading::run($otherData, static fn() => acf_get_field($blocks['key']));
$same(['acf/value-aware-other'], array_column($projected['layouts'], 'name'), 'Complete local definition ignored selection');

ob_start();
acf_render_field_wrap($complete['sub_fields'][1]);
$html = ob_get_clean();
$same(true, str_contains($html, 'data-layout="acf/value-aware-child"'), 'Editor layout choice disappeared');

echo "Value-aware fields WordPress: $checks checks passed.\n";
