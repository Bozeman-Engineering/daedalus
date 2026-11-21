<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\DeferredProcessor;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\daedalus_ai\PendingMedia\GenerationJob;
use Drupal\daedalus_ai\PendingMedia\GenerationJobStore;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills the file behind a minted media entity's uuid.
 *
 * Identity-first, content-deferred: the media entity was minted at compose
 * time (PendingMediaMinter) with a placeholder file, and every reference to
 * it is final. This processor loads the generation job registered under the
 * media uuid, generates the image via the text_to_image AI provider, and
 * swaps the file on the media entity. The media's cache tags propagate the
 * change to everything referencing it; no reference ever moves.
 *
 * The prompt that goes out is the job's prompt, the model's own words: the
 * design system shapes the page through the materials and the law, never
 * through a rule framed around every picture.
 */
class ImageGenerationProcessor implements DeferredProcessorInterface, RetryableProcessorInterface {

  /**
   * The batch type this processor handles.
   */
  public const TYPE = 'image_generation';

  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected GenerationJobStore $jobStore,
    protected LoggerInterface $logger,
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
  public function getMessages(): array {
    return [
      'loading' => 'Painting your images…',
      'retry' => 'Rewriting rejected prompts…',
      'success' => 'Images generated successfully.',
      'partial_success' => 'Some images were generated. @count failed.',
      'failure' => 'Image generation failed.',
      'error' => 'Image generation could not complete. Refresh and try again.',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function process(string $media_uuid, array $context): DeferredProcessingResult {
    $job = $this->jobStore->get($media_uuid);
    if ($job === NULL) {
      return DeferredProcessingResult::failure(
        $media_uuid,
        "No generation job registered for media {$media_uuid}.",
      );
    }

    $media = $this->loadMedia($media_uuid);
    if ($media === NULL) {
      return DeferredProcessingResult::failure(
        $media_uuid,
        "Media {$media_uuid} not found.",
        data: $this->failureData($job),
      );
    }

    // The swap updates the minted media as the current user. Gate before
    // doing any work: no point burning an Imagen call if the user cannot
    // update the media it would fill. Non-retryable: a prompt rewrite cannot
    // fix a permission denial.
    if (!$media->access('update')) {
      return DeferredProcessingResult::failure(
        $media_uuid,
        'Access denied: cannot update media.',
        data: $this->failureData($job),
      );
    }

    try {
      $file = $this->generateImageFile($job);
    }
    catch (\Exception $e) {
      return DeferredProcessingResult::failure(
        $media_uuid,
        $e->getMessage(),
        retryable: TRUE,
        data: $this->failureData($job),
      );
    }

    if ($file === NULL) {
      return DeferredProcessingResult::failure(
        $media_uuid,
        'Image generation returned no images.',
        retryable: TRUE,
        data: $this->failureData($job),
      );
    }

    // The file swap: the one write the deferred pipeline performs. The
    // placeholder file entity stays (it is shared by every pending mint);
    // only this media's source reference moves to the generated file.
    $source_field_name = $this->sourceFieldName();
    $media->set($source_field_name, [
      'target_id' => $file->id(),
      'alt' => $job->altText,
    ]);
    $media->save();
    $this->jobStore->delete($media_uuid);

    $this->logger->info('Generated image for media @uuid (file @fid).', [
      '@uuid' => $media_uuid,
      '@fid' => $file->id(),
    ]);

    return DeferredProcessingResult::success($media_uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function buildRetryJobs(array $failures): array {
    // What a retry rewrites and regenerates from is the stored job (the
    // prompt, the alt text, the provider configuration), never the request:
    // a failure with no job of this user's is not retried.
    $jobs = [];
    foreach ($failures as $failure) {
      $media_uuid = $failure['media_uuid'] ?? NULL;
      $job = is_string($media_uuid) ? $this->jobStore->get($media_uuid) : NULL;
      if ($job !== NULL) {
        $jobs[] = [$job, (string) ($failure['reason'] ?? '')];
      }
    }
    if ($jobs === []) {
      return [];
    }

    $rewritten = $this->rewritePrompts($jobs);
    if ($rewritten === NULL) {
      return [];
    }

    $media_uuids = [];
    foreach ($jobs as $i => [$job]) {
      $new_prompt = $rewritten[$i] ?? NULL;
      if (!is_string($new_prompt) || $new_prompt === '') {
        continue;
      }
      // Re-register under the same media uuid: the identity never changes,
      // only the prompt. Overwriting the store entry IS the rewrite; the
      // alt text and the chosen image dimensions carry over.
      $this->jobStore->set(new GenerationJob(
        mediaUuid: $job->mediaUuid,
        prompt: $new_prompt,
        altText: $job->altText,
        configuration: $job->configuration,
      ));
      $media_uuids[] = $job->mediaUuid;
    }

    return $media_uuids;
  }

  /**
   * Calls the LLM to rewrite rejected image prompts.
   *
   * @param array $jobs
   *   Per failure, a pair: the stored GenerationJob and the reason its
   *   generation was rejected.
   *
   * @return array|null
   *   Array of rewritten prompts indexed by position, or NULL on failure.
   */
  protected function rewritePrompts(array $jobs): ?array {
    $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat_with_tools');
    if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
      return NULL;
    }

    $provider = $this->aiProvider->createInstance($defaults['provider_id']);

    $items = [];
    foreach ($jobs as $i => [$job, $reason]) {
      $items[] = [
        'index' => $i,
        'original_prompt' => $job->prompt,
        'rejection_reason' => $reason,
        'alt_text' => $job->altText,
      ];
    }

    $system = <<<'PROMPT'
You rewrite image generation prompts that were rejected by content moderation. For each prompt, you receive the original prompt, the rejection reason, and the alt text. Your job is to preserve the creative intent while rephrasing to avoid the moderation issue.

Return ONLY a JSON array of objects with "index" and "prompt" keys. Do not include any other text.
PROMPT;

    $user = Json::encode($items);

    $input = new ChatInput([
      new ChatMessage('system', $system),
      new ChatMessage('user', $user),
    ]);

    try {
      $response = $provider->chat($input, $defaults['model_id'], ['daedalus_ai', 'deferred_retry']);
      $text = $response->getNormalized()->getText();
    }
    catch (\Exception) {
      return NULL;
    }

    // Strip markdown code fences if present.
    $text = trim($text);
    if (str_starts_with($text, '```')) {
      $text = preg_replace('/^```(?:json)?\s*/', '', $text);
      $text = preg_replace('/\s*```$/', '', $text);
    }

    $parsed = Json::decode($text);
    if (!is_array($parsed)) {
      return NULL;
    }

    $result = [];
    foreach ($parsed as $item) {
      if (isset($item['index'], $item['prompt'])) {
        $result[(int) $item['index']] = $item['prompt'];
      }
    }

    return $result;
  }

  /**
   * Generates an image and returns it as a managed file entity.
   *
   * @param \Drupal\daedalus_ai\PendingMedia\GenerationJob $job
   *   The generation job naming the prompt, alt text, and provider-shaped
   *   dimension configuration.
   *
   * @return \Drupal\file\FileInterface|null
   *   The generated file, or NULL when the provider produced nothing.
   */
  protected function generateImageFile(GenerationJob $job): ?FileInterface {
    $defaults = $this->aiProvider->getDefaultProviderForOperationType('text_to_image');
    if (empty($defaults['provider_id'])) {
      $this->logger->warning('No default text_to_image provider configured.');
      return NULL;
    }

    $provider = $this->aiProvider->createInstance($defaults['provider_id']);
    $model_id = $defaults['model_id'] ?? '';
    if ($job->configuration) {
      $provider->setConfiguration($job->configuration);
    }
    $input = new TextToImageInput($job->prompt);

    $output = $provider->textToImage($input, $model_id);
    $images = $output->getNormalized();

    if (empty($images)) {
      return NULL;
    }

    $image_obj = is_array($images) ? reset($images) : $images;
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(substr($job->altText, 0, 50))) ?: 'ai-image';
    $ext = str_contains($image_obj->getMimeType() ?? 'image/png', 'jpeg') ? 'jpg' : 'png';
    $image_obj->setFilename('ai-' . $slug . '-' . time() . '.' . $ext);
    return $image_obj->getAsFileEntity('public://ai_generated/');
  }

  /**
   * Loads a media entity by uuid.
   *
   * @param string $media_uuid
   *   The media uuid.
   *
   * @return \Drupal\media\MediaInterface|null
   *   The media entity, or NULL when none exists.
   */
  protected function loadMedia(string $media_uuid): ?MediaInterface {
    $media = $this->entityTypeManager->getStorage('media')
      ->loadByProperties(['uuid' => $media_uuid]);
    $media = reset($media);
    return $media instanceof MediaInterface ? $media : NULL;
  }

  /**
   * Returns the media:image bundle's source field name.
   *
   * @return string
   *   The source field name (e.g. field_media_image).
   */
  protected function sourceFieldName(): string {
    /** @var \Drupal\media\MediaTypeInterface $media_type */
    $media_type = $this->entityTypeManager->getStorage('media_type')->load('image');
    return $media_type->getSource()
      ->getSourceFieldDefinition($media_type)
      ->getName();
  }

  /**
   * What a failure reports about its job.
   *
   * For whoever reads the failure (the chat, a run's record). The retry
   * path does not read it back: it rewrites from the stored job.
   *
   * @param \Drupal\daedalus_ai\PendingMedia\GenerationJob $job
   *   The job that failed.
   *
   * @return array
   *   The job's prompt, alt text, and configuration.
   */
  protected function failureData(GenerationJob $job): array {
    $data = [
      'prompt' => $job->prompt,
      'alt_text' => $job->altText,
    ];
    if ($job->configuration) {
      $data['configuration'] = $job->configuration;
    }
    return $data;
  }

}
