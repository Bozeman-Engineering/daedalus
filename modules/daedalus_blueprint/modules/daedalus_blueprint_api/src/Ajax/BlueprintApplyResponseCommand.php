<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Delivers a mutation-response payload inside a Drupal AJAX response.
 *
 * The mutation-response contract (MutationResponseBuilder::build()) is
 * normally the whole HTTP response of the blueprint endpoint. A write that
 * rides another transport, a Drupal form's own AJAX submit, answers a
 * Drupal AJAX command list instead, and this command is how the payload
 * rides it: one command, the decoded payload verbatim, applied client-side
 * through the app's one applyResponse pipeline (mirror, subtree swaps by
 * key, history, materials, piggybacked commands) exactly as an endpoint
 * response or an AI turn's payload is.
 */
final class BlueprintApplyResponseCommand implements CommandInterface {

  /**
   * The client-side command name.
   */
  public const COMMAND = 'daedalusBlueprintApplyResponse';

  /**
   * Constructs the command.
   *
   * @param array $payload
   *   The decoded mutation-response payload, as MutationResponseBuilder
   *   built it (success or failure alike; the client judges by `success`).
   */
  public function __construct(
    private readonly array $payload,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => self::COMMAND,
      'payload' => $this->payload,
    ];
  }

}
