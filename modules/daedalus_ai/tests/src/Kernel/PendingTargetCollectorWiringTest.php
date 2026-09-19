<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Pins the collector's TargetKeyMapper as a lazy service closure.
 *
 * The pending-target collector sits on a service loop: its TargetKeyMapper
 * leads through the element locator to the field handler manager, whose
 * service_collector pass eagerly constructs every tagged field handler,
 * and several of those handlers' construction graphs lead back to the
 * collector (the image-generation handler directly; the style-map field
 * handler through the style-value resolver's generate handler). Any
 * resolution of the mapper DURING the collector's construction therefore
 * throws ServiceCircularReferenceException on the runtime container
 * whenever the collector's side is built first, which is exactly what a
 * chat turn does when it enters through a consumer of the style-map field
 * handler (the handler needs the style-value resolver, whose generate
 * handler reaches the collector before the field handler manager exists).
 * Both a constructor Reference and a setter
 * `calls` entry resolve during construction (calls run inside
 * createService), and both shipped and failed in turn; only a service
 * closure, resolved at flush time, breaks the loop.
 *
 * This is a DEFINITION pin, not a behavioral one, deliberately: the kernel
 * harness runs on Symfony's ContainerBuilder, which resolves these loops
 * in any construction order (verified empirically: with an eager mapper,
 * cold-order get() calls still succeed here), so only the dumped runtime
 * container exhibits the failure and no kernel test can. What CAN be
 * pinned is the seam that keeps the runtime container safe: the mapper
 * must arrive as a service closure, never as an eager reference. The
 * closure looks like a cleanup target (inject the service, promote the
 * type), and this test is what says no.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class PendingTargetCollectorWiringTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The list is what container COMPILATION needs (autowired references
   * across the enabled modules' services must all resolve), not daedalus_ai's
   * install dependencies: daedalus_blueprint + daedalus_blueprint_api carry the
   * loop's far side, ai carries the provider manager the deferred
   * processors and the image-generation handler's dimension manager
   * resolve, key is ai's own service dependency, daedalus_ui and file
   * satisfy the route-entity resolver's and the pending-media minter's
   * autowiring.
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'key',
    'ai',
    // daedalus_ui's own dependency chain must be listed explicitly for
    // the container to compile (see DaedalusAiKernelTestBase for the rationale).
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_ui',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ai',
  ];

  /**
   * The mapper arrives as a service closure, never an eager reference.
   */
  public function testMapperIsServiceClosure(): void {
    $definition = $this->container->getDefinition('daedalus_ai.pending_target_collector');

    $closure_targets = [];
    foreach ($definition->getArguments() as $argument) {
      if ($argument instanceof ServiceClosureArgument) {
        $closure_targets[] = (string) $argument->getValues()[0];
      }
      elseif ($argument instanceof Reference) {
        $this->assertNotSame('daedalus_blueprint_api.target_key_mapper', (string) $argument,
          'The TargetKeyMapper must not be an eager constructor argument: resolving it during the collector\'s construction closes the collector/field-handler loop and the runtime container throws ServiceCircularReferenceException whenever the collector\'s side is built first (the styling-skill-type chat path).');
      }
    }
    $this->assertSame(['daedalus_blueprint_api.target_key_mapper'], $closure_targets);

    $this->assertSame([], $definition->getMethodCalls(),
      'No setter calls: `calls` also resolve during construction, so a setter-injected mapper closes the same loop one node earlier (this shipped and failed).');
  }

}
