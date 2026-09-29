<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\ShippedVariantException;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\VariantImporter;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the module-shipped variants importer (the disk lane).
 *
 * The daedalus_stylish_variants_test module ships a MODULE.daedalus_stylish.variants.yml
 * with two variants, validated against daedalus_stylish_guide_test's guide. The
 * tests drive the importer's sync() directly; on a site it runs through
 * ShippedSync (cache rebuild, install, `drush daedalus_stylish:sync`). Every
 * definition rides the blueprint deserializer (the spine), and the
 * importer itself saves, live and published. Sync semantics are "one hash,
 * two comparisons": unmodified variants auto-sync new disk defaults as a
 * new revision, user-modified variants keep their styles AND their stored
 * hash so the UI can keep offering revert.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantImporterTest extends KernelTestBase {

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
    // The daedalus-stylish-install sweep imports daedalus_stylish's own entrance variants,
    // whose motion-* references mirror into the animation usage table.
    $this->installSchema('daedalus_stylish', ['daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    // The importer switches to an administrative account (shipped variants
    // are code, not user input); give the switcher one to find.
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
   * The importer service, resolved late (installs rebuild the container).
   */
  protected function importer(): VariantImporter {
    return \Drupal::service('daedalus_stylish.variant_importer');
  }

  /**
   * Installing a module imports its shipped variants, live and published.
   */
  public function testInstallImportsShippedVariants(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_test']);

    $bold = $this->loadVariant('testimonial_bold');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $bold);
    $this->assertSame('Testimonial bold', $bold->label());
    $this->assertSame('block_type:testimonial', $bold->getScope());
    // The optional axis key rides the import; omitted definitions land on
    // the built-in "style" axis (wide_section below). The optional
    // description rides too, the AI roster's payload, and so does the
    // optional weight, the roster sort key.
    $this->assertSame('emphasis', $bold->getAxis());
    $this->assertSame('A bold pull-quote treatment for testimonials that anchor a section.', $bold->getDescription());
    $this->assertSame(7, $bold->getWeight());
    $this->assertSame(self::TESTIMONIAL_BOLD_STYLES, $bold->getStyles());
    $this->assertTrue($bold->isPublished());
    $this->assertTrue($bold->isShipped());
    $this->assertSame('daedalus_stylish_variants_test', $bold->getProvider());
    $this->assertFalse($bold->isModified());
    $this->assertFalse($bold->hasNewDefaults(self::TESTIMONIAL_BOLD_STYLES));

    $wide = $this->loadVariant('wide_section');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $wide);
    $this->assertSame('kind:container', $wide->getScope());
    $this->assertSame('style', $wide->getAxis());
    $this->assertSame(0, $wide->getWeight());
    $this->assertTrue($wide->isPublished());
    $this->assertSame('daedalus_stylish_variants_test', $wide->getProvider());
    // The AI opt-out rides the import: ai: false withholds wide_section,
    // an absent key offers (the default every material has).
    $this->assertFalse($wide->isOffered());
    $this->assertTrue($bold->isOffered());
  }

  /**
   * Syncing with nothing changed on disk touches nothing.
   */
  public function testSyncIsIdempotent(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_test']);
    $revision_id = $this->loadVariant('testimonial_bold')->getRevisionId();

    $results = $this->importer()->sync()->outcomes();
    $this->assertSame(VariantImporter::UNCHANGED, $results['daedalus_stylish_variants_test']['testimonial_bold']);
    $this->assertSame(VariantImporter::UNCHANGED, $results['daedalus_stylish_variants_test']['wide_section']);
    $this->assertSame($revision_id, $this->loadVariant('testimonial_bold')->getRevisionId());
  }

  /**
   * The description syncs independently of the styles outcome.
   *
   * Ownership's sibling rule: a description: key added to the file after
   * variants were already imported must land even when the styles never
   * changed (the roster payload rollout case). Simulated by clearing the
   * stored description and re-syncing an otherwise UNCHANGED variant.
   */
  public function testDescriptionSyncsIndependentlyOfStyles(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_test']);

    $variant = $this->loadVariant('testimonial_bold');
    $variant->setDescription('');
    $variant->save();

    $results = $this->importer()->sync(['daedalus_stylish_variants_test'])->outcomes();
    $this->assertSame(VariantImporter::UNCHANGED, $results['daedalus_stylish_variants_test']['testimonial_bold']);
    $this->assertSame('A bold pull-quote treatment for testimonials that anchor a section.', $this->loadVariant('testimonial_bold')->getDescription());

    // An absent key leaves the stored description alone: wide_section ships
    // none, so a site-authored description survives every sync.
    $wide = $this->loadVariant('wide_section');
    $wide->setDescription('Site-authored: a spacious band.');
    $wide->save();
    $this->importer()->sync(['daedalus_stylish_variants_test']);
    $this->assertSame('Site-authored: a spacious band.', $this->loadVariant('wide_section')->getDescription());
  }

  /**
   * The AI opt-out syncs from disk independently of the styles outcome.
   *
   * Disk is the one author of a shipped variant's flag: ai: false lands
   * on an otherwise UNCHANGED variant, and an absent key is the default
   * (offered), not "leave it alone", so dropping the key re-offers. The
   * roster then shows two views of one site: legality reads the full set,
   * the AI the offered one.
   */
  public function testOptOutSyncsIndependentlyOfStyles(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_test']);

    $this->loadVariant('wide_section')->setOffered(TRUE)->save();
    $this->loadVariant('testimonial_bold')->setOffered(FALSE)->save();
    $this->assertFalse($this->loadVariant('wide_section')->isModified(), 'The flag is not part of the styles hash.');

    $results = $this->importer()->sync(['daedalus_stylish_variants_test'])->outcomes();
    $this->assertSame(VariantImporter::UNCHANGED, $results['daedalus_stylish_variants_test']['wide_section']);
    $this->assertSame(VariantImporter::UNCHANGED, $results['daedalus_stylish_variants_test']['testimonial_bold']);
    $this->assertFalse($this->loadVariant('wide_section')->isOffered(), 'ai: false on disk withholds the variant again.');
    $this->assertTrue($this->loadVariant('testimonial_bold')->isOffered(), 'An absent key is the default: offered.');

    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertArrayHasKey('wide_section', $roster->variants());
    $this->assertFalse($roster->variants()['wide_section']->offered);
    $this->assertArrayNotHasKey('wide_section', $roster->offered()->variants());
    $this->assertTrue($roster->offered()->variants()['testimonial_bold']->offered);

    // The key has one spelling: anything but false is refused, so the
    // default never grows a second spelling on disk.
    $this->expectException(ShippedVariantException::class);
    $this->importer()->checkDefinitionShape('daedalus_stylish_variants_test', 'loud', [
      'label' => 'Loud',
      'scope' => 'kind:container',
      'styles' => [],
      'ai' => TRUE,
    ]);
  }

  /**
   * The weight syncs independently of the styles outcome.
   *
   * The description's pattern: a weight: change on disk must land even
   * when the styles never changed (a family reorder must reach installed
   * sites without a styles bump). Simulated by resetting the stored weight
   * and re-syncing an otherwise UNCHANGED variant.
   */
  public function testWeightSyncsIndependentlyOfStyles(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_test']);

    $variant = $this->loadVariant('testimonial_bold');
    $variant->setWeight(0);
    $variant->save();

    $results = $this->importer()->sync(['daedalus_stylish_variants_test'])->outcomes();
    $this->assertSame(VariantImporter::UNCHANGED, $results['daedalus_stylish_variants_test']['testimonial_bold']);
    $this->assertSame(7, $this->loadVariant('testimonial_bold')->getWeight());

    // An absent key leaves the stored weight alone: wide_section ships
    // none, so a site-authored weight survives every sync.
    $wide = $this->loadVariant('wide_section');
    $wide->setWeight(42);
    $wide->save();
    $this->importer()->sync(['daedalus_stylish_variants_test']);
    $this->assertSame(42, $this->loadVariant('wide_section')->getWeight());
  }

  /**
   * New disk defaults auto-sync an UNMODIFIED variant as a new revision.
   */
  public function testNewDefaultsAutoSyncUnmodifiedVariant(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_test']);

    // Rewind the variant to a previously shipped state: entity styles match
    // the stored hash (unmodified), but the disk now ships something newer.
    $variant = $this->loadVariant('testimonial_bold');
    $variant->setStyles(self::OLD_STYLES);
    $variant->setShippedSource('daedalus_stylish_variants_test', self::OLD_STYLES);
    $variant->save();
    $old_revision_id = $variant->getRevisionId();

    $results = $this->importer()->sync(['daedalus_stylish_variants_test'])->outcomes();
    $this->assertSame(VariantImporter::UPDATED, $results['daedalus_stylish_variants_test']['testimonial_bold']);

    $synced = $this->loadVariant('testimonial_bold');
    $this->assertSame(self::TESTIMONIAL_BOLD_STYLES, $synced->getStyles());
    $this->assertNotSame($old_revision_id, $synced->getRevisionId());
    $this->assertTrue($synced->isPublished());
    $this->assertFalse($synced->isModified());
    $this->assertFalse($synced->hasNewDefaults(self::TESTIMONIAL_BOLD_STYLES));
    $this->assertStringContainsString('daedalus_stylish_variants_test', (string) $synced->getRevisionLogMessage());
  }

  /**
   * A user-modified variant keeps its styles AND its stored hash on sync.
   */
  public function testModifiedVariantKeptOnSync(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_test']);

    // The user edited the variant since the (old) shipped state, and the
    // disk has ALSO moved on: sync must not clobber the user's styles, and
    // must not touch the stored hash either, or the UI would lose the
    // "defaults changed" signal that powers the revert offer.
    $user_styles = ['' => ['default' => ['font-size' => '1.75rem']]];
    $variant = $this->loadVariant('testimonial_bold');
    $variant->setStyles($user_styles);
    $variant->setShippedSource('daedalus_stylish_variants_test', self::OLD_STYLES);
    $variant->save();
    $revision_id = $variant->getRevisionId();

    $results = $this->importer()->sync(['daedalus_stylish_variants_test'])->outcomes();
    $this->assertSame(VariantImporter::KEPT, $results['daedalus_stylish_variants_test']['testimonial_bold']);

    $kept = $this->loadVariant('testimonial_bold');
    $this->assertSame($user_styles, $kept->getStyles());
    $this->assertSame($revision_id, $kept->getRevisionId());
    $this->assertSame(DaedalusStylishVariant::hashStyles(self::OLD_STYLES), $kept->getSourceHash());
    $this->assertTrue($kept->isModified());
    $this->assertTrue($kept->hasNewDefaults(self::TESTIMONIAL_BOLD_STYLES));
  }

  /**
   * A machine-name collision with a variant the module does not ship is FAILED.
   */
  public function testConflictWithSiteCreatedVariant(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Local testimonial bold',
      'machine_name' => 'testimonial_bold',
      'scope' => 'block_type:testimonial',
    ]);
    $variant->save();

    $this->enableModules(['daedalus_stylish_variants_test']);
    $report = $this->importer()->sync(['daedalus_stylish_variants_test']);
    $this->assertSame(VariantImporter::FAILED, $report->outcome('daedalus_stylish_variants_test', 'testimonial_bold'));
    $this->assertSame('SHIPPED_VARIANT_CONFLICT', $report->errors('daedalus_stylish_variants_test', 'testimonial_bold')[0]->getCode());
    // The site's variant is untouched: still not shipped.
    $this->assertNull($this->loadVariant('testimonial_bold')->getProvider());
  }

  /**
   * A shipped variant failing spine validation is FAILED with its errors.
   */
  public function testInvalidShippedVariantFailsLoudly(): void {
    $this->enableModules(['daedalus_stylish_variants_test_invalid']);
    $report = $this->importer()->sync(['daedalus_stylish_variants_test_invalid']);
    $this->assertSame(VariantImporter::FAILED, $report->outcome('daedalus_stylish_variants_test_invalid', 'overflowing_section'));
    $codes = array_map(static fn ($error) => $error->getCode(), $report->errors('daedalus_stylish_variants_test_invalid', 'overflowing_section'));
    $this->assertContains('STYLE_VALUE_ABOVE_MAX', $codes);
    $this->assertNull($this->loadVariant('overflowing_section'));
    // The definition after the refused one still lands: a tolerant sync
    // records the failure instead of stopping at it.
    $this->assertSame(VariantImporter::CREATED, $report->outcome('daedalus_stylish_variants_test_invalid', 'fitting_section'));
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $this->loadVariant('fitting_section'));
  }

}
