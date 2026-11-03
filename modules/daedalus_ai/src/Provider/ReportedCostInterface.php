<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Provider;

use Drupal\ai\OperationType\OutputInterface;

/**
 * A provider plugin whose responses carry the charge for the leg.
 *
 * Some paths report what a response actually cost (a gateway's per-response
 * cost header, xAI's cost_in_usd_ticks). The usage ledger prefers that over
 * a price table when it is present. A provider without this interface
 * reports no cost and the ledger prices the tokens itself.
 *
 * @see \Drupal\daedalus_ai\Provider\UsageReader
 */
interface ReportedCostInterface {

  /**
   * The charge the provider reported for one leg, in USD.
   *
   * @param \Drupal\ai\OperationType\OutputInterface $output
   *   The output of the leg.
   *
   * @return float|null
   *   The reported cost, or NULL when the response carried none.
   */
  public function reportedCostUsd(OutputInterface $output): ?float;

}
