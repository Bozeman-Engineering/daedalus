<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\DeferredProcessor;

/**
 * Collects tagged processor services and dispatches jobs by batch type.
 */
class DeferredProcessorManager {

  /**
   * The registered processors, keyed by batch type.
   *
   * @var \Drupal\daedalus_ai\DeferredProcessor\DeferredProcessorInterface[]
   */
  protected array $processors = [];

  /**
   * Registers a processor (called by the service collector).
   *
   * @param \Drupal\daedalus_ai\DeferredProcessor\DeferredProcessorInterface $processor
   *   The processor to register.
   */
  public function addProcessor(DeferredProcessorInterface $processor): void {
    $this->processors[$processor->getType()] = $processor;
  }

  /**
   * Processes one pending generation by dispatching to the matching processor.
   *
   * @param string $type
   *   The batch type (e.g. 'image_generation').
   * @param string $media_uuid
   *   The uuid of the media entity whose content the job fills.
   * @param array $context
   *   Entity-level context: entity_type, entity_id, view_mode.
   *
   * @return \Drupal\daedalus_ai\DeferredProcessor\DeferredProcessingResult
   *   The processing result.
   *
   * @throws \InvalidArgumentException
   *   If no processor is registered for the type.
   */
  public function process(string $type, string $media_uuid, array $context): DeferredProcessingResult {
    if (!isset($this->processors[$type])) {
      throw new \InvalidArgumentException("No deferred processor registered for type '{$type}'.");
    }
    return $this->processors[$type]->process($media_uuid, $context);
  }

  /**
   * Checks whether a processor exists for the given type.
   *
   * @param string $type
   *   The batch type.
   *
   * @return bool
   *   TRUE if a processor is registered for this type.
   */
  public function hasProcessor(string $type): bool {
    return isset($this->processors[$type]);
  }

  /**
   * Returns the processor for a given type.
   *
   * @param string $type
   *   The batch type.
   *
   * @return \Drupal\daedalus_ai\DeferredProcessor\DeferredProcessorInterface|null
   *   The processor, or NULL if none registered.
   */
  public function getProcessor(string $type): ?DeferredProcessorInterface {
    return $this->processors[$type] ?? NULL;
  }

}
