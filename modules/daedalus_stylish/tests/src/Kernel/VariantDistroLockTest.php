<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\VariantValidationException;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\VariantManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the distro lock and its fork gesture.
 *
 * A variant with a LIVING disk source (its provider ships the entry right
 * now) is read-only: pushes refuse (VariantManager::checkShippedLock) and
 * the blueprint lane's styles writes refuse identically
 * (VariantStylesFieldHandler), while the importer's own sync passes as the
 * disk's writes (shipped_sync). A variant whose provider stopped shipping
 * it lives on as ordinary site content and stays editable — the exact
 * complement of revert()'s SHIPPED_DEFAULTS_MISSING. "Copy as my variant"
 * (copyAsMyVariant) is the fork: a verbatim, site-owned, editable copy.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantDistroLockTest extends KernelTestBase {

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
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
    // The unbound site: the class writes literal paddings, gaps and sizes
    // on a site that mints nothing, and the suite's bindings would refuse
    // every one for a reason that is the scale's, not the claim's.
    'daedalus_stylish_unbound_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser([], [], TRUE);
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_test']);
  }

  /**
   * The variant manager, resolved late (installs rebuild the container).
   */
  protected function manager(): VariantManager {
    return \Drupal::service('daedalus_stylish.variant_manager');
  }

  /**
   * Loads a variant by machine name, bypassing access.
   */
  protected function loadVariant(string $machine_name): ?DaedalusStylishVariantInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_variant');
    $storage->resetCache();
    $variants = $storage->loadByProperties(['machine_name' => $machine_name]);
    $variant = reset($variants) ?: NULL;
    assert($variant === NULL || $variant instanceof DaedalusStylishVariantInterface);
    return $variant;
  }

  /**
   * Collects error codes from a failing gesture.
   */
  protected function catchCodes(callable $gesture): array {
    try {
      $gesture();
    }
    catch (VariantValidationException $exception) {
      return array_map(static fn ($error) => $error->getCode(), $exception->getErrors());
    }
    return [];
  }

  /**
   * A shipped variant refuses every push; the disk stays the one author.
   */
  public function testPushRefusesShippedVariant(): void {
    $variant = $this->loadVariant('testimonial_bold');
    $before = $variant->getStyles();

    // The master push (no source key) — the strongest form: even editing
    // the variant "in isolation" refuses.
    $codes = $this->catchCodes(fn () => $this->manager()->push($variant, '', [
      '' => ['default' => ['font-size' => '1.75rem']],
    ]));
    $this->assertContains('STYLE_VARIANT_SHIPPED_LOCKED', $codes);
    $this->assertSame($before, $this->loadVariant('testimonial_bold')->getStyles());
  }

  /**
   * A provider that stopped shipping the entry leaves the variant editable.
   *
   * Sync never deletes, so such a variant lives on as ordinary site
   * content (revert's SHIPPED_DEFAULTS_MISSING hint says to push into it
   * instead) — the lock reads the LIVING disk source, not the provider
   * field alone.
   */
  public function testPushAllowedWhenProviderStoppedShipping(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Orphaned',
      'machine_name' => 'orphaned',
      'scope' => 'kind:container',
      'status' => 1,
    ]);
    assert($variant instanceof DaedalusStylishVariantInterface);
    $variant->setStyles(['' => ['default' => ['padding' => '1rem']]]);
    // daedalus_stylish_guide_test ships no variants file: a provider whose next
    // release dropped the entry.
    $variant->setShippedSource('daedalus_stylish_guide_test', ['' => ['default' => ['padding' => '1rem']]]);
    $variant->save();

    $this->manager()->push($variant, '', ['' => ['default' => ['padding' => '3rem']]]);
    $this->assertSame(['' => ['default' => ['padding' => '3rem']]], $this->loadVariant('orphaned')->getStyles());
  }

  /**
   * Copy as my variant: a verbatim, site-owned, editable fork.
   */
  public function testCopyAsMyVariant(): void {
    $source = $this->loadVariant('testimonial_bold');

    $copy = $this->manager()->copyAsMyVariant($source);
    $this->assertSame('Testimonial bold (copy)', (string) $copy->label());
    $this->assertNotSame($source->getMachineName(), $copy->getMachineName());
    $this->assertSame($source->getScope(), $copy->getScope());
    $this->assertSame($source->getAxis(), $copy->getAxis());
    $this->assertSame($source->getStyles(), $copy->getStyles());
    // No shipped source: the payload reads as an ordinary site variant.
    $this->assertNull($copy->getProvider());
    $payload = $this->manager()->variantPayload($copy);
    $this->assertNull($payload['provider']);
    $this->assertNull($payload['shipped_state']);

    // The fork is exactly what the lock points at: pushes land.
    $this->manager()->push($copy, '', ['' => ['default' => ['font-size' => '1.75rem']]]);
    $this->assertSame(['' => ['default' => ['font-size' => '1.75rem']]], $this->loadVariant($copy->getMachineName())->getStyles());

    // An explicit label wins over the derived one.
    $named = $this->manager()->copyAsMyVariant($source, 'My testimonial');
    $this->assertSame('My testimonial', (string) $named->label());
  }

  /**
   * The blueprint lane refuses shipped styles writes with the same code.
   *
   * The generic update tools (the AI's lane) ride the deserializer, whose
   * variant-styles field handler carries the lock; the importer's own sync
   * passes shipped_sync there (covered by VariantImporterTest's auto-sync,
   * which would fail if the exception lane broke).
   */
  public function testBlueprintLaneRefusesShippedVariant(): void {
    $deserializer = \Drupal::service('daedalus_blueprint.deserializer');
    $result = $deserializer->deserialize([
      'entity_type' => 'daedalus_variant',
      'bundle' => 'daedalus_variant',
      '_root_entity' => ['field' => 'machine_name', 'value' => 'testimonial_bold'],
      'fields' => [
        'styles' => ['' => ['default' => ['font-size' => '1.75rem']]],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $codes = array_map(static fn ($error) => $error->getCode(), $result->getErrors());
    $this->assertContains('STYLE_VARIANT_SHIPPED_LOCKED', $codes);

    // The same write against a site-owned copy validates fine.
    $copy = $this->manager()->copyAsMyVariant($this->loadVariant('testimonial_bold'));
    $result = $deserializer->deserialize([
      'entity_type' => 'daedalus_variant',
      'bundle' => 'daedalus_variant',
      '_root_entity' => ['field' => 'machine_name', 'value' => $copy->getMachineName()],
      'fields' => [
        'styles' => ['' => ['default' => ['font-size' => '1.75rem']]],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->getErrors(), TRUE));
  }

  /**
   * The developer gate lifts the lock: pushes and blueprint writes land.
   *
   * On a development site (`$settings['daedalus_blueprint_developer'] =
   * TRUE`, read live by DistroLock) the package author edits shipped
   * variants in place; the entity then reads as modified against the
   * disk, exactly the state `drush daedalus_stylish:ship` carries back. Revert
   * stays available (it still converges toward the disk).
   */
  public function testPushLandsUnderTheDeveloperGate(): void {
    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $variant = $this->loadVariant('testimonial_bold');
    $this->assertSame('unmodified', $this->manager()->variantPayload($variant)['shipped_state']);

    $this->manager()->push($variant, '', ['' => ['default' => ['font-size' => '1.75rem']]]);
    $variant = $this->loadVariant('testimonial_bold');
    $this->assertSame(['' => ['default' => ['font-size' => '1.75rem']]], $variant->getStyles());
    $this->assertSame('modified', $this->manager()->variantPayload($variant)['shipped_state']);
    // Still shipped: the provider stamp survives the edit, so the disk
    // lane (revert, ship) keeps its subject.
    $this->assertSame('daedalus_stylish_variants_test', $variant->getProvider());

    // The blueprint lane (the AI's generic update) opens with the same
    // gate.
    $result = \Drupal::service('daedalus_blueprint.deserializer')->deserialize([
      'entity_type' => 'daedalus_variant',
      'bundle' => 'daedalus_variant',
      '_root_entity' => ['field' => 'machine_name', 'value' => 'testimonial_bold'],
      'fields' => [
        'styles' => ['' => ['default' => ['font-size' => '2rem']]],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->getErrors(), TRUE));

    // Closing the gate again locks the (still shipped) variant.
    $this->setSetting('daedalus_blueprint_developer', FALSE);
    $codes = $this->catchCodes(fn () => $this->manager()->push($this->loadVariant('testimonial_bold'), '', ['' => ['default' => ['font-size' => '3rem']]]));
    $this->assertContains('STYLE_VARIANT_SHIPPED_LOCKED', $codes);
  }

}
