<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Base class for the lifecycle events an operation dispatches mid-flight.
 *
 * A lifecycle event carries the host the operation ran against and a bag
 * of targets. Targets mean "what changed", the same word and shape as
 * BlueprintResult::getTargets(): a subscriber that writes to the host
 * during the event (its style map, per-placement metadata) has changed
 * something the operation cannot know about, so it declares that change
 * here with addTarget() and the operation merges the event's targets into
 * its result. The mutation response re-renders only declared targets, so
 * an undeclared write lands in the saved host but never reaches the page
 * until a reload.
 *
 * A lifecycle event also carries the batch's POSTURE: whether the element
 * arrived DRESSED (its styles come with it: a blueprint expansion, a
 * document dialect) or UNDRESSED (the palette's blank drop, a flat
 * add_section/add_element). The posture is declared once, on the batch
 * (BlueprintOperationsInterface::batchOperations()'s `dressed` option),
 * and any enclosing dressed batch dresses its nested batches, so a birth
 * subscriber reads one fact instead of guessing from the door the element
 * came through. Birth defaults are for undressed creations only: a
 * dressed creation wears exactly what its batch declares.
 */
abstract class LifecycleEventBase extends Event {

  /**
   * Targets added by subscribers, keyed by "uuid:field_name" for dedupe.
   *
   * @var array<string, array{uuid: string, field_name: string}>
   */
  protected array $targets = [];

  /**
   * Constructs a lifecycle event.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the operation ran against.
   * @param bool $dressed
   *   Whether the batch the operation ran in is dressed (see the class
   *   doc); FALSE for an operation outside any batch.
   */
  public function __construct(
    protected HostInterface $host,
    protected bool $dressed = FALSE,
  ) {}

  /**
   * Gets the host the operation ran against.
   */
  public function getHost(): HostInterface {
    return $this->host;
  }

  /**
   * Whether the creation arrived dressed: its styles come with it.
   *
   * A birth subscriber (a module that stamps defaults on a new element)
   * returns early when this answers TRUE; the batch is a total statement
   * of what its elements wear.
   */
  public function dressed(): bool {
    return $this->dressed;
  }

  /**
   * Declares a field the subscriber changed on the host.
   *
   * @param string $uuid
   *   The UUID of the element (or the host) whose field changed.
   * @param string $field_name
   *   The field name that changed.
   */
  public function addTarget(string $uuid, string $field_name): void {
    $this->targets["$uuid:$field_name"] = ['uuid' => $uuid, 'field_name' => $field_name];
  }

  /**
   * Gets the targets subscribers declared, in declaration order.
   *
   * @return array<int, array{uuid: string, field_name: string}>
   *   Deduplicated ['uuid' => ..., 'field_name' => ...] entries, the shape
   *   BlueprintResult::withTargets() takes.
   */
  public function getTargets(): array {
    return array_values($this->targets);
  }

}
