<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\daedalus_blueprint\BlueprintSchemaBuilderInterface;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Zero stencils means NO stencils group and no stencil prose: empty is absent.
 *
 * Grows-by-data: the AI projection grows only with materials and never
 * carries teaching prose for materials that do not exist, so a site with
 * zero stencils gets no stencils group in the schema's materials, no
 * stencil sentence in its guidance, and daedalus_stylish's style-guide guidance
 * drops its stencil vocabulary on the same fact. The first saved stencil
 * surfaces all three through the roster's cache tags: this test drives
 * the REAL cached schema builder twice, so a stale cache entry would fail
 * the second half.
 *
 * The populated side (entry shape, both sources) is pinned in
 * StencilSchemaCatalogTest; this covers the absent-to-present flip.
 */
#[CoversClass(MaterialsSchema::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilSchemaEmptyCatalogTest extends StencilKernelTestBase {

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
   * Zero stencils: no group, no prose; the first stencil surfaces them.
   */
  public function testEmptyCatalogIsAbsentUntilPopulated(): void {
    // No file fixtures are enabled and no content stencil exists: the
    // group is absent entirely, not an empty roster under a teaching
    // paragraph, and neither guidance paragraph names stencils.
    $schema = $this->schemaBuilder()->getSchema('entity_test', 'entity_test');
    $this->assertArrayHasKey('layout_builder__layout', $schema);
    $this->assertArrayNotHasKey('stencils', $schema['materials']);
    $this->assertStringNotContainsStringIgnoringCase('stencil', $schema['materials']['guidance']);
    $style_guide = $schema['fields']['daedalus_stylish_styles']['style_guide'];
    $this->assertArrayNotHasKey('materials', $style_guide, 'The materials are the schema\'s own key, never the field\'s.');
    $this->assertStringNotContainsStringIgnoringCase('stencil', $style_guide['guidance']);

    // The first stencil grows the projection: every surface flips through
    // the roster's cache tags (the schema cache was warm).
    $stencil = Stencil::create([
      'label' => 'My Block',
      'description' => 'A block-rooted content stencil.',
    ]);
    $stencil->setElement(['kind' => 'block', 'block_type' => 'basic']);
    $stencil->save();

    $schema = $this->schemaBuilder()->getSchema('entity_test', 'entity_test');
    $materials = $schema['materials'];
    $this->assertSame('block', $materials['stencils']['my_block']['root']);
    $this->assertStringContainsString('<pb-stencil name="id"/>', $materials['guidance']);
    $this->assertStringContainsString('save_material', $materials['guidance']);
    $style_guide = $schema['fields']['daedalus_stylish_styles']['style_guide'];
    $this->assertStringContainsString('and the stencils', $style_guide['guidance']);
  }

}
