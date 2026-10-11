<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_blueprint\BlueprintSchemaBuilderInterface;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Zero blueprints means NO blueprints group and no blueprint prose: empty is absent.
 *
 * Grows-by-data: the AI projection grows only with materials and never
 * carries teaching prose for materials that do not exist, so a site with
 * zero blueprints gets no blueprints group in the schema's materials, no
 * blueprint sentence in its guidance, and daedalus_stylish's style-guide
 * guidance drops its blueprint vocabulary on the same fact. The first saved
 * blueprint surfaces all three through the roster's cache tags: this test
 * drives the REAL cached schema builder twice, so a stale cache entry
 * would fail the second half.
 *
 * The populated side (entry shape, both sources) is pinned in
 * BlueprintSchemaCatalogTest; this covers the absent-to-present flip.
 */
#[CoversClass(MaterialsSchema::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintSchemaEmptyCatalogTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The roster's daedalus_stylish provider queries the variant, swatch, and skin
    // storages on every build.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);

    // Styling enabled the product way: the field manager owns the map
    // field, so the schema carries a style_guide whose guidance this test
    // reads.
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * The schema builder.
   */
  protected function schemaBuilder(): BlueprintSchemaBuilderInterface {
    return \Drupal::service('daedalus_blueprint.schema_builder');
  }

  /**
   * Zero blueprints: no group, no prose; the first blueprint surfaces them.
   */
  public function testEmptyCatalogIsAbsentUntilPopulated(): void {
    // No file fixtures are enabled and no content blueprint exists: the
    // group is absent entirely, not an empty roster under a teaching
    // paragraph, and neither guidance paragraph names blueprints.
    $schema = $this->schemaBuilder()->getSchema('entity_test', 'entity_test');
    $this->assertArrayHasKey('layout', $schema);
    $this->assertArrayNotHasKey('blueprints', $schema['materials']);
    $this->assertArrayNotHasKey('groups', $schema['materials'], 'No blueprint and no vocabulary: no index either.');
    $this->assertStringNotContainsStringIgnoringCase('blueprint', $schema['materials']['guidance']);
    $style_guide = $schema['styles']['style_guide'];
    $this->assertArrayNotHasKey('materials', $style_guide, 'The materials are the schema\'s own key, never the field\'s.');
    $this->assertStringNotContainsString('and the blueprints', $style_guide['guidance']);

    // The first blueprint grows the projection: every surface flips through
    // the roster's cache tags (the schema cache was warm).
    $this->createBlueprint([
      'label' => 'My Block',
      'description' => 'A content blueprint holding one block.',
      'favorite' => TRUE,
    ]);

    $schema = $this->schemaBuilder()->getSchema('entity_test', 'entity_test');
    $materials = $schema['materials'];
    $this->assertSame('block', $materials['blueprints']['my_block']['scope']);
    $this->assertSame([DialectCatalog::UNGROUPED => ['blueprints' => 1]], $materials['groups'], 'The first blueprint grows the index too.');
    $this->assertStringContainsString('<pb-blueprint name="id"/>', $materials['guidance']);
    $this->assertStringContainsString('save_material', $materials['guidance']);
    $style_guide = $schema['styles']['style_guide'];
    $this->assertStringContainsString('and the blueprints', $style_guide['guidance']);
  }

}
