<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_tempstore\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_tempstore\ParamConverter\EntityConverter;
use Drupal\Tests\daedalus_tempstore\Kernel\Fixture\StateActivationVoter;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests the tagged-service collection and the converter's use of the voters.
 *
 * The unit test covers vote composition; this covers the container wiring
 * (service_collector gathers `tempstore_activation_voter` tags) and the
 * end-to-end path: an active vote makes the param converter swap in the
 * staged entity and notify every voter.
 */
#[Group('daedalus_tempstore')]
#[RunTestsInSeparateProcesses]
final class ActivationVotersWiringTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    'daedalus_tempstore',
    'entity_test',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    foreach (['voter_a', 'voter_b'] as $key) {
      $container->register("daedalus_tempstore_test.$key", StateActivationVoter::class)
        ->setArguments([new Reference('state'), $key])
        ->addTag('tempstore_activation_voter');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->setUpCurrentUser();
  }

  /**
   * Tagged voters are collected and their votes compose as specified.
   */
  public function testTaggedVotersAreCollected(): void {
    $voters = $this->container->get('daedalus_tempstore.activation_voters');
    $state = $this->container->get('state');

    // Both voters neutral: inactive.
    $this->assertFalse($voters->isActive());

    $state->set('voter_a', 'allow');
    $this->assertTrue($voters->isActive());

    $state->set('voter_b', 'deny');
    $this->assertFalse($voters->isActive());
  }

  /**
   * An active vote swaps in the staged entity and notifies every voter.
   */
  public function testConverterSwapsAndNotifies(): void {
    $entity = EntityTest::create(['name' => 'saved']);
    $entity->save();

    $staged = clone $entity;
    $staged->set('name', 'staged');
    $this->container->get('daedalus_tempstore.entity_tempstore_repository')->set($staged);

    $converter = $this->container->get(EntityConverter::class);
    $state = $this->container->get('state');

    // Inactive: the saved entity passes through untouched.
    $this->assertSame('saved', $converter->loadEntityFromTempstore($entity)->label());

    $state->set('voter_a', 'allow');
    $this->assertSame('staged', $converter->loadEntityFromTempstore($entity)->label());

    // Both voters were notified, including the one that voted neutral.
    $this->assertSame(['staged'], $state->get('voter_a.swapped'));
    $this->assertSame(['staged'], $state->get('voter_b.swapped'));

    // A deny vetoes the allow: back to the saved entity.
    $state->set('voter_b', 'deny');
    $this->assertSame('saved', $converter->loadEntityFromTempstore($entity)->label());
  }

}
