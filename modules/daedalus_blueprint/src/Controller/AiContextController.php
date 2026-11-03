<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The AI context usage as JSON: the budget's reading, whole.
 *
 * One thin door over MaterialsBudget::reading(), nothing computed here
 * that the reading does not already state: reading() answers
 * BudgetReading::toArray() (characters by kind, by record and the
 * kinds' shared, the system, the estimate against the budget), the
 * spine's API for any consumer that wants the figures without the
 * display. Cacheable on what the reading derived from (the roster's
 * tags and the settings), so a material's save or a budget change
 * re-derives it and nothing else does. The display itself is the form
 * (AiContextForm, the design system size: the bar over a box per
 * material) at MaterialsBudget::DIALOG_PATH.
 */
final class AiContextController extends ControllerBase {

  public function __construct(
    private readonly MaterialsBudget $budget,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(MaterialsBudget::class),
    );
  }

  /**
   * The reading as JSON: BudgetReading::toArray().
   */
  public function reading(): CacheableJsonResponse {
    $reading = $this->budget->reading();
    $response = new CacheableJsonResponse($reading->toArray());
    $response->addCacheableDependency($reading);
    return $response;
  }

}
