<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\LayoutBuilder;

use Drupal\daedalus_blueprint\BlueprintSchemaBuilder;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests BlueprintSchemaBuilder Layout Builder output.
 */
#[CoversClass(BlueprintSchemaBuilder::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class LayoutSchemaBuilderTest extends BlueprintKernelTestBase {

  /**
   * The schema builder.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintSchemaBuilder
   */
  protected BlueprintSchemaBuilder $schemaBuilder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('body', 'text_with_summary', 'text_section');
    $this->createBlockContentType('card', 'Card');
    $this->createBlockField('field_heading', 'string', 'card', ['max_length' => 255]);

    $this->schemaBuilder = $this->container->get('daedalus_blueprint.schema_builder');
  }

  /**
   * Tests schema includes layout_builder section when LB is enabled.
   */
  public function testLayoutBuilderSchemaPresent(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');

    $this->assertArrayHasKey('layout_builder__layout', $schema);
    $this->assertArrayHasKey('guidance', $schema['layout_builder__layout']);
    $this->assertArrayHasKey('available_layouts', $schema['layout_builder__layout']);
    $this->assertArrayHasKey('available_block_types', $schema['layout_builder__layout']);
  }

  /**
   * Tests schema does not include layout_builder when not enabled.
   */
  public function testNoLayoutBuilderSchema(): void {
    // entity_test_rev does not have LB enabled.
    $schema = $this->schemaBuilder->getSchema('entity_test_rev', 'entity_test_rev');

    $this->assertArrayNotHasKey('layout_builder__layout', $schema);
  }

  /**
   * Tests available layouts structure.
   */
  public function testAvailableLayouts(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $layouts = $schema['layout_builder__layout']['available_layouts'];

    $this->assertNotEmpty($layouts);

    // Find onecol layout.
    $onecol = NULL;
    foreach ($layouts as $layout) {
      if ($layout['id'] === 'layout_onecol') {
        $onecol = $layout;
        break;
      }
    }

    $this->assertNotNull($onecol, 'layout_onecol should be in available layouts');
    $this->assertSame('layout_onecol', $onecol['id']);
    $this->assertNotEmpty($onecol['label']);
    $this->assertNotEmpty($onecol['default_region']);
    $this->assertContains('content', $onecol['regions']);
    // Spatial vocabulary derived from the layout's icon map.
    $this->assertSame(1, $onecol['columns']);
    $this->assertSame([['content']], $onecol['arrangement']);

    // Find twocol layout.
    $twocol = NULL;
    foreach ($layouts as $layout) {
      if ($layout['id'] === 'layout_twocol_section') {
        $twocol = $layout;
        break;
      }
    }

    $this->assertNotNull($twocol, 'layout_twocol_section should be in available layouts');
    $this->assertContains('first', $twocol['regions']);
    $this->assertContains('second', $twocol['regions']);
    $this->assertSame(2, $twocol['columns']);
    $this->assertSame([['first', 'second']], $twocol['arrangement']);
  }

  /**
   * Tests available block types with field schemas.
   */
  public function testAvailableBlockTypes(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $block_types = $schema['layout_builder__layout']['available_block_types'];

    // Should include our created block types.
    $this->assertArrayHasKey('text_section', $block_types);
    $this->assertArrayHasKey('card', $block_types);

    // text_section should have body field.
    $text_section = $block_types['text_section'];
    $this->assertSame('Text Section', $text_section['label']);
    $this->assertArrayHasKey('body', $text_section['block_fields']);
    $this->assertSame('text_with_summary', $text_section['block_fields']['body']['type']);

    // Card should have field_heading.
    $card = $block_types['card'];
    $this->assertSame('Card', $card['label']);
    $this->assertArrayHasKey('field_heading', $card['block_fields']);
    $this->assertSame('string', $card['block_fields']['field_heading']['type']);
  }

  /**
   * Tests context field_types guidance reaches block type fields too.
   */
  public function testContextFieldTypeGuidanceReachesBlockFields(): void {
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::state()->set('daedalus_blueprint_test.context', [
      'field_types' => [
        'text_with_summary' => ['guidance' => 'Custom guidance from hook.'],
      ],
    ]);
    \Drupal::cache()->deleteAll();
    $schema = $this->container->get('daedalus_blueprint.schema_builder')->getSchema('entity_test', 'entity_test');

    $body = $schema['layout_builder__layout']['available_block_types']['text_section']['block_fields']['body'];
    $this->assertSame('Custom guidance from hook.', $body['guidance']);
  }

  /**
   * Tests block type fields exclude internal fields.
   */
  public function testBlockTypeFieldsExcludeInternal(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $text_fields = $schema['layout_builder__layout']['available_block_types']['text_section']['block_fields'];

    // Internal block_content fields should not appear.
    $this->assertArrayNotHasKey('id', $text_fields);
    $this->assertArrayNotHasKey('uuid', $text_fields);
    $this->assertArrayNotHasKey('type', $text_fields);
    $this->assertArrayNotHasKey('revision_id', $text_fields);
    $this->assertArrayNotHasKey('langcode', $text_fields);
    $this->assertArrayNotHasKey('default_langcode', $text_fields);
    $this->assertArrayNotHasKey('revision_default', $text_fields);
  }

  /**
   * Tests managed keys are inlined in schema.
   */
  public function testManagedKeysInlinedInSchema(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'section_layout_settings' => [
        'layout_onecol' => [
          'width' => [
            'label' => 'Layout width',
            'description' => 'Container width constraint.',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
    ]);

    // Clear caches and re-get the schema builder from rebuilt container.
    \Drupal::cache()->deleteAll();
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');

    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    // No standalone managed_keys dump.
    $this->assertArrayNotHasKey('managed_keys', $schema['layout_builder__layout']);

    // Layout settings inlined in available_layouts.
    $layouts = $schema['layout_builder__layout']['available_layouts'];
    $onecol = NULL;
    foreach ($layouts as $layout) {
      if ($layout['id'] === 'layout_onecol') {
        $onecol = $layout;
        break;
      }
    }
    $this->assertNotNull($onecol);
    $this->assertArrayHasKey('layout_settings', $onecol);
    $this->assertArrayHasKey('width', $onecol['layout_settings']);
    $this->assertSame('Layout width', $onecol['layout_settings']['width']['label']);
  }

  /**
   * Tests schema with no managed keys omits managed_keys key.
   */
  public function testNoManagedKeysOmitted(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');

    // Without test module registering keys, managed_keys should be absent.
    $this->assertArrayNotHasKey('managed_keys', $schema['layout_builder__layout']);
  }

  /**
   * Tests block type schema includes per-type configuration from managed keys.
   */
  public function testBlockTypeConfigurationInSchema(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'text_section' => [
          'title_size' => [
            'label' => 'Title size',
            'description' => 'CSS class for heading size.',
            'schema' => ['type' => 'string', 'enum' => ['text-large1', 'text-large2']],
          ],
          'label_display' => [
            'label' => 'Label display',
            'description' => 'Whether to show the label.',
            'schema' => ['type' => 'string', 'enum' => ['0', 'visible']],
          ],
        ],
      ],
    ]);
    \Drupal::cache()->deleteAll();

    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    $block_types = $schema['layout_builder__layout']['available_block_types'];

    // text_section should have configuration.
    $this->assertArrayHasKey('configuration', $block_types['text_section']);
    $config = $block_types['text_section']['configuration'];
    $this->assertArrayHasKey('title_size', $config);
    $this->assertSame('Title size', $config['title_size']['label']);
    $this->assertArrayHasKey('label_display', $config);

    // Card should NOT have configuration (no managed keys for it).
    $this->assertArrayNotHasKey('configuration', $block_types['card']);
  }

  /**
   * Tests schema caching works.
   */
  public function testSchemaCaching(): void {
    $schema1 = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $schema2 = $this->schemaBuilder->getSchema('entity_test', 'entity_test');

    $this->assertSame($schema1, $schema2);
  }

  /**
   * Tests block type context is merged into schema.
   */
  public function testBlockTypeMetaInSchema(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    \Drupal::state()->set('daedalus_blueprint_test.context', [
      'block_types' => [
        'text_section' => [
          'guidance' => 'Rich text block for body content.',
          'defaults' => ['configuration' => ['body_format' => 'full_html']],
        ],
      ],
    ]);

    \Drupal::cache()->deleteAll();
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    $text_section = $schema['layout_builder__layout']['available_block_types']['text_section'];
    $this->assertSame('Rich text block for body content.', $text_section['guidance']);
    $this->assertSame(['configuration' => ['body_format' => 'full_html']], $text_section['defaults']);

    // Card should not have context.
    $this->assertArrayNotHasKey('guidance', $schema['layout_builder__layout']['available_block_types']['card']);
    $this->assertArrayNotHasKey('defaults', $schema['layout_builder__layout']['available_block_types']['card']);
  }

  /**
   * Tests layout context is merged into schema.
   */
  public function testLayoutMetaInSchema(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    \Drupal::state()->set('daedalus_blueprint_test.context', [
      'layouts' => [
        'layout_onecol' => [
          'guidance' => 'Full-width single column.',
          'defaults' => ['layout_settings' => ['width' => 'max-w-screen-xl']],
        ],
      ],
    ]);

    \Drupal::cache()->deleteAll();
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    $onecol = NULL;
    foreach ($schema['layout_builder__layout']['available_layouts'] as $layout) {
      if ($layout['id'] === 'layout_onecol') {
        $onecol = $layout;
        break;
      }
    }

    $this->assertNotNull($onecol, 'layout_onecol should be in available layouts');
    $this->assertSame('Full-width single column.', $onecol['guidance']);
    $this->assertSame(['layout_settings' => ['width' => 'max-w-screen-xl']], $onecol['defaults']);
  }

  /**
   * Tests entity-level context is merged into schema.
   */
  public function testEntityMetaInSchema(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    \Drupal::state()->set('daedalus_blueprint_test.context', [
      'guidance' => 'Test entity with Layout Builder enabled.',
      'defaults' => ['name' => 'Default Name'],
    ]);

    \Drupal::cache()->deleteAll();
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    $this->assertSame('Test entity with Layout Builder enabled.', $schema['guidance']);
    $this->assertSame(['name' => 'Default Name'], $schema['defaults']);
  }

  /**
   * Tests no context keys exist when no module provides them.
   */
  public function testNoMetaWhenEmpty(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');

    $this->assertArrayNotHasKey('guidance', $schema);
    $this->assertArrayNotHasKey('defaults', $schema);

    // Check block types have no context.
    foreach ($schema['layout_builder__layout']['available_block_types'] as $block_type) {
      $this->assertArrayNotHasKey('guidance', $block_type);
      $this->assertArrayNotHasKey('defaults', $block_type);
    }

    // Check layouts have no context.
    foreach ($schema['layout_builder__layout']['available_layouts'] as $layout) {
      $this->assertArrayNotHasKey('guidance', $layout);
      $this->assertArrayNotHasKey('defaults', $layout);
    }
  }

  /**
   * Tests YAML context is applied to schema.
   */
  public function testYamlContextAppliedToSchema(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    \Drupal::cache()->deleteAll();
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    // Both universal and scoped YAML exist for entity_test.entity_test.
    // Scoped YAML overrides universal for layout_onecol guidance.
    $onecol = NULL;
    foreach ($schema['layout_builder__layout']['available_layouts'] as $layout) {
      if ($layout['id'] === 'layout_onecol') {
        $onecol = $layout;
        break;
      }
    }

    $this->assertNotNull($onecol, 'layout_onecol should be in available layouts');
    $this->assertSame('Scoped one-column guidance for entity_test.', $onecol['guidance']);
    // Universal YAML defaults should still be present (scoped file doesn't
    // declare defaults, so universal ones survive the merge).
    $this->assertSame(['layout_settings' => ['width' => 'full-width']], $onecol['defaults']);
  }

  /**
   * Tests hook context overrides YAML context values.
   */
  public function testHookContextOverridesYaml(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    // Hook sets different guidance for layout_onecol (same key as YAML).
    \Drupal::state()->set('daedalus_blueprint_test.context', [
      'layouts' => [
        'layout_onecol' => [
          'guidance' => 'Hook-provided guidance.',
          'defaults' => ['layout_settings' => ['width' => 'max-w-screen-xl']],
        ],
      ],
    ]);

    \Drupal::cache()->deleteAll();
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    $onecol = NULL;
    foreach ($schema['layout_builder__layout']['available_layouts'] as $layout) {
      if ($layout['id'] === 'layout_onecol') {
        $onecol = $layout;
        break;
      }
    }

    $this->assertNotNull($onecol);
    // Hook value should win over YAML.
    $this->assertSame('Hook-provided guidance.', $onecol['guidance']);
    $this->assertSame(['layout_settings' => ['width' => 'max-w-screen-xl']], $onecol['defaults']);
  }

  /**
   * Tests scoped YAML context is applied for matching entity_type+bundle.
   */
  public function testScopedYamlContextAppliedToSchema(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    \Drupal::cache()->deleteAll();
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    // Scoped YAML provides layout_onecol guidance for entity_test.entity_test.
    // This should override the universal YAML guidance.
    $onecol = NULL;
    foreach ($schema['layout_builder__layout']['available_layouts'] as $layout) {
      if ($layout['id'] === 'layout_onecol') {
        $onecol = $layout;
        break;
      }
    }

    $this->assertNotNull($onecol, 'layout_onecol should be in available layouts');
    $this->assertSame('Scoped one-column guidance for entity_test.', $onecol['guidance']);
  }

  /**
   * Tests scoped YAML context does not apply to non-matching bundles.
   */
  public function testScopedYamlContextNotAppliedToOtherBundles(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    // Enable LB for entity_test_rev so it has layouts in schema.
    $this->enableLayoutBuilder('entity_test_rev', 'entity_test_rev');

    \Drupal::cache()->deleteAll();
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test_rev', 'entity_test_rev');

    $onecol = NULL;
    foreach ($schema['layout_builder__layout']['available_layouts'] as $layout) {
      if ($layout['id'] === 'layout_onecol') {
        $onecol = $layout;
        break;
      }
    }

    $this->assertNotNull($onecol, 'layout_onecol should be in available layouts');
    // Should get universal YAML guidance, NOT the scoped entity_test guidance.
    $this->assertSame('YAML-provided one-column guidance.', $onecol['guidance']);
  }

}
