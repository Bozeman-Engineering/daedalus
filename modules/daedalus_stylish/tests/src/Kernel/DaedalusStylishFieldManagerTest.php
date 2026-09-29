<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

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
    'block',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    // daedalus_ui (and its dependency chain) provides the config schema
    // for the per-bundle Edit Mode status third-party setting the default-on
    // gate reads.
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
      $node_type->setThirdPartySetting('daedalus_ui', 'status', ['edit' => TRUE]);
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
   * Config sync never auto-creates the field.
   */
  public function testConfigSyncDoesNotAutoEnable(): void {
    $this->enableLayoutBuilderDisplay('page');

    $node_type = NodeType::load('page');
    $statuses = $node_type->getThirdPartySetting('daedalus_ui', 'status', []);
    $statuses['edit'] = TRUE;
    $node_type->setThirdPartySetting('daedalus_ui', 'status', $statuses);
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
   * Sets daedalus_ui's per-bundle Edit Mode status and saves.
   */
  protected function setEditModeStatus(string $bundle, bool $enabled): void {
    $node_type = NodeType::load($bundle);
    $statuses = $node_type->getThirdPartySetting('daedalus_ui', 'status', []);
    if ($enabled) {
      $statuses['edit'] = TRUE;
    }
    else {
      unset($statuses['edit']);
    }
    $node_type->setThirdPartySetting('daedalus_ui', 'status', $statuses);
    $node_type->save();
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
