<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Unit\DeferredProcessor;

use Drupal\daedalus_ai\DeferredProcessor\DeferredProcessingResult;
use Drupal\daedalus_ai\DeferredProcessor\DeferredProcessorInterface;
use Drupal\daedalus_ai\DeferredProcessor\DeferredProcessorManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the dispatch-by-type behavior of the deferred processor manager.
 *
 * The manager is the routing core of the deferred pipeline:
 * DeferredProcessingController reads each batch's type and media uuids and
 * asks the manager to process each uuid, keyed by that type string. Two
 * behaviors are safety-critical and untested elsewhere:
 *
 * - process() must route to the processor whose getType() matches the batch
 *   type and return that processor's result UNCHANGED. A regression in the
 *   keying (e.g. keying by class name instead of getType()) would route an
 *   image_generation batch to the wrong handler or none at all.
 * - process() on a type with no registered processor must THROW (naming the
 *   type), not silently no-op. Swallowing an unknown type would make a
 *   failed batch look successful, leaving the placeholder image on the page
 *   with no error surfaced to the user.
 *
 * hasProcessor()/getProcessor() are the controller's pre-flight checks and
 * are pinned alongside.
 *
 * This is a pure unit test: DeferredProcessorManager has no container
 * dependencies and DeferredProcessingResult is a plain value object, so
 * anonymous processor doubles fully exercise the routing. It also folds in
 * the only meaningful DeferredProcessingResult behavior worth pinning (the
 * result object flows back through process() identically), which is why no
 * separate DTO test exists.
 */
#[Group('daedalus_ai')]
class DeferredProcessorManagerTest extends UnitTestCase {

  /**
   * Builds a processor double reporting a given type.
   *
   * The double records the media uuid it received and returns a result
   * tagged with its own type, so the test can prove which processor actually
   * ran.
   *
   * @param string $type
   *   The batch type this double claims to handle.
   *
   * @return \Drupal\daedalus_ai\DeferredProcessor\DeferredProcessorInterface
   *   An anonymous processor double.
   */
  private function makeProcessor(string $type): DeferredProcessorInterface {
    return new class($type) implements DeferredProcessorInterface {

      /**
       * The media uuid process() received, NULL until it runs.
       */
      public ?string $received = NULL;

      public function __construct(private readonly string $type) {}

      /**
       * {@inheritdoc}
       */
      public function getType(): string {
        return $this->type;
      }

      /**
       * {@inheritdoc}
       */
      public function process(string $media_uuid, array $context): DeferredProcessingResult {
        $this->received = $media_uuid;
        return DeferredProcessingResult::success($media_uuid, ['handled_by' => $this->type]);
      }

      /**
       * {@inheritdoc}
       */
      public function getMessages(): array {
        return [];
      }

    };
  }

  /**
   * Process() routes by type, returns the result intact, and guards unknowns.
   *
   * Registers two processors ('image_generation' and 'other'), then proves:
   * registration is reflected by hasProcessor/getProcessor; an
   * 'image_generation' dispatch reaches ONLY that processor and its result
   * flows back unchanged; and a dispatch for an unregistered type throws an
   * exception that names the offending type.
   */
  public function testProcessRoutesByTypeAndThrowsOnUnknown(): void {
    $manager = new DeferredProcessorManager();
    $image = $this->makeProcessor('image_generation');
    $other = $this->makeProcessor('other');
    $manager->addProcessor($image);
    $manager->addProcessor($other);

    // Registration is observable through the controller's pre-flight checks.
    $this->assertTrue($manager->hasProcessor('image_generation'));
    $this->assertTrue($manager->hasProcessor('other'));
    $this->assertFalse($manager->hasProcessor('missing'));
    $this->assertSame($image, $manager->getProcessor('image_generation'));
    $this->assertSame($other, $manager->getProcessor('other'));
    $this->assertNull($manager->getProcessor('missing'));

    $media_uuid = 'ab2c58f0-6b6f-4c7a-9d3e-2f4a5b6c7d8e';
    $result = $manager->process('image_generation', $media_uuid, ['entity_type' => 'node']);

    // Only the matching processor ran, and the manager returned its result
    // verbatim (the DTO is not rewrapped or mutated in transit).
    $this->assertSame($media_uuid, $image->received);
    $this->assertNull($other->received);
    $this->assertInstanceOf(DeferredProcessingResult::class, $result);
    $this->assertTrue($result->success);
    $this->assertSame($media_uuid, $result->mediaUuid);
    $this->assertSame(['handled_by' => 'image_generation'], $result->data);

    // An unknown type fails loudly rather than silently doing nothing, and the
    // message names the type so the failure is diagnosable.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/no_such_type/');
    $manager->process('no_such_type', $media_uuid, []);
  }

}
