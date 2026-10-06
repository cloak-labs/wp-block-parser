<?php

declare(strict_types=1);

namespace CloakWP\BlockParser\Tests\Support;

final class CustomTransformer extends RecordingTransformer
{
  protected static string $type = 'custom';
}
