<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\DesignSystem;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemUninstallValidator;
use Drupal\daedalus_blueprint\DesignSystem\UnknownDesignSystemException;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the design system unit: the config entity and its one activation.
 *
 * Pins that a package's install config lands as the entity with its
 * package dependency, that activation is one config write validated
 * against the entities (an unknown id is refused loudly, the roster memo
 * drops), that a file-shipped member's membership resolves against the
 * landed-or-shipped systems (so a package's members read the same before
 * and after its system lands), that the active system refuses deletion,
 * and that the uninstall validator keeps a package from leaving while its
 * system is active or still has members.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DesignSystemTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'daedalus_blueprint',
    'daedalus_blueprint_materials_test',
    'daedalus_blueprint_design_system_test',
  ];

  /**
   * The package module under test.
   */
  protected const PACKAGE = 'daedalus_blueprint_design_system_test';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TestMaterialsProvider::$records = NULL;
    TestMaterialsProvider::$builds = 0;
    $this->installConfig(['daedalus_blueprint', self::PACKAGE]);
  }

  /**
   * The activation service.
   */
  protected function active(): ActiveDesignSystem {
    return $this->container->get(ActiveDesignSystem::SERVICE_ID);
  }

  /**
   * The roster builder.
   */
  protected function builder(): MaterialsRosterBuilder {
    return $this->container->get('daedalus_blueprint.materials_roster_builder');
  }

  /**
   * A package's install config lands as the entity, depending on the package.
   */
  public function testInstallConfigLands(): void {
    $aurora = DesignSystem::load('aurora');
    $this->assertNotNull($aurora);
    $this->assertSame('Aurora', $aurora->label());
    $this->assertSame('A test design system: cool light on a dark ground.', $aurora->getDescription());
    $this->assertNull($aurora->getIcon());
    $this->assertSame('aurora', $aurora->getDefaultSkin());
    $this->assertSame(self::PACKAGE, $aurora->getPackage());
    $this->assertSame(DesignSystemInterface::ENFORCEMENT_STRICT, $aurora->getEnforcement(), 'A shipped system carries the enforcement of its own law.');
    $this->assertContains(self::PACKAGE, $aurora->getDependencies()['module'] ?? [], 'A packaged system depends on its package, so it leaves with the module.');

    $this->assertSame(['aurora' => self::PACKAGE], $this->active()->packages());
    $this->assertSame('aurora', $this->active()->packageOf(self::PACKAGE));
    $this->assertNull($this->active()->packageOf('system'), 'A module that packages no system ships global members.');

    // A site-built system has no package and is absent from packages();
    // its law is guarded unless it says otherwise, and the mode exports
    // with the entity (a UI edit is a config save like any other).
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->assertSame(['aurora' => self::PACKAGE], $this->active()->packages());
    $this->assertSame([], DesignSystem::load('borealis')->getDependencies());
    $this->assertSame(DesignSystemInterface::ENFORCEMENT_GUARDED, DesignSystem::load('borealis')->getEnforcement());
    $this->assertSame(DesignSystemInterface::ENFORCEMENT_GUARDED, $this->config('daedalus_blueprint.design_system.borealis')->get('enforcement'));
    DesignSystem::load('borealis')->set('enforcement', DesignSystemInterface::ENFORCEMENT_OPEN)->save();
    $this->assertSame(DesignSystemInterface::ENFORCEMENT_OPEN, DesignSystem::load('borealis')->getEnforcement());
  }

  /**
   * Activation is one config write; the roster memo drops with it.
   */
  public function testActivate(): void {
    $this->assertNull($this->active()->activeId());
    $this->assertNull($this->active()->load());
    $before = $this->builder()->build();
    $this->assertSame(1, TestMaterialsProvider::$builds);

    $this->active()->activate('aurora');

    $this->assertSame('aurora', $this->active()->activeId());
    $this->assertSame('aurora', $this->active()->load()?->id());
    $this->assertSame('aurora', $this->config(ActiveDesignSystem::CONFIG_NAME)->get(ActiveDesignSystem::KEY));
    $after = $this->builder()->build();
    $this->assertNotSame($before, $after, 'The switch drops the memoized roster.');
    $this->assertSame(2, TestMaterialsProvider::$builds);

    // A pointer naming a departed system loads nothing rather than failing.
    $this->config(ActiveDesignSystem::CONFIG_NAME)->set(ActiveDesignSystem::KEY, 'gone')->save();
    $this->assertSame('gone', $this->active()->activeId());
    $this->assertNull($this->active()->load());
  }

  /**
   * Activating an unknown id is refused, naming the known ids.
   */
  public function testUnknownIdRefused(): void {
    try {
      $this->active()->activate('nope');
      $this->fail('An unknown design system was activated.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('No design system "nope"; the site has aurora.', $e->getMessage());
    }
    $this->assertNull($this->active()->activeId(), 'A refused activation writes nothing.');

    DesignSystem::load('aurora')->delete();
    try {
      $this->active()->activate('aurora');
      $this->fail('A deleted design system was activated.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('No design system "aurora"; the site has none.', $e->getMessage());
    }
  }

  /**
   * A module that ships a system is a shipped package before it lands.
   *
   * The landed set (packages(), packageOf()) follows the entity and is
   * the truth for an act on it; the shipped set is read off disk and
   * follows the module list, so it answers while the system has not
   * landed (a recipe installs the module before it imports the config)
   * and after it has left. packagedSystemOf() reads the landed set first
   * and the shipped set behind it, so a lane that must not act on a
   * package before its system is active has one answer in both states.
   */
  public function testShippedPackages(): void {
    $this->assertSame(['aurora' => self::PACKAGE], $this->active()->shippedPackages());
    $this->assertSame(['aurora' => self::PACKAGE], $this->active()->packages());
    $this->assertSame('aurora', $this->active()->packagedSystemOf(self::PACKAGE));
    $this->assertNull($this->active()->packagedSystemOf('system'));

    // The system has not landed (or has left): the landed set is empty
    // while the disk still says the module packages aurora.
    DesignSystem::load('aurora')->delete();
    $this->assertSame([], $this->active()->packages());
    $this->assertNull($this->active()->packageOf(self::PACKAGE));
    $this->assertSame('aurora', $this->active()->packagedSystemOf(self::PACKAGE));
    $this->assertSame(['aurora' => self::PACKAGE], $this->active()->shippedPackages());
    $this->assertSame(['aurora' => self::PACKAGE], $this->container->get(ActiveDesignSystem::SERVICE_ID)->shippedPackages(), 'The memo answers the same.');

    // A site-built system ships no file; a module shipping no system is
    // no shipped package either.
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->assertSame(['aurora' => self::PACKAGE], $this->active()->shippedPackages());
    $this->assertNotContains('daedalus_blueprint_materials_test', $this->active()->shippedPackages());
  }

  /**
   * A shipped member's membership reads the same before and after landing.
   *
   * The one disk-lane rule (shippedMembership()) resolves both of its
   * branches against the landed-or-shipped systems: a package's absent-key
   * member and its explicit-key member both belong to the package's
   * system while that system's install config is still on disk (the
   * recipe window, reproduced by deleting the entity), `~` opts out, a
   * packageless module's absent key is global, and an id nothing landed
   * and nothing ships refuses, listing the ids a member may name. The
   * landed-only list stays with activate(), where a pending id is no
   * answer: there is no entity to activate.
   */
  public function testMembershipResolvesLandedOrShipped(): void {
    $assert_window = function (string $state): void {
      $active = $this->active();
      $this->assertSame('aurora', $active->shippedMembership(self::PACKAGE, ['id' => 'band']), "An absent key is the package's system ($state).");
      $named = ['id' => 'band', 'design_system' => 'aurora'];
      $this->assertSame('aurora', $active->shippedMembership(self::PACKAGE, $named), "An explicit key naming the package's system resolves ($state).");
      $this->assertSame('aurora', $active->shippedMembership('system', $named), "Any installed module may name a shipped system ($state).");
      $this->assertNull($active->shippedMembership(self::PACKAGE, ['id' => 'band', 'design_system' => NULL]), "A null key opts out ($state).");
      $this->assertNull($active->shippedMembership('system', ['id' => 'band']), "A packageless module's absent key is global ($state).");
      try {
        $active->shippedMembership(self::PACKAGE, ['id' => 'band', 'design_system' => 'nope']);
        $this->fail("A member of an unshipped, unlanded system was accepted ($state).");
      }
      catch (UnknownDesignSystemException $e) {
        $this->assertSame('nope', $e->designSystem);
        $this->assertContains('aurora', $e->known, "The refusal lists the shipped system ($state).");
        $this->assertSame('No design system "nope"; the site has aurora.', $e->getMessage());
      }
    };

    $this->assertSame(['aurora'], $this->active()->nameableIds());
    $assert_window('landed');

    // The recipe window: the module is installed, its system's config is
    // still on disk. Membership answers the same; activation cannot.
    DesignSystem::load('aurora')->delete();
    $this->assertSame([], $this->active()->knownIds());
    $this->assertSame(['aurora'], $this->active()->nameableIds());
    $assert_window('pending');
    try {
      $this->active()->activate('nope');
      $this->fail('An unknown design system was activated.');
    }
    catch (UnknownDesignSystemException $e) {
      $this->assertSame([], $e->known, 'activate() lists only the landed systems.');
      $this->assertSame('No design system "nope"; the site has none.', $e->getMessage());
    }

    // A site-built system ships no file and is nameable only while it
    // exists; its deletion turns the membership back into a refusal.
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->assertSame(['aurora', 'borealis'], $this->active()->nameableIds());
    $site_built = ['id' => 'band', 'design_system' => 'borealis'];
    $this->assertSame('borealis', $this->active()->shippedMembership('system', $site_built));
    DesignSystem::load('borealis')->delete();
    $this->expectException(UnknownDesignSystemException::class);
    $this->expectExceptionMessage('No design system "borealis"; the site has aurora.');
    $this->active()->shippedMembership('system', $site_built);
  }

  /**
   * Two systems naming one package cannot say whose the shipped members are.
   */
  public function testAmbiguousPackageRefused(): void {
    DesignSystem::create(['id' => 'aurora_dark', 'label' => 'Aurora dark', 'package' => self::PACKAGE])->save();
    $this->assertSame(['aurora' => self::PACKAGE, 'aurora_dark' => self::PACKAGE], $this->active()->packages());
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Module "daedalus_blueprint_design_system_test" is the package of 2 design systems (aurora, aurora_dark); a module packages at most one.');
    $this->active()->packageOf(self::PACKAGE);
  }

  /**
   * The active system refuses deletion; an inactive one goes.
   */
  public function testActiveSystemRefusesDeletion(): void {
    $this->active()->activate('aurora');
    try {
      DesignSystem::load('aurora')->delete();
      $this->fail('The active design system was deleted.');
    }
    catch (\LogicException $e) {
      $this->assertSame('The design system "aurora" is active and cannot be deleted; activate another design system first.', $e->getMessage());
    }
    $this->assertNotNull(DesignSystem::load('aurora'));

    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->active()->activate('borealis');
    DesignSystem::load('aurora')->delete();
    $this->assertNull(DesignSystem::load('aurora'));
  }

  /**
   * A package may not uninstall while its system is active or has members.
   */
  public function testUninstallValidator(): void {
    $validator = $this->container->get('daedalus_blueprint.design_system_uninstall_validator');
    $this->assertInstanceOf(DesignSystemUninstallValidator::class, $validator);
    $reasons = static fn (array $reasons): array => array_map('strval', $reasons);

    // Inactive, no members: the package may go.
    $this->assertSame([], $validator->validate(self::PACKAGE));
    $this->assertSame([], $validator->validate('daedalus_blueprint_materials_test'), 'A module that packages no system is not this validator\'s concern.');

    // Active: refused, through the module installer's wiring too.
    $this->active()->activate('aurora');
    $this->assertSame(['The design system <em class="placeholder">aurora</em> it packages is active; activate another design system first.'], $reasons($validator->validate(self::PACKAGE)));
    $installer_reasons = $this->container->get('module_installer')->validateUninstall([self::PACKAGE]);
    $this->assertArrayHasKey(self::PACKAGE, $installer_reasons);
    $this->assertContains('The design system <em class="placeholder">aurora</em> it packages is active; activate another design system first.', $reasons($installer_reasons[self::PACKAGE]));

    // Inactive but with members still referencing it: refused with the count.
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->active()->activate('borealis');
    TestMaterialsProvider::$records = [
      new MaterialRecord(kind: MaterialKind::Swatch, id: 'ink', label: 'Ink', spellings: ['style' => 'var(--swatch-ink)']),
      new MaterialRecord(kind: MaterialKind::Swatch, id: 'glow', label: 'Glow', spellings: ['style' => 'var(--swatch-glow)'], designSystem: 'aurora'),
    ];
    $this->assertSame(['The design system <em class="placeholder">aurora</em> it packages still has 1 member; remove the member or make it global first.'], $reasons($validator->validate(self::PACKAGE)));
    TestMaterialsProvider::$records[] = new MaterialRecord(kind: MaterialKind::Variant, id: 'band', label: 'Band', spellings: ['class' => 'band'], scope: Address::fromString('kind:container'), designSystem: 'aurora');
    $this->assertSame(['The design system <em class="placeholder">aurora</em> it packages still has 2 members; remove them or make them global first.'], $reasons($validator->validate(self::PACKAGE)));

    // Members gone: the package may go.
    TestMaterialsProvider::$records = [];
    $this->assertSame([], $validator->validate(self::PACKAGE));
  }

}
