<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the tailor-made test fixture module's config installs coherently.
 *
 * The daedalus_edit_test_fixture module (tests/modules) is the one fixture for the
 * entity-form editing path: the 'plain' non-Layout-Builder node bundle,
 * the 'target' reference bundle, and the 'form_block' form-edited
 * block_content type. Config is imported with strict schema checking and
 * every declared dependency must already exist, so a broken shape fails
 * here instead of mid-way through an expensive FunctionalJavascript run.
 *
 * The load-bearing shape assertions:
 * - Both reference fields target ONLY the 'target' bundle, and 'target'
 *   itself carries no configurable fields at all — a reference target
 *   that CANNOT reference back into the fixture's own render. This is
 *   the recorded teaser-block lesson: daedalus_edit_teaser_block's related
 *   content rendered nodes that could contain the same teaser block,
 *   which self-referenced into an infinite render loop.
 * - The media fields ride media_library_widget on both bundles: the
 *   widget the form-path media machinery (FormMediaFieldAttributes,
 *   media.js) is keyed to.
 * - form_block declares NO composed content class (no
 *   page_builder.block_type file ships composed: true for it), so its
 *   placements ride the sidebar block form (UpdateBlockFormForEditTool).
 *
 * @group daedalus_edit
 */
#[Group('daedalus_edit')]
#[RunTestsInSeparateProcesses]
class FixtureInstallTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'block_content',
    'field',
    'file',
    'image',
    'media',
    'media_library',
    'views',
    'filter',
    'text',
    'editor',
    'block',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
    'daedalus_edit_test_fixture',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installConfig(['system', 'node']);
    // The media fields depend on media.type.image, which a real site gets
    // from its recipe (daedalus_media_image) rather than from any module
    // here.
    $this->createMediaType('image', ['id' => 'image']);
    $this->installConfig(['daedalus_edit_test_fixture']);
  }

  /**
   * The fixture bundles install with the machinery-derived shapes.
   */
  public function testFixtureConfigInstalls(): void {
    $this->assertNotNull(NodeType::load('plain'));
    $this->assertNotNull(NodeType::load('target'));
    $this->assertNotNull(BlockContentType::load('form_block'));

    // Both reference fields target ONLY the plain 'target' bundle.
    foreach (['node.plain.field_related', 'block_content.form_block.field_fb_related'] as $id) {
      $field = FieldConfig::load($id);
      $this->assertNotNull($field, "$id exists.");
      $this->assertSame('default:node', $field->getSetting('handler'), "$id uses the default node handler.");
      $this->assertSame(['target' => 'target'], $field->getSetting('handler_settings')['target_bundles'], "$id targets only the target bundle.");
      $this->assertContains('node.type.target', $field->getDependencies()['config'], "$id declares its target bundle dependency.");
    }

    // The teaser-block lesson, structurally enforced: the target bundle
    // cannot self-reference because it has no configurable fields at all.
    $target_fields = \Drupal::entityTypeManager()
      ->getStorage('field_config')
      ->loadByProperties(['entity_type' => 'node', 'bundle' => 'target']);
    $this->assertSame([], $target_fields, 'The target bundle carries no configurable fields, so it cannot reference back.');

    // The media fields ride the widget the form-path media machinery needs.
    $this->assertSame(
      'media_library_widget',
      EntityFormDisplay::load('node.plain.default')->getComponent('field_media')['type'],
    );
    $this->assertSame(
      'media_library_widget',
      EntityFormDisplay::load('block_content.form_block.default')->getComponent('field_fb_media')['type'],
    );

    // The fixture declares label_display as a managed configuration key for
    // form_block placements: add_element defaults label_display to '0' and
    // only managed keys may override component configuration through the op
    // spine, and the block-label editing scenario needs a placement whose
    // label actually renders.
    $managed = \Drupal::service('daedalus_blueprint.managed_keys_registry')
      ->getManagedKeysForScope('inline_block_configuration', 'node', 'plain');
    $this->assertArrayHasKey(
      'label_display',
      $managed['form_block'] ?? [],
      'label_display is a managed configuration key for form_block placements.',
    );
  }

}
