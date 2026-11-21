<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the block creation grant an installed site receives.
 *
 * Placing or copying an inline block newly asks core's create permission
 * on the block type, so daedalus_blueprint_post_update_grant_block_creation()
 * grants it, for every block type, to each role that could already
 * change a layout: one holding "configure any layout", a layout
 * overrides permission or "configure the site frame". A role without
 * such a permission gains nothing, an administrator role is untouched,
 * a second run changes nothing, and nothing happens without
 * block_content.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
final class BlockCreationGrantTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'filter',
    'block_content',
    'entity_test',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    // An overridable display, so the layout overrides permissions exist.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    BlockContentType::create(['id' => 'text', 'label' => 'Text'])->save();
    BlockContentType::create(['id' => 'image', 'label' => 'Image'])->save();
    require_once $this->container->get('extension.list.module')->getPath('daedalus_blueprint') . '/daedalus_blueprint.post_update.php';
  }

  /**
   * Creates a role holding the given permissions.
   *
   * @param string $id
   *   The role id.
   * @param string[] $permissions
   *   The permissions the role holds.
   * @param bool $is_admin
   *   Whether the role is an administrator role.
   */
  private function createRole(string $id, array $permissions, bool $is_admin = FALSE): void {
    Role::create([
      'id' => $id,
      'label' => ucfirst($id),
      'permissions' => $permissions,
      'is_admin' => $is_admin,
    ])->save();
  }

  /**
   * The permissions a stored role holds, sorted.
   *
   * @param string $id
   *   The role id.
   *
   * @return string[]
   *   The permissions.
   */
  private function permissionsOf(string $id): array {
    $permissions = Role::load($id)->getPermissions();
    sort($permissions);
    return $permissions;
  }

  /**
   * Each role that could change a layout gains create on every type.
   */
  public function testGrant(): void {
    $create = ['create image block content', 'create text block content'];
    $this->createRole('anylayout', ['configure any layout']);
    $this->createRole('alloverrides', ['configure all entity_test entity_test layout overrides']);
    $this->createRole('editable', ['configure editable entity_test entity_test layout overrides']);
    $this->createRole('frame', ['configure the site frame']);
    $this->createRole('partial', ['configure any layout', 'create text block content']);
    $this->createRole('reader', ['view test entity']);
    $this->createRole('boss', ['configure any layout'], TRUE);

    $message = PlainTextOutput::renderFromHtml(daedalus_blueprint_post_update_grant_block_creation());

    $this->assertSame(['configure any layout', ...$create], $this->permissionsOf('anylayout'));
    $this->assertSame(['configure all entity_test entity_test layout overrides', ...$create], $this->permissionsOf('alloverrides'));
    $this->assertSame(['configure editable entity_test entity_test layout overrides', ...$create], $this->permissionsOf('editable'));
    $this->assertSame(['configure the site frame', ...$create], $this->permissionsOf('frame'));
    $this->assertSame(['configure any layout', ...$create], $this->permissionsOf('partial'));
    $this->assertSame(['view test entity'], $this->permissionsOf('reader'));
    // Core empties an administrator role's permission list on save, so
    // it holds none before and none after; the message not naming the
    // role (below) is what shows it was not granted to.
    $this->assertSame([], $this->permissionsOf('boss'));

    $this->assertStringContainsString('The "Anylayout" role (anylayout) gained: create image block content, create text block content.', $message);
    $this->assertStringContainsString('The "Frame" role (frame) gained: create image block content, create text block content.', $message);
    $this->assertStringContainsString('The "Partial" role (partial) gained: create image block content.', $message);
    $this->assertStringNotContainsString('reader', $message);
    $this->assertStringNotContainsString('boss', $message);

    // A second run changes nothing.
    $before = [];
    foreach (array_keys(Role::loadMultiple()) as $id) {
      $before[$id] = $this->permissionsOf((string) $id);
    }
    $second = (string) daedalus_blueprint_post_update_grant_block_creation();
    $this->assertSame('No role needed the block creation grant.', $second);
    foreach ($before as $id => $permissions) {
      $this->assertSame($permissions, $this->permissionsOf((string) $id));
    }
  }

  /**
   * Without block_content the update does nothing.
   */
  public function testWithoutBlockContent(): void {
    $this->createRole('anylayout', ['configure any layout']);
    $this->disableModules(['block_content']);

    $this->assertNull(daedalus_blueprint_post_update_grant_block_creation());
    $this->assertSame(['configure any layout'], $this->permissionsOf('anylayout'));
  }

}
