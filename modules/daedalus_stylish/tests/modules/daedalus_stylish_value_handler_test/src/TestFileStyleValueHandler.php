<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_value_handler_test;

use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\StyleValueHandlerInterface;

/**
 * Stubs daedalus_ai's `_file` marker without minting anything.
 *
 * The dialect's CSS grammar translates url('file:<name>') into the
 * {"_file": "<name>"} handled value (alone, or as one layer of a
 * media-capable value), and the production handler lives in daedalus_ai
 * (FileStyleValueHandler: resolves the name through the conversation's
 * attachment store and mints media from the file). Kernel tests pinning
 * the translation seam end to end need the marker registered without
 * daedalus_ai's store, so this stub resolves every name to the media token
 * url('media:<UUID>') with a fixed uuid and no side effects, and records
 * the conversation the seam handed it so the lanes' threading is testable.
 * Never enable this module beside daedalus_ai: a marker has exactly one
 * handler and StyleValueResolver refuses duplicates.
 */
final class TestFileStyleValueHandler implements StyleValueHandlerInterface {

  /**
   * The fixed uuid every resolved token carries.
   */
  public const UUID = '22222222-2222-4222-8222-222222222222';

  /**
   * The conversation the last check() ran for.
   */
  public static string $conversation = '';

  /**
   * {@inheritdoc}
   */
  public function getMarkerKey(): string {
    return '_file';
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): string {
    return '{"_file": "<name>"} resolves to the stored media token url(\'media:<uuid>\') (test stub: a fixed uuid, no media minted).';
  }

  /**
   * {@inheritdoc}
   */
  public function check(array $value, array $context): array {
    self::$conversation = (string) ($context['conversation'] ?? '');
    $name = $value['_file'];
    if (!is_string($name) || trim($name) === '') {
      return [
        new BlueprintError('_file', 'INVALID_STYLE_VALUE', 'The _file name must be a non-empty string.', [], 'Provide {"_file": "the name the conversation holds the file under"}.'),
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
