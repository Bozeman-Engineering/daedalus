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
 * Tests the stencils group of the schema's materials fragment.
 *
 * The stencils are one of the site's materials: core renders the schema's
 * `materials` fragment from the roster, and the stencil provider's records
 * land there as the `stencils` group with real semantics per entry (label,
 * description, the root kind naming what a placement lands as, the
 * definition printed as a page-language fragment or the reason it cannot
 * be). The
 * guidance teaches the page language's placement spelling and the capture
 * gesture, never the retired JSON op. The fragment declares the stencil
 * cache tag through the roster, so saving a stencil refreshes the
 * advertised vocabulary.
 */
#[CoversClass(MaterialsSchema::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilSchemaCatalogTest extends StencilKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_blueprint_stencil_test'];

  /**
   * The schema builder.
   */
  protected function schemaBuilder(): BlueprintSchemaBuilderInterface {
    return \Drupal::service('daedalus_blueprint.schema_builder');
  }

  /**
   * The catalog advertises both sources with real semantics.
   */
  public function testCatalog(): void {
    $schema = $this->schemaBuilder()->getSchema('entity_test', 'entity_test');
    $materials = $schema['materials'];
    $guidance = $materials['guidance'];
    // The placement spelling is the page language's element; the retired
    // op and its location grammar never come back.
    $this->assertStringContainsString('<pb-stencil name="id"/>', $guidance);
    $this->assertStringNotContainsString('instantiate_stencil', $guidance);
    $this->assertStringNotContainsString('{anchor, placement', $guidance);
    // Stencils are framed as one of the site's materials, and the
    // save_material recipe is how-to-when-asked, never an unprompted
    // minting invitation.
    $this->assertStringContainsString('save_material', $guidance);
    $this->assertStringContainsString('When the user asks', $guidance);
    $this->assertStringContainsString(MaterialsSchema::LOOKS_BY_POSITION, $guidance, 'The owned-looks rule rides while the site has stencils.');

    // The fixture module ships two section-rooted stencils on multi-region
    // layouts; the entries carry the label, description, root kind (the
    // anchor grammar's one discriminator) and, since the page language
    // prints no region node, the printer's reason in place of a fragment,
    // keyed by id.
    $this->assertSame([
      'label' => 'Hero',
      'description' => 'Single-column hero banner with a heading.',
      'root' => 'section',
      'unprintable' => 'The page language cannot print this stencil (multi-region layouts have no dialect print (spec v0.2 covers container layouts); region nodes cannot be printed); place it as <pb-stencil name="hero"/> and style the placed elements once they print with ids.',
    ], $materials['stencils']['hero']);
    $this->assertSame('section', $materials['stencils']['call_to_action']['root']);
    $this->assertArrayNotHasKey('fragment', $materials['stencils']['call_to_action']);

    // Saving a content stencil refreshes the catalog: the roster's cache
    // tags were declared on the schema cache entry.
    $stencil = Stencil::create([
      'label' => 'My Block',
      'description' => 'A block-rooted content stencil.',
    ]);
    $stencil->setElement(['kind' => 'block', 'block_type' => 'basic']);
    $stencil->save();

    $schema = $this->schemaBuilder()->getSchema('entity_test', 'entity_test');
    $entry = $schema['materials']['stencils']['my_block'];
    $this->assertSame('block', $entry['root'], 'A block-rooted stencil advertises root: block (the anchor location grammar).');
    $this->assertSame(['label', 'description', 'root', 'fragment'], array_keys($entry));
    $this->assertSame("<pb-basic />\n", $entry['fragment'], 'A block stencil prints as its one element, id-less.');

    // The AI opt-out: a withheld stencil leaves the schema (the fragment
    // reads the roster's offered view) while the full roster, what
    // legality and the palette read, still carries it.
    $stencil->setOffered(FALSE)->save();
    $schema = $this->schemaBuilder()->getSchema('entity_test', 'entity_test');
    $this->assertArrayNotHasKey('my_block', $schema['materials']['stencils']);
    $this->assertArrayHasKey('hero', $schema['materials']['stencils']);
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertFalse($roster->stencils()['my_block']->offered);
    $this->assertArrayNotHasKey('my_block', $roster->offered()->stencils());
    $stencil->setOffered(TRUE)->save();
    $this->assertArrayHasKey('my_block', $this->schemaBuilder()->getSchema('entity_test', 'entity_test')['materials']['stencils']);
  }

}
