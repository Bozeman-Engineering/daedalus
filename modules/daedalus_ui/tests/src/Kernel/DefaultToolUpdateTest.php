<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The update that retires the place_block default tool.
 *
 * A bundle migrated from the old suite stores `modes.edit.default_tool:
 * place_block`, a tool Daedalus does not ship. The update rewrites it to the
 * selector, leaves every other bundle alone, and does nothing on a rerun.
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
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');

    $this->createType('migrated', ['edit' => ['default_tool' => 'place_block']]);
    $this->createType('current', ['edit' => ['default_tool' => 'selector']]);
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
      $type->setThirdPartySetting('daedalus_ui', 'modes', $modes);
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
   * The tool becomes selector, other bundles stay, and a rerun is a no-op.
   */
  public function testPlaceBlockBecomesSelector(): void {
    $sandbox = [];
    $message = (string) daedalus_ui_update_10403($sandbox);
    $this->assertStringContainsString('1 bundles', $message);

    $this->assertSame(['edit' => ['default_tool' => 'selector']], $this->modes('migrated'));
    $this->assertSame(['edit' => ['default_tool' => 'selector']], $this->modes('current'));
    $this->assertNull($this->modes('plain'));
    $this->assertNull(NodeType::load('plain')->getThirdPartySetting('daedalus_ui', 'initial_mode'));

    $sandbox = [];
    $message = (string) daedalus_ui_update_10403($sandbox);
    $this->assertStringContainsString('0 bundles', $message);
    $this->assertSame(['edit' => ['default_tool' => 'selector']], $this->modes('migrated'));
  }

}
