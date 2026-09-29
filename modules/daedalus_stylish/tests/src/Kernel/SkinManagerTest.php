<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the active skin: derived from the active design system.
 *
 * The site activates a design system (daedalus_blueprint.settings
 * active_design_system) and wears its default_skin; daedalus_stylish stores no
 * skin pointer of its own. SkinManager is the one reader of that
 * derivation, tolerant of a system whose skin is not there (a recipe's
 * activation lands before the skins lane syncs; a deleted skin leaves its
 * system naming nothing) and of a system carrying no skin at all.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SkinManagerTest extends KernelTestBase {

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
    $this->installEntitySchema('daedalus_skin');
    // A switch recompiles the roster-fed files, whose provider reads all
    // four storages.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_blueprint', 'daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * The skin manager.
   */
  protected function skinManager(): SkinManager {
    return $this->container->get('daedalus_stylish.skin_manager');
  }

  /**
   * The activation service.
   */
  protected function active(): ActiveDesignSystem {
    return $this->container->get(ActiveDesignSystem::SERVICE_ID);
  }

  /**
   * Activation derives the skin; daedalus_stylish holds no pointer of its own.
   */
  public function testActiveSkinIsTheActiveSystemsDefaultSkin(): void {
    $manager = $this->skinManager();
    $this->assertNull($manager->activeSkinName());
    $this->assertNull($manager->loadActiveSkin());

    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora', 'default_skin' => 'aurora'])->save();
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();

    // Activating before the skin exists (a recipe's config precedes the
    // skins lane's content): the name is known, the skin is not.
    $this->active()->activate('aurora');
    $this->assertSame('aurora', $manager->activeSkinName());
    $this->assertNull($manager->loadActiveSkin());

    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora']);
    $skin->setDefaults(['kind:container' => ['' => ['default' => ['padding' => '2rem']]]])->save();
    $this->assertSame($skin->id(), $manager->loadActiveSkin()?->id());

    // A system carrying no skin (stencils and doctrine only) wears none.
    $this->active()->activate('borealis');
    $this->assertNull($manager->activeSkinName());
    $this->assertNull($manager->loadActiveSkin());

    // Switching back derives the skin again; deleting the skin leaves the
    // system naming nothing, which delivery treats as "no skin".
    $this->active()->activate('aurora');
    $this->assertSame('aurora', $manager->activeSkinName());
    $skin->delete();
    $this->assertSame('aurora', $manager->activeSkinName());
    $this->assertNull($manager->loadActiveSkin());
  }

}
