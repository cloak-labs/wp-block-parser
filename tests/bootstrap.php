<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($autoload)) {
  require $autoload;
} else {
  spl_autoload_register(static function (string $class): void {
    $prefix = 'CloakWP\\BlockParser\\';
    if (!str_starts_with($class, $prefix)) {
      return;
    }
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
      require_once $path;
    }
  });
}

$GLOBALS['wp_filters'] = [];

if (!function_exists('add_filter')) {
  function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): void
  {
    $GLOBALS['wp_filters'][$hook][$priority][] = $callback;
  }
}

if (!function_exists('apply_filters')) {
  function apply_filters($hook, $value, ...$args)
  {
    $priorities = $GLOBALS['wp_filters'][$hook] ?? [];
    ksort($priorities);

    foreach ($priorities as $callbacks) {
      foreach ($callbacks as $callback) {
        $value = $callback($value, ...$args);
      }
    }

    return $value;
  }
}
