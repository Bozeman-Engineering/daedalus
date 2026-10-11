<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\Dispatch;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the first-supports-wins-in-priority-order backend dispatch contract.
 *
 * BlueprintBackendResolver is the single decision point that routes every
 * blueprint operation to a backend. The service_collector compiler pass adds
 * backends highest-priority-first, and resolve() returns the first backend
 * whose supports() returns TRUE. That ordering is the only thing keeping the
 * config backend (priority 0) ahead of the content backend (priority -10): a
 * config entity type is claimed by the config backend before the content
 * fallback ever sees it.
 *
 * This test pins that contract directly, with no container. It registers two
 * backend doubles in the exact order the compiler pass would hand them to
 * addBackend() (higher priority first) and proves:
 * - resolve() returns the FIRST backend that supports the type,
 *   short-circuiting the lower-priority one even when both would match.
 * - a type only the lower-priority backend supports still resolves to it.
 * - resolve() returns NULL when no backend supports the type.
 * - hasBackend() is exactly resolve() !== NULL.
 *
 * A regression to last-wins, unordered iteration, or "collect all matches"
 * would misroute config-vs-content operations to the wrong backend, and nothing
 * else in the suite would catch it. It deliberately does not test the concrete
 * ContentBlueprintBackend/ConfigBlueprintBackend (those delegate to services
 * covered elsewhere); it isolates the resolver's routing logic.
 */
#[CoversClass(BlueprintBackendResolver::class)]
#[Group('daedalus_blueprint')]
class BlueprintBackendResolverTest extends UnitTestCase {

  /**
   * Builds a backend double whose supports() returns TRUE for given types.
   *
   * @param string[] $supported
   *   The entity type IDs this backend claims to support. An empty array
   *   models a backend that supports nothing (always returns FALSE).
   *
   * @return \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface
   *   A configured mock backend.
   */
  protected function backendSupporting(array $supported): BlueprintBackendInterface {
    $backend = $this->createMock(BlueprintBackendInterface::class);
    $backend->method('supports')->willReturnCallback(
      static fn (string $type): bool => in_array($type, $supported, TRUE),
    );
    return $backend;
  }

  /**
   * Tests priority-ordered, first-supports-wins resolution and hasBackend().
   */
  public function testFirstSupportingBackendInPriorityOrderWins(): void {
    $resolver = new BlueprintBackendResolver();

    // Backend A is the higher-priority backend (added first, mirroring the
    // compiler pass): it only claims 'view'. Backend B is the lower-priority
    // catch-all that claims everything. Both support 'view', so the resolver
    // must return A to prove it short-circuits on the first match in order.
    $backendA = $this->backendSupporting(['view']);
    $backendB = $this->backendSupporting(['view', 'node', 'block_content']);

    $resolver->addBackend($backendA);
    $resolver->addBackend($backendB);

    // 'view' is supported by both; the first (higher-priority) backend wins.
    $this->assertSame($backendA, $resolver->resolve('view'));

    // 'node' is supported only by the catch-all; it falls through to B.
    $this->assertSame($backendB, $resolver->resolve('node'));

    // hasBackend mirrors resolve() !== NULL for supported types.
    $this->assertTrue($resolver->hasBackend('view'));
    $this->assertTrue($resolver->hasBackend('node'));
  }

  /**
   * Tests the entity-level door: resolveFor() asks supportsEntity() in order.
   *
   * What an entity IS decides its backend, not its type id: the first
   * backend in priority order that claims the entity wins, and no claim
   * yields NULL.
   */
  public function testResolveForAsksBackendsAboutTheEntity(): void {
    $resolver = new BlueprintBackendResolver();
    $claimed = $this->createMock(EntityInterface::class);
    $unclaimed = $this->createMock(EntityInterface::class);

    $backendA = $this->createMock(BlueprintBackendInterface::class);
    $backendA->method('supportsEntity')->willReturn(FALSE);
    $backendB = $this->createMock(BlueprintBackendInterface::class);
    $backendB->method('supportsEntity')->willReturnCallback(
      static fn (EntityInterface $entity): bool => $entity === $claimed,
    );
    $resolver->addBackend($backendA);
    $resolver->addBackend($backendB);

    $this->assertSame($backendB, $resolver->resolveFor($claimed));
    $this->assertNull($resolver->resolveFor($unclaimed));
  }

  /**
   * Tests that an unsupported type resolves to NULL and hasBackend is FALSE.
   */
  public function testNoSupportingBackendYieldsNull(): void {
    $resolver = new BlueprintBackendResolver();

    // Two backends that both refuse everything.
    $resolver->addBackend($this->backendSupporting([]));
    $resolver->addBackend($this->backendSupporting([]));

    $this->assertNull($resolver->resolve('node'));
    $this->assertFalse($resolver->hasBackend('node'));
  }

  /**
   * Tests an empty resolver: no backends means no match.
   *
   * Guards the empty-loop path so a future refactor cannot accidentally return
   * a default backend when none were registered.
   */
  public function testEmptyResolverResolvesToNull(): void {
    $resolver = new BlueprintBackendResolver();

    $this->assertNull($resolver->resolve('node'));
    $this->assertFalse($resolver->hasBackend('node'));
  }

}
