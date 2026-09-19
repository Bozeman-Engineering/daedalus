<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_value_handler_test;

use Drupal\Core\State\StateInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\StyleValueHandlerInterface;

/**
 * Test handler on the style grammar's input-tier seam.
 *
 * Registers the "_test_value" marker: {"_test_value": "<payload>"} resolves
 * to url("resolved:<payload>"). Every check() and resolve() invocation is
 * recorded in state so tests can pin the two-phase contract: CHECK runs
 * side-effect-free before validation, COMMIT runs only after validation
 * succeeded (a failed write resolves nothing).
 */
final class TestStyleValueHandler implements StyleValueHandlerInterface {

  public const CHECKS = 'daedalus_stylish_value_handler_test.checks';

  public const RESOLVES = 'daedalus_stylish_value_handler_test.resolves';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getMarkerKey(): string {
    return '_test_value';
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): string {
    return '{"_test_value": "<payload>"} resolves to url("resolved:<payload>").';
  }

  /**
   * {@inheritdoc}
   */
  public function check(array $value, array $context): array {
    $this->record(self::CHECKS, $context);
    $payload = $value['_test_value'];
    if (!is_string($payload) || trim($payload) === '') {
      return [
        new BlueprintError('_test_value', 'INVALID_TEST_VALUE', 'The _test_value payload must be a non-empty string.', [], 'Provide {"_test_value": "<payload>"}.'),
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
    $this->record(self::RESOLVES, $context);
    return sprintf('url("resolved:%s")', trim((string) $value['_test_value']));
  }

  /**
   * Appends one invocation's context to a state log.
   */
  private function record(string $key, array $context): void {
    $log = $this->state->get($key, []);
    $log[] = [
      'key' => $context['key'],
      'condition' => $context['condition'],
      'property' => $context['property'],
      'path' => $context['path'],
      'layer' => $context['layer'],
    ];
    $this->state->set($key, $log);
  }

}
