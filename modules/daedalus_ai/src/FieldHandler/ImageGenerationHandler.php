<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\FieldHandler;

use Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterManager;
use Drupal\daedalus_ai\PendingMedia\PendingMediaMinter;
use Drupal\daedalus_ai\PendingMedia\PendingTargetCollector;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\FieldHandler\EntityReferenceFieldHandler;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\FieldHandler\MediaGenerationInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles entity_reference fields targeting media with image generation.
 *
 * Mint+delegate sugar over the one generation capability
 * (PendingMediaMinter): when the incoming value carries a '_generate' key,
 * the handler mints a media entity synchronously (real uuid, alt text,
 * placeholder file), registers the generation job behind its uuid, and sets
 * the reference IMMEDIATELY (identity-first, content-deferred). The deferred
 * pipeline later swaps only the file behind the media uuid; the reference
 * never moves.
 *
 * On a regenerate (the field already references media), a NEW media entity
 * is minted, seeded with the previous media's file for visual continuity
 * while the pixels fill; shared media is never mutated.
 *
 * When image generation is disabled (see ImageGenerationGate inside the
 * minter), the mint still happens but its file is the final image, no job is
 * registered, and a warning rides back so the AI can tell the user
 * generation was off.
 */
class ImageGenerationHandler extends EntityReferenceFieldHandler implements MediaGenerationInterface {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerInterface $logger,
    protected PendingMediaMinter $minter,
    protected PendingTargetCollector $pendingTargets,
    protected ImageDimensionAdapterManager $dimensionManager,
    BlueprintAccessChecker $accessChecker,
  ) {
    parent::__construct($entityTypeManager, $logger, $accessChecker);
  }

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    if ($definition->getType() !== 'entity_reference') {
      return FALSE;
    }
    $target_type = $definition->getSettings()['target_type'] ?? '';
    return $target_type === 'media';
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    $key = $this->dimensionManager->getKey();
    $sizing = $key === NULL ? '' : sprintf(', %s: "%s"', $key, implode('|', $this->dimensionManager->getValues()));
    return sprintf('Media reference field. To use existing media: {target_id: N} or {_label: "Name"}. To generate a new image: {_generate: "description of the image", alt_text: "accessible image description"%s}. %s', $sizing, $this->getGenerationGuidance());
  }

  /**
   * {@inheritdoc}
   */
  public function getGenerationGuidance(): string {
    $dimension = $this->dimensionManager->getGuidance();
    return 'A detailed description of the image itself (subject, setting, style, light), written for an image model.'
      . ($dimension === '' ? '' : ' ' . $dimension)
      . ' Generation sets the reference to a new media entity immediately and the image itself renders behind it asynchronously, so never wait for it, rewrite the value, or save the entity on its account.';
  }

  /**
   * {@inheritdoc}
   */
  public function getDimensionKey(): ?string {
    return $this->dimensionManager->getKey();
  }

  /**
   * {@inheritdoc}
   */
  public function getDimensionValues(): array {
    return $this->dimensionManager->getValues();
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    $field_name = $definition->getName();
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();

    // LLMs commonly send [{...}] instead of {...} for single-value fields.
    if ($cardinality === 1 && is_array($value) && array_is_list($value) && count($value) === 1) {
      $value = $value[0];
    }

    if ($cardinality === 1 || !is_array($value) || !array_is_list($value)) {
      $items = [$value];
      $is_single = TRUE;
    }
    else {
      $items = $value;
      $is_single = FALSE;
    }

    $has_generate = FALSE;
    foreach ($items as $item) {
      if (is_array($item) && isset($item['_generate'])) {
        $has_generate = TRUE;
        break;
      }
    }

    if (!$has_generate) {
      return parent::doDeserialize($value, $definition, $options);
    }

    $warnings = [];
    $results = [];

    foreach ($items as $delta => $item) {
      if (is_array($item) && isset($item['_generate'])) {
        // Let the active provider's adapter turn the AI-supplied sizing key
        // (e.g. aspect_ratio or size) into provider configuration. The
        // configuration is provider-shaped and opaque to the pipeline.
        $configuration = $this->dimensionManager->extractConfiguration($item);
        try {
          $mint = $this->minter->mint(
            $item['_generate'],
            $item['alt_text'] ?? $item['_generate'],
            $configuration,
            seed_file: $this->previousFile($field_name, $delta, $options),
          );
        }
        catch (AccessException $e) {
          return new FieldHandlerResult(NULL, [
            new BlueprintError(
              path: 'fields.' . $field_name,
              code: 'ACCESS_DENIED_FIELD',
              message: $e->getMessage(),
              hint: 'The current user lacks permission to create media.',
            ),
          ]);
        }

        // Identity-first: the reference is final at compose time. The
        // deferred pipeline only fills the file behind the media uuid.
        $results[] = ['target_id' => (int) $mint->media->id()];

        if ($mint->jobQueued) {
          $element_uuid = $options['element_uuid'] ?? NULL;
          $this->pendingTargets->register(
            $mint->media->uuid(),
            is_string($element_uuid)
              ? ['uuid' => $element_uuid, 'field_name' => $field_name]
              : NULL,
          );
        }
        elseif ($warnings === []) {
          // Gate off: the minted file is the final image; warn once with the
          // level-specific remediation, scoped to this field.
          $warnings[] = $this->minter->buildDisabledWarning($field_name);
        }
      }
      else {
        $parent_result = parent::doDeserialize($item, $definition, $options);
        if ($parent_result->hasErrors()) {
          return $parent_result;
        }
        $results[] = $parent_result->getValue();
      }
    }

    $final_value = $is_single ? ($results[0] ?? NULL) : $results;
    return new FieldHandlerResult($final_value, [], $warnings);
  }

  /**
   * Resolves the file behind the field's current media reference at a delta.
   *
   * Regenerating an existing image seeds the new mint with the previous
   * media's file so the page keeps showing the current image (not a
   * placeholder flash) while the replacement generates.
   *
   * @param string $field_name
   *   The field being written.
   * @param int $delta
   *   The item delta being written.
   * @param array $options
   *   The deserialize options; 'entity' carries the entity being written
   *   when the caller has it.
   *
   * @return \Drupal\file\FileInterface|null
   *   The previous media's source file, or NULL when there is none (create,
   *   empty field, or a non-file media source).
   */
  protected function previousFile(string $field_name, int $delta, array $options): ?FileInterface {
    $entity = $options['entity'] ?? NULL;
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($field_name)) {
      return NULL;
    }
    $previous = $entity->get($field_name)->referencedEntities()[$delta] ?? NULL;
    if (!$previous instanceof MediaInterface) {
      return NULL;
    }
    $source_value = $previous->getSource()->getSourceFieldValue($previous);
    if (!is_numeric($source_value)) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load((int) $source_value);
    return $file instanceof FileInterface ? $file : NULL;
  }

}
