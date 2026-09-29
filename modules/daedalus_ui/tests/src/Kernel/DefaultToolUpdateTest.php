<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The update that strips the retired default_tool Edit Mode setting.
 *
 * Bundles configured before the tool system was purged store
 * `modes.edit.default_tool` (whatever value). The update removes the key,
 * drops the mode entry and the `modes` map when they are left empty, keeps
 * a mode entry that still carries other settings, leaves every other bundle
 * alone, and does nothing on a rerun.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class DefaultToolUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'link',
    'options',
    'file',
    'image',
    'media',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
  ];

  /**
   * {@inheritdoc}
   *
   * The legacy bundles seed config the current schema no longer describes
   * (that is what the update removes), so the schema checker skips them.
   */
  protected static $configSchemaCheckerExclusions = [
    'node.type.legacy',
    'node.type.mixed',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');

    $this->createType('legacy', ['edit' => ['default_tool' => 'edit']]);
    $this->createType('mixed', ['edit' => ['default_tool' => 'selector', 'other' => 'kept']]);
    $this->createType('current', []);
    $this->createType('plain', NULL);

    \Drupal::moduleHandler()->loadInclude('daedalus_ui', 'install');
  }

  /**
   * Creates a node type, with daedalus_ui mode settings when given.
   */
  protected function createType(string $id, ?array $modes): void {
    $type = NodeType::create(['type' => $id, 'name' => $id]);
    if ($modes !== NULL) {
      $type->setThirdPartySetting('daedalus_ui', 'initial_mode', 'edit');
      $type->setThirdPartySetting('daedalus_ui', 'status', ['edit' => TRUE]);
      if ($modes !== []) {
        $type->setThirdPartySetting('daedalus_ui', 'modes', $modes);
      }
    }
    $type->save();
  }

  /**
   * Reads a node type's daedalus_ui mode settings fresh from storage.
   */
  protected function modes(string $id): ?array {
    $storage = \Drupal::entityTypeManager()->getStorage('node_type');
    $storage->resetCache([$id]);
    return $storage->load($id)->getThirdPartySetting('daedalus_ui', 'modes');
  }

  /**
   * The setting goes, empty containers with it, and a rerun is a no-op.
   */
  public function testDefaultToolIsStripped(): void {
    $sandbox = [];
    $message = (string) daedalus_ui_update_10404($sandbox);
    $this->assertStringContainsString('2 bundles', $message, 'The two bundles carrying default_tool were rewritten.');

    $this->assertNull($this->modes('legacy'), 'A modes map left empty is removed with the setting.');
    $this->assertSame(['edit' => ['other' => 'kept']], $this->modes('mixed'), 'Other mode settings survive the strip.');
    $this->assertNull($this->modes('current'), 'A bundle without mode settings is untouched.');
    $this->assertNull($this->modes('plain'));
    $legacy = NodeType::load('legacy');
    $this->assertSame('edit', $legacy->getThirdPartySetting('daedalus_ui', 'initial_mode'), 'The other daedalus_ui settings stay.');
    $this->assertSame(['edit' => TRUE], $legacy->getThirdPartySetting('daedalus_ui', 'status'));
    $this->assertNull(NodeType::load('plain')->getThirdPartySetting('daedalus_ui', 'initial_mode'));

    $sandbox = [];
    $message = (string) daedalus_ui_update_10404($sandbox);
    $this->assertStringContainsString('0 bundles', $message, 'A rerun finds nothing to strip.');
    $this->assertSame(['edit' => ['other' => 'kept']], $this->modes('mixed'));
  }

}
