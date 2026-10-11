<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_blueprint\BlueprintSchemaBuilderInterface;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use Drupal\daedalus_blueprint_library\BlueprintInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the blueprints group of the schema's materials fragment.
 *
 * The blueprints are one of the site's materials: core renders the schema's
 * `materials` fragment from the roster, and the blueprint provider's records
 * land there as the `blueprints` group with real semantics per entry (label,
 * description, the blueprint's scope, the definition printed as a
 * page-language fragment or the reason it cannot be). The guidance teaches
 * the page language's placement spelling and the capture gesture, never
 * the retired JSON op. The fragment declares the vocabulary cache tag
 * through the roster, so saving a blueprint refreshes the advertised
 * vocabulary.
 */
#[CoversClass(MaterialsSchema::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintSchemaCatalogTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * daedalus_blueprint_test declares the container layout the page
   * language prints a container with.
   */
  protected static $modules = ['daedalus_blueprint_library_test', 'daedalus_blueprint_test'];

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
    $this->assertStringContainsString('<pb-blueprint name="id"/>', $guidance);
    $this->assertStringNotContainsString('place_blueprint', $guidance);
    $this->assertStringNotContainsString('{anchor, placement', $guidance);
    // Blueprints are framed as one of the site's materials, and the
    // save_material recipe is how-to-when-asked, never an unprompted
    // minting invitation.
    $this->assertStringContainsString('save_material', $guidance);
    $this->assertStringContainsString('When the user asks', $guidance);
    $this->assertStringContainsString(MaterialsSchema::LOOKS_BY_POSITION, $guidance, 'The owned-looks rule rides while the site has blueprints.');

    // The fixture module ships two blueprints on core layouts (region
    // nodes); the entries carry the label, description, scope and, since
    // a print of a section the page language does not spell is no write
    // template, the print's first note in place of a fragment, keyed by
    // id. A bundle's schema lists what its host is offered: the call to
    // action needs an image block type this site has none of, so it is
    // not offered to entity_test and is absent here (the roster still
    // carries it), as it is absent from the Add panel on the same canvas.
    // The listing is the site favorites (the hero ships favorite: true);
    // the index beside it counts the hero under the reserved bucket, since
    // its groups (heroes, openers) are ids no layer declares on this
    // kernel and an undeclared id counts nowhere.
    $this->assertSame([
      'label' => 'Hero',
      'description' => 'Single-column hero banner with a heading.',
      'scope' => 'block',
      'unprintable' => 'The page language cannot print this blueprint (<pb-container> is a section on the "layout_onecol" layout, which the page language does not spell); place it as <pb-blueprint name="hero"/> and style the placed elements once they print with ids.',
    ], $materials['blueprints']['hero']);
    $this->assertSame(['hero'], array_keys($materials['blueprints']));
    $this->assertSame([DialectCatalog::UNGROUPED => ['blueprints' => 1]], $materials['groups']);
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame(['text', 'image'], $roster->blueprints()['call_to_action']->requires['block_types']);
    $this->assertSame(['heroes', 'openers'], $roster->blueprints()['hero']->groups);
    $this->assertArrayNotHasKey('starts', $schema, 'No page blueprint, no starts.');

    // Saving a content blueprint refreshes the catalog: the roster's cache
    // tags were declared on the schema cache entry. Its document is one
    // container-layout section holding a plugin block, which the page
    // language prints as a fragment. A favorite, so the default listing
    // prints it; the index counts it beside the hero.
    $blueprint = $this->createBlueprint([
      'label' => 'My Block',
      'description' => 'A content blueprint holding one block.',
      'favorite' => TRUE,
    ], [$this->section('eb_test_container', [$this->pluginComponent('system_powered_by_block')])]);

    $schema = $this->schemaBuilder()->getSchema('entity_test', 'entity_test');
    $this->assertSame([DialectCatalog::UNGROUPED => ['blueprints' => 2]], $schema['materials']['groups']);
    $entry = $schema['materials']['blueprints']['my_block'];
    $this->assertSame('block', $entry['scope'], 'A content blueprint advertises its scope.');
    $this->assertSame(['label', 'description', 'scope', 'entity_id', 'fragment'], array_keys($entry), 'A saved blueprint carries the page id the AI reads it back by.');
    $this->assertSame((string) $blueprint->id(), $entry['entity_id']);
    $this->assertStringStartsWith('<pb-container', $entry['fragment'], 'A container-layout document prints as a fragment.');
    $this->assertStringContainsString('<pb-block plugin="system_powered_by_block" />', $entry['fragment']);
    $this->assertStringNotContainsString(' id="', $entry['fragment'], 'A fragment prints without ids.');

    // The AI opt-out: a withheld blueprint leaves the schema (the fragment
    // reads the roster's offered view) while the full roster, what
    // legality and the palette read, still carries it.
    $blueprint->setOffered(FALSE)->save();
    $schema = $this->schemaBuilder()->getSchema('entity_test', 'entity_test');
    $this->assertArrayNotHasKey('my_block', $schema['materials']['blueprints']);
    $this->assertArrayHasKey('hero', $schema['materials']['blueprints']);
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertFalse($roster->blueprints()['my_block']->offered);
    $this->assertArrayNotHasKey('my_block', $roster->offered()->blueprints());
    $blueprint->setOffered(TRUE)->save();
    $this->assertArrayHasKey('my_block', $this->schemaBuilder()->getSchema('entity_test', 'entity_test')['materials']['blueprints']);
  }

  /**
   * A page bundle's schema lists its page blueprints under starts, nowhere else.
   *
   * Scopes do not mix on an offering surface: a page blueprint is what a
   * page of the bundle may start as (page_create's `start`), never a
   * placement, so it is under `starts` with its face and page id (label,
   * description, entity_id; no fragment, since a whole page's print is
   * heavy and page_read reads it on demand) and absent from
   * materials.blueprints. The starts are the offer asked for page scope
   * (a root, fits the bundle's host): one whose allow-list names another
   * bundle is not a start here, and one its author withheld from the AI
   * leaves the key as it leaves the fragment. A block blueprint is never
   * a start. Before any page blueprint fits, the key is absent.
   */
  public function testStarts(): void {
    $page = $this->createBlueprint([
      'label' => 'Whole page',
      'machine_name' => 'whole_page',
      'description' => 'A page document needing nothing of its host.',
      'scope' => BlueprintInterface::SCOPE_PAGE,
    ]);
    $this->createBlueprint([
      'label' => 'Article page',
      'machine_name' => 'article_page',
      'description' => 'For articles only.',
      'scope' => BlueprintInterface::SCOPE_PAGE,
      'bundles' => ['node:article'],
    ]);
    $this->createBlueprint([
      'label' => 'Quiet page',
      'machine_name' => 'quiet_page',
      'description' => 'Withheld from the AI.',
      'scope' => BlueprintInterface::SCOPE_PAGE,
      'ai_offered' => FALSE,
    ]);
    $this->createBlueprint([
      'label' => 'My Block',
      'machine_name' => 'my_block',
    ], [$this->section('eb_test_container', [$this->pluginComponent('system_powered_by_block')])]);

    $schema = $this->schemaBuilder()->getSchema('entity_test', 'entity_test');
    $this->assertSame([
      'whole_page' => [
        'label' => 'Whole page',
        'description' => 'A page document needing nothing of its host.',
        'entity_id' => (string) $page->id(),
      ],
    ], $schema['starts']);
    $this->assertSame(['hero'], array_keys($schema['materials']['blueprints']), 'The fragment lists the block-scope favorites alone.');
    $this->assertSame([DialectCatalog::UNGROUPED => ['blueprints' => 2]], $schema['materials']['groups'], 'The index counts block scope alone, the non-favorite included.');
    $this->assertStringNotContainsString('Whole page', json_encode($schema['materials']));
    $this->assertStringNotContainsString('Withheld from the AI', json_encode($schema));

    // The roster keeps every one of them: the schema is an offering
    // surface, and legality reads the full roster.
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame(['call_to_action', 'hero', 'whole_page', 'article_page', 'quiet_page', 'my_block'], array_keys($roster->blueprints()));
    $this->assertSame(['whole_page', 'article_page', 'quiet_page'], array_keys($roster->blueprints('page')));
  }

}
