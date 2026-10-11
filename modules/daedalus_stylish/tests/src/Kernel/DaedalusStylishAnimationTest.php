<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishAnimation;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\AnimationStepsItem;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the animation entity: identity, steps, access, and the repository.
 *
 * An animation is a named keyframes block, the motion material. Its
 * machine name IS the keyframes ident tail (motion-{name}), so identity is
 * gated hard at save: the steps must exist and an authored machine name
 * must fit the CSS-safe charset. Steps store canonical (offsets folded to
 * ascending percentages). Access follows the swatch governance grid
 * (per-gesture permissions, view open for published animations), and the
 * repository resolves references and the published set once per request.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishAnimationTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * Creates and saves an animation.
   */
  protected function createAnimation(array $values = [], array $steps = []): DaedalusStylishAnimation {
    $animation = DaedalusStylishAnimation::create($values + ['label' => 'Fade in']);
    $animation->setSteps($steps ?: ['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']]);
    $animation->save();
    return $animation;
  }

  /**
   * Identity: machine name generation, steps roundtrip, published default.
   */
  public function testAnimationIdentity(): void {
    $animation = $this->createAnimation();
    $this->assertSame('fade_in', $animation->getMachineName());
    $this->assertSame(['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']], $animation->getSteps());
    $this->assertTrue($animation->isPublished());
    $this->assertFalse($animation->isShipped());
    $this->assertSame('', $animation->getDescription());
    // The one derivation rule, both directions.
    $this->assertSame('motion-fade_in', StyleGuide::animationName($animation->getMachineName()));
    $this->assertSame('fade_in', StyleGuide::animationReference('motion-fade_in'));
    $this->assertNull(StyleGuide::animationReference('spin'));
    $this->assertNull(StyleGuide::animationReference('motion-'));
    $this->assertNull(StyleGuide::animationReference('motionless'));

    // Collisions suffix like every machine-named daedalus_stylish entity.
    $second = $this->createAnimation();
    $this->assertSame('fade_in_2', $second->getMachineName());

    // Steps store canonical: from/to fold, numbers normalize, offsets sort.
    $rise = DaedalusStylishAnimation::create(['label' => 'Rise']);
    $rise->setDescription('A soft entrance for page bands.');
    $rise->setSteps([
      'to' => ['opacity' => '1', 'transform' => 'none'],
      '50.0%' => ['opacity' => '0.5'],
      'from' => ['opacity' => '0', 'transform' => 'translateY(1rem)'],
    ]);
    $rise->save();
    $reloaded = $this->container->get('entity_type.manager')->getStorage('daedalus_animation')->loadUnchanged($rise->id());
    $this->assertSame([
      '0%' => ['opacity' => '0', 'transform' => 'translateY(1rem)'],
      '50%' => ['opacity' => '0.5'],
      '100%' => ['opacity' => '1', 'transform' => 'none'],
    ], $reloaded->getSteps());
    $this->assertSame('rise', $reloaded->getMachineName());
    $this->assertSame('A soft entrance for page bands.', $reloaded->getDescription());
  }

  /**
   * The offset grammar: one canonical spelling per offset.
   */
  public function testOffsetCanonicalization(): void {
    $this->assertSame('0%', AnimationStepsItem::canonicalOffset('from'));
    $this->assertSame('100%', AnimationStepsItem::canonicalOffset(' TO '));
    $this->assertSame('50%', AnimationStepsItem::canonicalOffset('050.0%'));
    $this->assertSame('33.3%', AnimationStepsItem::canonicalOffset('33.3%'));
    $this->assertSame('0%', AnimationStepsItem::canonicalOffset('0%'));
    $this->assertSame('100%', AnimationStepsItem::canonicalOffset('100%'));
    $this->assertNull(AnimationStepsItem::canonicalOffset('50'));
    $this->assertNull(AnimationStepsItem::canonicalOffset('150%'));
    $this->assertNull(AnimationStepsItem::canonicalOffset('-1%'));
    $this->assertNull(AnimationStepsItem::canonicalOffset('middle'));
    $this->assertNull(AnimationStepsItem::canonicalOffset('0%, 100%'));
    // Uncanonical keys survive canonicalization (the validator refuses
    // them; storage never drops a step silently) and sort last.
    $this->assertSame([
      '0%',
      '100%',
      'middle',
    ], array_keys(AnimationStepsItem::canonicalizeSteps(['middle' => [], 'to' => [], '0%' => []])));
  }

  /**
   * An animation IS its steps: saving without any is malformed.
   */
  public function testStepsAreRequired(): void {
    try {
      DaedalusStylishAnimation::create(['label' => 'Empty'])->save();
      $this->fail('A step-less animation saved.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertStringContainsString('requires at least one keyframe step', $e->getMessage());
    }
  }

  /**
   * Authored machine names are CSS-charset-gated; hyphens are legal.
   */
  public function testMachineNameCharset(): void {
    $animation = $this->createAnimation(['machine_name' => 'fade-in-slow']);
    $this->assertSame('fade-in-slow', $animation->getMachineName());

    try {
      $this->createAnimation(['machine_name' => 'Fade In!']);
      $this->fail('A CSS-unsafe machine name saved.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertStringContainsString('not an animation machine name', $e->getMessage());
    }
  }

  /**
   * The five-grade permission grid maps onto entity access; view is open.
   */
  public function testAccess(): void {
    // Burn uid 1, the superuser bypass.
    $this->createUser();
    $animation = $this->createAnimation();
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('daedalus_animation');

    $nobody = $this->createUser();
    $this->assertFalse($animation->access('update', $nobody));
    $this->assertFalse($animation->access('delete', $nobody));
    $this->assertFalse($handler->createAccess(NULL, $nobody));
    // Wearing an animation is permissionless (an ordinary host-entity
    // edit), so anyone may see published animations: their CSS is public.
    $this->assertTrue($animation->access('view', $nobody));

    $creator = $this->createUser(['create animations']);
    $this->assertTrue($handler->createAccess(NULL, $creator));
    $this->assertFalse($animation->access('update', $creator));

    $updater = $this->createUser(['update animations']);
    $this->assertTrue($animation->access('update', $updater));
    $this->assertFalse($animation->access('delete', $updater));

    $deleter = $this->createUser(['delete animations']);
    $this->assertTrue($animation->access('delete', $deleter));
    $in_use_deleter = $this->createUser(['delete animations in use']);
    $this->assertTrue($animation->access('delete', $in_use_deleter));

    $admin = $this->createUser(['administer animations']);
    $this->assertTrue($animation->access('update', $admin));
    $this->assertTrue($animation->access('delete', $admin));

    // Unpublished animations hide from everyone but administrators.
    $animation->setUnpublished()->save();
    $handler->resetCache();
    $this->assertFalse($animation->access('view', $nobody));
    $this->assertTrue($animation->access('view', $admin));
  }

  /**
   * The shipped-source lane: one hash over the steps, two comparisons.
   */
  public function testShippedSource(): void {
    $steps = ['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']];
    $animation = $this->createAnimation();
    $animation->setShippedSource('daedalus_stylish_animations_test', $steps)->save();
    $this->assertTrue($animation->isShipped());
    $this->assertSame('shipped', $animation->getOrigin());
    $this->assertFalse($animation->isModified());
    // The hash is over content: key order never matters.
    $this->assertFalse($animation->hasNewDefaults(array_reverse($steps, TRUE)));
    $this->assertTrue($animation->hasNewDefaults(['0%' => ['opacity' => '0.2'], '100%' => ['opacity' => '1']]));

    $animation->setSteps(['0%' => ['opacity' => '0.5'], '100%' => ['opacity' => '1']]);
    $this->assertTrue($animation->isModified());
  }

  /**
   * The repository: reference resolution and the published universe.
   */
  public function testRepository(): void {
    $repository = $this->container->get('daedalus_stylish.animation_repository');
    $fade = $this->createAnimation();
    $rise = $this->createAnimation(['label' => 'Rise']);
    $draft = $this->createAnimation(['label' => 'Draft']);
    $draft->setUnpublished()->save();

    // Machine name first, then uuid; misses memoize as NULL.
    $this->assertSame($fade->id(), $repository->resolve('fade_in')?->id());
    $this->assertSame($rise->id(), $repository->resolve($rise->uuid())?->id());
    $this->assertNull($repository->resolve('nope'));
    $this->assertNull($repository->resolve('nope'));

    // The published set, label-sorted; drafts are not vocabulary.
    $this->assertSame(['fade_in', 'rise'], array_map(static fn ($animation) => $animation->getMachineName(), $repository->publishedAnimations()));

    // The memo lives until reset: a save the hooks do not cover (they
    // land with delivery) is invisible until reset() forgets it.
    $this->createAnimation(['label' => 'Bounce']);
    $repository->reset();
    $this->assertSame(['bounce', 'fade_in', 'rise'], array_map(static fn ($animation) => $animation->getMachineName(), $repository->publishedAnimations()));
    $this->assertSame('bounce', $repository->resolve('bounce')?->getMachineName());
  }

}
