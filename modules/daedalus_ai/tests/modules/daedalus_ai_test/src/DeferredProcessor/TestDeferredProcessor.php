<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_test\DeferredProcessor;

use Drupal\daedalus_ai\DeferredProcessor\DeferredProcessingResult;
use Drupal\daedalus_ai\DeferredProcessor\DeferredProcessorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;

/**
 * A deferred processor that succeeds without calling a provider.
 *
 * The real deferred pipeline (image generation) ends at an AI provider, the
 * same network boundary the behavior tests deliberately stop at. This stub
 * stands in for exactly that boundary: everything AROUND it (the tempstore
 * batch, the claim/execute/store lifecycle in DeferredProcessingController,
 * the mutation-response render, and the client-side applyResponse swap +
 * highlight) is the real production code under test.
 *
 * When the job's media uuid names a REAL media entity (the identity-first
 * scenarios: the entity was minted at compose time and only its pixels are
 * pending), the stub performs the same one write the real processor performs
 * after its provider call succeeds: it swaps the file behind the media's
 * source field, so cache tags propagate the fill exactly as in production. A
 * uuid with no media (the pipeline-shape legs) just reports success, which is
 * all the refresh contract needs.
 */
class TestDeferredProcessor implements DeferredProcessorInterface {

  /**
   * The batch type this stub handles.
   */
  public const TYPE = 'daedalus_ai_test';

  /**
   * A 1x1 transparent PNG standing in for generated pixels.
   */
  protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileRepositoryInterface $fileRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return self::TYPE;
  }

  /**
   * {@inheritdoc}
   */
  public function process(string $media_uuid, array $context): DeferredProcessingResult {
    $media = $this->entityTypeManager->getStorage('media')
      ->loadByProperties(['uuid' => $media_uuid]);
    $media = reset($media);
    if ($media instanceof MediaInterface) {
      // The one write the real processor performs once its provider call
      // succeeds: swap the file behind the media uuid. References never
      // move; the media's cache tags propagate the fill.
      $file = $this->fileRepository->writeData(
        base64_decode(self::PNG),
        'public://daedalus-ai-test-generated-' . $media->id() . '.png',
        FileExists::Replace,
      );
      /** @var \Drupal\media\MediaTypeInterface $media_type */
      $media_type = $this->entityTypeManager->getStorage('media_type')->load($media->bundle());
      $source_field_name = $media_type->getSource()
        ->getSourceFieldDefinition($media_type)
        ->getName();
      $media->set($source_field_name, [
        'target_id' => $file->id(),
        'alt' => $media->getName(),
      ]);
      $media->save();
    }
    return DeferredProcessingResult::success($media_uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function getMessages(): array {
    return [];
  }

}
