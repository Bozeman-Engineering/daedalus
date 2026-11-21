<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\FieldHandler;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\daedalus_blueprint\FieldHandler\InlineEntityFieldHandler;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which entities an inline entity write may update.
 *
 * The rule under test reads two things of the written field: its target
 * type and the entity each of its items holds. A core reference field
 * answers both exactly as a revisions reference field does, so these tests
 * hand the handler one and run on a build without the optional
 * entity_reference_revisions module, where InlineEntityFieldHandlerTest
 * skips.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(InlineEntityFieldHandler::class)]
#[Group('daedalus_blueprint')]
class InlineEntityOwnershipTest extends BlueprintKernelTestBase {

  /**
   * The handler under test.
   */
  protected InlineEntityFieldHandler $handler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createRevField('field_heading', 'string', 'entity_test_rev', ['max_length' => 255]);
    $this->createField('field_items', 'entity_reference', ['target_type' => 'entity_test_rev'], [
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ]);
    $this->createField('field_other_item', 'entity_reference', ['target_type' => 'entity_test_rev']);

    $this->handler = $this->container->get('daedalus_blueprint.field_handler.inline_entity');
  }

  /**
   * A target_id the written field holds updates that item, in place.
   */
  public function testHeldTargetIdUpdates(): void {
    $first = $this->createRevEntity(['name' => 'First', 'field_heading' => 'First Heading']);
    $second = $this->createRevEntity(['name' => 'Second', 'field_heading' => 'Second Heading']);
    $host = $this->createTestEntity(['field_items' => [['entity' => $first], ['entity' => $second]]]);
    $definition = $host->getFieldDefinition('field_items');

    $result = $this->handler->deserialize([
      ['target_id' => (int) $second->id(), 'fields' => ['field_heading' => 'Updated']],
      ['type' => 'entity_test_rev', 'fields' => ['field_heading' => 'Brand New']],
      // An id as a read of a string-keyed store would spell it.
      ['target_id' => (string) $first->id()],
    ], $definition, ['entity' => $host]);

    $this->assertFalse($result->hasErrors());
    $value = $result->getValue();
    $this->assertCount(3, $value);
    $this->assertFalse($value[0]['entity']->isNew());
    $this->assertSame($second->uuid(), $value[0]['entity']->uuid());
    $this->assertSame('Updated', $value[0]['entity']->get('field_heading')->value);
    $this->assertTrue($value[1]['entity']->isNew());
    $this->assertSame('Brand New', $value[1]['entity']->get('field_heading')->value);
    $this->assertSame($first->uuid(), $value[2]['entity']->uuid());
    $this->assertSame('First Heading', $value[2]['entity']->get('field_heading')->value);
  }

  /**
   * A target_id the written field does not hold never reaches that entity.
   *
   * An inline entity belongs to the field that holds it. An id held by
   * another entity's field, by another field of the same entity or by no
   * field names nothing on the field being written: with a type the item
   * creates a new entity, without one it is refused, and the entity the id
   * names is neither changed nor attached either way.
   */
  public function testTargetIdOutsideTheFieldCreates(): void {
    $foreign = $this->createRevEntity([
      'name' => 'Foreign',
      'field_heading' => 'Foreign Heading',
    ]);
    $foreign_id = (int) $foreign->id();
    // Held by another entity's field, and by this entity's other field.
    $this->createTestEntity(['field_items' => [['entity' => $foreign]]]);
    $own = $this->createRevEntity(['name' => 'Own', 'field_heading' => 'Own Heading']);
    $host = $this->createTestEntity([
      'field_items' => [['entity' => $own]],
      'field_other_item' => ['entity' => $foreign],
    ]);
    $definition = $host->getFieldDefinition('field_items');

    // With a type: a new entity, the foreign one untouched.
    $result = $this->handler->deserialize([
      [
        'target_id' => $foreign_id,
        'type' => 'entity_test_rev',
        'fields' => ['field_heading' => 'Written'],
      ],
    ], $definition, ['entity' => $host]);

    $this->assertFalse($result->hasErrors());
    $created = $result->getValue()[0]['entity'];
    $this->assertTrue($created->isNew());
    $this->assertNotSame($foreign->uuid(), $created->uuid());
    $this->assertSame('Written', $created->get('field_heading')->value);
    $this->assertSame('Foreign Heading', $foreign->get('field_heading')->value);
    $storage = $this->entityTypeManager->getStorage('entity_test_rev');
    $storage->resetCache();
    $stored = $storage->load($foreign_id);
    assert($stored instanceof FieldableEntityInterface);
    $this->assertSame('Foreign Heading', $stored->get('field_heading')->value);

    // Without a type: refused, in the words of an id the field lacks, and
    // the answer names only the ids this field holds.
    $result = $this->handler->deserialize([
      ['target_id' => $foreign_id, 'fields' => ['field_heading' => 'Written']],
    ], $definition, ['entity' => $host]);

    $this->assertTrue($result->hasErrors());
    $error = $result->getErrors()[0];
    $this->assertSame('REFERENCE_NOT_FOUND', $error->getCode());
    $this->assertSame('fields.field_items[0]', $error->getPath());
    $this->assertEquals([(int) $own->id()], array_map('intval', $error->getContext()['held_ids']));
    $storage->resetCache();
    $stored = $storage->load($foreign_id);
    assert($stored instanceof FieldableEntityInterface);
    $this->assertSame('Foreign Heading', $stored->get('field_heading')->value);

    // The same id answers the same way as one no entity has.
    $missing = $this->handler->deserialize([
      ['target_id' => 99999, 'fields' => ['field_heading' => 'Written']],
    ], $definition, ['entity' => $host]);
    $this->assertSame(
      str_replace((string) $foreign_id, 'N', $error->getMessage()),
      str_replace('99999', 'N', $missing->getErrors()[0]->getMessage()),
    );

    // A caller that hands no entity holds nothing: the id creates.
    $result = $this->handler->deserialize([
      ['target_id' => (int) $own->id(), 'type' => 'entity_test_rev'],
    ], $definition);
    $this->assertFalse($result->hasErrors());
    $this->assertTrue($result->getValue()[0]['entity']->isNew());

    // A new entity holds nothing either.
    $new_host = $this->entityTypeManager->getStorage('entity_test')->create(['name' => 'new']);
    $result = $this->handler->deserialize([
      ['target_id' => (int) $own->id(), 'type' => 'entity_test_rev'],
    ], $definition, ['entity' => $new_host]);
    $this->assertFalse($result->hasErrors());
    $this->assertTrue($result->getValue()[0]['entity']->isNew());
  }

}
