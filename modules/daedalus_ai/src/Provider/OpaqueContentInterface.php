<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Provider;

use Drupal\ai\OperationType\OutputInterface;

/**
 * A provider plugin that round-trips provider-native assistant content.
 *
 * Some of what a model returns on an assistant turn has no place on a
 * drupal/ai ChatMessage and must still be replayed verbatim on the next
 * request: Anthropic's thinking blocks with their signatures, xAI's
 * encrypted reasoning items. The spine keeps that content as an opaque
 * per-provider slot on each assistant entry of the transcript, reads it
 * from the provider after a leg through this interface, and hands it back
 * on the RenderingPlan keyed by the assistant message's index for the
 * provider to emit. A provider without this interface replays nothing and
 * the spine stores nothing for it.
 *
 * @see \Drupal\daedalus_ai\Provider\RenderingPlan::opaque()
 */
interface OpaqueContentInterface {

  /**
   * The provider-native content of an assistant turn to replay later.
   *
   * @param \Drupal\ai\OperationType\OutputInterface $output
   *   The output of the leg that produced the assistant turn.
   *
   * @return array|null
   *   A serializable array in the provider's own shape (the spine never
   *   reads inside it), or NULL when the turn carries nothing to replay.
   */
  public function opaqueContentOf(OutputInterface $output): ?array;

}
