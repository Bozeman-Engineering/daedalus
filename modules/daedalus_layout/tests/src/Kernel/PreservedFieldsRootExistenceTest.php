<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the preserved-fields root-existence feeder.
 *
 * On a Layout Builder display, non-preserved configurable root fields have
 * no row of their own in the page-elements tree (the display renders only
 * the preserved ones beside the layout), so the feeder reports them
 * nonexistent to daedalus_ui's element model. That answers the tree's
 * default rows, not editability: a field block placing the same field
 * renders it under its own key inside the placement, and that leaf is
 * admitted by rendered evidence (the placement's merged presentation and
 * the resolver's mirror-backed gate), never by this feeder. Non-LB
 * displays are not daedalus_layout's truth: the feeder stays silent.
 *
 * @group daedalus_layout
 */
#[RunTestsInSeparateProcesses]
class PreservedFieldsRootExistenceTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['field_kept', 'field_dropped'] as $field_name) {
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
    // The base class LB-enabled entity_test's default display; preserve one
    // of the two fields.
    $display = $this->entityTypeManager->getStorage('entity_view_display')
      ->load('entity_test.entity_test.default');
    $display->setThirdPartySetting('daedalus_layout', 'preserved_fields', ['field_kept'])->save();
  }

  /**
   * Non-preserved configurable fields are nonexistent at the root.
   *
   * Asked for the unsaved 'full' view mode, the feeder resolves the default
   * display exactly as the render path's collectRenderDisplay fallback does.
   */
  public function testPreservedFieldsFeedRootExistence(): void {
    $model = $this->container->get('daedalus_ui.element_model');
    $names = $model->nonExistentFields(ElementModel::KIND_ENTITY, 'entity_test', 'entity_test', 'full');

    $this->assertContains('field_dropped', $names, 'A non-preserved field has no row of its own on an LB display (a field block placing it presents it under the placement).');
    $this->assertNotContains('field_kept', $names, 'A preserved field keeps rendering beside the layout.');
  }

  /**
   * The feeder speaks only for root entities on LB displays.
   */
  public function testFeederScope(): void {
    /** @var \Drupal\daedalus_layout\ElementModel\PreservedFieldsRootExistence $feeder */
    $feeder = $this->container->get('daedalus_layout.element_model.preserved_fields');

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
