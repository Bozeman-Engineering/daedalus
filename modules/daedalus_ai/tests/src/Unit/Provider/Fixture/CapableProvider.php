<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Unit\Provider\Fixture;

use Drupal\ai\OperationType\OutputInterface;
use Drupal\daedalus_ai\Provider\CacheBreakpointsInterface;
use Drupal\daedalus_ai\Provider\ContextCeilingInterface;
use Drupal\daedalus_ai\Provider\RenderingPlan;
use Drupal\daedalus_ai\Provider\ReportedCostInterface;
use Drupal\daedalus_ai_test\Plugin\AiProvider\StubProvider;

/**
 * A real provider plugin declaring three of the five capabilities.
 *
 * It extends the stub provider so that drupal/ai's ProviderProxy::getPlugin()
 * (typed AiProviderClientBase) can hand it back; the unit tests build it
 * without its constructor, since no capability method touches a service.
 */
final class CapableProvider extends StubProvider implements CacheBreakpointsInterface, ContextCeilingInterface, ReportedCostInterface {

  public const COST_USD = 0.0421;

  /**
   * {@inheritdoc}
   */
  public function supportedCacheTtls(string $model_id): array {
    return [RenderingPlan::TTL_5M, RenderingPlan::TTL_1H];
  }

  /**
   * {@inheritdoc}
   */
  public function contextCeiling(string $model_id): ?int {
    return 200000;
  }

  /**
   * {@inheritdoc}
   */
  public function reportedCostUsd(OutputInterface $output): ?float {
    return self::COST_USD;
  }

  /**
   * An instance with no services behind it.
   */
  public static function bare(): self {
    return (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
  }

}
