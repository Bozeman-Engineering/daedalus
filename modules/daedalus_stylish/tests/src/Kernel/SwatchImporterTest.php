<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\DaedalusStylishSwatchInterface;
use Drupal\daedalus_stylish\SwatchImporter;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the module-shipped swatches importer (the palette disk lane).
 *
 * The daedalus_stylish_swatches_test module ships a MODULE.daedalus_stylish.swatches.yml
 * with a scalar color and a condition-keyed gradient. The tests drive the
 * importer's sync() directly; on a site it runs through ShippedSync (cache
 * rebuild, install, `drush daedalus_stylish:sync`). Every definition rides the
 * blueprint deserializer through the swatch value field handler (the
 * same value-seam validation an AI- or UI-minted swatch gets), and the
 * importer itself saves, live and published. Sync is "one hash, two
 * comparisons" over the WHOLE shipped payload, the keyed {kind, value}
 * shape, so either half diverging reads as modified.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SwatchImporterTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The gradient value daedalus_stylish_swatches_test ships for hero_fade.
   */
  protected const HERO_FADE_VALUE = [
    'default' => 'linear-gradient(160deg, #1a1a2e, #e94560)',
    'dark' => 'linear-gradient(160deg, #0a0a14, #712235)',
  ];

  /**
   * A stand-in for a previously shipped state of brand_red.
   */
  protected const OLD_PAYLOAD = [
    'kind' => 'color',
    'value' => '#111111',
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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    // The importer switches to an administrative account (shipped swatches
    // are code, not user input); give the switcher one to find.
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * Loads a swatch by machine name, bypassing access.
   */
  protected function loadSwatch(string $machine_name): ?DaedalusStylishSwatchInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_swatch');
    $storage->resetCache();
    $swatches = $storage->loadByProperties(['machine_name' => $machine_name]);
    $swatch = reset($swatches) ?: NULL;
    assert($swatch === NULL || $swatch instanceof DaedalusStylishSwatchInterface);
    return $swatch;
  }

  /**
   * The importer service, resolved late (installs rebuild the container).
   */
  protected function importer(): SwatchImporter {
    return \Drupal::service('daedalus_stylish.swatch_importer');
  }

  /**
   * Installing a module imports its shipped swatches, live and published.
   */
  public function testInstallImportsShippedSwatches(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_swatches_test']);

    $red = $this->loadSwatch('brand_red');
    $this->assertInstanceOf(DaedalusStylishSwatchInterface::class, $red);
    $this->assertSame('Brand red', $red->label());
    $this->assertSame('color', $red->getKind());
    $this->assertSame('#e94560', $red->getSwatchValue());
    $this->assertTrue($red->isPublished());
    $this->assertTrue($red->isShipped());
    $this->assertSame('daedalus_stylish_swatches_test', $red->getProvider());
    $this->assertFalse($red->isModified());
    $this->assertFalse($red->hasNewDefaults(['kind' => 'color', 'value' => '#e94560']));
    // The description is the shipped face: it lands and is not payload.
    $this->assertSame('The one accent: the call to action and nothing else.', $red->getDescription());

    // A condition-keyed gradient imports with its conditions intact; an
    // undescribed entry leaves the description empty.
    $fade = $this->loadSwatch('hero_fade');
    $this->assertInstanceOf(DaedalusStylishSwatchInterface::class, $fade);
    $this->assertSame('gradient', $fade->getKind());
    $this->assertSame(self::HERO_FADE_VALUE, $fade->getSwatchValue());
    $this->assertTrue($fade->isPublished());
    $this->assertFalse($fade->isModified());
    $this->assertSame('', $fade->getDescription());
    // The AI opt-out rides the import: ai: false withholds hero_fade, an
    // absent key offers (the default every material has).
    $this->assertFalse($fade->isOffered());
    $this->assertTrue($red->isOffered());

    // The published set (what the materials roster serves, and so what
    // var(--swatch-*) validates against) sees both immediately.
    $this->assertSame(['brand_red', 'hero_fade'], array_map(static fn ($swatch) => $swatch->getMachineName(), \Drupal::service('daedalus_stylish.swatch_repository')->publishedSwatches()));
  }

  /**
   * Syncing with nothing changed on disk touches nothing.
   */
  public function testSyncIsIdempotent(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_swatches_test']);
    $revision_id = $this->loadSwatch('brand_red')->getRevisionId();

    $results = $this->importer()->sync()->outcomes();
    $this->assertSame(SwatchImporter::UNCHANGED, $results['daedalus_stylish_swatches_test']['brand_red']);
    $this->assertSame(SwatchImporter::UNCHANGED, $results['daedalus_stylish_swatches_test']['hero_fade']);
    $this->assertSame($revision_id, $this->loadSwatch('brand_red')->getRevisionId());
  }

  /**
   * The description syncs from disk independently of the payload hash.
   *
   * The variants lane's rule: an authored description: key lands on sync
   * whether or not the value changed (the sync still reads UNCHANGED for
   * the payload), and an absent key leaves a site-authored description
   * alone.
   */
  public function testDescriptionSyncsIndependentlyOfThePayload(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_swatches_test']);
    $red = $this->loadSwatch('brand_red');
    $red->setDescription('Stale site text.')->save();
    $this->assertFalse($this->loadSwatch('brand_red')->isModified(), 'The description is not part of the payload hash.');
    $fade = $this->loadSwatch('hero_fade');
    $fade->setDescription('Authored on the site.')->save();

    $results = $this->importer()->sync(['daedalus_stylish_swatches_test'])->outcomes();
    $this->assertSame(SwatchImporter::UNCHANGED, $results['daedalus_stylish_swatches_test']['brand_red']);
    $this->assertSame(SwatchImporter::UNCHANGED, $results['daedalus_stylish_swatches_test']['hero_fade']);
    $this->assertSame('The one accent: the call to action and nothing else.', $this->loadSwatch('brand_red')->getDescription(), 'Disk is the author of a shipped description.');
    $this->assertSame('Authored on the site.', $this->loadSwatch('hero_fade')->getDescription(), 'A silent file leaves the site\'s description alone.');

    // The roster record carries it.
    $record = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build()->get(MaterialKind::Swatch, 'brand_red');
    $this->assertSame('The one accent: the call to action and nothing else.', $record->description);
  }

  /**
   * The AI opt-out syncs from disk independently of the payload hash.
   *
   * Disk is the one author of a shipped swatch's flag: ai: false lands on
   * an otherwise UNCHANGED swatch, and an absent key is the default
   * (offered), so dropping the key re-offers. The full roster keeps the
   * withheld swatch (its custom property still validates and delivers);
   * the offered view, what the brief and the schema read, omits it.
   */
  public function testOptOutSyncsIndependentlyOfThePayload(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_swatches_test']);
    $this->loadSwatch('hero_fade')->setOffered(TRUE)->save();
    $this->loadSwatch('brand_red')->setOffered(FALSE)->save();
    $this->assertFalse($this->loadSwatch('hero_fade')->isModified(), 'The flag is not part of the payload hash.');

    $results = $this->importer()->sync(['daedalus_stylish_swatches_test'])->outcomes();
    $this->assertSame(SwatchImporter::UNCHANGED, $results['daedalus_stylish_swatches_test']['hero_fade']);
    $this->assertSame(SwatchImporter::UNCHANGED, $results['daedalus_stylish_swatches_test']['brand_red']);
    $this->assertFalse($this->loadSwatch('hero_fade')->isOffered(), 'ai: false on disk withholds the swatch again.');
    $this->assertTrue($this->loadSwatch('brand_red')->isOffered(), 'An absent key is the default: offered.');

    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertFalse($roster->get(MaterialKind::Swatch, 'hero_fade')->offered);
    $this->assertNull($roster->offered()->get(MaterialKind::Swatch, 'hero_fade'));
    $this->assertTrue($roster->offered()->get(MaterialKind::Swatch, 'brand_red')->offered);
  }

  /**
   * New disk defaults auto-sync an UNMODIFIED swatch as a new revision.
   */
  public function testNewDefaultsAutoSyncUnmodifiedSwatch(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_swatches_test']);

    // Rewind the swatch to a previously shipped state: entity data matches
    // the stored hash (unmodified), but the disk now ships something newer.
    $swatch = $this->loadSwatch('brand_red');
    $swatch->setSwatchValue(self::OLD_PAYLOAD['value']);
    $swatch->setShippedSource('daedalus_stylish_swatches_test', self::OLD_PAYLOAD);
    $swatch->save();
    $old_revision_id = $swatch->getRevisionId();

    $results = $this->importer()->sync(['daedalus_stylish_swatches_test'])->outcomes();
    $this->assertSame(SwatchImporter::UPDATED, $results['daedalus_stylish_swatches_test']['brand_red']);

    $synced = $this->loadSwatch('brand_red');
    $this->assertSame('#e94560', $synced->getSwatchValue());
    $this->assertNotSame($old_revision_id, $synced->getRevisionId());
    $this->assertTrue($synced->isPublished());
    $this->assertFalse($synced->isModified());
    $this->assertStringContainsString('daedalus_stylish_swatches_test', (string) $synced->getRevisionLogMessage());
  }

  /**
   * A user-modified swatch keeps its data AND its stored hash on sync.
   */
  public function testModifiedSwatchKeptOnSync(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_swatches_test']);

    // The user edited the swatch since the (old) shipped state, and the
    // disk has ALSO moved on: sync must not clobber the user's value, and
    // must not touch the stored hash either, so the "defaults changed"
    // signal stays detectable (the revert button IS the ask).
    $swatch = $this->loadSwatch('brand_red');
    $swatch->setSwatchValue('#abcdef');
    $swatch->setShippedSource('daedalus_stylish_swatches_test', self::OLD_PAYLOAD);
    $swatch->save();
    $revision_id = $swatch->getRevisionId();

    $results = $this->importer()->sync(['daedalus_stylish_swatches_test'])->outcomes();
    $this->assertSame(SwatchImporter::KEPT, $results['daedalus_stylish_swatches_test']['brand_red']);

    $kept = $this->loadSwatch('brand_red');
    $this->assertSame('#abcdef', $kept->getSwatchValue());
    $this->assertSame($revision_id, $kept->getRevisionId());
    $this->assertSame(DaedalusStylishSwatch::hashData(self::OLD_PAYLOAD), $kept->getSourceHash());
    $this->assertTrue($kept->isModified());
    $this->assertTrue($kept->hasNewDefaults(['kind' => 'color', 'value' => '#e94560']));
  }

  /**
   * A machine-name collision with a swatch the module does not ship is FAILED.
   */
  public function testConflictWithSiteMintedSwatch(): void {
    $swatch = DaedalusStylishSwatch::create([
      'label' => 'Local red',
      'machine_name' => 'brand_red',
      'kind' => 'color',
    ]);
    $swatch->setSwatchValue('#ff0000');
    $swatch->save();

    $this->enableModules(['daedalus_stylish_swatches_test']);
    $report = $this->importer()->sync(['daedalus_stylish_swatches_test']);
    $this->assertSame(SwatchImporter::FAILED, $report->outcome('daedalus_stylish_swatches_test', 'brand_red'));
    $this->assertSame('SHIPPED_SWATCH_CONFLICT', $report->errors('daedalus_stylish_swatches_test', 'brand_red')[0]->getCode());
    // The site's swatch is untouched: still not shipped.
    $this->assertNull($this->loadSwatch('brand_red')->getProvider());
  }

  /**
   * A shipped swatch failing spine validation is FAILED with its errors.
   *
   * The invalid module ships a gradient-kind swatch whose value is a plain
   * color: the value seam (validateSwatchValue through the swatch value
   * field handler) refuses, proving disk definitions are validated exactly
   * like AI- and UI-minted swatches.
   */
  public function testInvalidShippedSwatchFailsLoudly(): void {
    $this->enableModules(['daedalus_stylish_swatches_test_invalid']);
    $report = $this->importer()->sync(['daedalus_stylish_swatches_test_invalid']);
    $this->assertSame(SwatchImporter::FAILED, $report->outcome('daedalus_stylish_swatches_test_invalid', 'broken'));
    $codes = array_map(static fn ($error) => $error->getCode(), $report->errors('daedalus_stylish_swatches_test_invalid', 'broken'));
    $this->assertContains('INVALID_STYLE_VALUE', $codes);
    $this->assertNull($this->loadSwatch('broken'));
  }

}
