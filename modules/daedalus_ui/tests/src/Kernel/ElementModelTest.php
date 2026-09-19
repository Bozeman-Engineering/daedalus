<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Drupal\daedalus_ui\Presentation\Presentation;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the element model: precedence across its tiers, all three axes.
 *
 * Presentation (derived defaults, declarations, third-party settings),
 * collapse (block-type declarations), and existence (element: false
 * declarations plus the display-hidden inference feeder).
 *
 * @group daedalus_ui
 */
#[RunTestsInSeparateProcesses]
class ElementModelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Navigation_plus's hard dependencies (daedalus_blueprint +
   * daedalus_blueprint_api) are invisible to kernel environments and
   * must be listed explicitly or the container fails to compile.
   */
  protected static $modules = [
    'system',
    'user',
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
    // Block type declarations exercising the declaration tier.
    'daedalus_ui_element_model_test',
  ];

  /**
   * The model under test.
   *
   * @var \Drupal\daedalus_ui\ElementModel\ElementModel
   */
  protected $model;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->model = $this->container->get('daedalus_ui.element_model');
  }

  /**
   * Creates a block_content type with the given fields.
   *
   * @param string $type
   *   The bundle machine name.
   * @param array $fields
   *   Field name => field type.
   *
   * @return \Drupal\block_content\Entity\BlockContentType
   *   The bundle config entity.
   */
  protected function createBlockType(string $type, array $fields = []): object {
    $bundle = $this->container->get('entity_type.manager')
      ->getStorage('block_content_type')
      ->create(['id' => $type, 'label' => ucfirst($type), 'revision' => TRUE]);
    $bundle->save();

    foreach ($fields as $field_name => $field_type) {
      if (!FieldStorageConfig::loadByName('block_content', $field_name)) {
        $storage = ['field_name' => $field_name, 'entity_type' => 'block_content', 'type' => $field_type];
        if ($field_type === 'entity_reference_media') {
          $storage = [
            'field_name' => $field_name,
            'entity_type' => 'block_content',
            'type' => 'entity_reference',
            'settings' => ['target_type' => 'media'],
          ];
        }
        FieldStorageConfig::create($storage)->save();
      }
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'block_content',
        'bundle' => $type,
        'label' => ucwords(str_replace(['field_', '_'], ['', ' '], $field_name)),
      ])->save();
    }

    return $bundle;
  }

  /**
   * A single-field block merges into its one field, recursively derivable.
   */
  public function testSingleFieldBlockMerges(): void {
    $this->createBlockType('text_block', ['field_text' => 'text_long']);

    $presentation = $this->model->bundlePresentation('block_content', 'text_block');

    $this->assertSame(Presentation::MERGED, $presentation->mode);
    $this->assertSame('Text', $presentation->label);
    $this->assertSame('field_text', $presentation->editTarget);
  }

  /**
   * A visible label beside one field makes two meaningful things: open.
   */
  public function testVisibleLabelPlusFieldStaysOpen(): void {
    $this->createBlockType('labeled_text', ['field_text' => 'text_long']);

    $presentation = $this->model->bundlePresentation('block_content', 'labeled_text', label_visible: TRUE);

    $this->assertSame(Presentation::OPEN, $presentation->mode);
    $this->assertNull($presentation->editTarget);
  }

  /**
   * A heading-style block (no fields, displayed label) merges into its label.
   */
  public function testHeadingBlockMergesIntoLabel(): void {
    $this->createBlockType('heading');

    $presentation = $this->model->bundlePresentation('block_content', 'heading', label_visible: TRUE);

    $this->assertSame(Presentation::MERGED, $presentation->mode);
    $this->assertNull($presentation->label);
    $this->assertSame('label', $presentation->editTarget);
  }

  /**
   * A multi-field block (a CTA) shows its structure.
   */
  public function testMultiFieldBlockStaysOpen(): void {
    $this->createBlockType('cta', [
      'field_cta_heading' => 'string',
      'field_cta_text' => 'text_long',
      'field_cta_image' => 'entity_reference_media',
    ]);

    $presentation = $this->model->bundlePresentation('block_content', 'cta');

    $this->assertSame(Presentation::OPEN, $presentation->mode);
  }

  /**
   * An image block chains through its single media reference to "Image".
   */
  public function testSingleMediaReferenceBlockMerges(): void {
    $this->createBlockType('image_block', ['field_image' => 'entity_reference_media']);

    $presentation = $this->model->bundlePresentation('block_content', 'image_block');

    $this->assertSame(Presentation::MERGED, $presentation->mode);
    $this->assertSame('Image', $presentation->label);
    $this->assertSame('field_image', $presentation->editTarget);
  }

  /**
   * The bundle three-way setting outranks the derived default.
   */
  public function testBundleSettingPrecedence(): void {
    $unit = $this->createBlockType('shipped_cta', [
      'field_a' => 'string',
      'field_b' => 'text_long',
    ]);
    $unit->setThirdPartySetting('daedalus_ui', ElementModel::SETTING, 'unit')->save();
    $this->assertSame(
      Presentation::UNIT,
      $this->model->bundlePresentation('block_content', 'shipped_cta')->mode,
      'A configured "unit" overrides the derived open default.',
    );

    $open = $this->createBlockType('open_text', ['field_text2' => 'text_long']);
    $open->setThirdPartySetting('daedalus_ui', ElementModel::SETTING, 'open')->save();
    $this->assertSame(
      Presentation::OPEN,
      $this->model->bundlePresentation('block_content', 'open_text')->mode,
      'A configured "open" overrides the derived merge default.',
    );
  }

  /**
   * A configured merge presents a composite multi-field block as one row.
   *
   * The edit target (the projection's live-label field) is the single
   * plain-text (string / string_long) content field; the label stays NULL
   * so an empty block keeps the component's own label.
   */
  public function testConfiguredMergeDerivesPrimaryTextField(): void {
    $composite = $this->createBlockType('composite_text', [
      'field_ct_tag' => 'list_string',
      'field_ct_text' => 'string_long',
      'field_ct_url' => 'link',
    ]);
    $composite->setThirdPartySetting('daedalus_ui', ElementModel::SETTING, 'merged')->save();

    $presentation = $this->model->bundlePresentation('block_content', 'composite_text');

    $this->assertSame(Presentation::MERGED, $presentation->mode);
    $this->assertNull($presentation->label);
    $this->assertSame('field_ct_text', $presentation->editTarget);
  }

  /**
   * A configured merge with an ambiguous text field has no edit target.
   */
  public function testConfiguredMergeAmbiguousTextFields(): void {
    $ambiguous = $this->createBlockType('two_texts', [
      'field_tt_first' => 'string',
      'field_tt_second' => 'string_long',
    ]);
    $ambiguous->setThirdPartySetting('daedalus_ui', ElementModel::SETTING, 'merged')->save();

    $presentation = $this->model->bundlePresentation('block_content', 'two_texts');

    $this->assertSame(Presentation::MERGED, $presentation->mode);
    $this->assertNull($presentation->label);
    $this->assertNull($presentation->editTarget);
  }

  /**
   * A bundle carrying a nested layout is a structural container: open.
   */
  public function testLayoutBlockStaysOpen(): void {
    $this->createBlockType('layout_block');
    FieldStorageConfig::create([
      'field_name' => 'field_nested_layout',
      'entity_type' => 'block_content',
      'type' => 'layout_section',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_nested_layout',
      'entity_type' => 'block_content',
      'bundle' => 'layout_block',
      'label' => 'Layout',
    ])->save();

    $presentation = $this->model->bundlePresentation('block_content', 'layout_block');

    $this->assertSame(Presentation::OPEN, $presentation->mode);
  }

  /**
   * A bare block (no fields, no label) is one element with no edit target.
   */
  public function testBareBlockMerges(): void {
    $this->createBlockType('bare');

    $presentation = $this->model->bundlePresentation('block_content', 'bare');

    $this->assertSame(Presentation::MERGED, $presentation->mode);
    $this->assertNull($presentation->editTarget);
  }

  /**
   * A declared presentation outranks the derived default, TPS outranks it.
   */
  public function testDeclaredPresentationTier(): void {
    // One text field would derive MERGED; the declaration says unit.
    $bundle = $this->createBlockType('declared_unit', ['field_du_text' => 'text_long']);
    $this->assertSame(
      Presentation::UNIT,
      $this->model->bundlePresentation('block_content', 'declared_unit')->mode,
      'The block type declaration outranks the derived merge default.',
    );

    // The site builder's setting outranks the declaration.
    $bundle->setThirdPartySetting('daedalus_ui', ElementModel::SETTING, 'open')->save();
    $this->assertSame(
      Presentation::OPEN,
      $this->model->bundlePresentation('block_content', 'declared_unit')->mode,
      'The third-party setting outranks the declaration tier.',
    );
  }

  /**
   * A declared merge carries its label and derives the missing edit target.
   */
  public function testDeclaredMergeDerivesEditTarget(): void {
    $this->createBlockType('declared_merged', [
      'field_dm_text' => 'string',
      'field_dm_url' => 'link',
    ]);

    $presentation = $this->model->bundlePresentation('block_content', 'declared_merged');

    $this->assertSame(Presentation::MERGED, $presentation->mode);
    $this->assertSame('Declared', $presentation->label);
    $this->assertSame('field_dm_text', $presentation->editTarget);
  }

  /**
   * Field presentation: media references default to unit; config overrides.
   */
  public function testFieldPresentation(): void {
    $this->createBlockType('fields', [
      'field_media' => 'entity_reference_media',
      'field_plain' => 'string',
    ]);
    $definitions = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('block_content', 'fields');

    $this->assertSame(Presentation::UNIT, $this->model->fieldPresentation($definitions['field_media']));
    $this->assertSame(Presentation::OPEN, $this->model->fieldPresentation($definitions['field_plain']));

    // A FieldConfig third-party setting overrides the field-type default.
    $definitions['field_media']->setThirdPartySetting('daedalus_ui', ElementModel::SETTING, 'open')->save();
    $definitions = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('block_content', 'fields');
    $this->assertSame(Presentation::OPEN, $this->model->fieldPresentation($definitions['field_media']));
  }

  /**
   * A declared field presentation outranks inference; TPS outranks both.
   */
  public function testDeclaredFieldPresentationTier(): void {
    $this->createBlockType('gone_field', [
      'field_unit_declared' => 'string',
      'field_gone' => 'string',
      'field_hidden' => 'string',
      'field_shown' => 'string',
    ]);
    $definitions = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('block_content', 'gone_field');

    $this->assertSame(
      Presentation::UNIT,
      $this->model->fieldPresentation($definitions['field_unit_declared']),
      'The declaration tier outranks the open field-type default.',
    );

    $definitions['field_unit_declared']->setThirdPartySetting('daedalus_ui', ElementModel::SETTING, 'open')->save();
    $definitions = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('block_content', 'gone_field');
    $this->assertSame(
      Presentation::OPEN,
      $this->model->fieldPresentation($definitions['field_unit_declared']),
      'The third-party setting outranks the declaration tier.',
    );
  }

  /**
   * Collapse is a block-type declaration; plugin ids resolve through it.
   */
  public function testCollapse(): void {
    $this->assertTrue($this->model->isComposedInlineBlock('collapsed_type'));
    $this->assertTrue($this->model->isComposed('inline_block:collapsed_type'));
    $this->assertFalse($this->model->isComposedInlineBlock('declared_unit'));
    // Collapse is a block-type fact: a bare plugin id (not an inline
    // block) can never answer TRUE, whatever it is named.
    $this->assertFalse($this->model->isComposed('collapsed_type'));
    $this->assertFalse($this->model->isComposed('block_content:some-uuid'));

    $this->assertContains('collapsed_type', $this->model->composedInlineBlockBundles());
    $this->assertNotContains('declared_unit', $this->model->composedInlineBlockBundles());
  }

  /**
   * Existence: declarations union the display-hidden inference feeder.
   *
   * 'info' rides in from daedalus_ui's own universal block declaration
   * (metadata with no canvas render); 'field_gone' from the test block
   * type's element: false; 'field_hidden' from the display-hidden feeder.
   * The feeder only answers for KIND_BLOCK: a root entity's display-hidden
   * field can still reach the canvas through a Layout Builder field block.
   */
  public function testNonExistentFields(): void {
    $this->createBlockType('gone_field', [
      'field_unit_declared' => 'string',
      'field_gone' => 'string',
      'field_hidden' => 'string',
      'field_shown' => 'string',
    ]);
    $this->container->get('entity_type.manager')
      ->getStorage('entity_view_display')
      ->create([
        'targetEntityType' => 'block_content',
        'bundle' => 'gone_field',
        'mode' => 'default',
        'status' => TRUE,
      ])
      ->setComponent('field_shown', ['type' => 'string'])
      ->setComponent('field_unit_declared', ['type' => 'string'])
      ->setComponent('field_gone', ['type' => 'string'])
      ->removeComponent('field_hidden')
      ->save();

    $this->assertEqualsCanonicalizing(
      ['info', 'field_gone', 'field_hidden'],
      $this->model->nonExistentFields(ElementModel::KIND_BLOCK, 'block_content', 'gone_field', 'full'),
    );

    $this->assertEqualsCanonicalizing(
      ['info', 'field_gone'],
      $this->model->nonExistentFields(ElementModel::KIND_ENTITY, 'block_content', 'gone_field', 'full'),
      'The display-hidden inference never speaks for root entities.',
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->model->nonExistentFields('region', 'block_content', 'gone_field', 'full');
  }

}
