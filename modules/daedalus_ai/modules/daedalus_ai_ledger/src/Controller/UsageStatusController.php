<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\daedalus_ai_ledger\UsageGate;
use Drupal\daedalus_ai_ledger\WindowStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The chat's read of the usage windows: the gate's status as JSON.
 *
 * One GET for the current user, answered from UsageGate::status(): each
 * configured window's scope, period (as configured and in words), the
 * fraction of its cap left, whether it is reached and the next moment the
 * fraction changes. Nothing in USD leaves here: the cap and the spend are
 * the administrator's, the meter is the user's.
 */
final class UsageStatusController extends ControllerBase {

  public function __construct(
    private readonly UsageGate $usageGate,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_ai_ledger.usage_gate'),
      $container->get('date.formatter'),
    );
  }

  /**
   * The current user's usage windows.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   {windows: [{scope, period, label, remaining, reached, reset_at}]},
   *   the windows that apply to the user (UsageGate::windowsFor()), site
   *   windows first; an empty list when none applies.
   */
  public function status(): JsonResponse {
    $windows = array_map(
      fn (WindowStatus $status): array => [
        'scope' => $status->scope,
        'period' => $status->period,
        'label' => $status->label($this->dateFormatter),
        'remaining' => $status->remaining,
        'reached' => $status->reached,
        'reset_at' => $status->resetAt,
      ],
      $this->usageGate->status($this->currentUser()),
    );
    // `remaining` is a float on the wire whatever its value: a whole cap
    // left is 1.0, not 1, so a reader need not guess the type. The option
    // is set before the data: setEncodingOptions() re-encodes from the
    // decoded string, where the fraction is already gone.
    $response = new JsonResponse();
    $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRESERVE_ZERO_FRACTION);
    return $response->setData(['windows' => $windows]);
  }

}
