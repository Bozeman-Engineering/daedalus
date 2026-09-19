<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintSchemaBuilder;
use Drupal\daedalus_blueprint\Exception\BlueprintException;
use Drupal\field\Entity\FieldConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the BlueprintSchemaBuilder service.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintSchemaBuilder::class)]
#[Group('daedalus_blueprint')]
class BlueprintSchemaBuilderTest extends BlueprintKernelTestBase {

  /**
   * The schema builder under test.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintSchemaBuilder
   */
  protected BlueprintSchemaBuilder $schemaBuilder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Make field_subtitle required for testing.
    $field_config = FieldConfig::loadByName('entity_test', 'entity_test', 'field_subtitle');
    $field_config->setRequired(TRUE)->save();

    // Set min/max on field_count for testing settings output.
    $field_config = FieldConfig::loadByName('entity_test', 'entity_test', 'field_count');
    $field_config->setSetting('min', 1);
    $field_config->setSetting('max', 100);
    $field_config->save();

    // Clear cached field definitions so the schema builder sees changes.
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->schemaBuilder = $this->container->get('daedalus_blueprint.schema_builder');
  }

  /**
   * Tests basic schema structure.
   */
  public function testBasicSchemaStructure(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');

    $this->assertSame('entity_test', $schema['entity_type']);
    $this->assertSame('entity_test', $schema['bundle']);
    $this->assertNotEmpty($schema['label']);
    $this->assertIsArray($schema['fields']);
    $this->assertNotEmpty($schema['fields']);

    // The envelope note tells the AI field values nest under "fields".
    $this->assertArrayHasKey('_structure', $schema);
    $this->assertStringContainsString('"fields"', $schema['_structure']);
  }

  /**
   * A field the user cannot edit is dropped from the advertised schema.
   */
  public function testFieldAccessDeniedFieldDropped(): void {
    \Drupal::state()->set('views_field_access_test-field', 'field_subtitle');

    // Can create entity_test (clears the schema's entity-access gate) but
    // lacks the field permission, so field_subtitle's edit access is forbidden.
    $this->setUpCurrentUser([], ['administer entity_test content']);

    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');

    $this->assertArrayNotHasKey('field_subtitle', $schema['fields']);
    // Unrestricted fields are still advertised.
    $this->assertArrayHasKey('field_count', $schema['fields']);
  }

  /**
   * The same field is advertised for a user permitted to edit it.
   */
  public function testFieldAccessAllowedFieldKept(): void {
    \Drupal::state()->set('views_field_access_test-field', 'field_subtitle');

    $this->setUpCurrentUser([], [
      'administer entity_test content',
      'view test entity field',
    ]);

    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');

    $this->assertArrayHasKey('field_subtitle', $schema['fields']);
  }

  /**
   * Tests that entity key fields are excluded from schema.
   */
  public function testEntityKeyFieldsExcluded(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $fields = $schema['fields'];

    // Entity keys should not appear.
    $this->assertArrayNotHasKey('id', $fields);
    $this->assertArrayNotHasKey('uuid', $fields);
    $this->assertArrayNotHasKey('type', $fields);
    $this->assertArrayNotHasKey('langcode', $fields);

    // Label field (name) SHOULD appear.
    $this->assertArrayHasKey('name', $fields);
  }

  /**
   * Tests schema for simple scalar fields.
   */
  public function testSimpleScalarFields(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $fields = $schema['fields'];

    // String field.
    $this->assertSame('string', $fields['field_subtitle']['type']);
    $this->assertSame('Subtitle', $fields['field_subtitle']['label']);
    $this->assertTrue($fields['field_subtitle']['required']);
    $this->assertSame(255, $fields['field_subtitle']['max_length']);
    $this->assertArrayNotHasKey('cardinality', $fields['field_subtitle']);

    // Integer field.
    $this->assertSame('integer', $fields['field_count']['type']);
    $this->assertSame('Count', $fields['field_count']['label']);
    $this->assertFalse($fields['field_count']['required']);

    // Boolean field.
    $this->assertSame('boolean', $fields['field_published']['type']);
    $this->assertSame('Published', $fields['field_published']['label']);

    // Email field.
    $this->assertSame('email', $fields['field_email']['type']);
    $this->assertSame('Email', $fields['field_email']['label']);
  }

  /**
   * Tests schema for text fields includes properties and available_formats.
   */
  public function testTextFieldSchema(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $fields = $schema['fields'];

    // text_long field should have properties and available_formats.
    $this->assertSame('text_long', $fields['field_description']['type']);
    $this->assertContains('value', $fields['field_description']['properties']);
    $this->assertContains('format', $fields['field_description']['properties']);
    $this->assertArrayHasKey('available_formats', $fields['field_description']);
    $this->assertContains('plain_text', $fields['field_description']['available_formats']);

    // text_with_summary field should include summary property.
    $this->assertSame('text_with_summary', $fields['field_body']['type']);
    $this->assertContains('summary', $fields['field_body']['properties']);
    $this->assertArrayHasKey('available_formats', $fields['field_body']);
  }

  /**
   * Tests multi-value field includes cardinality.
   */
  public function testMultiValueFieldCardinality(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $fields = $schema['fields'];

    // Multi-value field should have cardinality.
    $this->assertArrayHasKey('cardinality', $fields['field_tags_text']);
    $this->assertSame(-1, $fields['field_tags_text']['cardinality']);

    // Single-value field should NOT have cardinality.
    $this->assertArrayNotHasKey('cardinality', $fields['field_subtitle']);
  }

  /**
   * Tests that min/max settings appear as top-level schema keys.
   */
  public function testMinMaxSettingsInSchema(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $fields = $schema['fields'];

    $this->assertSame(1, $fields['field_count']['min']);
    $this->assertSame(100, $fields['field_count']['max']);
    $this->assertArrayNotHasKey('constraints', $fields['field_count']);
  }

  /**
   * Tests that schema results are cached.
   */
  public function testSchemaCaching(): void {
    $schema1 = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $schema2 = $this->schemaBuilder->getSchema('entity_test', 'entity_test');

    $this->assertSame($schema1, $schema2);

    // Verify cache entry exists. The cache id is varied by the current user's
    // permissions hash (the schema is access-filtered per user), so compute it
    // the same way getSchema() does rather than hardcoding the hash.
    $permission_keys = \Drupal::service('cache_contexts_manager')
      ->convertTokensToKeys(['user.permissions'])
      ->getKeys();
    $cache_id = 'daedalus_blueprint:schema:' . implode(':', array_merge(
      ['entity_test', 'entity_test', 'full'],
      $permission_keys,
    ));
    $cache = \Drupal::cache()->get($cache_id);
    $this->assertNotFalse($cache);
    $this->assertSame($schema1, $cache->data);
  }

  /**
   * Tests that cache invalidation works via entity_field_info tag.
   */
  public function testCacheInvalidation(): void {
    $schema1 = $this->schemaBuilder->getSchema('entity_test', 'entity_test');

    // Invalidate the cache tag.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['entity_field_info']);

    // Cache should be invalidated.
    $cache = \Drupal::cache()->get('daedalus_blueprint:schema:entity_test:entity_test:full');
    $this->assertFalse($cache);

    // Rebuild should produce the same result.
    $schema2 = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $this->assertSame($schema1, $schema2);
  }

  /**
   * Tests that handler-provided guidance appears inline on fields.
   */
  public function testHandlerGuidanceInlineOnFields(): void {
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $fields = $schema['fields'];

    // Text fields should have inline guidance from TextFieldHandler.
    $this->assertArrayHasKey('guidance', $fields['field_description']);
    $this->assertStringContainsString('bare string', $fields['field_description']['guidance']);
    $this->assertArrayHasKey('guidance', $fields['field_body']);
    $this->assertStringContainsString('text_with_summary', $fields['field_body']['guidance']);

    // Simple scalar fields (string, integer) should NOT have guidance
    // since DefaultFieldHandler returns NULL.
    $this->assertArrayNotHasKey('guidance', $fields['field_subtitle']);
    $this->assertArrayNotHasKey('guidance', $fields['field_count']);

    // No top-level field_types section should exist.
    $this->assertArrayNotHasKey('field_types', $schema);
  }

  /**
   * Tests that context hook field_types guidance overrides handler guidance.
   */
  public function testContextFieldTypeGuidanceOverridesHandler(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    // Set field_types context via state (used by the test module's hook).
    \Drupal::state()->set('daedalus_blueprint_test.context', [
      'field_types' => [
        'text_long' => [
          'guidance' => 'Custom guidance from hook.',
        ],
      ],
    ]);

    \Drupal::cache()->deleteAll();
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');
    $fields = $schema['fields'];

    // text_long field should have the hook-provided guidance.
    $this->assertSame('Custom guidance from hook.', $fields['field_description']['guidance']);

    // text_with_summary field should still have the handler default
    // (hook only targeted text_long).
    $this->assertStringContainsString('text_with_summary', $fields['field_body']['guidance']);
  }

  /**
   * Tests context field_types guidance on fields without handler guidance.
   */
  public function testContextFieldTypeGuidanceOnFieldsWithoutHandler(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    // Provide guidance for 'string' type which has no handler guidance.
    \Drupal::state()->set('daedalus_blueprint_test.context', [
      'field_types' => [
        'string' => [
          'guidance' => 'Plain text, no HTML allowed.',
        ],
      ],
    ]);

    \Drupal::cache()->deleteAll();
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');
    $fields = $schema['fields'];

    // String fields should now have guidance from the hook.
    $this->assertSame('Plain text, no HTML allowed.', $fields['field_subtitle']['guidance']);
    $this->assertSame('Plain text, no HTML allowed.', $fields['field_tags_text']['guidance']);
  }

  /**
   * Context anatomy is inlined at every level; cache_tags never leaks.
   *
   * The anatomy key is the element model's markup-shell fragment
   * (daedalus_ui feeds it in production); the seam itself is
   * feeder-agnostic and rides the same merge as guidance/defaults. The
   * reserved cache_tags bookkeeping key joins the cache metadata but must
   * never reach schema output.
   */
  public function testContextAnatomyInlinedAndCacheTagsStripped(): void {
    $this->enableModules(['daedalus_blueprint_test']);
    $this->enableLayoutBuilder();
    $this->createBlockContentType('anatomy_block', 'Anatomy block');

    \Drupal::state()->set('daedalus_blueprint_test.context', [
      'anatomy' => [
        'kinds' => ['field' => ['elements' => ['.field__item' => ['contains' => 'value']]]],
      ],
      'block_types' => [
        'anatomy_block' => [
          'anatomy' => ['composed' => TRUE],
        ],
      ],
      'layouts' => [
        'layout_onecol' => [
          'anatomy' => ['elements' => ['.layout__container' => ['contains' => 'regions']]],
        ],
      ],
      'cache_tags' => ['daedalus_blueprint_test_anatomy'],
    ]);

    \Drupal::cache()->deleteAll();
    $schema = $this->container->get('daedalus_blueprint.schema_builder')
      ->getSchema('entity_test', 'entity_test');

    // Entity-level anatomy merges into the schema root.
    $this->assertSame('value', $schema['anatomy']['kinds']['field']['elements']['.field__item']['contains']);

    // Block type and layout anatomy inline into their catalog entries, the
    // managed-keys pattern.
    $this->assertTrue($schema['layout_builder__layout']['available_block_types']['anatomy_block']['anatomy']['composed']);
    $onecol = array_values(array_filter(
      $schema['layout_builder__layout']['available_layouts'],
      static fn (array $layout) => $layout['id'] === 'layout_onecol',
    ))[0];
    $this->assertSame('regions', $onecol['anatomy']['elements']['.layout__container']['contains']);

    // The bookkeeping key stays out of the schema.
    $this->assertArrayNotHasKey('cache_tags', $schema);
  }

  /**
   * Tests that invalid entity type throws exception.
   */
  public function testInvalidEntityTypeThrowsException(): void {
    $this->expectException(BlueprintException::class);
    $this->expectExceptionMessage("Entity type 'nonexistent' does not exist.");
    $this->schemaBuilder->getSchema('nonexistent', 'some_bundle');
  }

  /**
   * Tests that invalid bundle throws exception.
   */
  public function testInvalidBundleThrowsException(): void {
    $this->expectException(BlueprintException::class);
    $this->expectExceptionMessage("Bundle 'nonexistent' does not exist on entity type 'entity_test'.");
    $this->schemaBuilder->getSchema('entity_test', 'nonexistent');
  }

  /**
   * Tests that non-fieldable entity type throws exception.
   */
  public function testNonFieldableEntityTypeThrowsException(): void {
    $this->enableModules(['entity_test']);
    $this->expectException(BlueprintException::class);
    $this->expectExceptionMessage("not fieldable");
    // 'date_format' is a config entity that is not fieldable.
    $this->schemaBuilder->getSchema('date_format', 'date_format');
  }

}
