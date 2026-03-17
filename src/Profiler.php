<?php

namespace CloakWP\BlockParser;

/**
 * Lightweight profiler for BlockParser. Enable via filter to see where time is spent.
 *
 * Enable: add_filter('cloakwp/block_parser/profile', '__return_true');
 * Then request a post that includes blocks_data (e.g. GET /wp-json/wp/v2/posts/123).
 * Timings appear as response headers (X-CloakWP-Parser-*) and in debug.log when WP_DEBUG_LOG is on.
 *
 * Optional: only log blocks slower than a threshold (ms):
 *   add_filter('cloakwp/block_parser/profile_log_threshold_ms', fn() => 50);
 *
 * Note: "transform_other" is the full transform phase per block (transformer + inner blocks recursion).
 * Summed across all blocks it can exceed wall-clock and acf_transform, because parent blocks'
 * time includes processing their children, and each child is also counted separately.
 */
class Profiler
{
  private static ?array $run = null;
  private static int $depth = 0;
  private static bool $dispatchRegistered = false;
  private static ?string $currentBlockName = null;

  public static function isEnabled(): bool
  {
    return apply_filters('cloakwp/block_parser/profile', false);
  }

  /**
   * Minimum block time (render + parse_attrs + transform_other) to include in per-block log. 0 = log all.
   */
  public static function getLogThresholdMs(): float
  {
    return (float) apply_filters('cloakwp/block_parser/profile_log_threshold_ms', 0);
  }

  public static function start(): void
  {
    if (!self::isEnabled()) {
      return;
    }
    self::$depth++;
    if (self::$depth === 1) {
      self::$run = [
        'start' => microtime(true),
        'render_ms' => 0.0,
        'parse_attrs_ms' => 0.0,
        'transform_other_ms' => 0.0,
        'inner_blocks_ms' => 0.0,
        'acf_transform_ms' => 0.0,
        'acf_get_field_objects_ms' => 0.0,
        'acf_get_field_definitions_ms' => 0.0,
        'acf_format_fields_ms' => 0.0,
        'block_count' => 0,
        'by_block_name' => [],
      ];
      self::registerDispatchOnce();
    }
  }

  public static function addRenderMs(float $ms): void
  {
    if (self::$run === null) return;
    self::$run['render_ms'] += $ms;
    if (self::$currentBlockName !== null && isset(self::$run['by_block_name'][self::$currentBlockName])) {
      self::$run['by_block_name'][self::$currentBlockName]['render_ms'] += $ms;
    }
  }

  public static function addParseAttrsMs(float $ms): void
  {
    if (self::$run === null) return;
    self::$run['parse_attrs_ms'] += $ms;
    if (self::$currentBlockName !== null && isset(self::$run['by_block_name'][self::$currentBlockName])) {
      self::$run['by_block_name'][self::$currentBlockName]['parse_attrs_ms'] += $ms;
    }
  }

  /**
   * @param float $ms Time spent in this block's transform phase (transformer + inner blocks).
   * @param string|null $blockName Block to attribute to. If null, uses current block (pass explicitly when current was cleared by children).
   */
  public static function addTransformOtherMs(float $ms, ?string $blockName = null): void
  {
    if (self::$run === null) return;
    self::$run['transform_other_ms'] += $ms;
    $name = $blockName ?? self::$currentBlockName;
    if ($name !== null && isset(self::$run['by_block_name'][$name])) {
      self::$run['by_block_name'][$name]['transform_other_ms'] += $ms;
    }
  }

  /**
   * @param float $ms Time spent processing this block's child blocks
   * @param string|null $blockName Block to attribute to (parent that had inner blocks). If null, uses current block (cleared by children, so pass explicitly from caller).
   */
  public static function addInnerBlocksMs(float $ms, ?string $blockName = null): void
  {
    if (self::$run === null) return;
    self::$run['inner_blocks_ms'] += $ms;
    $name = $blockName ?? self::$currentBlockName;
    if ($name !== null && isset(self::$run['by_block_name'][$name])) {
      self::$run['by_block_name'][$name]['inner_blocks_ms'] += $ms;
    }
  }

