<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the Layout Builder root-existence feeder.
 *
 * On a Layout Builder display, display-configurable root fields have no
 * row of their own in the page-elements tree (core strips every one of
 * them from the entity render once sections exist), so the feeder reports
 * them nonexistent to daedalus_ui's element model. That answers the tree's
 * default rows, not editability: a field block placing the same field
 * renders it under its own key inside the placement, and that leaf is
 * admitted by rendered evidence (the placement's merged presentation and
 * the resolver's mirror-backed gate), never by this feeder. Non-LB
 * displays are not daedalus_layout's truth: the feeder stays silent.
 *
 * @group daedalus_layout
 */
#[RunTestsInSeparateProcesses]
class LayoutBuilderRootExistenceTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Two configurable fields on the base class's LB-enabled entity_test
    // default display; no third-party setting distinguishes them.
    foreach (['field_one', 'field_two'] as $field_name) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'entity_test',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'entity_test',
        'bundle' => 'entity_test',
        'label' => $field_name,
      ])->save();
    }
  }

  /**
   * Every configurable root field is nonexistent at the root of an LB display.
   *
   * Asked for the unsaved 'full' view mode, the feeder resolves the default
   * display exactly as the render path's collectRenderDisplay fallback does.
   */
  public function testLayoutBuilderDisplayFeedsRootExistence(): void {
    $model = $this->container->get('daedalus_ui.element_model');
    $names = $model->nonExistentFields(ElementModel::KIND_ENTITY, 'entity_test', 'entity_test', 'full');

    $this->assertContains('field_one', $names, 'A configurable field has no row of its own on an LB display (a field block placing it presents it under the placement).');
    $this->assertContains('field_two', $names, 'Every configurable field is stripped alike: no setting spares one.');
  }

  /**
   * The feeder speaks only for root entities on LB displays.
   */
  public function testFeederScope(): void {
    /** @var \Drupal\daedalus_layout\ElementModel\LayoutBuilderRootExistence $feeder */
    $feeder = $this->container->get('daedalus_layout.element_model.layout_builder_root_existence');

    $this->assertSame(
      [],
      $feeder->nonExistentFields(ElementModel::KIND_BLOCK, 'entity_test', 'entity_test', 'full'),
      'Inline-block fields render through their bundle display, not the layout: not this feeder\'s truth.',
    );
    $this->assertSame(
      [],
      $feeder->nonExistentFields(ElementModel::KIND_ENTITY, 'block_content', 'basic', 'full'),
      'A display without Layout Builder renders its fields directly: the feeder stays silent.',
    );
  }

}
