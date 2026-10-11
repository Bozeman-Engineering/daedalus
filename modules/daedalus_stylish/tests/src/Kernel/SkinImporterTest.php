<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\SkinImporter;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the module-shipped skins importer (the skins disk lane).
 *
 * The daedalus_stylish_skins_test module ships a MODULE.daedalus_stylish.skins.yml with two
 * skins, validated against the token scales and base-tag vocabulary the
 * test injects as the site declaration. The tests drive the importer's
 * sync() directly; on a site it runs through ShippedSync (cache rebuild,
 * install, `drush daedalus_stylish:sync`). Every definition rides the blueprint
 * deserializer through the skin field handlers (the same validation an AI-
 * or UI-authored skin gets), and the importer itself saves, live and
 * published. Sync writes the PACKAGE layer only and hashes it, the keyed
 * {tokens, defaults} shape; the site layer is never on the lane, so a new
 * payload on disk always lands (no KEPT outcome for skins: the distro lock
 * keeps a consuming site out of the package layer, SkinDistroLockTest) and
 * the site's layer rides the new revision untouched. The active pointer
 * is never touched.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SkinImporterTest extends KernelTestBase {

  use UserCreationTrait;
  use DesignSystemActivationTrait;

  /**
   * The tokens daedalus_stylish_skins_test ships for aurora, as stored.
   *
   * The file spells bare values and one record (the hero's description):
   * the disk door canonicalizes, so the entity holds records for all
   * three and the hash covers exactly these.
   */
  protected const AURORA_TOKENS = [
    'space' => [
      'gutter' => ['value' => ['default' => '2rem', 'narrow' => '1rem'], 'description' => '', 'offered' => TRUE],
    ],
    'color' => [
      'accent' => ['value' => ['default' => '#111111', 'dark' => '#eeeeee'], 'description' => '', 'offered' => TRUE],
    ],
    'text' => [
      'hero' => ['value' => '3rem', 'description' => 'The hero size the h2 default speaks.', 'offered' => TRUE],
    ],
  ];

  /**
   * The defaults daedalus_stylish_skins_test ships for aurora.
   *
   * Deliberately self-referencing (var(--text-hero) is aurora's own token):
   * shipped skins are self-contained, and strict sites must validate their
   * defaults against their OWN tokens, not the active skin's.
   */
  protected const AURORA_DEFAULTS = [
    'base:h2' => [
      '' => ['default' => ['font-size' => 'var(--text-hero)']],
    ],
  ];

  /**
   * A stand-in for a previously shipped state of aurora (as stored).
   */
  protected const OLD_PAYLOAD = [
    'tokens' => ['space' => ['gutter' => ['value' => '3rem', 'description' => '', 'offered' => TRUE]]],
    'defaults' => [],
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
    'daedalus_stylish_control_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    // The vocabulary the shipped skins validate against: token scales for
    // the tokens tier, the base: section for the defaults tier.
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
        'text' => ['label' => 'Type scale', 'shape' => 'dimension'],
      ],
      'base' => [
        'h2' => ['property_groups' => ['typography' => []]],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    // The importer switches to an administrative account (shipped skins are
    // code, not user input); give the switcher one to find.
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * Loads a skin by machine name, bypassing access.
   */
  protected function loadSkin(string $machine_name): ?DaedalusStylishSkinInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_skin');
    $storage->resetCache();
    $skins = $storage->loadByProperties(['machine_name' => $machine_name]);
    $skin = reset($skins) ?: NULL;
    assert($skin === NULL || $skin instanceof DaedalusStylishSkinInterface);
    return $skin;
  }

  /**
   * The importer service, resolved late (installs rebuild the container).
   */
  protected function importer(): SkinImporter {
    return \Drupal::service('daedalus_stylish.skin_importer');
  }

  /**
   * Installing a module imports its shipped skins, live and published.
   */
  public function testInstallImportsShippedSkins(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_skins_test']);

    $aurora = $this->loadSkin('aurora');
    $this->assertInstanceOf(DaedalusStylishSkinInterface::class, $aurora);
    $this->assertSame('Aurora', $aurora->label());
    $this->assertSame(self::AURORA_TOKENS, $aurora->packageTokens());
    $this->assertSame(self::AURORA_DEFAULTS, $aurora->packageDefaults());
    $this->assertTrue($aurora->isPublished());
    $this->assertTrue($aurora->isShipped());
    $this->assertSame('daedalus_stylish_skins_test', $aurora->getProvider());
    $this->assertFalse($aurora->isModified());
    $this->assertFalse($aurora->hasNewDefaults(['tokens' => self::AURORA_TOKENS, 'defaults' => self::AURORA_DEFAULTS]));

    // A tier-less skin imports too: label only, both data tiers empty.
    $bare = $this->loadSkin('bare');
    $this->assertInstanceOf(DaedalusStylishSkinInterface::class, $bare);
    $this->assertSame([], $bare->packageTokens());
    $this->assertSame([], $bare->packageDefaults());
    $this->assertTrue($bare->isPublished());
    $this->assertSame('daedalus_stylish_skins_test', $bare->getProvider());
    $this->assertFalse($bare->isModified());

    // Shipping never activates: which skin the site wears stays the site's
    // call.
    $this->assertNull(\Drupal::service('daedalus_stylish.skin_manager')->activeSkinName());
  }

  /**
   * Syncing with nothing changed on disk touches nothing.
   */
  public function testSyncIsIdempotent(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_skins_test']);
    $revision_id = $this->loadSkin('aurora')->getRevisionId();

    $results = $this->importer()->sync()->outcomes();
    $this->assertSame(SkinImporter::UNCHANGED, $results['daedalus_stylish_skins_test']['aurora']);
    $this->assertSame(SkinImporter::UNCHANGED, $results['daedalus_stylish_skins_test']['bare']);
    $this->assertSame($revision_id, $this->loadSkin('aurora')->getRevisionId());
  }

  /**
   * New disk defaults auto-sync an UNMODIFIED skin as a new revision.
   */
  public function testNewDefaultsAutoSyncUnmodifiedSkin(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_skins_test']);

    // Rewind the skin to a previously shipped state: entity data matches
    // the stored hash (unmodified), but the disk now ships something newer.
    $skin = $this->loadSkin('aurora');
    $skin->setPackageTokens(self::OLD_PAYLOAD['tokens']);
    $skin->setPackageDefaults(self::OLD_PAYLOAD['defaults']);
    $skin->setShippedSource('daedalus_stylish_skins_test', self::OLD_PAYLOAD);
    $skin->save();
    $old_revision_id = $skin->getRevisionId();

    $results = $this->importer()->sync(['daedalus_stylish_skins_test'])->outcomes();
    $this->assertSame(SkinImporter::UPDATED, $results['daedalus_stylish_skins_test']['aurora']);

    $synced = $this->loadSkin('aurora');
    $this->assertSame(self::AURORA_TOKENS, $synced->packageTokens());
    $this->assertSame(self::AURORA_DEFAULTS, $synced->packageDefaults());
    $this->assertNotSame($old_revision_id, $synced->getRevisionId());
    $this->assertTrue($synced->isPublished());
    $this->assertFalse($synced->isModified());
    $this->assertStringContainsString('daedalus_stylish_skins_test', (string) $synced->getRevisionLogMessage());
  }

  /**
   * New disk defaults land over an edited package layer too: no KEPT.
   *
   * Only a development site (the lock lifted) can hold an edited package
   * layer; there the disk still wins when it moved, the cost of the
   * layer having one author (ship before pulling). The stored hash is
   * restamped, so the skin reads unmodified against the new disk.
   */
  public function testNewDefaultsLandOverAnEditedPackageLayer(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_skins_test']);

    $edited_tokens = ['space' => ['gutter' => ['value' => '4rem', 'description' => '', 'offered' => TRUE]]];
    $skin = $this->loadSkin('aurora');
    $skin->setPackageTokens($edited_tokens);
    $skin->setPackageDefaults([]);
    $skin->setShippedSource('daedalus_stylish_skins_test', self::OLD_PAYLOAD);
    $skin->save();
    $revision_id = $skin->getRevisionId();
    $this->assertTrue($this->loadSkin('aurora')->isModified());

    $results = $this->importer()->sync(['daedalus_stylish_skins_test'])->outcomes();
    $this->assertSame(SkinImporter::UPDATED, $results['daedalus_stylish_skins_test']['aurora'], 'a new payload on disk lands whatever the package layer held');
    $this->assertNotContains(SkinImporter::KEPT, $results['daedalus_stylish_skins_test'], 'kept is not an outcome for skins');

    $synced = $this->loadSkin('aurora');
    $this->assertSame(self::AURORA_TOKENS, $synced->packageTokens());
    $this->assertSame(self::AURORA_DEFAULTS, $synced->packageDefaults());
    $this->assertNotSame($revision_id, $synced->getRevisionId());
    $this->assertSame(DaedalusStylishSkin::hashData([
      'tokens' => self::AURORA_TOKENS,
      'defaults' => self::AURORA_DEFAULTS,
    ]), $synced->getSourceHash());
    $this->assertFalse($synced->isModified());
  }

  /**
   * An UPDATED sync rewrites the package layer under the site layer.
   *
   * The site's additions and overrides are not in the shipped payload:
   * they ride the new revision untouched, and the merge shows the new
   * package values wherever the site changed nothing.
   */
  public function testSyncLeavesTheSiteLayerAlone(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_skins_test']);

    $site_tokens = [
      'space' => ['gutter' => ['value' => '5rem']],
      'color' => ['brand' => ['value' => '#ff0000', 'description' => 'Minted on the site.']],
    ];
    $site_defaults = ['base:h2' => ['' => ['default' => ['font-weight' => '700']]]];
    $skin = $this->loadSkin('aurora');
    $skin->setPackageTokens(self::OLD_PAYLOAD['tokens']);
    $skin->setPackageDefaults(self::OLD_PAYLOAD['defaults']);
    $skin->setShippedSource('daedalus_stylish_skins_test', self::OLD_PAYLOAD);
    $skin->setSiteTokens($site_tokens);
    $skin->setSiteDefaults($site_defaults);
    $skin->save();
    // A site write is never a modification of what the package shipped.
    $this->assertFalse($this->loadSkin('aurora')->isModified());

    $results = $this->importer()->sync(['daedalus_stylish_skins_test'])->outcomes();
    $this->assertSame(SkinImporter::UPDATED, $results['daedalus_stylish_skins_test']['aurora']);

    $synced = $this->loadSkin('aurora');
    $this->assertSame(self::AURORA_TOKENS, $synced->packageTokens());
    $this->assertSame(self::AURORA_DEFAULTS, $synced->packageDefaults());
    $this->assertSame($site_tokens, $synced->siteTokens(), 'the site layer rode the new revision untouched');
    $this->assertSame($site_defaults, $synced->siteDefaults());
    $merged = $synced->tokens();
    $this->assertSame('5rem', $merged['space']['gutter']['value'], 'the site override still wins over the new package value');
    $this->assertSame('The hero size the h2 default speaks.', $merged['text']['hero']['description'], 'a package token the site never touched shows through');
    $this->assertSame('#ff0000', $merged['color']['brand']['value']);
    $this->assertSame(['font-size' => 'var(--text-hero)', 'font-weight' => '700'], $synced->defaults()['base:h2']['']['default']);
  }

  /**
   * The defaults tier participates in the hash (the keyed payload shape).
   */
  public function testDefaultsTierParticipatesInHash(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_skins_test']);

    // Divergence in DEFAULTS alone (tokens untouched) reads as modified:
    // the one hash covers the whole {tokens, defaults} payload.
    $user_defaults = ['base:h2' => ['' => ['default' => ['font-size' => '3rem']]]];
    $skin = $this->loadSkin('aurora');
    $skin->setPackageDefaults($user_defaults);
    $skin->save();
    $this->assertTrue($this->loadSkin('aurora')->isModified());

    // Disk unchanged: the edit is left alone (an edited package layer is a
    // development site's, ship-worthy), never reverted by an idempotent
    // sync.
    $results = $this->importer()->sync(['daedalus_stylish_skins_test'])->outcomes();
    $this->assertSame(SkinImporter::UNCHANGED, $results['daedalus_stylish_skins_test']['aurora']);
    $this->assertSame($user_defaults, $this->loadSkin('aurora')->packageDefaults());
  }

  /**
   * On strict sites a skin's defaults validate against its OWN tokens.
   *
   * The disk lane's self-containment case: nothing is active at import time
   * (or a different skin is), yet aurora's defaults reference
   * var(--text-hero), which aurora itself mints. Validating against the
   * active skin would make a shipped self-referencing skin unimportable on
   * every strict site.
   */
  public function testStrictSelfReferencingSkinImports(): void {
    $this->enforce('strict');
    $this->enableModules(['daedalus_stylish_skins_test']);

    $results = $this->importer()->sync(['daedalus_stylish_skins_test'])->outcomes();
    $this->assertSame(SkinImporter::CREATED, $results['daedalus_stylish_skins_test']['aurora']);
    $this->assertSame(self::AURORA_DEFAULTS, $this->loadSkin('aurora')->packageDefaults());
  }

  /**
   * A machine-name collision with a skin the module does not ship is FAILED.
   */
  public function testConflictWithSiteCreatedSkin(): void {
    $skin = DaedalusStylishSkin::create([
      'label' => 'Local aurora',
      'machine_name' => 'aurora',
    ]);
    $skin->save();

    $this->enableModules(['daedalus_stylish_skins_test']);
    $report = $this->importer()->sync(['daedalus_stylish_skins_test']);
    $this->assertSame(SkinImporter::FAILED, $report->outcome('daedalus_stylish_skins_test', 'aurora'));
    $this->assertSame('SHIPPED_SKIN_CONFLICT', $report->errors('daedalus_stylish_skins_test', 'aurora')[0]->getCode());
    // The site's skin is untouched: still not shipped.
    $this->assertNull($this->loadSkin('aurora')->getProvider());
  }

  /**
   * A shipped skin failing spine validation is FAILED with its errors.
   */
  public function testInvalidShippedSkinFailsLoudly(): void {
    $this->enableModules(['daedalus_stylish_skins_test_invalid']);
    $report = $this->importer()->sync(['daedalus_stylish_skins_test_invalid']);
    $this->assertSame(SkinImporter::FAILED, $report->outcome('daedalus_stylish_skins_test_invalid', 'broken'));
    $codes = array_map(static fn ($error) => $error->getCode(), $report->errors('daedalus_stylish_skins_test_invalid', 'broken'));
    $this->assertContains('TOKEN_SCALE_UNKNOWN', $codes);
    $this->assertNull($this->loadSkin('broken'));
  }

}
