<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the auto-managed style map field lifecycle and value round trip.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishFieldManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    // A type with bundles but no bundle entity type: the Edit Mode
    // setting has no carrier on it.
    'entity_test',
    'block',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    // daedalus_ui (and its dependency chain) provides the config schema
    // for the per-bundle `enabled` third-party setting the default-on gate
    // reads.
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * The field manager under test.
   */
  protected DaedalusStylishFieldManager $fieldManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('entity_test');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['field']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->fieldManager = $this->container->get('daedalus_stylish.field_manager');
  }

  /**
   * Enable/disable manages field storage and per-bundle fields.
   */
  public function testEnableDisableLifecycle(): void {
    $this->assertFalse($this->fieldManager->isEnabled('node', 'page'));

    $this->fieldManager->enable('node', 'page');
    $this->assertTrue($this->fieldManager->isEnabled('node', 'page'));
    $this->assertFalse($this->fieldManager->isEnabled('node', 'article'));

    // The shared storage is locked against Field UI.
    $storage = FieldStorageConfig::loadByName('node', DaedalusStylishFieldManager::FIELD_NAME);
    $this->assertNotNull($storage);
    $this->assertTrue($storage->isLocked());

    // Enabling twice is a no-op, not an error.
    $this->fieldManager->enable('node', 'page');
    $this->assertTrue($this->fieldManager->isEnabled('node', 'page'));

    // Storage survives while another bundle still uses it.
    $this->fieldManager->enable('node', 'article');
    $this->fieldManager->disable('node', 'page');
    $this->assertFalse($this->fieldManager->isEnabled('node', 'page'));
    $this->assertNotNull(FieldStorageConfig::loadByName('node', DaedalusStylishFieldManager::FIELD_NAME));

    // The last disable removes the storage: no schema on a disabled type.
    $this->fieldManager->disable('node', 'article');
    $this->assertNull(FieldStorageConfig::loadByName('node', DaedalusStylishFieldManager::FIELD_NAME));

    // Disabling recorded both opt-outs; re-enabling clears the record.
    $this->assertTrue($this->fieldManager->isOptedOut('node', 'page'));
    $this->assertTrue($this->fieldManager->isOptedOut('node', 'article'));
    $this->fieldManager->enable('node', 'page');
    $this->assertFalse($this->fieldManager->isOptedOut('node', 'page'));
    $this->assertTrue($this->fieldManager->isOptedOut('node', 'article'));
  }

  /**
   * Styling auto-enables when the LB + Edit Mode conjunction becomes true.
   *
   * Covers both gate orders: Edit Mode enabled last (the gate check fires at
   * the bundle save) and Layout Builder enabled last (it fires at the
   * display save).
   */
  public function testAutoEnableOnGateCarrierSaves(): void {
    // Page: LB first. Enabling LB alone doesn't auto-enable.
    $this->enableLayoutBuilderDisplay('page');
    $this->assertFalse($this->fieldManager->isEnabled('node', 'page'));

    // Edit Mode second — the conjunction becomes true at the bundle save.
    $this->setEditModeStatus('page', TRUE);
    $this->assertTrue($this->fieldManager->isEnabled('node', 'page'));

    // Article: Edit Mode first. Alone it doesn't auto-enable.
    $this->setEditModeStatus('article', TRUE);
    $this->assertFalse($this->fieldManager->isEnabled('node', 'article'));

    // LB second — the conjunction becomes true at the display save.
    $this->enableLayoutBuilderDisplay('article');
    $this->assertTrue($this->fieldManager->isEnabled('node', 'article'));
  }

  /**
   * A deliberate opt-out sticks: re-saves AND gate re-toggles never re-default.
   */
  public function testOptOutSticks(): void {
    $this->enableLayoutBuilderDisplay('page');
    $this->setEditModeStatus('page', TRUE);
    $this->assertTrue($this->fieldManager->isEnabled('node', 'page'));

    // The admin unchecks "Enable styling": the opt-out is recorded.
    $this->fieldManager->disable('node', 'page');
    $this->assertTrue($this->fieldManager->isOptedOut('node', 'page'));

    // Ordinary re-saves of both gate carriers don't re-default.
    NodeType::load('page')->save();
    LayoutBuilderEntityViewDisplay::load('node.page.default')->save();
    $this->assertFalse($this->fieldManager->isEnabled('node', 'page'));

    // Neither does re-toggling a gate (the old transition-era escape hatch).
    $this->setEditModeStatus('page', FALSE);
    $this->setEditModeStatus('page', TRUE);
    $this->assertFalse($this->fieldManager->isEnabled('node', 'page'));

    // Explicitly re-enabling clears the record: the admin changed their mind
    // and the bundle behaves like any other gated-on bundle again.
    $this->fieldManager->enable('node', 'page');
    $this->assertTrue($this->fieldManager->isEnabled('node', 'page'));
    $this->assertFalse($this->fieldManager->isOptedOut('node', 'page'));
  }

  /**
   * Field absence without an opt-out record is NOT a memory: it converges ON.
   *
   * A bundle that missed its enablement moment (hooks not around, exotic
   * install pipeline) defaults ON at the next gate-carrier save.
   */
  public function testMissedEnablementConvergesOnNextSave(): void {
    $this->enableLayoutBuilderDisplay('page');
    $this->setEditModeStatus('page', TRUE);
    $this->assertTrue($this->fieldManager->isEnabled('node', 'page'));

    // Simulate the field having gone missing WITHOUT a deliberate opt-out
    // (delete the config directly, bypassing disable()).
    \Drupal::configFactory()->getEditable('field.field.node.page.daedalus_stylish_styles')->delete();
    \Drupal::configFactory()->getEditable('field.storage.node.daedalus_stylish_styles')->delete();
    $this->container->get('entity_type.manager')->getStorage('field_config')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('field_storage_config')->resetCache();
    $this->assertFalse($this->fieldManager->isEnabled('node', 'page'));
    $this->assertFalse($this->fieldManager->isOptedOut('node', 'page'));

    // An ordinary re-save of a gate carrier re-defaults styling ON.
    NodeType::load('page')->save();
    $this->assertTrue($this->fieldManager->isEnabled('node', 'page'));
  }

  /**
   * The syncAll() defaults every gate-passing bundle ON, respecting opt-outs.
   *
   * The install-time / update-time sweep for gates that opened while
   * Daedalus Stylish's hooks weren't around to see the saves.
   */
  public function testSyncAll(): void {
    // Open both bundles' gates under config sync, where nothing auto-enables
    // (the stand-in for "the gate opened before Daedalus Stylish existed").
    foreach (['page', 'article'] as $bundle) {
      $display = LayoutBuilderEntityViewDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ])->enableLayoutBuilder()->setOverridable();
      $display->setSyncing(TRUE);
      $display->save();
      $node_type = NodeType::load($bundle);
      $node_type->setThirdPartySetting('daedalus_ui', 'enabled', TRUE);
      $node_type->setSyncing(TRUE);
      $node_type->save();
    }
    $this->assertFalse($this->fieldManager->isEnabled('node', 'page'));
    $this->assertFalse($this->fieldManager->isEnabled('node', 'article'));

    // Article deliberately opted out at some point.
    $this->fieldManager->disable('node', 'article');

    $this->fieldManager->syncAll();
    $this->assertTrue($this->fieldManager->isEnabled('node', 'page'));
    $this->assertFalse($this->fieldManager->isEnabled('node', 'article'));
  }

  /**
   * A type with no bundle entity type has no Edit Mode gate to pass.
   *
   * The per-bundle Edit Mode status lives on the bundle config entity
   * (daedalus_ui), so a bundle-less type (entity_test here, the blueprint
   * type on a real site) has no carrier for it; ShouldNotEditMode treats
   * that as no veto, and the field manager must read the same setting the
   * same way: Edit Mode counts as enabled and Layout Builder alone opens
   * styling at the display save.
   */
  public function testBundleLessTypeCountsAsEditModeEnabled(): void {
    $this->assertTrue($this->fieldManager->editModeEnabled('entity_test', 'entity_test'));
    $this->assertFalse($this->fieldManager->isEnabled('entity_test', 'entity_test'));

    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    $this->assertTrue($this->fieldManager->isEnabled('entity_test', 'entity_test'));
    $this->assertArrayHasKey('style entity_test entity_test elements', $this->container->get('user.permissions')->getPermissions());

    // The opt-out is the one memory, as for any bundle.
    $this->fieldManager->disable('entity_test', 'entity_test');
    LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default')->save();
    $this->assertFalse($this->fieldManager->isEnabled('entity_test', 'entity_test'));
  }

  /**
   * An applied recipe sweeps the gate.
   *
   * A recipe installs modules and imports config under the syncing flag,
   * so neither hook_install's sweep nor the gate-carrier hooks fire for
   * what it lands; the sweep after each applied recipe converges the
   * bundles whose gate the recipe opened, opt-outs respected.
   */
  public function testRecipeAppliedSweepsGate(): void {
    foreach (['page', 'article'] as $bundle) {
      $display = LayoutBuilderEntityViewDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ])->enableLayoutBuilder()->setOverridable();
      $display->setSyncing(TRUE);
      $display->save();
      $node_type = NodeType::load($bundle);
      $node_type->setThirdPartySetting('daedalus_ui', 'enabled', TRUE);
      $node_type->setSyncing(TRUE);
      $node_type->save();
    }
    $this->fieldManager->disable('node', 'article');
    $this->assertFalse($this->fieldManager->isEnabled('node', 'page'));

    $directory = $this->siteDirectory . '/gate_sweep_recipe';
    mkdir($directory, 0777, TRUE);
    file_put_contents($directory . '/recipe.yml', "name: 'Gate sweep'\n");
    RecipeRunner::processRecipe(Recipe::createFromDirectory($directory));

    $this->assertTrue($this->fieldManager->isEnabled('node', 'page'));
    $this->assertFalse($this->fieldManager->isEnabled('node', 'article'));
  }

  /**
   * Config sync never auto-creates the field.
   */
  public function testConfigSyncDoesNotAutoEnable(): void {
    $this->enableLayoutBuilderDisplay('page');

    $node_type = NodeType::load('page');
    $node_type->setThirdPartySetting('daedalus_ui', 'enabled', TRUE);
    $node_type->setSyncing(TRUE);
    $node_type->save();

    $this->assertFalse($this->fieldManager->isEnabled('node', 'page'));
  }

  /**
   * Enables Layout Builder on a node bundle's default view display.
   */
  protected function enableLayoutBuilderDisplay(string $bundle): void {
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => $bundle,
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
  }

  /**
   * Turns the page builder on or off for a bundle (daedalus_ui `enabled`).
   */
  protected function setEditModeStatus(string $bundle, bool $enabled): void {
    $node_type = NodeType::load($bundle);
    $node_type->setThirdPartySetting('daedalus_ui', 'enabled', $enabled);
    $node_type->save();
  }

  /**
   * The count of a bundle's styled entities: what disabling deletes.
   */
  public function testCountStyled(): void {
    $this->fieldManager->enable('node', 'page');
    foreach (['one', 'two'] as $title) {
      $node = Node::create(['type' => 'page', 'title' => $title]);
      $node->get(DaedalusStylishFieldManager::FIELD_NAME)->appendItem()->setStyleMap([
        'block:d4e5f6' => ['default' => ['padding' => '2rem']],
      ]);
      $node->save();
    }
    Node::create(['type' => 'page', 'title' => 'unstyled'])->save();

    $this->assertSame(2, $this->fieldManager->countStyled('node', 'page'));
    // Another bundle's entities are not this bundle's; a bundle with no
    // field stores nothing.
    $this->assertSame(0, $this->fieldManager->countStyled('node', 'article'));
    $this->fieldManager->disable('node', 'page');
    $this->assertSame(0, $this->fieldManager->countStyled('node', 'page'));
  }

  /**
   * The style map round-trips through save/load and empties cleanly.
   */
  public function testStyleMapRoundTrip(): void {
    $this->fieldManager->enable('node', 'page');

    $map = [
      'block:d4e5f6' => [
        'default' => ['padding' => '2rem', 'background-color' => '#f5f5f5'],
        'narrow' => ['padding' => '1rem'],
      ],
      'block:d4e5f6::.testimonial__quote' => [
        'default' => ['font-size' => '1.25rem'],
      ],
      'block:a0b1c2' => ['variants' => [['variant' => 'cta_bold', 'fragment' => '']]],
    ];

    $node = Node::create(['type' => 'page', 'title' => 'Styled page']);
    $node->get(DaedalusStylishFieldManager::FIELD_NAME)->first() ?? $node->get(DaedalusStylishFieldManager::FIELD_NAME)->appendItem();
    $node->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->setStyleMap($map);
    $node->save();

    $reloaded = Node::load($node->id());
    $this->assertSame($map, $reloaded->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap());

    // An empty map empties the field: "no styles" === "no value".
    $reloaded->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->setStyleMap([]);
    $reloaded->save();
    $again = Node::load($node->id());
    $this->assertTrue($again->get(DaedalusStylishFieldManager::FIELD_NAME)->isEmpty());
    $this->assertSame([], $again->get(DaedalusStylishFieldManager::FIELD_NAME)->first()?->getStyleMap() ?? []);
  }

}
