<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\BlueprintSchemaBuilder;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A schema read asks field access with a stub of the bundle in hand.
 *
 * Core's node handler takes the items as optional and then reads the entity
 * off them for revision_log when the account lacks administer nodes (the
 * type's new-revision setting decides). A bare definition crashed every
 * editor's schema read with "Call to a member function getEntity() on null";
 * the stub gives the rule an entity to read, and the rule's answer shows in
 * what the schema advertises.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintSchemaBuilder::class)]
#[CoversClass(BlueprintAccessChecker::class)]
#[Group('daedalus_blueprint')]
class SchemaFieldAccessSubjectTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'page', 'name' => 'Page', 'new_revision' => TRUE])->save();
    NodeType::create(['type' => 'note', 'name' => 'Note', 'new_revision' => FALSE])->save();
  }

  /**
   * An editor without administer nodes reads the node schema.
   */
  public function testEditorReadsNodeSchema(): void {
    $this->setUpCurrentUser([], ['access content', 'create page content']);

    $schema = $this->container->get('daedalus_blueprint.schema_builder')->getSchema('node', 'page');

    $this->assertSame('page', $schema['bundle']);
    $this->assertArrayHasKey('title', $schema['fields']);
    // Revision metadata never reaches the fields (FieldHandlerManager skips
    // it); the crash came from the style-map pass over every definition.
    $this->assertArrayNotHasKey('revision_log', $schema['fields']);
  }

  /**
   * The checker answers from the stub's items, not from a NULL list.
   *
   * Node's rule answers allowed for a type that creates revisions and
   * neutral (allowedIf(FALSE)) for one that does not; the definition-level
   * check is fail-open against neutral, so both keep the field. What the
   * stub buys is the answer itself: without it the rule dereferenced NULL.
   */
  public function testFieldDefinitionAccessReadsTheStub(): void {
    $this->setUpCurrentUser([], ['access content', 'create page content']);
    $checker = $this->container->get('daedalus_blueprint.access_checker');
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $definition = $this->container->get('entity_field.manager')->getFieldDefinitions('node', 'page')['revision_log'];

    $this->assertTrue($checker->fieldDefinitionAccess($definition, 'edit', $storage->create(['type' => 'page'])));
    $this->assertTrue($checker->fieldDefinitionAccess($definition, 'edit', $storage->create(['type' => 'note'])));
  }

}
