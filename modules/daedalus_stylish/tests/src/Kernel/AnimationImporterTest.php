<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\AnimationImporter;
use Drupal\daedalus_stylish\Entity\DaedalusStylishAnimation;
use Drupal\daedalus_stylish\DaedalusStylishAnimationInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the module-shipped animations importer (the motion disk lane).
 *
 * The daedalus_stylish_animations_test module ships a MODULE.daedalus_stylish.animations.yml
 * with a from/to fade (described) and a percentage-keyed rise. The tests
 * drive the importer's sync() directly; on a site it runs through
 * ShippedSync (cache rebuild, install, `drush daedalus_stylish:sync`). Every
 * definition rides the blueprint deserializer through the animation steps
 * field handler
 * (the same steps-seam validation an AI- or UI-minted animation gets), and
 * the importer itself saves, live and published. Sync is "one hash, two
 * comparisons" over the canonical STEPS map (label and description are
 * metadata), so a spelling difference (from vs 0%) never reads as a
 * change while a step edit does.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class AnimationImporterTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The canonical steps daedalus_stylish_animations_test ships for test_fade.
   */
  protected const FADE_STEPS = ['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']];

  /**
   * A stand-in for a previously shipped state of test_fade.
   */
  protected const OLD_STEPS = ['0%' => ['opacity' => '0.2'], '100%' => ['opacity' => '1']];

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
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    // The importer switches to an administrative account (shipped
    // animations are code, not user input); give the switcher one to find.
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * Loads an animation by machine name, bypassing access.
   */
  protected function loadAnimation(string $machine_name): ?DaedalusStylishAnimationInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_animation');
    $storage->resetCache();
    $animations = $storage->loadByProperties(['machine_name' => $machine_name]);
    $animation = reset($animations) ?: NULL;
    assert($animation === NULL || $animation instanceof DaedalusStylishAnimationInterface);
    return $animation;
  }

  /**
   * The importer service, resolved late (installs rebuild the container).
   */
  protected function importer(): AnimationImporter {
    return \Drupal::service('daedalus_stylish.animation_importer');
  }

  /**
   * Installing a module imports its shipped animations, live and published.
   */
  public function testInstallImportsShippedAnimations(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_animations_test']);

    $fade = $this->loadAnimation('test_fade');
    $this->assertInstanceOf(DaedalusStylishAnimationInterface::class, $fade);
    $this->assertSame('Fade in', $fade->label());
    $this->assertSame('A soft entrance for page bands.', $fade->getDescription());
    // from/to store canonical.
    $this->assertSame(self::FADE_STEPS, $fade->getSteps());
    $this->assertTrue($fade->isPublished());
    $this->assertTrue($fade->isShipped());
    $this->assertSame('daedalus_stylish_animations_test', $fade->getProvider());
    $this->assertSame('shipped', $fade->getOrigin());
    $this->assertFalse($fade->isModified());
    $this->assertFalse($fade->hasNewDefaults(self::FADE_STEPS));
    // The disk read canonicalizes too, so the raw spelling never reads as
    // new defaults.
    $this->assertSame(self::FADE_STEPS, $this->importer()->getShippedData($fade));

    // A percentage-keyed animation with a per-step easing and no
    // description imports intact.
    $rise = $this->loadAnimation('test_rise');
    $this->assertInstanceOf(DaedalusStylishAnimationInterface::class, $rise);
    $this->assertSame('', $rise->getDescription());
    $this->assertSame(['opacity' => '0', 'transform' => 'translateY(1rem)', 'animation-timing-function' => 'ease-out'], $rise->getSteps()['0%']);
    $this->assertTrue($rise->isPublished());
    $this->assertFalse($rise->isModified());

    // The published set (what the materials roster serves, and so what
    // motion-* validates against, and what the keyframes file compiles)
    // sees both immediately.
    $this->assertSame(['test_fade', 'test_rise'], array_map(static fn ($animation) => $animation->getMachineName(), \Drupal::service('daedalus_stylish.animation_repository')->publishedAnimations()));
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame(['test_fade', 'test_rise'], array_keys($roster->animations()));
    // The AI opt-out rides the import: ai: false withholds test_rise from
    // the offered view (the brief and the schema) while the full roster,
    // what motion-* validates against, keeps it; an absent key offers.
    $this->assertFalse($rise->isOffered());
    $this->assertTrue($fade->isOffered());
    $this->assertSame(['test_fade'], array_keys($roster->offered()->animations()));
  }

  /**
   * The AI opt-out syncs from disk independently of the steps hash.
   *
   * Disk is the one author of a shipped animation's flag: ai: false lands
   * on an otherwise UNCHANGED animation, and an absent key is the default
   * (offered), so dropping the key re-offers.
   */
  public function testOptOutSyncsIndependentlyOfTheSteps(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_animations_test']);
    $this->loadAnimation('test_rise')->setOffered(TRUE)->save();
    $this->loadAnimation('test_fade')->setOffered(FALSE)->save();
    $this->assertFalse($this->loadAnimation('test_rise')->isModified(), 'The flag is not part of the steps hash.');

    $results = $this->importer()->sync(['daedalus_stylish_animations_test'])->outcomes();
    $this->assertSame(AnimationImporter::UNCHANGED, $results['daedalus_stylish_animations_test']['test_rise']);
    $this->assertSame(AnimationImporter::UNCHANGED, $results['daedalus_stylish_animations_test']['test_fade']);
    $this->assertFalse($this->loadAnimation('test_rise')->isOffered(), 'ai: false on disk withholds the animation again.');
    $this->assertTrue($this->loadAnimation('test_fade')->isOffered(), 'An absent key is the default: offered.');
  }

  /**
   * Syncing with nothing changed on disk touches nothing.
   */
  public function testSyncIsIdempotent(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_animations_test']);
    $revision_id = $this->loadAnimation('test_fade')->getRevisionId();

    $results = $this->importer()->sync()->outcomes();
    $this->assertSame(AnimationImporter::UNCHANGED, $results['daedalus_stylish_animations_test']['test_fade']);
    $this->assertSame(AnimationImporter::UNCHANGED, $results['daedalus_stylish_animations_test']['test_rise']);
    $this->assertSame($revision_id, $this->loadAnimation('test_fade')->getRevisionId());
  }

  /**
   * New disk defaults auto-sync an UNMODIFIED animation as a new revision.
   */
  public function testNewDefaultsAutoSyncUnmodifiedAnimation(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_animations_test']);

    // Rewind the animation to a previously shipped state: entity data
    // matches the stored hash (unmodified), but the disk now ships
    // something newer.
    $animation = $this->loadAnimation('test_fade');
    $animation->setSteps(self::OLD_STEPS);
    $animation->setShippedSource('daedalus_stylish_animations_test', self::OLD_STEPS);
    $animation->save();
    $old_revision_id = $animation->getRevisionId();

    $results = $this->importer()->sync(['daedalus_stylish_animations_test'])->outcomes();
    $this->assertSame(AnimationImporter::UPDATED, $results['daedalus_stylish_animations_test']['test_fade']);

    $synced = $this->loadAnimation('test_fade');
    $this->assertSame(self::FADE_STEPS, $synced->getSteps());
    $this->assertNotSame($old_revision_id, $synced->getRevisionId());
    $this->assertTrue($synced->isPublished());
    $this->assertFalse($synced->isModified());
    $this->assertStringContainsString('daedalus_stylish_animations_test', (string) $synced->getRevisionLogMessage());
  }

  /**
   * A user-modified animation keeps its steps AND its stored hash on sync.
   */
  public function testModifiedAnimationKeptOnSync(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_animations_test']);

    // The user edited the steps since the (old) shipped state, and the
    // disk has ALSO moved on: sync must not clobber the user's steps, and
    // must not touch the stored hash either, so the "defaults changed"
    // signal stays detectable (the revert button IS the ask).
    $animation = $this->loadAnimation('test_fade');
    $animation->setSteps(['0%' => ['opacity' => '0.4'], '100%' => ['opacity' => '1']]);
    $animation->setShippedSource('daedalus_stylish_animations_test', self::OLD_STEPS);
    $animation->save();
    $revision_id = $animation->getRevisionId();

    $results = $this->importer()->sync(['daedalus_stylish_animations_test'])->outcomes();
    $this->assertSame(AnimationImporter::KEPT, $results['daedalus_stylish_animations_test']['test_fade']);

    $kept = $this->loadAnimation('test_fade');
    $this->assertSame(['0%' => ['opacity' => '0.4'], '100%' => ['opacity' => '1']], $kept->getSteps());
    $this->assertSame($revision_id, $kept->getRevisionId());
    $this->assertSame(DaedalusStylishAnimation::hashData(self::OLD_STEPS), $kept->getSourceHash());
    $this->assertTrue($kept->isModified());
    $this->assertTrue($kept->hasNewDefaults(self::FADE_STEPS));
  }

  /**
   * A machine-name collision with a site-minted animation is FAILED.
   */
  public function testConflictWithSiteMintedAnimation(): void {
    $animation = DaedalusStylishAnimation::create([
      'label' => 'Local fade',
      'machine_name' => 'test_fade',
    ]);
    $animation->setSteps(self::OLD_STEPS);
    $animation->save();

    $this->enableModules(['daedalus_stylish_animations_test']);
    $report = $this->importer()->sync(['daedalus_stylish_animations_test']);
    $this->assertSame(AnimationImporter::FAILED, $report->outcome('daedalus_stylish_animations_test', 'test_fade'));
    $this->assertSame('SHIPPED_ANIMATION_CONFLICT', $report->errors('daedalus_stylish_animations_test', 'test_fade')[0]->getCode());
    // The site's animation is untouched: still not shipped.
    $this->assertNull($this->loadAnimation('test_fade')->getProvider());
  }

  /**
   * A shipped animation failing spine validation is FAILED with its errors.
   *
   * The invalid module ships a step that re-times the animation running
   * it: the steps seam (validateAnimationSteps through the animation steps
   * field handler) refuses, proving disk definitions are validated exactly
   * like AI- and UI-minted animations.
   */
  public function testInvalidShippedAnimationFailsLoudly(): void {
    $this->enableModules(['daedalus_stylish_animations_test_invalid']);
    $report = $this->importer()->sync(['daedalus_stylish_animations_test_invalid']);
    $this->assertSame(AnimationImporter::FAILED, $report->outcome('daedalus_stylish_animations_test_invalid', 'broken'));
    $codes = array_map(static fn ($error) => $error->getCode(), $report->errors('daedalus_stylish_animations_test_invalid', 'broken'));
    $this->assertContains('INVALID_ANIMATION_STEP_PROPERTY', $codes);
    $paths = array_map(static fn ($error) => $error->getPath(), $report->errors('daedalus_stylish_animations_test_invalid', 'broken'));
    $this->assertContains('fields.steps.0%.animation-duration', $paths);
    $this->assertNull($this->loadAnimation('broken'));
  }

}
