<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests;

use CloakWP\BlockParser\BlockParser;
use CloakWP\BlockParser\Profiler;
use CloakWP\BlockParser\Tests\Support\ParserTestCase;
use CloakWP\BlockParser\Tests\Support\TestEnvironment;
use WP_REST_Response;

final class ProfilerTest extends ParserTestCase
{
  public function testDisabledProfilerDoesNotRecordResultsOrRegisterRestHooks(): void
  {
    Profiler::start();
    Profiler::setCurrentBlock('core/paragraph');
    Profiler::addRenderMs(10);
    Profiler::end();

    $this->assertFalse(Profiler::isEnabled());
    $this->assertNull(Profiler::getLastResult());
    $this->assertFalse(has_filter('rest_post_dispatch'));
  }

  public function testNestedRunsAccumulateMetricsAndFinishOnlyAtTheOutermostEnd(): void
  {
    add_filter('cloakwp/block_parser/profile', '__return_true');
    Profiler::start();
    Profiler::setCurrentBlock('core/group');
    Profiler::addRenderMs(1.5);
    Profiler::start();
    Profiler::setCurrentBlock('core/paragraph');
    Profiler::addRenderMs(2.5);
    Profiler::addParseAttrsMs(3);
    Profiler::clearCurrentBlock();
    Profiler::addInnerBlocksMs(4, 'core/group');
    Profiler::addTransformOtherMs(5, 'core/group');
    Profiler::addAcfTransformMs(6);
    Profiler::addAcfGetFieldObjectsMs(7, 'core/group');
    Profiler::addAcfGetFieldDefinitionsMs(8, 'core/group');
    Profiler::addAcfFormatFieldsMs(9, 'core/group');
    Profiler::end();
    $this->assertNull(Profiler::getLastResult());
    Profiler::end();

    $result = Profiler::getLastResult();
    $this->assertSame(2, $result['block_count']);
    $this->assertSame(4.0, $result['render_ms']);
    $this->assertSame(3.0, $result['parse_attrs_ms']);
    $this->assertSame(4.0, $result['inner_blocks_ms']);
    $this->assertSame(5.0, $result['transform_other_ms']);
    $this->assertSame(6.0, $result['acf_transform_ms']);
    $this->assertSame(7.0, $result['acf_get_field_objects_ms']);
    $this->assertSame(8.0, $result['acf_get_field_definitions_ms']);
    $this->assertSame(9.0, $result['acf_format_fields_ms']);
    $this->assertSame(1.5, $result['by_block_name']['core/group']['render_ms']);
    $this->assertSame(2.5, $result['by_block_name']['core/paragraph']['render_ms']);
    $this->assertSame(4.0, $result['by_block_name']['core/group']['inner_blocks_ms']);
    $this->assertGreaterThanOrEqual(0, $result['total_ms']);
  }

  public function testSubsequentRunsResetMetricsAndRegisterDispatchOnlyOnce(): void
  {
    add_filter('cloakwp/block_parser/profile', '__return_true');
    Profiler::start();
    Profiler::setCurrentBlock('acf/hero');
    Profiler::addRenderMs(5);
    Profiler::end();
    Profiler::start();
    Profiler::setCurrentBlock('core/paragraph');
    Profiler::end();
    $result = Profiler::getLastResult();

    $this->assertSame(1, $result['block_count']);
    $this->assertSame(0.0, $result['render_ms']);
    $this->assertArrayNotHasKey('acf/hero', $result['by_block_name']);
    $this->assertCount(1, $GLOBALS['wp_filter']['rest_post_dispatch']->callbacks[10]);
  }

  public function testRestDispatchInjectsRoundedMetricsIncludingOptionalAcfHeaders(): void
  {
    add_filter('cloakwp/block_parser/profile', '__return_true');
    Profiler::start();
    Profiler::addRenderMs(1.25);
    Profiler::addParseAttrsMs(2.34);
    Profiler::addTransformOtherMs(3.45);
    Profiler::addInnerBlocksMs(4.56);
    Profiler::addAcfTransformMs(5.67);
    Profiler::addAcfGetFieldObjectsMs(6.78);
    Profiler::addAcfGetFieldDefinitionsMs(7.89);
    Profiler::addAcfFormatFieldsMs(8.91);
    Profiler::end();
    $response = new WP_REST_Response(['ok' => true]);
    $result = apply_filters('rest_post_dispatch', $response, null, null);

    $this->assertSame($response, $result);
    $this->assertSame(['ok' => true], $response->get_data());
    $headers = $response->get_headers();
    $this->assertSame(1.3, $headers['X-CloakWP-Parser-Render-Ms']);
    $this->assertSame(2.3, $headers['X-CloakWP-Parser-ParseAttrs-Ms']);
    $this->assertSame(3.5, $headers['X-CloakWP-Parser-TransformOther-Ms']);
    $this->assertSame(4.6, $headers['X-CloakWP-Parser-InnerBlocks-Ms']);
    $this->assertSame(5.7, $headers['X-CloakWP-Parser-AcfTransform-Ms']);
    $this->assertSame(6.8, $headers['X-CloakWP-Parser-AcfGetFieldObjects-Ms']);
    $this->assertSame(7.9, $headers['X-CloakWP-Parser-AcfGetFieldDefinitions-Ms']);
    $this->assertSame(8.9, $headers['X-CloakWP-Parser-AcfFormatFields-Ms']);
    $this->assertArrayHasKey('X-CloakWP-Parser-Total-Ms', $headers);
  }

  public function testHeaderInjectionIgnoresOtherResponseTypesAndUnrecordedRuns(): void
  {
    $response = new WP_REST_Response();
    $this->assertSame($response, Profiler::injectHeaders($response, null, null));
    $this->assertSame([], $response->get_headers());
    $other = (object) ['data' => 'value'];
    $this->assertSame($other, Profiler::injectHeaders($other, null, null));
  }

  public function testProfilingDoesNotChangeParserOutputAndMissingPostsFinishTheirRun(): void
  {
    TestEnvironment::post(42, '<!-- wp:paragraph --><p>Profile me.</p><!-- /wp:paragraph -->');
    $parser = new BlockParser();
    $expected = $parser->parseBlocksFromPost(42);
    add_filter('cloakwp/block_parser/profile', '__return_true');
    $this->assertSame($expected, $parser->parseBlocksFromPost(42));
    $this->assertSame(1, Profiler::getLastResult()['block_count']);
    $this->assertSame([], $parser->parseBlocksFromPost(404));
    $this->assertSame(0, Profiler::getLastResult()['block_count']);
  }
}
