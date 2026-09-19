<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\PendingMedia;

use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Registry of pending image-generation jobs, keyed by media uuid.
 *
 * Backed by the same private tempstore family as the deferred batch stash:
 * jobs are registered during the minting user's chat turn and processed by
 * that same user's later trigger request, so per-user scoping is correct.
 * The batch names WHICH media uuids to process; this store holds WHAT to
 * generate for each of them.
 */
class GenerationJobStore {

  /**
   * The private tempstore collection holding the jobs.
   */
  const COLLECTION = 'daedalus_ai_generation_jobs';

  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * Registers (or replaces) a generation job under its media uuid.
   *
   * @param \Drupal\daedalus_ai\PendingMedia\GenerationJob $job
   *   The job to store.
   */
  public function set(GenerationJob $job): void {
    $this->store()->set($job->mediaUuid, $job->toArray());
  }

  /**
   * Loads the pending job for a media uuid.
   *
   * @param string $media_uuid
   *   The media entity uuid.
   *
   * @return \Drupal\daedalus_ai\PendingMedia\GenerationJob|null
   *   The job, or NULL when none is registered for that uuid.
   */
  public function get(string $media_uuid): ?GenerationJob {
    $values = $this->store()->get($media_uuid);
    return is_array($values) ? GenerationJob::fromArray($values) : NULL;
  }

  /**
   * Removes a media uuid's job (after processing succeeds or fails for good).
   *
   * @param string $media_uuid
   *   The media entity uuid.
   */
  public function delete(string $media_uuid): void {
    $this->store()->delete($media_uuid);
  }

  /**
   * Returns the underlying tempstore.
   *
   * @return \Drupal\Core\TempStore\PrivateTempStore
   *   The private tempstore collection.
   */
  protected function store() {
    return $this->tempStoreFactory->get(self::COLLECTION);
  }

}
