<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\DeferredProcessor;

/**
 * Processes one pending generation job, addressed by its media uuid.
 *
 * Identity-first, content-deferred: the media entity a job fills already
 * exists with a placeholder file, and every reference to it is final. A
 * processor's whole contract is "fill the content behind this media uuid":
 * it looks up WHAT to produce (e.g. in the GenerationJobStore) and swaps the
 * file on the media entity; cache tags propagate the change to every
 * reference.
 *
 * Implementations are tagged services (daedalus_ai.deferred_processor) collected
 * by DeferredProcessorManager and dispatched by the batch's type.
 */
interface DeferredProcessorInterface {

  /**
   * Returns the batch type this processor handles.
   *
   * Must match the type stored in the batch stash (e.g. 'image_generation').
   *
   * @return string
   *   The type identifier.
   */
  public function getType(): string;

  /**
   * Processes the pending generation for one media uuid.
   *
   * @param string $media_uuid
   *   The uuid of the media entity whose content the job fills.
   * @param array $context
   *   Entity-level context from the batch route: entity_type, entity_id,
   *   view_mode.
   *
   * @return \Drupal\daedalus_ai\DeferredProcessor\DeferredProcessingResult
   *   The result of processing.
   */
  public function process(string $media_uuid, array $context): DeferredProcessingResult;

  /**
   * Returns user-facing messages for this processor type.
   *
   * The JS deferred trigger uses these to display status in the chat panel.
   *
   * @return array
   *   Associative array with keys: loading, retry, success, partial_success,
   *   failure, error.
   */
  public function getMessages(): array;

}
