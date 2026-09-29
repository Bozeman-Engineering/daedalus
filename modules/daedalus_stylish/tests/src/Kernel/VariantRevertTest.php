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
 * Tests the shipped-state UI vocabulary and the revert gesture.
 *
 * The variant library payload carries provider + shipped_state so the
 * inspector can show where a shipped variant stands against its provider's
 * CURRENT disk file: 'unmodified', 'modified' (the user's styles diverged)
 * or 'defaults_changed' (the disk moved on; for a modified variant the
 * importer's sync deliberately KEPT the user's styles, and the revert
 * button IS the ask). Revert is an ordinary revisioned save of the disk
 * styles through the variant manager, so on workspace sites it stages in
 * the active workspace for free, unlike the importer's live sync.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantRevertTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The styles daedalus_stylish_variants_test ships for testimonial_bold.
   */
  protected const TESTIMONIAL_BOLD_STYLES = [
    '' => [
      'default' => ['font-size' => '1.5rem', 'padding' => '1rem'],
    ],
    '::.testimonial__quote' => [
      'default' => ['font-size' => '1.25rem'],
    ],
  ];

  /**
   * A stand-in for a previously shipped state of testimonial_bold.
   */
  protected const OLD_STYLES = [
    '' => ['default' => ['font-size' => '1rem']],
  ];

  /**
   * A user's local edit of testimonial_bold.
   */
  protected const USER_STYLES = [
    '' => ['default' => ['font-size' => '1.75rem']],
  ];

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
    // The importer switches to an administrative account; the revert gesture
    // itself runs as the current user, like push.
    $this->setUpCurrentUser([], [], TRUE);
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
   * The variant manager, resolved late (installs rebuild the container).
   */
  protected function manager(): VariantManager {
    return \Drupal::service('daedalus_stylish.variant_manager');
  }

  /**
   * A variant's gesture payload by machine name (the usage route's facts).
   *
   * The shipped state and the provider are management facts the client
   * reads per open through the usage route, which projects them from
   * VariantManager::variantPayload(); no list carries them.
   */
  protected function libraryEntry(string $machine_name): ?array {
    $variant = $this->loadVariant($machine_name);
    return $variant === NULL ? NULL : $this->manager()->variantPayload($variant);
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
   * The management payload walks a variant through all three shipped states.
   */
  public function testShippedStateInLibrary(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_test']);

    // Freshly imported: the entity, the stored hash and the disk agree.
    $entry = $this->libraryEntry('testimonial_bold');
    $this->assertSame('daedalus_stylish_variants_test', $entry['provider']);
    $this->assertSame('unmodified', $entry['shipped_state']);

    // The user edits the variant: entity styles diverge from the stored
    // hash while the disk still matches it.
    $variant = $this->loadVariant('testimonial_bold');
    $variant->setStyles(self::USER_STYLES);
    $variant->save();
    $this->assertSame('modified', $this->libraryEntry('testimonial_bold')['shipped_state']);

    // The disk moves on (simulated by rewinding the stored hash to an older
    // shipped state): defaults_changed wins over modified, because the new
    // defaults are the actionable signal the revert button answers.
    $variant = $this->loadVariant('testimonial_bold');
    $variant->setShippedSource('daedalus_stylish_variants_test', self::OLD_STYLES);
    $variant->save();
    $this->assertSame('defaults_changed', $this->libraryEntry('testimonial_bold')['shipped_state']);

    // A site-created variant has no disk source: no provider, no state.
    DaedalusStylishVariant::create([
      'label' => 'Local only',
      'machine_name' => 'local_only',
      'scope' => 'kind:container',
      'status' => 1,
    ])->save();
    $entry = $this->libraryEntry('local_only');
    $this->assertNull($entry['provider']);
    $this->assertNull($entry['shipped_state']);
  }

  /**
   * Revert restores the CURRENT disk styles as a new revision.
   */
  public function testRevertRestoresShippedDefaults(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_test']);

    // The KEPT scenario: the user modified the variant AND the disk moved
    // on since the state the stored hash records, so the importer's sync
    // keeps the user's styles and the UI offers revert instead.
    $variant = $this->loadVariant('testimonial_bold');
    $variant->setStyles(self::USER_STYLES);
    $variant->setShippedSource('daedalus_stylish_variants_test', self::OLD_STYLES);
    $variant->save();
    $old_revision_id = $variant->getRevisionId();

    $usages = $this->manager()->revert($variant);
    $this->assertSame(0, $usages);

    $reverted = $this->loadVariant('testimonial_bold');
    $this->assertSame(self::TESTIMONIAL_BOLD_STYLES, $reverted->getStyles());
    $this->assertNotSame($old_revision_id, $reverted->getRevisionId());
    $this->assertStringContainsString('daedalus_stylish_variants_test', (string) $reverted->getRevisionLogMessage());
    // The hash was re-stamped from the same disk read: the variant now
    // reads unmodified against what disk ships.
    $this->assertFalse($reverted->isModified());
    $this->assertFalse($reverted->hasNewDefaults(self::TESTIMONIAL_BOLD_STYLES));
    $this->assertSame('unmodified', $this->libraryEntry('testimonial_bold')['shipped_state']);
  }

  /**
   * A site-created variant has no shipped defaults to revert to.
   */
  public function testRevertRejectsSiteCreatedVariant(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Local only',
      'machine_name' => 'local_only',
      'scope' => 'kind:container',
      'status' => 1,
    ]);
    $variant->save();

    $codes = $this->catchCodes(fn () => $this->manager()->revert($variant));
    $this->assertContains('STYLE_VARIANT_NOT_SHIPPED', $codes);
  }

  /**
   * A provider that stopped shipping the entry leaves nothing to revert to.
   *
   * Sync never deletes, so such a variant lives on as ordinary site content:
   * no shipped state in the library, and revert refuses loudly.
   */
  public function testRevertWithoutDiskEntry(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Orphaned',
      'machine_name' => 'orphaned',
      'scope' => 'kind:container',
      'status' => 1,
    ]);
    assert($variant instanceof DaedalusStylishVariantInterface);
    $variant->setStyles(self::OLD_STYLES);
    // daedalus_stylish_guide_test is enabled but ships no variants file, the state of
    // a provider whose next release dropped the entry.
    $variant->setShippedSource('daedalus_stylish_guide_test', self::OLD_STYLES);
    $variant->save();

    $entry = $this->libraryEntry('orphaned');
    $this->assertSame('daedalus_stylish_guide_test', $entry['provider']);
    $this->assertNull($entry['shipped_state']);

    $codes = $this->catchCodes(fn () => $this->manager()->revert($variant));
    $this->assertContains('SHIPPED_DEFAULTS_MISSING', $codes);
  }

}
