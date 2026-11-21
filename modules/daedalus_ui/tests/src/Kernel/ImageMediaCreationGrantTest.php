<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the image media creation grant an installed site receives.
 *
 * Dropping a file on the page newly asks create on the media type, so
 * daedalus_ui_post_update_grant_image_media_creation() grants it, for
 * each media type whose source is the image plugin, to each role that
 * can enter Edit Mode. A file-backed type of another source is not
 * granted: the message names the Edit Mode roles that cannot create it
 * and the permission. A type that keeps no file is neither granted nor
 * named, a role outside Edit Mode gains nothing, a role that already
 * creates media of every type is left alone, an administrator role is
 * untouched, a second run grants nothing, and nothing happens without
 * media.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
final class ImageMediaCreationGrantTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'block',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'filter',
    'daedalus_blueprint_api',
    'daedalus_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);
    // Two types on the image source, a file-backed type of another
    // source, and a type that keeps no file.
    $this->createMediaType('image', ['id' => 'image']);
    $this->createMediaType('image', ['id' => 'photo']);
    $this->createMediaType('file', ['id' => 'document']);
    $this->createMediaType('oembed:video', ['id' => 'remote_video']);
    require_once $this->container->get('extension.list.module')->getPath('daedalus_ui') . '/daedalus_ui.post_update.php';
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
  private function createGrantRole(string $id, array $permissions, bool $is_admin = FALSE): void {
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
   * Edit Mode roles gain create on image types; other file types are named.
   */
  public function testGrant(): void {
    $edit = 'use daedalus ui edit mode';
    $this->createGrantRole('editor', [$edit]);
    $this->createGrantRole('partial', [$edit, 'create image media']);
    $this->createGrantRole('filer', [$edit, 'create document media']);
    $this->createGrantRole('librarian', [$edit, 'create media']);
    $this->createGrantRole('reader', ['view media']);
    $this->createGrantRole('boss', [$edit], TRUE);

    $message = PlainTextOutput::renderFromHtml(daedalus_ui_post_update_grant_image_media_creation());

    $this->assertSame(['create image media', 'create photo media', $edit], $this->permissionsOf('editor'));
    $this->assertSame(['create image media', 'create photo media', $edit], $this->permissionsOf('partial'));
    $this->assertSame(['create document media', 'create image media', 'create photo media', $edit], $this->permissionsOf('filer'));
    // Already creates media of every type: nothing to gain, nothing lost.
    $this->assertSame(['create media', $edit], $this->permissionsOf('librarian'));
    $this->assertSame(['view media'], $this->permissionsOf('reader'));
    // Core empties an administrator role's permission list on save; the
    // message not naming the role (below) shows it was not granted to.
    $this->assertSame([], $this->permissionsOf('boss'));

    $this->assertStringContainsString('The "Editor" role (editor) gained: create image media, create photo media.', $message);
    $this->assertStringContainsString('The "Partial" role (partial) gained: create photo media.', $message);
    $this->assertStringContainsString('The "Filer" role (filer) gained: create image media, create photo media.', $message);
    // The file-backed type of another source: named with the roles that
    // lack it, and held only by the role that held it before.
    $this->assertStringContainsString('"create document media" is not granted; without it a file dropped for that media type is refused to: the "Editor" role (editor), the "Partial" role (partial).', $message);
    $this->assertStringNotContainsString('remote_video', $message);
    $this->assertStringNotContainsString('librarian', $message);
    $this->assertStringNotContainsString('reader', $message);
    $this->assertStringNotContainsString('boss', $message);

    // A second run grants nothing and still names what is left.
    $before = [];
    foreach (array_keys(Role::loadMultiple()) as $id) {
      $before[$id] = $this->permissionsOf((string) $id);
    }
    $second = PlainTextOutput::renderFromHtml(daedalus_ui_post_update_grant_image_media_creation());
    $this->assertStringNotContainsString(' gained: ', $second);
    $this->assertStringContainsString('"create document media" is not granted', $second);
    foreach ($before as $id => $permissions) {
      $this->assertSame($permissions, $this->permissionsOf((string) $id));
    }
  }

  /**
   * With no Edit Mode role in need, the update says so.
   */
  public function testNothingNeeded(): void {
    $this->createGrantRole('reader', ['view media']);

    $this->assertSame('No role needed the image media creation grant.', (string) daedalus_ui_post_update_grant_image_media_creation());
    $this->assertSame(['view media'], $this->permissionsOf('reader'));
  }

  /**
   * Without media the update does nothing.
   */
  public function testWithoutMedia(): void {
    $this->createGrantRole('editor', ['use daedalus ui edit mode']);
    $this->disableModules(['media']);

    $this->assertNull(daedalus_ui_post_update_grant_image_media_creation());
    $this->assertSame(['use daedalus ui edit mode'], $this->permissionsOf('editor'));
  }

}