  public static function addAcfTransformMs(float $ms): void
  {
    if (self::$run === null) return;
    self::$run['acf_transform_ms'] += $ms;
  }

  /**
   * @param string|null $blockName When provided, attribute this time to the block's ACF breakdown (for per-block "get field objects" view).
   */
  public static function addAcfGetFieldObjectsMs(float $ms, ?string $blockName = null): void
  {
    if (self::$run === null) return;
    self::$run['acf_get_field_objects_ms'] += $ms;
    if ($blockName !== null && isset(self::$run['by_block_name'][$blockName])) {
      $cur = self::$run['by_block_name'][$blockName]['acf_get_field_objects_ms'] ?? 0;
      self::$run['by_block_name'][$blockName]['acf_get_field_objects_ms'] = $cur + $ms;
    }
  }

  /**
   * Time spent in acf_get_field() only (definition lookup). When ≈ acf_get_field_objects_ms, definition lookup is the bottleneck.
   *
   * @param string|null $blockName When provided, attribute to block's ACF breakdown.
   */
  public static function addAcfGetFieldDefinitionsMs(float $ms, ?string $blockName = null): void
  {
    if (self::$run === null) return;
    self::$run['acf_get_field_definitions_ms'] += $ms;
    if ($blockName !== null && isset(self::$run['by_block_name'][$blockName])) {
      $cur = self::$run['by_block_name'][$blockName]['acf_get_field_definitions_ms'] ?? 0;
      self::$run['by_block_name'][$blockName]['acf_get_field_definitions_ms'] = $cur + $ms;
    }
  }

  /**
   * @param string|null $blockName When provided, attribute this time to the block's ACF breakdown (for per-block "format fields" view).
   */
  public static function addAcfFormatFieldsMs(float $ms, ?string $blockName = null): void
  {
    if (self::$run === null) return;
    self::$run['acf_format_fields_ms'] += $ms;
    if ($blockName !== null && isset(self::$run['by_block_name'][$blockName])) {
      $cur = self::$run['by_block_name'][$blockName]['acf_format_fields_ms'] ?? 0;
      self::$run['by_block_name'][$blockName]['acf_format_fields_ms'] = $cur + $ms;
    }
  }

  public static function setCurrentBlock(string $blockName): void
  {
    self::$currentBlockName = $blockName;
    if (self::$run === null) return;
    self::$run['block_count']++;
    if (!isset(self::$run['by_block_name'][$blockName])) {
      self::$run['by_block_name'][$blockName] = ['count' => 0, 'render_ms' => 0.0, 'parse_attrs_ms' => 0.0, 'transform_other_ms' => 0.0, 'inner_blocks_ms' => 0.0];
    }
    self::$run['by_block_name'][$blockName]['count']++;
  }

  public static function clearCurrentBlock(): void
  {
    self::$currentBlockName = null;
  }

  public static function end(): void
  {
    if (!self::isEnabled() || self::$run === null) return;
    self::$depth--;
    if (self::$depth === 0) {
      self::$run['total_ms'] = (microtime(true) - self::$run['start']) * 1000;
      self::log();
      self::$run = null;
    }
  }

  private static function registerDispatchOnce(): void
  {
    if (self::$dispatchRegistered) return;
    self::$dispatchRegistered = true;
    add_filter('rest_post_dispatch', [self::class, 'injectHeaders'], 10, 3);
  }

  /**
   * Store last run so rest_post_dispatch can add headers (run is ended before response is sent).
   */
  private static ?array $lastResult = null;

  public static function getLastResult(): ?array
  {
    return self::$lastResult;
  }

