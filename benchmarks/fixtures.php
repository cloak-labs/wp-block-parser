<?php

// Fixed content keeps HTTP payloads comparable across implementations.
$figures = '';
for ($i = 1; $i <= 12; $i++) {
  $figures .= '<figure><a href="/photo-' . $i . '"><img src="photo-' . $i . '.jpg" alt="Photo ' . $i . '"></a>'
    . '<figcaption>Photo <em>' . $i . '</em></figcaption></figure>';
}

return [
  'simple' => [
    'schema' => [
      'content' => ['type' => 'string', 'source' => 'rich-text', 'selector' => 'p'],
      'anchor' => ['type' => 'string', 'source' => 'attribute', 'selector' => 'p', 'attribute' => 'id'],
    ],
    'attrs' => [],
    'html' => '<p id="intro">Some <strong>formatted</strong> text with <a href="/contact">a link</a>.</p>',
  ],
  'nested' => [
    'schema' => [
      'images' => ['type' => 'array', 'source' => 'query', 'selector' => 'figure', 'query' => [
        'url' => ['type' => 'string', 'source' => 'attribute', 'selector' => 'img', 'attribute' => 'src'],
        'alt' => ['type' => 'string', 'source' => 'attribute', 'selector' => 'img', 'attribute' => 'alt'],
        'caption' => ['type' => 'string', 'source' => 'html', 'selector' => 'figcaption'],
        'links' => ['type' => 'array', 'source' => 'query', 'selector' => 'a', 'query' => [
          'href' => ['type' => 'string', 'source' => 'attribute', 'attribute' => 'href'],
        ]],
      ]],
    ],
    'attrs' => [],
    'html' => '<div class="gallery">' . $figures . '</div>',
  ],
  'stored' => [
    'schema' => [
      'content' => ['type' => 'string', 'source' => 'rich-text', 'selector' => 'p'],
      'enabled' => ['type' => 'boolean', 'default' => false],
    ],
    'attrs' => ['content' => 'Already stored', 'enabled' => false],
    'html' => '<div class="gallery">' . $figures . '</div>',
  ],
  'image' => [
    'schema' => [
      'url' => ['type' => 'string', 'source' => 'attribute', 'selector' => 'img', 'attribute' => 'src'],
      'alt' => ['type' => 'string', 'source' => 'attribute', 'selector' => 'img', 'attribute' => 'alt'],
      'caption' => ['type' => 'string', 'source' => 'rich-text', 'selector' => 'figcaption'],
    ],
    'attrs' => [],
    'html' => '<figure class="wp-block-image"><img src="photo.jpg" alt="A photo" width="1600" height="900"></figure>',
  ],
  'text' => [
    'schema' => ['content' => ['type' => 'string', 'source' => 'text', 'selector' => 'p']],
    'attrs' => [],
    'html' => '<p>Some <strong>formatted</strong> text &amp; <em>more</em> words.</p>',
  ],
  'css' => [
    'schema' => ['url' => ['type' => 'string', 'source' => 'attribute', 'selector' => '.gallery > figure:nth-child(2) img', 'attribute' => 'src']],
    'attrs' => [],
    'html' => '<div class="gallery">' . $figures . '</div>',
  ],
  'table' => [
    'schema' => ['cells' => ['source' => 'query', 'selector' => 'td,th', 'query' => [
      'content' => ['source' => 'rich-text'], 'tag' => ['source' => 'tag'],
    ]]],
    'attrs' => [],
    'html' => '<figure><table><tbody><tr><td>One <strong>cell</strong></td><td>Two</td></tr></tbody></table></figure>',
  ],
];
