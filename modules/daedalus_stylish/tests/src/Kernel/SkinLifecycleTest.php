<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Controller\SkinApiController;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Exception\SkinValidationException;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The skin's lifecycle: the card's read, copy, revisions, restore, revert.
 *
 * The variant lifecycle's twin for the look. A shipped skin on a
 * consuming site is the package's (its package layer the disk's, locked)
 * with the site's additions and changes a layer over it; the site's own
 * skin has one layer. So: copy as my skin folds the merge into a new
 * skin with no provider (every gesture the lock refuses on the source is
 * open on the copy), a member of the ACTIVE design system whatever the
 * source's membership, and a site's own unit takes its first copy as
 * its default_skin, so the copy is worn at once; a restore brings back
 * the layer the site writes
 * (the site layer alone while the package layer is locked, the revision
 * whole otherwise), as a NEW revision, never a rollback; revert empties
 * the site layer so the package shows through whole; and the card's read
 * lists the active system's members with what may be worn and why not.
 * The routes adapt onto the manager and answer the materials delta.
 */
#[Group('daedalus_stylish')]
#[CoversClass(SkinManager::class)]
#[CoversClass(SkinApiController::class)]
#[RunTestsInSeparateProcesses]
class SkinLifecycleTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The provider stamped on the shipped fixture skin (no disk file).
   */
  protected const PROVIDER = 'daedalus_stylish_guide_test';

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
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_blueprint', 'daedalus_stylish']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
      ],
      'token_contract' => ['space' => ['gutter']],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora', 'default_skin' => 'aurora'])->save();
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis', 'default_skin' => 'north'])->save();
  }

  /**
   * The skin manager.
   */
  protected function manager(): SkinManager {
    return $this->container->get('daedalus_stylish.skin_manager');
  }

  /**
   * The activation service.
   */
  protected function active(): ActiveDesignSystem {
    return $this->container->get(ActiveDesignSystem::SERVICE_ID);
  }

  /**
   * Loads a skin by machine name, fresh from storage.
   */
  protected function load(string $machine_name): DaedalusStylishSkinInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('daedalus_skin');
    $storage->resetCache();
    $skin = $this->manager()->loadByMachineName($machine_name);
    $this->assertNotNull($skin, "The $machine_name skin exists.");
    return $skin;
  }

  /**
   * The package's skin as a consuming site holds it: provider, site layer.
   *
   * The package layer mints gutter (the contract) and tight; the site
   * overrides gutter, adds wide and sets one default over the package's.
   */
  protected function createShipped(string $machine_name = 'aurora', string $design_system = 'aurora'): DaedalusStylishSkin {
    $skin = DaedalusStylishSkin::create([
      'label' => ucfirst($machine_name),
      'machine_name' => $machine_name,
      'design_system' => $design_system,
    ]);
    $skin->setPackageTokens(['space' => ['gutter' => '1rem', 'tight' => '0.5rem']]);
    $skin->setPackageDefaults(['base:h2' => ['' => ['default' => ['font-size' => '2rem']]]]);
    $skin->setShippedSource(self::PROVIDER, ['tokens' => $skin->packageTokens(), 'defaults' => $skin->packageDefaults()]);
    $skin->setSiteTokens(['space' => ['gutter' => ['value' => '2rem'], 'wide' => ['value' => '4rem']]]);
    $skin->setSiteDefaults(['base:h2' => ['' => ['default' => ['color' => 'red']]]]);
    $skin->save();
    return $skin;
  }

  /**
   * A skin the site made: no provider, one layer.
   */
  protected function createOwn(string $machine_name, string $design_system, array $space, string $label = ''): DaedalusStylishSkin {
    $skin = DaedalusStylishSkin::create([
      'label' => $label === '' ? ucfirst($machine_name) : $label,
      'machine_name' => $machine_name,
      'design_system' => $design_system,
    ]);
    $skin->setPackageTokens(['space' => $space])->save();
    return $skin;
  }

  /**
   * The one error code a refused gesture carries, keyed by path.
   */
  protected function refusal(callable $gesture): array {
    try {
      $gesture();
    }
    catch (SkinValidationException $exception) {
      $errors = $exception->getErrorsAsArrays();
      $this->assertCount(1, $errors);
      return [$errors[0]['path'] => $errors[0]['code']];
    }
    $this->fail('The gesture was not refused.');
  }

  /**
   * A skin's revision ids, newest first.
   *
   * @return int[]
   *   The ids.
   */
  protected function revisionIds(DaedalusStylishSkinInterface $skin): array {
    return array_map(static fn (array $entry): int => $entry['revision_id'], $this->manager()->revisionsList($skin));
  }

  /**
   * A merged token map's values, {scale: {name: value}}.
   */
  protected function values(array $tokens): array {
    $values = [];
    foreach ($tokens as $scale => $records) {
      foreach ($records as $name => $record) {
        $values[$scale][$name] = $record['value'];
      }
    }
    return $values;
  }

  /**
   * Decodes a controller response.
   */
  protected function payload(JsonResponse $response): array {
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * Loads a design system fresh from storage.
   */
  protected function loadSystem(string $id): DesignSystem {
    $storage = $this->container->get('entity_type.manager')->getStorage(DesignSystem::ENTITY_TYPE_ID);
    $storage->resetCache([$id]);
    $system = $storage->load($id);
    $this->assertInstanceOf(DesignSystem::class, $system);
    return $system;
  }

  /**
   * The compiled defaults file's CSS, '' when no file is compiled.
   */
  protected function compiledCss(): string {
    $uri = $this->container->get('state')->get(DefaultsStylesheet::STATE_KEY);
    return $uri === NULL ? '' : (string) file_get_contents($uri);
  }

  /**
   * A delta side's token ids, sorted.
   *
   * @return string[]
   *   The ids.
   */
  protected function deltaIds(array $delta, string $side): array {
    $ids = array_map(static fn (array $entry): string => $entry['id'], array_filter($delta[$side], static fn (array $entry): bool => $entry['kind'] === 'token'));
    sort($ids);
    return array_values($ids);
  }

  /**
   * Copy as my skin: the merge folded into a skin the active system owns.
   */
  public function testCopyAsMySkin(): void {
    $manager = $this->manager();
    $source = $this->createShipped();
    $this->assertTrue($manager->writesSiteLayer($source), 'The fixture is a consuming site\'s shipped skin.');

    // Nothing active: nothing to land the copy in, the error choose() speaks.
    $this->assertSame(['' => 'STYLE_DESIGN_SYSTEM_NONE_ACTIVE'], $this->refusal(fn () => $manager->copyAsMySkin($source)));
    $this->assertNull($manager->loadByMachineName('aurora_copy'), 'A refused copy saves nothing.');

    $this->active()->activate('aurora');
    $copy = $manager->copyAsMySkin($source);
    $this->assertSame('Aurora (copy)', (string) $copy->label(), 'The label derives from the source\'s.');
    $this->assertSame('aurora_copy', $copy->getMachineName(), 'The machine name mints from the label at save.');
    $this->assertNull($copy->getProvider(), 'The copy has no provider: nothing ships it.');
    $this->assertFalse($copy->isShipped());
    $this->assertFalse($manager->writesSiteLayer($copy), 'The site edits the copy\'s one layer directly.');
    $this->assertSame('aurora', $copy->getDesignSystem(), 'The copy lands in the active design system.');
    $this->assertTrue($copy->isPublished(), 'The copy saves published, choosable at once.');
    $this->assertSame((int) $this->container->get('current_user')->id(), (int) $copy->getOwnerId(), 'The copy is the current user\'s.');
    $this->assertSame('Copied from the "aurora" skin.', $copy->getRevisionLogMessage());
    $this->assertNotSame($source->id(), $copy->id());

    // The copy's package layer IS the source's merge: the site's override
    // and addition folded in beside the package's untouched token, the
    // records whole (description and offered carried), and the defaults
    // merged down to the property.
    $this->assertSame([
      'space' => ['gutter' => '2rem', 'tight' => '0.5rem', 'wide' => '4rem'],
    ], $this->values($copy->packageTokens()), 'The copy\'s package layer is the source\'s merge.');
    $this->assertSame($source->tokens(), $copy->packageTokens(), 'Every record fact carries over whole.');
    $this->assertSame([
      'base:h2' => ['' => ['default' => ['font-size' => '2rem', 'color' => 'red']]],
    ], $copy->packageDefaults(), 'The copy\'s defaults are the source\'s merge.');
    $this->assertSame([], $copy->siteTokens(), 'The copy\'s site layer starts empty.');
    $this->assertSame([], $copy->siteDefaults());
    $this->assertSame($source->tokens(), $copy->tokens(), 'The copy looks exactly as the source does on this site.');
    $this->assertSame($source->defaults(), $copy->defaults());

    // Copying touched nothing on the source, and nothing on a unit that
    // already names a default: the copy wears nothing there.
    $fresh = $this->load('aurora');
    $this->assertSame($source->getRevisionId(), $fresh->getRevisionId(), 'The source gained no revision.');
    $this->assertSame(['space' => ['gutter' => '2rem', 'wide' => '4rem']], $this->values($fresh->siteTokens()));
    $this->assertSame('aurora', $this->loadSystem('aurora')->getDefaultSkin(), 'A unit naming a default is never written.');
    $this->assertSame('aurora', $manager->activeSkinName(), 'Copying alone wore nothing.');

    // The copy conforms (it mints gutter), so the system may wear it.
    $this->assertSame('aurora', $manager->choose('aurora_copy'));
    $this->assertSame('aurora_copy', $manager->activeSkinName(), 'The copy is worn once chosen.');

    // Another system's member and a global skin copy into the active
    // system too: the source's membership never travels.
    $this->createOwn('north', 'borealis', ['gutter' => '1rem']);
    DaedalusStylishSkin::create(['label' => 'Global', 'machine_name' => 'global'])
      ->setPackageTokens(['space' => ['gutter' => '3rem']])->save();
    $from_north = $manager->copyAsMySkin($this->load('north'));
    $this->assertSame(['north_copy', 'aurora'], [$from_north->getMachineName(), $from_north->getDesignSystem()], 'Another system\'s member copies into the active system.');
    $this->assertSame('borealis', $this->load('north')->getDesignSystem(), 'The source keeps its membership.');
    $from_global = $manager->copyAsMySkin($this->load('global'), 'Mine');
    $this->assertSame(['mine', 'aurora'], [$from_global->getMachineName(), $from_global->getDesignSystem()], 'A global skin copies into the active system, where it can be chosen.');
    $this->assertNull($this->load('global')->getDesignSystem());
    $this->assertSame(['space' => ['gutter' => '3rem']], $this->values($from_global->packageTokens()));
    $this->assertSame('aurora', $manager->choose('mine'), 'The copy of a global skin is choosable, which the global skin never was.');

    // A label of the site's choosing, and a taken name suffixed.
    $this->assertSame('mine_2', $manager->copyAsMySkin($fresh, '  Mine  ')->getMachineName());
    // Forking a site skin is harmless and the gesture is uniform.
    $again = $manager->copyAsMySkin($from_global);
    $this->assertSame('Mine (copy)', (string) $again->label());
    $this->assertSame($from_global->packageTokens(), $again->packageTokens());
  }

  /**
   * The first-skin door: a site's own unit takes its first copy as its default.
   *
   * A unit created on the site has no package and no default_skin, and
   * no member to choose, so the copy gesture is how it gets a skin: the
   * copy's machine name becomes the unit's default_skin and the copy is
   * worn at once, the defaults file recompiled and the copy's token ids
   * on the delta, as a choice's are. The write follows the copy's save
   * (the machine name mints there), so it is the unit's own save that
   * restyles. A second copy writes no unit, nor does a copy into a
   * packaged unit (a tenant's write would edit config the package
   * ships): those wear nothing.
   */
  public function testFirstCopyIntoTheSitesOwnUnitIsWorn(): void {
    $manager = $this->manager();
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    assert($change_set instanceof MaterialsChangeSet);
    $this->createShipped();
    DesignSystem::create(['id' => 'blank', 'label' => 'Blank'])->save();
    $this->active()->activate('blank');
    $this->assertNull($manager->activeSkinName(), 'The site\'s own unit wears nothing yet.');
    $this->assertSame([], $manager->memberSkins('blank'));
    $this->assertStringNotContainsString('font-size: 2rem', $this->compiledCss());
    $change_set->flush();

    $copy = $manager->copyAsMySkin($this->load('aurora'), 'First');
    $this->assertSame(['first', 'blank'], [$copy->getMachineName(), $copy->getDesignSystem()]);
    $this->assertSame('first', $this->loadSystem('blank')->getDefaultSkin(), 'The copy became the unit\'s default_skin.');
    $this->assertNull($this->loadSystem('blank')->getPackage(), 'The unit stays the site\'s own.');
    $this->assertSame('first', $manager->activeSkinName(), 'The copy is worn at once.');
    $this->assertNull($manager->choice('blank'), 'Through the unit\'s default, not a choice.');
    $this->assertSame(['first'], array_keys($manager->memberSkins('blank')));
    $delta = $change_set->flush();
    $this->assertSame(['space/gutter', 'space/tight', 'space/wide'], $this->deltaIds($delta, 'upsert'), 'The copy\'s token ids ride the delta, as a choice\'s do.');
    $this->assertSame([], $this->deltaIds($delta, 'remove'));
    $this->assertStringContainsString('font-size: 2rem', $this->compiledCss(), 'The defaults file recompiled after the unit\'s write, so the copy\'s defaults deliver.');
    $this->assertStringContainsString('color: red', $this->compiledCss(), 'The source\'s site layer, folded into the copy, delivers with it.');

    // A second copy into the same unit writes nothing: the unit names a
    // default now, and choosing is the next gesture.
    $change_set->flush();
    $second = $manager->copyAsMySkin($this->load('aurora'), 'Second');
    $this->assertSame('blank', $second->getDesignSystem());
    $this->assertSame('first', $this->loadSystem('blank')->getDefaultSkin(), 'A unit naming a default is never written.');
    $this->assertSame('first', $manager->activeSkinName());
    $this->assertSame(['upsert' => [], 'remove' => []], $change_set->flush(), 'Nothing worn changed: an empty delta.');

    // A packaged unit with no default is never written either.
    DesignSystem::create(['id' => 'shipped', 'label' => 'Shipped', 'package' => 'daedalus_stylish_control_test'])->save();
    $this->active()->activate('shipped');
    $change_set->flush();
    $into_packaged = $manager->copyAsMySkin($this->load('aurora'), 'Tenant');
    $this->assertSame('shipped', $into_packaged->getDesignSystem());
    $this->assertNull($this->loadSystem('shipped')->getDefaultSkin(), 'A packaged unit is never written.');
    $this->assertNull($manager->activeSkinName(), 'The copy into a packaged unit wears nothing.');
    $this->assertSame(['upsert' => [], 'remove' => []], $change_set->flush());
    $this->assertSame('shipped', $manager->choose('tenant'), 'Choosing is the next gesture there.');
    $this->assertSame('tenant', $manager->activeSkinName());
  }

  /**
   * Revisions and restore on a consuming site: the site layer comes back.
   *
   * The package layer is the disk's, so a restore lands the old
   * revision's site layer over the package layer AS IT IS NOW (a revision
   * from before a sync must not rewind what the package shipped), as a
   * new revision on top, never a rollback.
   */
  public function testRestoreKeepsToTheSiteLayerUnderTheLock(): void {
    $manager = $this->manager();
    $skin = $this->createShipped();
    $first = (int) $skin->getRevisionId();

    // Two site-layer edits, as the token gestures land them.
    $skin->setSiteTokens(['space' => ['gutter' => ['value' => '3rem'], 'wide' => ['value' => '4rem']]]);
    $manager->saveRevision($skin, 'Updated space/gutter.');
    $second = (int) $skin->getRevisionId();
    $skin->setSiteTokens(['space' => ['gutter' => ['value' => '3rem']]]);
    $skin->setSiteDefaults([]);
    $manager->saveRevision($skin, 'Removed space/wide.');
    $third = (int) $skin->getRevisionId();
    $this->assertGreaterThan($second, $third);

    $list = $manager->revisionsList($this->load('aurora'));
    $this->assertSame([$third, $second, $first], array_column($list, 'revision_id'), 'Newest first.');
    $this->assertSame([TRUE, FALSE, FALSE], array_column($list, 'current'), 'The default revision is marked current.');
    $this->assertSame(['Removed space/wide.', 'Updated space/gutter.', ''], array_column($list, 'log_message'));
    $this->assertSame(['Aurora', 'Aurora', 'Aurora'], array_column($list, 'label'));
    $this->assertSame(['revision_id', 'label', 'created', 'user', 'log_message', 'current'], array_keys($list[0]), 'The variant lifecycle card\'s exact shape.');

    // A sync lands a new package layer meanwhile (the importer writes the
    // package layer alone; the site layer rides untouched).
    $synced = $this->load('aurora');
    $synced->setPackageTokens(['space' => ['gutter' => '1.5rem', 'tight' => '0.5rem', 'snug' => '0.25rem']]);
    $synced->setShippedSource(self::PROVIDER, [
      'tokens' => $synced->packageTokens(),
      'defaults' => $synced->packageDefaults(),
    ]);
    $synced->setNewRevision(TRUE);
    $synced->save();
    $fourth = (int) $synced->getRevisionId();

    // Restore the second revision: its site layer over today's package.
    $restored = $manager->restoreRevision($this->load('aurora'), $second);
    $this->assertGreaterThan($fourth, (int) $restored->getRevisionId(), 'A NEW revision on top, never a rollback.');
    $this->assertSame(['space' => ['gutter' => '3rem', 'wide' => '4rem']], $this->values($restored->siteTokens()), 'The second revision\'s site tokens are back.');
    $this->assertSame(['base:h2' => ['' => ['default' => ['color' => 'red']]]], $restored->siteDefaults(), 'The second revision\'s site defaults are back.');
    $this->assertSame(['space' => ['gutter' => '1.5rem', 'tight' => '0.5rem', 'snug' => '0.25rem']], $this->values($restored->packageTokens()), 'The package layer stays what the sync landed.');
    $this->assertSame(['space' => ['gutter' => '3rem', 'tight' => '0.5rem', 'snug' => '0.25rem', 'wide' => '4rem']], $this->values($restored->tokens()), 'The merge: the restored site layer over today\'s package.');
    $this->assertSame("Restored the site layer of revision $second.", $restored->getRevisionLogMessage());
    $this->assertFalse($restored->isModified(), 'The package layer as synced is not a modification.');
    $fresh = $this->load('aurora');
    $this->assertSame($restored->getRevisionId(), $fresh->getRevisionId(), 'The restore is the default revision.');
    $this->assertCount(5, $manager->revisionsList($fresh));

    // Restoring the first revision brings its site layer back over the
    // same package, and a restore is itself one restore away from undo.
    $manager->restoreRevision($fresh, $first);
    $this->assertSame(['space' => ['gutter' => '2rem', 'wide' => '4rem']], $this->values($this->load('aurora')->siteTokens()));
    $manager->restoreRevision($this->load('aurora'), (int) $restored->getRevisionId());
    $this->assertSame(['space' => ['gutter' => '3rem', 'wide' => '4rem']], $this->values($this->load('aurora')->siteTokens()));

    // Not this skin's revision: refused, nothing saved.
    $other = $this->createOwn('dusk', 'aurora', ['gutter' => '1rem']);
    $before = $this->load('aurora')->getRevisionId();
    $this->assertSame(['revision' => 'STYLE_SKIN_REVISION_NOT_FOUND'], $this->refusal(fn () => $manager->restoreRevision($this->load('aurora'), 999999)));
    $this->assertSame(['revision' => 'STYLE_SKIN_REVISION_NOT_FOUND'], $this->refusal(fn () => $manager->restoreRevision($this->load('aurora'), (int) $other->getRevisionId())), 'Another skin\'s revision id is not this skin\'s.');
    $this->assertSame($before, $this->load('aurora')->getRevisionId());
  }

  /**
   * Restore brings the revision back whole where the site owns the layer.
   *
   * On the site's own skin (no provider) and under the developer gate the
   * old revision's revisionable fields come back: label and both layers.
   */
  public function testRestoreWholeWhereTheSiteWritesThePackageLayer(): void {
    $manager = $this->manager();
    $mine = $this->createOwn('mine', 'aurora', ['gutter' => '1rem'], 'Mine');
    $first = (int) $mine->getRevisionId();
    $mine->set('label', 'Mine, bolder');
    $mine->setPackageTokens(['space' => ['gutter' => '2rem']]);
    $mine->setPackageDefaults(['base:h2' => ['' => ['default' => ['font-size' => '3rem']]]]);
    $manager->saveRevision($mine, 'Bolder.');
    $second = (int) $mine->getRevisionId();

    $restored = $manager->restoreRevision($this->load('mine'), $first);
    $this->assertGreaterThan($second, (int) $restored->getRevisionId());
    $this->assertSame('Mine', (string) $restored->label(), 'The label is revisionable and comes back.');
    $this->assertSame(['space' => ['gutter' => '1rem']], $this->values($restored->packageTokens()));
    $this->assertSame([], $restored->packageDefaults());
    $this->assertSame('mine', $restored->getMachineName(), 'Identity never revisioned, never drifts.');
    $this->assertSame("Restored revision $first.", $restored->getRevisionLogMessage());
    $this->assertSame([(int) $restored->getRevisionId(), $second, $first], $this->revisionIds($this->load('mine')));
    $this->assertSame('Mine', (string) $this->load('mine')->label());

    // The developer gate lifts the lock on the shipped skin: a restore
    // rewinds the package layer too (the authoring site ships it back).
    $skin = $this->createShipped();
    $before = (int) $skin->getRevisionId();
    $skin->setPackageTokens(['space' => ['gutter' => '9rem', 'tight' => '0.5rem']]);
    $skin->setSiteTokens([]);
    $manager->saveRevision($skin, 'Developer edit.');
    $this->setSetting(DistroLock::SETTING, TRUE);
    $this->assertFalse($manager->writesSiteLayer($this->load('aurora')));
    $restored = $manager->restoreRevision($this->load('aurora'), $before);
    $this->assertSame(['space' => ['gutter' => '1rem', 'tight' => '0.5rem']], $this->values($restored->packageTokens()), 'Under the gate the package layer comes back.');
    $this->assertSame(['space' => ['gutter' => '2rem', 'wide' => '4rem']], $this->values($restored->siteTokens()), 'And the site layer with it.');
    $this->assertSame("Restored revision $before.", $restored->getRevisionLogMessage());
  }

  /**
   * Revert the site layer: the skin wears what its package ships, whole.
   */
  public function testRevertSiteLayer(): void {
    $manager = $this->manager();
    $skin = $this->createShipped();
    $this->assertTrue($manager->hasSiteLayer($skin));
    $before = (int) $skin->getRevisionId();

    $this->assertTrue($manager->revertSiteLayer($skin), 'A revision landed.');
    $this->assertSame([], $skin->siteTokens());
    $this->assertSame([], $skin->siteDefaults());
    $this->assertFalse($manager->hasSiteLayer($skin));
    $this->assertSame(['space' => ['gutter' => '1rem', 'tight' => '0.5rem']], $this->values($skin->tokens()), 'The package shows through whole.');
    $this->assertSame($skin->packageDefaults(), $skin->defaults());
    $this->assertGreaterThan($before, (int) $skin->getRevisionId(), 'One new revision.');
    $this->assertSame('Reverted the site layer: the skin wears what the ' . self::PROVIDER . ' module ships.', $skin->getRevisionLogMessage());
    $this->assertFalse($skin->isModified(), 'The package layer was never touched.');
    $this->assertSame(['space' => ['gutter' => '1rem', 'tight' => '0.5rem']], $this->values($this->load('aurora')->tokens()));

    // Nothing to clear saves nothing and mints no revision.
    $after = (int) $this->load('aurora')->getRevisionId();
    $this->assertFalse($manager->revertSiteLayer($this->load('aurora')));
    $this->assertSame($after, (int) $this->load('aurora')->getRevisionId());

    // The revert is itself one restore away from undo.
    $manager->restoreRevision($this->load('aurora'), $before);
    $this->assertSame(['space' => ['gutter' => '2rem', 'wide' => '4rem']], $this->values($this->load('aurora')->siteTokens()));

    // Only a site-layer-only default: reverting drops it too.
    $only = $this->load('aurora');
    $only->setSiteTokens([]);
    $manager->saveRevision($only, 'Tokens gone.');
    $this->assertTrue($manager->hasSiteLayer($only), 'A site default alone is a site layer.');
    $this->assertTrue($manager->revertSiteLayer($only));
    $this->assertSame([], $only->siteDefaults());

    // The site's own skin has no package to reveal.
    $mine = $this->createOwn('mine', 'aurora', ['gutter' => '1rem']);
    $this->assertSame(['id' => 'STYLE_SKIN_NOT_SHIPPED'], $this->refusal(fn () => $manager->revertSiteLayer($mine)));

    // Under the developer gate the site layer is still a layer to clear
    // (a package-layer write under the gate leaves an older site record in
    // place; this is what clears it).
    $this->setSetting(DistroLock::SETTING, TRUE);
    $gated = $this->load('aurora');
    $gated->setSiteTokens(['space' => ['gutter' => ['value' => '5rem']]]);
    $manager->saveRevision($gated, 'Stale site record.');
    $this->assertTrue($manager->revertSiteLayer($this->load('aurora')));
    $this->assertSame([], $this->load('aurora')->siteTokens());
  }

  /**
   * The card's read: the active system's members and what may be worn.
   */
  public function testCardRead(): void {
    $manager = $this->manager();
    $this->assertSame([
      'design_system' => NULL,
      'worn' => NULL,
      'choice' => NULL,
      'default' => NULL,
      'skins' => [],
      'sources' => [],
    ], $manager->card(), 'No active system: every fact NULL, no members, no skins to copy.');

    $this->createShipped();
    $this->createOwn('dusk', 'aurora', ['gutter' => '2rem']);
    $this->createOwn('bare', 'aurora', ['wide' => '4rem']);
    $this->createOwn('night', 'aurora', ['gutter' => '1rem'])->setUnpublished()->save();
    $this->createOwn('north', 'borealis', ['gutter' => '1rem']);
    DaedalusStylishSkin::create(['label' => 'Global', 'machine_name' => 'global'])->save();
    $this->active()->activate('aurora');

    $read = $manager->card();
    $this->assertSame('aurora', $read['design_system']);
    $this->assertSame('aurora', $read['worn']);
    $this->assertNull($read['choice'], 'The site has chosen nothing.');
    $this->assertSame('aurora', $read['default']);
    $this->assertSame(['aurora', 'bare', 'dusk', 'night'], array_column($read['skins'], 'machine_name'), 'Members by label; the other system\'s and the global skin excluded.');
    $this->assertSame(['machine_name', 'label', 'published', 'provider', 'site_layer', 'locked', 'refusal'], array_keys($read['skins'][0]));
    $this->assertSame([
      ['aurora', 'Aurora', 'aurora', 'Aurora', self::PROVIDER],
      ['bare', 'Bare', 'aurora', 'Aurora', NULL],
      ['dusk', 'Dusk', 'aurora', 'Aurora', NULL],
      ['global', 'Global', NULL, NULL, NULL],
      ['night', 'Night', 'aurora', 'Aurora', NULL],
      ['north', 'North', 'borealis', 'Borealis', NULL],
    ], array_map('array_values', $read['sources']), 'Sources: every skin on the site by label, the other system\'s member and the global skin included, unpublished or not.');
    $this->assertSame(['machine_name', 'label', 'design_system', 'design_system_label', 'provider'], array_keys($read['sources'][0]));
    [$aurora, $bare, $dusk, $night] = $read['skins'];
    $this->assertSame(['Aurora', TRUE, self::PROVIDER, TRUE, TRUE, NULL], array_values(array_slice($aurora, 1)), 'The shipped skin: a site layer over a locked package layer, choosable.');
    $this->assertSame([NULL, FALSE, FALSE], [$dusk['provider'], $dusk['site_layer'], $dusk['locked']], 'The site\'s own skin: no provider, no site layer, not locked.');
    $this->assertNull($dusk['refusal']);
    $this->assertSame('STYLE_SKIN_NONCONFORMING', $bare['refusal']['code']);
    $this->assertSame(['lacks' => ['space' => ['gutter']]], $bare['refusal']['context'], 'The refusal names what the skin lacks.');
    $this->assertFalse($night['published']);
    $this->assertSame('STYLE_SKIN_UNPUBLISHED', $night['refusal']['code']);

    // A choice reads as worn and as the choice; the default stays the unit's.
    $manager->choose('dusk');
    $read = $manager->card();
    $this->assertSame(['dusk', 'dusk', 'aurora'], [$read['worn'], $read['choice'], $read['default']]);
    // A choice the system may no longer wear falls back in the read, and
    // the member carries the refusal.
    $this->load('dusk')->setUnpublished()->save();
    $read = $manager->card();
    $this->assertSame(['aurora', 'dusk'], [$read['worn'], $read['choice']], 'The stored choice survives the fallback.');
    $this->assertSame('STYLE_SKIN_UNPUBLISHED', $read['skins'][2]['refusal']['code']);

    // The other system wears its own default and lists its own members.
    $this->active()->activate('borealis');
    $read = $manager->card();
    $this->assertSame(['borealis', 'north', NULL, 'north'], [
      $read['design_system'],
      $read['worn'],
      $read['choice'],
      $read['default'],
    ]);
    $this->assertSame(['north'], array_column($read['skins'], 'machine_name'));
    $this->assertSame(['aurora', 'bare', 'dusk', 'global', 'night', 'north'], array_column($read['sources'], 'machine_name'), 'The sources do not depend on which system is active.');
    // With no active system the sources still list.
    $this->config(ActiveDesignSystem::CONFIG_NAME)->clear(ActiveDesignSystem::KEY)->save();
    $read = $manager->card();
    $this->assertNull($read['design_system']);
    $this->assertCount(6, $read['sources']);
  }

  /**
   * The routes: the card's adapters over the manager, with the delta.
   */
  public function testRoutes(): void {
    $this->createShipped();
    $this->createOwn('dusk', 'aurora', ['gutter' => '2rem']);
    $controller = SkinApiController::create($this->container);
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    assert($change_set instanceof MaterialsChangeSet);
    $post = static fn (?string $body = '{}'): Request => Request::create('/', 'POST', [], [], [], [], $body);

    // Copy with no active system: 422, the choose route's answer.
    $response = $controller->copy('aurora', $post());
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('STYLE_DESIGN_SYSTEM_NONE_ACTIVE', $this->payload($response)['errors'][0]['code']);
    $this->active()->activate('aurora');
    $change_set->flush();

    // The read.
    $response = $controller->skins();
    $this->assertSame(200, $response->getStatusCode());
    $read = $this->payload($response);
    $this->assertSame('aurora', $read['worn']);
    $this->assertSame(['aurora', 'dusk'], array_column($read['skins'], 'machine_name'));
    $this->assertSame(['aurora', 'dusk'], array_column($read['sources'], 'machine_name'));

    // Copy: 404 for no such skin, 400 for a malformed body, else the
    // copy's identity, worn FALSE and an empty delta (the unit names a
    // default, so nothing is worn by copying).
    $this->assertSame(404, $controller->copy('ghost', $post())->getStatusCode());
    $this->assertSame('STYLE_SKIN_NOT_FOUND', $this->payload($controller->copy('ghost', $post()))['errors'][0]['code']);
    $this->assertSame(400, $controller->copy('aurora', $post('not json'))->getStatusCode());
    $response = $controller->copy('aurora', $post(json_encode(['label' => 'Mine'])));
    $this->assertSame(200, $response->getStatusCode());
    $payload = $this->payload($response);
    $this->assertSame([
      'copied' => 'mine',
      'label' => 'Mine',
      'worn' => FALSE,
      'materials' => ['upsert' => [], 'remove' => []],
    ], $payload);
    $this->assertSame('aurora', $this->load('mine')->getDesignSystem());
    $payload = $this->payload($controller->copy('aurora', $post()));
    $this->assertSame(['aurora_copy', 'Aurora (copy)'], [$payload['copied'], $payload['label']], 'No label: the derived one.');
    $this->assertSame(['aurora', 'aurora_copy', 'dusk', 'mine'], array_column($this->payload($controller->skins())['skins'], 'machine_name'), 'The read lists the copies.');

    // The first-skin door through the route: a site's own unit with no
    // default takes the copy as its default_skin, worn TRUE and the
    // copy's token ids on the delta, as a choice's.
    DesignSystem::create(['id' => 'blank', 'label' => 'Blank'])->save();
    $this->active()->activate('blank');
    $change_set->flush();
    $response = $controller->copy('dusk', $post(json_encode(['label' => 'First'])));
    $this->assertSame(200, $response->getStatusCode());
    $payload = $this->payload($response);
    $this->assertSame(['first', 'First', TRUE], [$payload['copied'], $payload['label'], $payload['worn']]);
    $this->assertSame(['space/gutter'], $this->deltaIds($payload['materials'], 'upsert'), 'The worn copy\'s tokens ride the delta.');
    $this->assertSame([], $this->deltaIds($payload['materials'], 'remove'));
    $this->assertSame('first', $this->loadSystem('blank')->getDefaultSkin());
    $read = $this->payload($controller->skins());
    $this->assertSame(['first', 'first', 'blank'], [$read['worn'], $read['default'], $read['design_system']]);
    $this->assertFalse($this->payload($controller->copy('dusk', $post()))['worn'], 'The second copy wears nothing.');
    $this->active()->activate('aurora');
    $change_set->flush();

    // Revisions: the history with what a restore restores.
    $skin = $this->load('aurora');
    $first = (int) $skin->getRevisionId();
    $skin->setSiteTokens(['space' => ['gutter' => ['value' => '3rem']]]);
    $this->manager()->saveRevision($skin, 'Updated space/gutter.');
    $second = (int) $skin->getRevisionId();
    $this->assertSame(404, $controller->revisions('ghost')->getStatusCode());
    $payload = $this->payload($controller->revisions('aurora'));
    $this->assertSame(['aurora', 'Aurora', TRUE], [$payload['machine_name'], $payload['label'], $payload['locked']], 'Locked: a restore keeps to the site layer.');
    $this->assertSame([$second, $first], array_column($payload['revisions'], 'revision_id'));
    $this->assertFalse($this->payload($controller->revisions('dusk'))['locked'], 'The site\'s own skin restores whole.');

    // Restore: 404 for a revision that is not this skin's; else the new
    // revision and the delta, since the worn skin's tokens changed (the
    // dropped site addition reads as a remove; every token the skin
    // carries as an upsert).
    $response = $controller->restore('aurora', 999999);
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('STYLE_SKIN_REVISION_NOT_FOUND', $this->payload($response)['errors'][0]['code']);
    $this->assertSame(404, $controller->restore('ghost', $first)->getStatusCode());
    $change_set->flush();
    $response = $controller->restore('aurora', (string) $first);
    $this->assertSame(200, $response->getStatusCode());
    $payload = $this->payload($response);
    $this->assertSame($first, $payload['restored']);
    $ids = [];
    foreach (['upsert', 'remove'] as $side) {
      $ids[$side] = array_map(static fn (array $entry): string => $entry['kind'] . ':' . $entry['id'], $payload['materials'][$side]);
      sort($ids[$side]);
    }
    $this->assertSame([
      'upsert' => ['token:space/gutter', 'token:space/tight', 'token:space/wide'],
      'remove' => [],
    ], $ids, 'The first revision\'s site layer minted wide again: every token the worn skin carries rides as an upsert.');
    $this->assertSame(['space' => ['gutter' => '2rem', 'wide' => '4rem']], $this->values($this->load('aurora')->siteTokens()));

    // Revert: the site layer goes (wide reads as a remove); again is a
    // no-op with an empty delta; the site's own skin refuses.
    $change_set->flush();
    $response = $controller->revert('aurora');
    $this->assertSame(200, $response->getStatusCode());
    $payload = $this->payload($response);
    $this->assertTrue($payload['reverted']);
    $this->assertSame(['token:space/wide'], array_map(static fn (array $entry): string => $entry['kind'] . ':' . $entry['id'], $payload['materials']['remove']));
    $this->assertSame([], $this->load('aurora')->siteTokens());
    $payload = $this->payload($controller->revert('aurora'));
    $this->assertFalse($payload['reverted']);
    $this->assertSame(['upsert' => [], 'remove' => []], $payload['materials']);
    $response = $controller->revert('dusk');
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('STYLE_SKIN_NOT_SHIPPED', $this->payload($response)['errors'][0]['code']);
    $this->assertSame(404, $controller->revert('ghost')->getStatusCode());
  }

}