  private static function log(): void
  {
    $r = self::$run;
    self::$lastResult = $r;

    if (!defined('WP_DEBUG') || !WP_DEBUG || !defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
      return;
    }

    $threshold = self::getLogThresholdMs();
    $pad = fn($n, $w = 8) => str_pad(round($n, 1), $w, ' ', STR_PAD_LEFT);

    error_log('');
    error_log('┌─ CloakWP BlockParser ───────────────────────────────────────────────────');
    error_log('│ TOTAL   ' . $pad($r['total_ms'], 10) . ' ms   (blocks: ' . $r['block_count'] . ')');
    error_log('│');
    error_log('│   render          ' . $pad($r['render_ms']) . ' ms');
    error_log('│   parse_attrs     ' . $pad($r['parse_attrs_ms']) . ' ms');
    error_log('│   transform_other ' . $pad($r['transform_other_ms']) . ' ms');
    error_log('│     inner_blocks    ' . $pad($r['inner_blocks_ms'] ?? 0) . ' ms  (child blocks only)');
    if (isset($r['acf_transform_ms']) && $r['acf_transform_ms'] > 0) {
      error_log('│     acf_transform   ' . $pad($r['acf_transform_ms']) . ' ms');
      if ((isset($r['acf_get_field_objects_ms']) && $r['acf_get_field_objects_ms'] > 0) || (isset($r['acf_format_fields_ms']) && $r['acf_format_fields_ms'] > 0)) {
        error_log('│       get_field_objects  ' . $pad($r['acf_get_field_objects_ms'] ?? 0) . ' ms');
        error_log('│       (definitions only)  ' . $pad($r['acf_get_field_definitions_ms'] ?? 0) . ' ms');
        error_log('│       format_fields       ' . $pad($r['acf_format_fields_ms'] ?? 0) . ' ms');
      }
    }
    error_log('│');

    $blocks = $r['by_block_name'];
    if ($threshold > 0) {
      $blocks = array_filter($blocks, function ($data) use ($threshold) {
        $total = ($data['render_ms'] ?? 0) + ($data['parse_attrs_ms'] ?? 0) + ($data['transform_other_ms'] ?? 0);
        return $total >= $threshold;
      });
    }

    $label = $threshold > 0 ? " blocks (only ≥ {$threshold} ms)" : ' blocks';
    $barLen = 68 - strlen($label);
    error_log('├' . $label . ($barLen > 0 ? ' ' . str_repeat('─', $barLen) : ''));

    $wBlock = 28;
    $wN = 5;
    $wNum = 10;
    $wT = 15;
    $sep = '│';
    $cell = fn($s, $w, $right = false) => $right ? str_pad((string) $s, $w, ' ', STR_PAD_LEFT) : str_pad(strlen((string) $s) > $w ? substr((string) $s, 0, $w - 3) . '...' : (string) $s, $w);

    if (!empty($blocks)) {
      $header = $sep . ' ' . $cell('block', $wBlock) . ' ' . $sep . ' ' . str_pad('n', $wN, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad('total', $wNum, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad('render', $wNum, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad('parse', $wNum, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad('total transform', $wT, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad('own transform', $wT, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad('inner transform', $wT, ' ', STR_PAD_LEFT) . ' ' . $sep;
      $rule = $sep . str_repeat('─', $wBlock + 2) . $sep . str_repeat('─', $wN + 2) . $sep . str_repeat('─', $wNum + 2) . $sep . str_repeat('─', $wNum + 2) . $sep . str_repeat('─', $wNum + 2) . $sep . str_repeat('─', $wT + 2) . $sep . str_repeat('─', $wT + 2) . $sep . str_repeat('─', $wT + 2) . $sep;
      error_log($header);
      error_log($rule);
      foreach ($blocks as $name => $data) {
        $transformTotal = $data['transform_other_ms'] ?? 0;
        $innerMs = $data['inner_blocks_ms'] ?? 0;
        $ownTransform = max(0, $transformTotal - $innerMs);
        $total = ($data['render_ms'] ?? 0) + ($data['parse_attrs_ms'] ?? 0) + $transformTotal;
        $row = $sep . ' ' . $cell($name, $wBlock) . ' ' . $sep . ' ' . str_pad((string) $data['count'], $wN, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad(round($total, 1), $wNum, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad(round($data['render_ms'] ?? 0, 1), $wNum, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad(round($data['parse_attrs_ms'] ?? 0, 1), $wNum, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad(round($transformTotal, 1), $wT, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad(round($ownTransform, 1), $wT, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad(round($innerMs, 1), $wT, ' ', STR_PAD_LEFT) . ' ' . $sep;
        error_log($row);
      }
    } elseif ($threshold > 0) {
      error_log('│   (no blocks exceeded threshold)');
    }

    $acfBlocks = array_filter($r['by_block_name'], function ($data) {
      return ((int) (($data['acf_get_field_objects_ms'] ?? 0) + ($data['acf_format_fields_ms'] ?? 0) + ($data['acf_get_field_definitions_ms'] ?? 0))) > 0;
    });
    if (!empty($acfBlocks)) {
      error_log('│');
      error_log('├─ ACF breakdown (get_objects vs definitions vs format) ─' . str_repeat('─', 12));
      $acfHeader = $sep . ' ' . $cell('block', $wBlock) . ' ' . $sep . ' ' . str_pad('get_objects', $wT, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad('definitions', $wT, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad('format', $wT, ' ', STR_PAD_LEFT) . ' ' . $sep;
      error_log($acfHeader);
      error_log($sep . str_repeat('─', $wBlock + 2) . $sep . str_repeat('─', $wT + 2) . $sep . str_repeat('─', $wT + 2) . $sep . str_repeat('─', $wT + 2) . $sep);
      foreach ($acfBlocks as $name => $data) {
        $getObj = round($data['acf_get_field_objects_ms'] ?? 0, 1);
        $defs = round($data['acf_get_field_definitions_ms'] ?? 0, 1);
        $format = round($data['acf_format_fields_ms'] ?? 0, 1);
        error_log($sep . ' ' . $cell($name, $wBlock) . ' ' . $sep . ' ' . str_pad((string) $getObj, $wT, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad((string) $defs, $wT, ' ', STR_PAD_LEFT) . ' ' . $sep . ' ' . str_pad((string) $format, $wT, ' ', STR_PAD_LEFT) . ' ' . $sep);
      }
    }

    $footerLen = $wBlock + $wN + ($wNum * 3) + ($wT * 3) + (2 * 9);
    error_log('└' . str_repeat('─', $footerLen));
    error_log('');
  }

  public static function injectHeaders($response, $server, $request)
  {
    $result = self::$lastResult;
    if ($result === null || !($response instanceof \WP_REST_Response)) {
      return $response;
    }
    $response->header('X-CloakWP-Parser-Total-Ms', round($result['total_ms'], 1));
    $response->header('X-CloakWP-Parser-Render-Ms', round($result['render_ms'], 1));
    $response->header('X-CloakWP-Parser-ParseAttrs-Ms', round($result['parse_attrs_ms'], 1));
    $response->header('X-CloakWP-Parser-TransformOther-Ms', round($result['transform_other_ms'], 1));
    if (isset($result['inner_blocks_ms']) && $result['inner_blocks_ms'] > 0) {
      $response->header('X-CloakWP-Parser-InnerBlocks-Ms', round($result['inner_blocks_ms'], 1));
    }
    if (isset($result['acf_transform_ms']) && $result['acf_transform_ms'] > 0) {
      $response->header('X-CloakWP-Parser-AcfTransform-Ms', round($result['acf_transform_ms'], 1));
      if (isset($result['acf_get_field_objects_ms'])) {
        $response->header('X-CloakWP-Parser-AcfGetFieldObjects-Ms', round($result['acf_get_field_objects_ms'], 1));
      }
      if (isset($result['acf_get_field_definitions_ms']) && $result['acf_get_field_definitions_ms'] > 0) {
        $response->header('X-CloakWP-Parser-AcfGetFieldDefinitions-Ms', round($result['acf_get_field_definitions_ms'], 1));
      }
      if (isset($result['acf_format_fields_ms'])) {
        $response->header('X-CloakWP-Parser-AcfFormatFields-Ms', round($result['acf_format_fields_ms'], 1));
      }
    }
    $response->header('X-CloakWP-Parser-BlockCount', $result['block_count']);
    return $response;
  }
}
