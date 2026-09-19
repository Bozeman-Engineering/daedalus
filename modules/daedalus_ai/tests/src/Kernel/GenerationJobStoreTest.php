<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\daedalus_ai\PendingMedia\GenerationJob;
use Drupal\daedalus_ai\PendingMedia\GenerationJobStore;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the pending image-generation job registry.
 *
 * The store is a thin uuid-keyed map over private tempstore; one lifecycle
 * method covers register, load, replace (the retry prompt-rewrite path), and
 * delete, keeping the per-method Drupal install cost to a single pay.
 *
 * The daedalus_ai module is not enabled (its info.yml drags the full AI stack,
 * none of which the store touches); the store is constructed directly over
 * the container's real tempstore.private, exactly as the service definition
 * wires it. An authenticated current user makes tempstore ownership
 * session-free.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class GenerationJobStoreTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * The store under test.
   *
   * @var \Drupal\daedalus_ai\PendingMedia\GenerationJobStore
   */
  protected GenerationJobStore $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(['name' => 'minter']);
    $this->store = new GenerationJobStore($this->container->get('tempstore.private'));
  }

  /**
   * Covers the full job lifecycle: set, get, replace, delete.
   */
  public function testJobLifecycle(): void {
    $uuid = '2cbc1b02-98a4-4f0f-b6a7-3c4d5e6f7a8b';

    $this->assertNull($this->store->get($uuid), 'An unregistered uuid loads as NULL.');

    $job = new GenerationJob(
      mediaUuid: $uuid,
      prompt: 'A dark forest at dusk',
      altText: 'Dark forest',
      configuration: ['aspect_ratio' => '16:9'],
    );
    $this->store->set($job);

    $loaded = $this->store->get($uuid);
    $this->assertNotNull($loaded);
    $this->assertSame($uuid, $loaded->mediaUuid);
    $this->assertSame('A dark forest at dusk', $loaded->prompt);
    $this->assertSame('Dark forest', $loaded->altText);
    $this->assertSame(['aspect_ratio' => '16:9'], $loaded->configuration);

    // Replacing under the same uuid is the retry prompt-rewrite path: the
    // media identity is stable, only the job payload changes.
    $this->store->set(new GenerationJob(
      mediaUuid: $uuid,
      prompt: 'A moonlit forest at dusk',
      altText: 'Dark forest',
    ));
    $rewritten = $this->store->get($uuid);
    $this->assertSame('A moonlit forest at dusk', $rewritten->prompt);
    $this->assertSame([], $rewritten->configuration, 'Replacement fully overwrites; configuration defaults to empty.');

    // Jobs for other uuids are untouched by a delete.
    $other = new GenerationJob(
      mediaUuid: 'e5cf8b62-1111-4222-8333-444455556666',
      prompt: 'A sunny meadow',
      altText: 'Meadow',
    );
    $this->store->set($other);
    $this->store->delete($uuid);
    $this->assertNull($this->store->get($uuid), 'Deleted jobs are gone.');
    $this->assertNotNull($this->store->get($other->mediaUuid), 'Deleting one uuid leaves other jobs alone.');
  }

}
