<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the swatch entity: identity, kinds, values, and access.
 *
 * A swatch is a named CSS value — the palette's unit. Its machine name IS
 * the custom property tail (--swatch-{name}), so identity is gated hard at
 * save: the kind must name a SwatchKind plugin, the value must exist, and
 * an authored machine name must fit the CSS-safe charset. Access follows
 * the variant governance grid (per-gesture permissions, view open for
 * published swatches) with the skin-like blast radius named in the
 * permission descriptions.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishSwatchTest extends KernelTestBase {

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
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * Creates and saves a swatch.
   */
  protected function createSwatch(array $values = []): DaedalusStylishSwatch {
    $swatch = DaedalusStylishSwatch::create($values + [
      'label' => 'Brand red',
      'kind' => 'color',
    ]);
    if ($swatch->getSwatchValue() === '') {
      $swatch->setSwatchValue('#e94560');
    }
    $swatch->save();
    return $swatch;
  }

  /**
   * The shipped kinds discover as plugins: color and gradient.
   */
  public function testKindPlugins(): void {
    $manager = $this->container->get('plugin.manager.daedalus_swatch_kind');
    $this->assertEqualsCanonicalizing(['color', 'gradient'], $manager->kinds());
    $this->assertSame('Color', (string) $manager->createInstance('color')->label());
    $this->assertSame('Gradient', (string) $manager->createInstance('gradient')->label());
  }

  /**
   * Identity: machine name generation, value roundtrip, published default.
   */
  public function testSwatchIdentity(): void {
    $swatch = $this->createSwatch();
    $this->assertSame('brand_red', $swatch->getMachineName());
    $this->assertSame('color', $swatch->getKind());
    $this->assertSame('#e94560', $swatch->getSwatchValue());
    $this->assertTrue($swatch->isPublished());
    $this->assertFalse($swatch->isShipped());

    // Collisions suffix like every machine-named daedalus_stylish entity.
    $second = $this->createSwatch();
    $this->assertSame('brand_red_2', $second->getMachineName());

    // Condition-keyed values roundtrip as authored (the token value shape).
    $gradient = DaedalusStylishSwatch::create([
      'label' => 'Hero fade',
      'kind' => 'gradient',
    ]);
    $value = [
      'default' => 'linear-gradient(135deg, #1a1a2e, #e94560)',
      'dark' => 'linear-gradient(135deg, #0a0a14, #7a1f33)',
    ];
    $gradient->setSwatchValue($value);
    $gradient->save();
    $reloaded = $this->container->get('entity_type.manager')->getStorage('daedalus_swatch')->loadUnchanged($gradient->id());
    $this->assertSame($value, $reloaded->getSwatchValue());
    $this->assertSame('hero_fade', $reloaded->getMachineName());
  }

  /**
   * The description is the swatch's face: stored, captured, updated, served.
   *
   * A revisionable string beside the value ('' when undescribed), written
   * by the capture op (save_material {kind: swatch, description}) and the
   * update op (update_material {kind: swatch, description}) through the
   * one provider, and carried on the roster record for the brief.
   */
  public function testDescription(): void {
    $this->assertSame('', $this->createSwatch()->getDescription());
    $described = $this->createSwatch(['label' => 'Ink', 'description' => 'The body text color.']);
    $this->assertSame('The body text color.', $this->container->get('entity_type.manager')->getStorage('daedalus_swatch')->loadUnchanged($described->id())->getDescription());

    $this->setUpCurrentUser([], ['create swatches', 'update swatches']);
    $provider = $this->container->get('daedalus_stylish.materials_provider');
    $result = $provider->capture(MaterialKind::Swatch, [
      'label' => 'Accent',
      'value' => '#b91c1c',
      'description' => 'The one accent: the call to action and nothing else.',
    ], NULL, []);
    $this->assertSame('accent', $result->id);
    $this->assertSame('The one accent: the call to action and nothing else.', $result->entity->getDescription());
    $this->assertSame('The one accent: the call to action and nothing else.', $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->get(MaterialKind::Swatch, 'accent')->description);

    $result = $provider->update(MaterialKind::Swatch, 'accent', ['description' => 'Reserved for the one call to action.'], NULL, []);
    $reloaded = $this->container->get('entity_type.manager')->getStorage('daedalus_swatch')->loadUnchanged($result->entity->id());
    $this->assertSame('Reserved for the one call to action.', $reloaded->getDescription());
    $this->assertSame('#b91c1c', $reloaded->getSwatchValue(), 'The value stays.');
    $this->assertStringContainsString('Updated the description.', (string) $reloaded->getRevisionLogMessage());

    // A non-text description refuses on the capture op.
    try {
      $provider->capture(MaterialKind::Swatch, ['label' => 'Nope', 'value' => '#000000', 'description' => ['no']], NULL, []);
      $this->fail('A non-text description was accepted.');
    }
    catch (MaterialGestureException $exception) {
      $this->assertSame('INVALID_PARAMETER', $exception->getErrors()[0]->getCode());
    }
  }

  /**
   * The kind is identity: unknown or missing kinds can never be saved.
   */
  public function testKindIsGated(): void {
    $swatch = DaedalusStylishSwatch::create(['label' => 'Mystery', 'kind' => 'sparkle']);
    $swatch->setSwatchValue('#fff');
    try {
      $swatch->save();
      $this->fail('An unknown kind saved.');
    }
    catch (EntityStorageException $e) {
      // The corrective names the legal kinds.
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertStringContainsString('color', $e->getMessage());
      $this->assertStringContainsString('gradient', $e->getMessage());
    }

    try {
      DaedalusStylishSwatch::create(['label' => 'Kindless'])->setSwatchValue('#fff')->save();
      $this->fail('A kind-less swatch saved.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
    }
  }

  /**
   * A swatch IS its value: saving without one is malformed.
   */
  public function testValueIsRequired(): void {
    try {
      DaedalusStylishSwatch::create(['label' => 'Empty', 'kind' => 'color'])->save();
      $this->fail('A value-less swatch saved.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertStringContainsString('requires a value', $e->getMessage());
    }
  }

  /**
   * Authored machine names are CSS-charset-gated; hyphens are legal.
   */
  public function testMachineNameCharset(): void {
    // The name prints as the custom property tail, so the token-style
    // hyphenated form is first-class.
    $swatch = $this->createSwatch(['machine_name' => 'brand-red-hover']);
    $this->assertSame('brand-red-hover', $swatch->getMachineName());

    try {
      $this->createSwatch(['machine_name' => 'Brand Red!']);
      $this->fail('A CSS-unsafe machine name saved.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertStringContainsString('not a swatch machine name', $e->getMessage());
    }
  }

  /**
   * The five-grade permission grid maps onto entity access; view is open.
   */
  public function testAccess(): void {
    // Burn uid 1, the superuser bypass.
    $this->createUser();
    $swatch = $this->createSwatch();
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('daedalus_swatch');

    $nobody = $this->createUser();
    $this->assertFalse($swatch->access('update', $nobody));
    $this->assertFalse($swatch->access('delete', $nobody));
    $this->assertFalse($handler->createAccess(NULL, $nobody));
    // Referencing a swatch is permissionless (an ordinary host-entity
    // edit), so anyone may see published swatches — their CSS is public.
    $this->assertTrue($swatch->access('view', $nobody));

    $creator = $this->createUser(['create swatches']);
    $this->assertTrue($handler->createAccess(NULL, $creator));
    $this->assertFalse($swatch->access('update', $creator));

    $updater = $this->createUser(['update swatches']);
    $this->assertTrue($swatch->access('update', $updater));
    $this->assertFalse($swatch->access('delete', $updater));

    $deleter = $this->createUser(['delete swatches']);
    $this->assertTrue($swatch->access('delete', $deleter));
    $in_use_deleter = $this->createUser(['delete swatches in use']);
    $this->assertTrue($swatch->access('delete', $in_use_deleter));

    $admin = $this->createUser(['administer swatches']);
    $this->assertTrue($swatch->access('update', $admin));
    $this->assertTrue($swatch->access('delete', $admin));

    // Unpublished swatches hide from everyone but administrators.
    $swatch->setUnpublished()->save();
    $handler->resetCache();
    $this->assertFalse($swatch->access('view', $nobody));
    $this->assertTrue($swatch->access('view', $admin));
  }

  /**
   * The shipped-source lane: one hash, two comparisons.
   */
  public function testShippedSource(): void {
    $swatch = $this->createSwatch();
    $swatch->setShippedSource('daedalus_stylish_swatches_test', ['kind' => 'color', 'value' => '#e94560'])->save();
    $this->assertTrue($swatch->isShipped());
    $this->assertFalse($swatch->isModified());
    $this->assertFalse($swatch->hasNewDefaults(['kind' => 'color', 'value' => '#e94560']));
    $this->assertTrue($swatch->hasNewDefaults(['kind' => 'color', 'value' => '#ff0000']));

    $swatch->setSwatchValue('#111111');
    $this->assertTrue($swatch->isModified());
  }

}
