<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatched while assembling a mutation endpoint response.
 *
 * Subscribers can inspect the mutation result and alter the response payload
 * or piggyback Drupal AJAX commands onto it. Commands are rendered into the
 * payload's 'drupal_commands' key; the client plays them through Drupal's
 * AJAX command processor after it has applied the response itself (markup
 * swap first, then commands).
 */
final class BlueprintMutationResponseEvent extends Event {

  /**
   * AJAX commands to piggyback on the response.
   *
   * @var \Drupal\Core\Ajax\CommandInterface[]
   */
  protected array $commands = [];

  /**
   * Constructs a BlueprintMutationResponseEvent.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The mutation request.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the mutation targeted.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResultInterface $result
   *   The mutation result.
   * @param array $payload
   *   The response payload assembled so far.
   */
  public function __construct(
    public readonly Request $request,
    public readonly EntityInterface $entity,
    public readonly BlueprintResultInterface $result,
    protected array $payload,
  ) {}

  /**
   * Returns the response payload.
   *
   * @return array
   *   The payload.
   */
  public function getPayload(): array {
    return $this->payload;
  }

  /**
   * Replaces the response payload.
   *
   * @param array $payload
   *   The new payload.
   */
  public function setPayload(array $payload): void {
    $this->payload = $payload;
  }

  /**
   * Adds an AJAX command to play on the client after the response is applied.
   *
   * @param \Drupal\Core\Ajax\CommandInterface $command
   *   The command.
   */
  public function addCommand(CommandInterface $command): void {
    $this->commands[] = $command;
  }

  /**
   * Returns the piggybacked AJAX commands.
   *
   * @return \Drupal\Core\Ajax\CommandInterface[]
   *   The commands.
   */
  public function getCommands(): array {
    return $this->commands;
  }

}
