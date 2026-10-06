<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests\Support;

use PHPUnit\Framework\TestCase;
use WP_Block;
use WP_Block_Type;
use WP_Block_Type_Registry;

abstract class ParserTestCase extends TestCase
{
  protected function setUp(): void
  {
    TestEnvironment::reset();
    $this->registerBlock('core/paragraph', [
      'content' => ['type' => 'string', 'source' => 'rich-text', 'selector' => 'p'],
      'dropCap' => ['type' => 'boolean', 'default' => false],
    ]);
    $this->registerBlock('core/heading', [
      'content' => ['type' => 'string', 'source' => 'rich-text', 'selector' => 'h1,h2,h3,h4,h5,h6'],
      'level' => ['type' => 'integer', 'default' => 2],
    ]);
    $this->registerBlock('core/group');
    $this->registerBlock('core/block', ['ref' => ['type' => 'integer']]);
  }

  protected function tearDown(): void
  {
    TestEnvironment::reset();
  }

  protected function registerBlock(string $name, array $attributes = [], array $args = []): WP_Block_Type
  {
    return WP_Block_Type_Registry::get_instance()->register($name, ['attributes' => $attributes] + $args);
  }

  protected function fixture(string $name): string
  {
    return file_get_contents(dirname(__DIR__) . '/Fixtures/' . $name . '.html');
  }

  protected function block(string $name, array $attrs = [], string $html = ''): array
  {
    return [
      'blockName' => $name,
      'attrs' => $attrs,
      'innerBlocks' => [],
      'innerHTML' => $html,
      'innerContent' => [$html],
    ];
  }

  protected function wpBlock(string $name, array $attrs = [], string $html = ''): WP_Block
  {
    return new WP_Block($this->block($name, $attrs, $html));
  }
}
