<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\SectionComponent;

/**
 * The pre-migration layout_block fixture of the Plus Suite migration tests.
 *
 * The layout_block bundle is gone from the product: nesting is embedded
 * sections, and the site's shared compositions (the header, the footer)
 * are blueprints placed linked in the site frame. The layout-block
 * migration (LayoutBlockToSectionTransform, the GC and retirement post
 * updates) still needs the old data shape to convert, so the two tests of
 * that history (LayoutBlockMigrationTest, StorageSpineUpgradeTest) build it
 * here, on top of NestedStorageKernelTestBase.
 */
trait LegacyLayoutBlockFixtureTrait {

  /**
   * Creates the LEGACY layout_block bundle with a layout_builder__layout field.
   *
   * Pre-migration fixture: the old data shape the layout-block migration
   * converts. Overridable, matching the retired recipe bundle
   * (allow_custom): without it, findByContext resolves no overrides storage
   * for a block_content and its nested sections render empty.
   */
  protected function createLayoutBlockType(): void {
    // Create the block type.
    $type = BlockContentType::create([
      'id' => 'layout_block',
      'label' => 'Block Layout',
      'revision' => FALSE,
    ]);
    $type->save();

    // Create the field storage for layout_builder__layout.
    FieldStorageConfig::create([
      'field_name' => 'layout_builder__layout',
      'entity_type' => 'block_content',
      'type' => 'layout_section',
    ])->save();

    // Create the field instance.
    FieldConfig::create([
      'field_name' => 'layout_builder__layout',
      'entity_type' => 'block_content',
      'bundle' => 'layout_block',
      'label' => 'Layout',
    ])->save();

    // Create entity_view_display with layout_builder enabled, overridable.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'block_content',
      'bundle' => 'layout_block',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->setThirdPartySetting('daedalus_layout', 'default_section', [
        'layout_plugin' => 'layout_onecol',
        'label' => '',
      ])
      ->save();
  }

  /**
   * Creates a legacy layout block component (pre-migration shape).
   *
   * The storage spine no longer traverses these; they exist only so
   * pre-migration fixtures (collapse stamping, migration walkers) can be
   * built. New-shape nesting uses createNestedSectionComponent().
   *
   * @param array $nested_sections
   *   The sections to include in the nested layout.
   * @param string|null $uuid
   *   Optional component UUID.
   * @param string $region
   *   The region name.
   * @param string $label
   *   The component's admin label ('Layout Block' was the shipped default).
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The created layout block component.
   */
  protected function createLayoutBlockComponent(array $nested_sections = [], ?string $uuid = NULL, string $region = 'content', string $label = 'Layout Block'): SectionComponent {
    // Create a block_content entity with nested sections.
    $block_content = BlockContent::create([
      'type' => 'layout_block',
      'info' => 'Test layout block',
      'reusable' => FALSE,
      'layout_builder__layout' => $nested_sections,
    ]);

    $component_uuid = $uuid ?? $this->uuid->generate();
    return new SectionComponent($component_uuid, $region, [
      'id' => 'inline_block:layout_block',
      'label' => $label,
      'label_display' => FALSE,
      'block_serialized' => serialize($block_content),
    ]);
  }

}
