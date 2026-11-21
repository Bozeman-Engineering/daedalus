<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Access;

use Drupal\daedalus_blueprint\FieldHandler\InlineEntityFieldHandler;
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the write path enforces field-edit access on nested content.
 *
 * CP2a: deserialization must honor per-field edit access where core's widgets
 * do, on content the root field-access check never reaches — inline entities
 * (paragraphs via entity_reference_revisions) and inline blocks inside a
 * layout. Owned children get field-level checks only; there is no independent
 * entity-level access on them.
 *
 * (The layout_builder__layout field itself is deliberately NOT gated: core
 * hardcodes its edit access to forbidden for everyone, so per-entity layout
 * access is carried by the entity 'update' check, not by that field.)
 *
 * The base test class runs as an admin (access-transparent). Each test here
 * switches to a restricted user and uses entity_test's
 * `views_field_access_test-field` state hook to forbid a single named field
 * for users lacking 'view test entity field'.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class BlueprintWriteAccessTest extends BlueprintKernelTestBase {

  /**
   * The blueprint deserializer.
   */
  protected BlueprintDeserializerInterface $deserializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Layout Builder with an inline block type carrying a restrictable field.
    // entity_reference_revisions is enabled per-method (it is optional and
    // absent in some builds), so it must not gate the whole class here.
    // The whole-layout write anchors a container's children on its
    // identity, which is daedalus_layout's.
    $this->enableLayoutBuilder();
    $this->enableModules(['daedalus_ui', 'daedalus_tempstore', 'daedalus_blueprint_api', 'daedalus_layout']);
    $this->createBlockContentType('card', 'Card');
    $this->createBlockField('field_heading', 'string', 'card', ['max_length' => 255]);

    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
  }

  /**
   * Sets up the inline (owned) paragraph fixtures, skipping if ERR is absent.
   *
   * @return \Drupal\daedalus_blueprint\FieldHandler\InlineEntityFieldHandler
   *   The inline entity field handler, with services refreshed after the
   *   entity_reference_revisions module is enabled.
   */
  protected function setUpInlineParagraphs(): InlineEntityFieldHandler {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev', ['max_length' => 255]);
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
      ['entity_test_rev'],
    );

    // Refresh service references after enabling the module.
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    return $this->container->get('daedalus_blueprint.field_handler.inline_entity');
  }

  /**
   * A field on a paragraph the user cannot edit blocks the write.
   */
  public function testInlineEntityFieldEditDenied(): void {
    $handler = $this->setUpInlineParagraphs();
    \Drupal::state()->set('views_field_access_test-field', 'field_heading');

    // A user who can write entity_test content but lacks the field permission.
    $this->setUpCurrentUser([], ['administer entity_test content']);

    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'host'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'type' => 'entity_test_rev',
        'fields' => ['field_heading' => 'Should be blocked'],
      ],
    ];

    $result = $handler->deserialize($value, $definition);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_FIELD', $errors[0]->getCode());
    $this->assertSame(
      'fields.field_paragraphs[0].fields.field_heading',
      $errors[0]->getPath(),
    );
  }

  /**
   * The same paragraph field applies for a user who can edit it.
   */
  public function testInlineEntityFieldEditAllowed(): void {
    $handler = $this->setUpInlineParagraphs();
    \Drupal::state()->set('views_field_access_test-field', 'field_heading');

    // A user holding the field permission.
    $this->setUpCurrentUser([], ['view test entity field']);

    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'host'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'type' => 'entity_test_rev',
        'fields' => ['field_heading' => 'Allowed'],
      ],
    ];

    $result = $handler->deserialize($value, $definition);

    $this->assertFalse($result->hasErrors());
    $entity = $result->getValue()[0]['entity'];
    $this->assertSame('Allowed', $entity->get('field_heading')->value);
  }

  /**
   * A field on an inline block the user cannot edit blocks the layout write.
   */
  public function testInlineBlockFieldEditDenied(): void {
    \Drupal::state()->set('views_field_access_test-field', 'field_heading');

    // The actor may write the page, place blocks and create a card (the
    // root gate, the per-op gate and the block type's create access),
    // lacking only the field's own permission.
    $this->setUpCurrentUser([], [
      'administer entity_test content',
      'configure any layout',
      'create and edit custom blocks',
      'create card block content',
    ]);

    $result = $this->deserializer->deserialize($this->cardBlueprint('Should be blocked'));

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_FIELD', $errors[0]->getCode());
    $this->assertSame(
      'layout[0].children[0].children[0].block_fields.field_heading',
      $errors[0]->getPath(),
    );
  }

  /**
   * The same inline block field applies for a user who can edit it.
   */
  public function testInlineBlockFieldEditAllowed(): void {
    \Drupal::state()->set('views_field_access_test-field', 'field_heading');

    $this->setUpCurrentUser([], [
      'administer entity_test content',
      'configure any layout',
      'create and edit custom blocks',
      'create card block content',
      'view test entity field',
    ]);

    $result = $this->deserializer->deserialize($this->cardBlueprint('Allowed'));

    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $sections = $result->getEntity()->get('layout_builder__layout')->getSections();
    $block = $this->unserializeBlock($sections[0]->getComponent('faf95c53-35e7-74e1-184e-041152031df4')->get('configuration')['block_serialized']);
    $this->assertSame('Allowed', $block->get('field_heading')->value);
  }

  /**
   * A restricted root field blocks the surgical updateFields write.
   *
   * Exercises BlueprintOperations::validateAndApplyFields — the single
   * chokepoint for all surgical write ops (updateElement,
   * updateFields, addElement, batch). CP2a covered the deserialize/inline/
   * layout paths; this covers the surgical-operations path.
   */
  public function testSurgicalUpdateFieldsEditDenied(): void {
    \Drupal::state()->set('views_field_access_test-field', 'field_subtitle');

    // A user who can write entity_test content but lacks the field permission.
    $this->setUpCurrentUser([], ['administer entity_test content']);

    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'host']);

    $operations = $this->container->get('daedalus_blueprint.operations');
    $result = $operations->updateFields($this->host($entity), ['field_subtitle' => 'Blocked']);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_FIELD', $errors[0]->getCode());
    $this->assertSame('fields.field_subtitle', $errors[0]->getPath());
  }

  /**
   * The same surgical updateFields write applies for a permitted user.
   */
  public function testSurgicalUpdateFieldsEditAllowed(): void {
    \Drupal::state()->set('views_field_access_test-field', 'field_subtitle');

    // A user holding the field permission.
    $this->setUpCurrentUser([], ['view test entity field']);

    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'host']);

    $operations = $this->container->get('daedalus_blueprint.operations');
    $result = $operations->updateFields($this->host($entity), ['field_subtitle' => 'Allowed']);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('Allowed', $entity->get('field_subtitle')->value);
  }

  /**
   * Placing and copying an inline block ask the block type's create access.
   *
   * Insert on the destination is not the whole question: a placement makes
   * a new block, and so does a copy of a block or of the container holding
   * it. An actor who may write the page and arrange its layout but may not
   * create a card is refused on every one of those roads, with the grants
   * that would open the gesture named; a batch acting for the site is not
   * asked.
   */
  public function testBlockCreationAsksTheBlockTypeCreateAccess(): void {
    $container = 'c0ffee00-0000-4000-8000-000000000001';
    $card = 'faf95c53-35e7-74e1-184e-041152031df4';
    $blueprint = $this->cardBlueprint('Shipped');
    $blueprint['layout'][0]['uuid'] = $container;

    // The page is made by someone who may make all of it.
    $result = $this->deserializer->deserialize($blueprint);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $entity = $result->getEntity();
    $entity->save();
    $operations = $this->container->get('daedalus_blueprint.operations');
    $components = fn (): int => count($this->host($entity)->sections()[0]->getComponents());
    $sections = fn (): int => count($this->host($entity)->sections());
    $this->assertSame(1, $components());

    $permissions = [
      'administer entity_test content',
      'configure any layout',
      'create and edit custom blocks',
    ];
    $this->setUpCurrentUser([], $permissions);

    $add = [
      'op' => 'add_element',
      'block_type' => 'card',
      'location' => ['anchor' => $container, 'placement' => 'inside'],
      'properties' => ['field_heading' => 'Placed'],
    ];
    $refused = function (array $operation, string $path) use ($operations, $entity): void {
      $result = $operations->batchOperations($this->host($entity), [$operation]);
      $this->assertFalse($result->isSuccess());
      $error = $result->getErrors()[0]->toArray();
      $this->assertSame('ACCESS_DENIED_PERMISSION', $error['code']);
      $this->assertStringEndsWith($path, $error['path']);
      $this->assertSame(['create card block content', 'administer block content'], $error['context']['permissions']);
    };

    $refused($add, 'block_type');
    $refused(['op' => 'duplicate_element', 'element' => $card], 'element');
    $refused(['op' => 'duplicate_element', 'element' => $container], 'element');
    $this->assertSame(1, $components(), 'A refused placement or copy leaves the container as it was.');
    $this->assertSame(1, $sections(), 'A refused container copy adds no container.');

    // A whole document written at creation meets the same question.
    $result = $this->deserializer->deserialize($this->cardBlueprint('Created'));
    $this->assertFalse($result->isSuccess());
    $this->assertSame('ACCESS_DENIED_PERMISSION', $result->getErrors()[0]->getCode());
    $this->assertSame('layout[0].children[0].children[0].block_type', $result->getErrors()[0]->getPath());

    // What creates no block is not asked: a plugin block, and its copy.
    $result = $operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'plugin_id' => 'system_powered_by_block',
        'uuid' => 'c0ffee00-0000-4000-8000-000000000002',
        'location' => ['anchor' => $container, 'placement' => 'inside'],
      ],
      ['op' => 'duplicate_element', 'element' => 'c0ffee00-0000-4000-8000-000000000002'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $this->assertSame(3, $components());

    // A batch acting for the site names no actor to ask.
    $result = $operations->batchOperations($this->host($entity), [['op' => 'duplicate_element', 'element' => $card]], ['system' => TRUE]);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $this->assertSame(4, $components());

    // With the block type's create access every road opens.
    $this->setUpCurrentUser([], [...$permissions, 'create card block content']);
    $result = $operations->batchOperations($this->host($entity), [
      $add,
      ['op' => 'duplicate_element', 'element' => $card],
      ['op' => 'duplicate_element', 'element' => $container],
    ]);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $this->assertSame(6, $components());
    $this->assertSame(2, $sections());
  }

  /**
   * An actor names an existing root by id or uuid, and learns of it by access.
   *
   * The root lookup runs before the entity it finds can be judged, so for an
   * actor it is asked only about the entity's identity, and what it found is
   * told only past the gate: an entity the actor may neither write nor view
   * answers as one the lookup did not find.
   */
  public function testRootLookupAnswersAfterTheGate(): void {
    $entity = $this->createTestEntity(['name' => 'Secret name', 'field_subtitle' => 'Stored']);
    $id = (int) $entity->id();
    $document = fn (array $root): array => [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => $root,
      'fields' => ['field_subtitle' => 'Written'],
    ];
    $first = function (array $root, array $options = []) use ($document): array {
      $result = $this->deserializer->deserialize($document($root), $options + ['validate_only' => TRUE]);
      $this->assertFalse($result->isSuccess());
      return $result->getErrors()[0]->toArray();
    };

    // An actor who may neither write nor view the entity.
    $this->setUpCurrentUser([], []);

    // A field of the actor's choosing is not looked up: the answer is the
    // same whether or not an entity holds the value.
    $held = $first(['field' => 'name', 'value' => 'Secret name']);
    $unheld = $first(['field' => 'name', 'value' => 'No such name']);
    $this->assertSame('INVALID_ROOT_ENTITY', $held['code']);
    $this->assertSame($held, $unheld);

    // By id and by uuid, an entity outside the actor's access reads as one
    // that does not exist.
    $hidden = $first(['field' => 'id', 'value' => $id]);
    $missing = $first(['field' => 'id', 'value' => 99999]);
    $this->assertSame('ENTITY_NOT_FOUND', $hidden['code']);
    $this->assertSame(
      str_replace((string) $id, 'N', json_encode($hidden)),
      str_replace('99999', 'N', json_encode($missing)),
    );
    $this->assertSame('ENTITY_NOT_FOUND', $first(['field' => 'uuid', 'value' => $entity->uuid()])['code']);

    // A viewer who may not write is told why, as before.
    $this->setUpCurrentUser([], ['view test entity']);
    $this->assertSame('ACCESS_DENIED_ENTITY', $first(['field' => 'id', 'value' => $id])['code']);
    $this->assertSame('INVALID_ROOT_ENTITY', $first(['field' => 'name', 'value' => 'Secret name'])['code']);

    // A writer reaches the entity by either identity.
    $this->setUpCurrentUser([], ['administer entity_test content', 'view test entity']);
    foreach ([['field' => 'id', 'value' => $id], ['field' => 'uuid', 'value' => $entity->uuid()]] as $root) {
      $result = $this->deserializer->deserialize($document($root), ['validate_only' => TRUE]);
      $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    }

    // A caller acting for the site addresses its entity by any field.
    $this->setUpCurrentUser([], []);
    $result = $this->deserializer->deserialize($document(['field' => 'name', 'value' => 'Secret name']), ['system' => TRUE]);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $this->assertSame($id, (int) $result->getEntity()->id());
    $this->assertSame('Written', $result->getEntity()->get('field_subtitle')->value);
  }

  /**
   * Builds a create blueprint whose one container holds a 'card' block.
   *
   * @param string $heading
   *   The field_heading value for the card.
   *
   * @return array
   *   The blueprint for the deserializer.
   */
  protected function cardBlueprint(string $heading): array {
    return [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'host'],
      'layout' => [
        [
          'kind' => 'container',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                [
                  'kind' => 'block',
                  'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
                  'block_type' => 'card',
                  'label' => 'Card',
                  'view_mode' => 'full',
                  'block_fields' => ['field_heading' => $heading],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

}
