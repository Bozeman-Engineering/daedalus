<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\SkinStylesheet;
use Drupal\daedalus_stylish\VariantStylesheet;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the design-system switch: delivery and the delta follow it.
 *
 * Activating a system is one config write (daedalus_blueprint.settings);
 * DesignSystemSwitchSubscriber reacts to it, so every writer (the op,
 * the admin form, drush config-set, config sync) restyles the site the
 * same way: the skin and variants files recompile to the newly
 * active system's members plus the global ones, and both systems' token
 * and member ids ride the request's materials delta (the old system's
 * resolve to removes, the new system's to upserts). Every activation
 * first syncs every enabled module's shipped definitions under the
 * incoming law (ShippedSync::onDesignSystemActivated()), so the files
 * and the delta carry the members the site's law refused while a
 * packaged system was inactive; the globals daedalus_stylish itself ships
 * converge in setUp the way a real site's install lands them, so a
 * switch's delta is the two systems' own ids plus every published
 * variant (a sweep is a law change and the conformance verdict a
 * projection of the law, so each variant rides, resolved against the
 * fresh roster: offered to an upsert, withheld or inactive to a remove).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DesignSystemSwitchSubscriberTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'filter',
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
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->installConfig(['daedalus_blueprint', 'daedalus_stylish']);
    // The importers switch to an administrative account: give them one.
    $this->setUpCurrentUser([], [], TRUE);
    // enableModules() skips install hooks: land daedalus_stylish's own shipped
    // globals the way hook_modules_installed() does on a real site, so
    // the activations below find them converged and a switch's delta is
    // the two systems' own ids.
    $this->container->get('daedalus_stylish.shipped_sync')->onInstall(['daedalus_stylish']);
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        // The packaged aurora skin mints color/accent: the fixture replaces
        // the suite's site lane, so the scale is declared here.
        'color' => ['label' => 'Color', 'shape' => 'color'],
      ],
    ]);
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['variant_axes' => ['container_layout']],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * Ships a design system with a member skin and variant.
   *
   * The skin carries a space and a color token.
   */
  protected function createSystem(string $id, string $token): void {
    DesignSystem::create(['id' => $id, 'label' => ucfirst($id), 'default_skin' => $id])->save();
    $skin = DaedalusStylishSkin::create(['label' => ucfirst($id), 'machine_name' => $id, 'design_system' => $id]);
    $skin->setPackageTokens(['space' => [$token => '1rem'], 'color' => [$id => '#6941c6']])->save();
    $variant = DaedalusStylishVariant::create([
      'label' => ucfirst($id) . ' section',
      'machine_name' => $id . '_section',
      'scope' => 'kind:container',
      'design_system' => $id,
    ]);
    $variant->setStyles(['' => ['default' => ['padding' => '1rem']]])->save();
  }

  /**
   * The compiled file's CSS, '' when no file is compiled.
   */
  protected function css(string $state_key): string {
    $uri = $this->container->get('state')->get($state_key);
    return $uri === NULL ? '' : (string) file_get_contents($uri);
  }

  /**
   * The delta's ids as "kind:id" strings per side, sorted.
   */
  protected function deltaIds(array $delta): array {
    $ids = [];
    foreach (['upsert', 'remove'] as $side) {
      $ids[$side] = array_map(static fn (array $entry): string => $entry['kind'] . ':' . $entry['id'], $delta[$side]);
      sort($ids[$side]);
    }
    return $ids;
  }

  /**
   * A switch recompiles both roster-fed files and touches both systems.
   */
  public function testSwitchRecompilesAndTouchesBothSystems(): void {
    $this->createSystem('aurora', 'gutter');
    $this->createSystem('borealis', 'section');
    $global = DaedalusStylishVariant::create([
      'label' => 'Plain section',
      'machine_name' => 'plain_section',
      'scope' => 'kind:container',
    ]);
    $global->setStyles(['' => ['default' => ['padding' => '2rem']]])->save();
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    assert($change_set instanceof MaterialsChangeSet);
    $active = $this->container->get(ActiveDesignSystem::SERVICE_ID);

    // No system active: no skin, the global variant alone.
    $this->assertSame('', $this->css(SkinStylesheet::STATE_KEY));
    $variants = $this->css(VariantStylesheet::STATE_KEY);
    $this->assertStringContainsString('.daedalus-stylish-variant--plain_section', $variants);
    $this->assertStringNotContainsString('.daedalus-stylish-variant--aurora_section', $variants);

    // Activating aurora lands its members in both files and upserts
    // them on the delta; nothing was active before, so no token leaves.
    // Every published variant rides each sweep (the law changed, so the
    // verdict may have): the global as an upsert, the inactive system's
    // member as a remove.
    $change_set->flush();
    $active->activate('aurora');
    $this->assertSame('aurora', $this->container->get('daedalus_stylish.skin_manager')->activeSkinName());
    $this->assertStringContainsString('--space-gutter: 1rem;', $this->css(SkinStylesheet::STATE_KEY));
    $variants = $this->css(VariantStylesheet::STATE_KEY);
    $this->assertStringContainsString('.daedalus-stylish-variant--aurora_section', $variants);
    $this->assertStringContainsString('.daedalus-stylish-variant--plain_section', $variants);
    $this->assertStringNotContainsString('.daedalus-stylish-variant--borealis_section', $variants);
    $this->assertStringContainsString('--color-aurora: #6941c6;', $this->css(SkinStylesheet::STATE_KEY));
    $this->assertSame([
      'upsert' => ['token:color/aurora', 'token:space/gutter', 'variant:aurora_section', 'variant:plain_section'],
      'remove' => ['variant:borealis_section'],
    ], $this->deltaIds($change_set->flush()));

    // Switching to borealis: aurora's members leave every file and the
    // delta, borealis's arrive; the global variant stays throughout (and
    // rides as an upsert each time).
    $active->activate('borealis');
    $this->assertSame('borealis', $this->container->get('daedalus_stylish.skin_manager')->activeSkinName());
    $skin = $this->css(SkinStylesheet::STATE_KEY);
    $this->assertStringContainsString('--space-section: 1rem;', $skin);
    $this->assertStringNotContainsString('--space-gutter', $skin);
    $variants = $this->css(VariantStylesheet::STATE_KEY);
    $this->assertStringContainsString('.daedalus-stylish-variant--borealis_section', $variants);
    $this->assertStringContainsString('.daedalus-stylish-variant--plain_section', $variants);
    $this->assertStringNotContainsString('.daedalus-stylish-variant--aurora_section', $variants);
    $this->assertSame([
      'upsert' => ['token:color/borealis', 'token:space/section', 'variant:borealis_section', 'variant:plain_section'],
      'remove' => ['token:color/aurora', 'token:space/gutter', 'variant:aurora_section'],
    ], $this->deltaIds($change_set->flush()));

    // Deactivating: the global variant alone again, borealis's ids leave
    // (and aurora's member, inactive still, resolves to a remove again).
    $this->config(ActiveDesignSystem::CONFIG_NAME)->set(ActiveDesignSystem::KEY, NULL)->save();
    $this->assertSame('', $this->css(SkinStylesheet::STATE_KEY));
    $variants = $this->css(VariantStylesheet::STATE_KEY);
    $this->assertStringContainsString('.daedalus-stylish-variant--plain_section', $variants);
    $this->assertStringNotContainsString('.daedalus-stylish-variant--borealis_section', $variants);
    $this->assertSame([
      'upsert' => ['variant:plain_section'],
      'remove' => ['token:color/borealis', 'token:space/section', 'variant:aurora_section', 'variant:borealis_section'],
    ], $this->deltaIds($change_set->flush()));

    // A daedalus_stylish.settings save is not a switch and not a choice:
    // nothing rides the delta (both files recompile, byte-identical).
    $this->config('daedalus_stylish.settings')->set('container_roots', FALSE)->save();
    $this->assertTrue($change_set->isEmpty());
  }

  /**
   * Activating a packaged system syncs its package before the compiles.
   *
   * A package syncs only under its own law: the importers defer it whole while
   * its system is inactive (the daedalus_stylish_design_system_test package
   * ships a variant on an axis only its own declaration adds, aurora_rhythm,
   * and a package's declarations layer only while its system is active), so
   * nothing of it exists when the package lands with nothing active, and the
   * activation lands all of it. The sync runs after the roster reset and before
   * the ids are touched and the files compiled, so the three files and the
   * delta carry every member at once; the switch to a site-built system takes
   * them out again.
   */
  public function testActivatingPackagedSystemSyncsItsPackage(): void {
    $this->enableModules(['daedalus_stylish_design_system_test']);
    // setUp's container override lists an axis, and lists replace across
    // layers: keep the kind (its groups) and leave the axes to the
    // package's own declaration, the law under test.
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['property_groups' => ['spacing' => [], 'background' => [], 'motion' => []]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    assert($change_set instanceof MaterialsChangeSet);
    $active = $this->container->get(ActiveDesignSystem::SERVICE_ID);
    $storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');

    // Landing with nothing active: the package is deferred whole, so no
    // member exists and none is delivered.
    $this->installConfig(['daedalus_stylish_design_system_test']);
    $this->assertSame([], $storage->loadByProperties(['machine_name' => 'aurora_section']));
    $this->assertSame([], $storage->loadByProperties(['machine_name' => 'aurora_rhythm']));
    $this->assertStringNotContainsString('.daedalus-stylish-variant--aurora_section', $this->css(VariantStylesheet::STATE_KEY));

    // Activation lands the package whole in all three files and on the delta.
    $change_set->flush();
    $active->activate('aurora');
    $this->assertNotEmpty($storage->loadByProperties(['machine_name' => 'aurora_section']));
    $this->assertNotEmpty($storage->loadByProperties(['machine_name' => 'aurora_rhythm']));
    $this->assertStringContainsString('--space-gutter: 2rem;', $this->css(SkinStylesheet::STATE_KEY));
    $variants = $this->css(VariantStylesheet::STATE_KEY);
    $this->assertStringContainsString('.daedalus-stylish-variant--aurora_section', $variants);
    $this->assertStringContainsString('.daedalus-stylish-variant--aurora_rhythm', $variants);
    $this->assertStringContainsString('--color-accent: #6941c6;', $this->css(SkinStylesheet::STATE_KEY));
    $members = [
      'animation:aurora_fade',
      'token:color/accent',
      'token:space/gutter',
      'variant:aurora_rhythm',
      'variant:aurora_section',
    ];
    $this->assertSame(['upsert' => $members, 'remove' => []], $this->deltaIds($change_set->flush()));

    // A site-built system packages nothing: the switch's sync finds the
    // site converged and every member of aurora leaves.
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $active->activate('borealis');
    $variants = $this->css(VariantStylesheet::STATE_KEY);
    $this->assertStringNotContainsString('.daedalus-stylish-variant--aurora_section', $variants);
    $this->assertStringNotContainsString('.daedalus-stylish-variant--aurora_rhythm', $variants);
    $this->assertSame(['upsert' => [], 'remove' => $members], $this->deltaIds($change_set->flush()));
  }

  /**
   * The skin a system wears follows the site's choice, on every switch.
   *
   * A choice (daedalus_stylish.settings skin_choice.{system}) swaps the
   * active skin's tokens the way a switch swaps a system's members: the
   * defaults file recompiles to the chosen skin and both skins' token
   * ids ride the delta (DefaultsSettingsSubscriber). A switch away and
   * back touches the CHOSEN skin's ids, never the default's, and a
   * choice recorded for an inactive system changes nothing worn.
   */
  public function testChoiceFollowsTheSystem(): void {
    $this->createSystem('aurora', 'gutter');
    $this->createSystem('borealis', 'section');
    $dusk = DaedalusStylishSkin::create(['label' => 'Dusk', 'machine_name' => 'dusk', 'design_system' => 'aurora']);
    $dusk->setPackageTokens(['space' => ['dusk' => '2rem']])->save();
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    assert($change_set instanceof MaterialsChangeSet);
    $active = $this->container->get(ActiveDesignSystem::SERVICE_ID);
    $manager = $this->container->get('daedalus_stylish.skin_manager');

    $active->activate('aurora');
    $this->assertStringContainsString('--space-gutter: 1rem;', $this->css(SkinStylesheet::STATE_KEY));
    $change_set->flush();
    $manager->choose('dusk');
    $this->assertSame('dusk', $manager->activeSkinName());
    $css = $this->css(SkinStylesheet::STATE_KEY);
    $this->assertStringContainsString('--space-dusk: 2rem;', $css);
    $this->assertStringNotContainsString('--space-gutter', $css);
    // A choice is a law change too, so every published variant rides it:
    // aurora's member offered (an upsert), borealis's inactive (a remove).
    $this->assertSame([
      'upsert' => ['token:space/dusk', 'variant:aurora_section'],
      'remove' => ['token:color/aurora', 'token:space/gutter', 'variant:borealis_section'],
    ], $this->deltaIds($change_set->flush()));

    // Away: the chosen skin's tokens leave with aurora's members.
    $active->activate('borealis');
    $this->assertStringContainsString('--space-section: 1rem;', $this->css(SkinStylesheet::STATE_KEY));
    $this->assertSame([
      'upsert' => ['token:color/borealis', 'token:space/section', 'variant:borealis_section'],
      'remove' => ['token:space/dusk', 'variant:aurora_section'],
    ], $this->deltaIds($change_set->flush()));

    // Back: the choice holds, and it is the chosen skin that arrives.
    $active->activate('aurora');
    $css = $this->css(SkinStylesheet::STATE_KEY);
    $this->assertStringContainsString('--space-dusk: 2rem;', $css);
    $this->assertStringNotContainsString('--space-gutter', $css);
    $this->assertSame([
      'upsert' => ['token:space/dusk', 'variant:aurora_section'],
      'remove' => ['token:color/borealis', 'token:space/section', 'variant:borealis_section'],
    ], $this->deltaIds($change_set->flush()));

    // A choice for the inactive system: nothing worn changes, nothing
    // rides the delta, the file is byte-identical.
    $this->config('daedalus_stylish.settings')->set('skin_choice.borealis', 'borealis')->save();
    $this->assertTrue($change_set->isEmpty());
    $this->assertStringContainsString('--space-dusk: 2rem;', $this->css(SkinStylesheet::STATE_KEY));
  }

}
