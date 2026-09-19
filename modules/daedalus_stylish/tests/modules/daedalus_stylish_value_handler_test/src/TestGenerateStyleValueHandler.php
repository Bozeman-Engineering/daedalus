<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_value_handler_test;

use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\StyleValueHandlerInterface;

/**
 * Stubs daedalus_ai's `_generate` marker without minting anything.
 *
 * The dialect's CSS grammar translates url('generate:<prompt>') into the
 * {"_generate": "<prompt>"} handled value (alone, or as one layer of a
 * media-capable value), and the production handler lives in daedalus_ai
 * (GenerateStyleValueHandler: mints media, queues the generation job).
 * Kernel tests pinning the translation seam end to end need the marker
 * registered without daedalus_ai's minting machinery, so this stub resolves
 * every request to the media token url('media:<UUID>') with a fixed uuid
 * and no side effects. Never enable this module beside daedalus_ai: a marker
 * has exactly one handler and StyleValueResolver refuses duplicates.
 */
final class TestGenerateStyleValueHandler implements StyleValueHandlerInterface {

  /**
   * The fixed uuid every resolved token carries.
   */
  public const UUID = '11111111-1111-4111-8111-111111111111';

  /**
   * {@inheritdoc}
   */
  public function getMarkerKey(): string {
    return '_generate';
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): string {
    return '{"_generate": "<prompt>"} resolves to the stored media token url(\'media:<uuid>\') (test stub: a fixed uuid, no media minted).';
  }

  /**
   * {@inheritdoc}
   */
  public function check(array $value, array $context): array {
    $prompt = $value['_generate'];
    if (!is_string($prompt) || trim($prompt) === '') {
      return [
        new BlueprintError('_generate', 'INVALID_STYLE_VALUE', 'The _generate prompt must be a non-empty string.', [], 'Provide {"_generate": "detailed description of the desired image"}.'),
      ];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getStandIn(array $value, array $context): string {
    return 'none';
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(array $value, array $context, array &$warnings): string {
    return sprintf("url('media:%s')", self::UUID);
  }

}
