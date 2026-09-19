<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\FieldHandler;

use Drupal\daedalus_blueprint\FieldHandler\DefaultFieldHandler;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the DefaultFieldHandler with real Drupal field definitions.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(DefaultFieldHandler::class)]
#[CoversClass(FieldHandlerManager::class)]
#[Group('daedalus_blueprint')]
class DefaultFieldHandlerTest extends BlueprintKernelTestBase {

  /**
   * The handler under test.
   *
   * @var \Drupal\daedalus_blueprint\FieldHandler\DefaultFieldHandler
   */
  protected DefaultFieldHandler $handler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_subtitle')->getFieldDefinition();
    $this->handler = $this->fieldHandlerManager->getHandler($definition);
    $this->assertInstanceOf(DefaultFieldHandler::class, $this->handler);
  }

  /**
   * Tests serializing a simple string field.
   */
  public function testSerializeStringField(): void {
    $entity = $this->createTestEntity([
      'field_subtitle' => 'My subtitle',
    ]);

    $items = $entity->get('field_subtitle');
    $definition = $items->getFieldDefinition();
    $result = $this->handler->serialize($items, $definition);

    $this->assertSame('My subtitle', $result);
  }

  /**
   * Tests serializing an empty field returns NULL.
   */
  public function testSerializeEmptyField(): void {
    $entity = $this->createTestEntity();

    $items = $entity->get('field_subtitle');
    $definition = $items->getFieldDefinition();
    $result = $this->handler->serialize($items, $definition);

    $this->assertNull($result);
  }

  /**
   * Tests serializing an integer field.
   */
  public function testSerializeIntegerField(): void {
    $entity = $this->createTestEntity([
      'field_count' => 42,
    ]);

    $items = $entity->get('field_count');
    $definition = $items->getFieldDefinition();
    $result = $this->handler->serialize($items, $definition);

    $this->assertSame(42, $result);
  }

  /**
   * Tests serializing a boolean field.
   */
  public function testSerializeBooleanField(): void {
    $entity = $this->createTestEntity([
      'field_published' => TRUE,
    ]);

    $items = $entity->get('field_published');
    $definition = $items->getFieldDefinition();
    $result = $this->handler->serialize($items, $definition);

    // Drupal boolean fields store TRUE/1 — assert the value is truthy.
    $this->assertTrue((bool) $result);
  }

  /**
   * Tests serializing an email field.
   */
  public function testSerializeEmailField(): void {
    $entity = $this->createTestEntity([
      'field_email' => 'test@example.com',
    ]);

    $items = $entity->get('field_email');
    $definition = $items->getFieldDefinition();
    $result = $this->handler->serialize($items, $definition);

    $this->assertSame('test@example.com', $result);
  }

  /**
   * Tests serializing a multi-value string field.
   */
  public function testSerializeMultiValueField(): void {
    $entity = $this->createTestEntity([
      'field_tags_text' => ['Alpha', 'Beta', 'Gamma'],
    ]);

    $items = $entity->get('field_tags_text');
    $definition = $items->getFieldDefinition();
    $result = $this->handler->serialize($items, $definition);

    $this->assertIsArray($result);
    $this->assertCount(3, $result);
    $this->assertSame('Alpha', $result[0]);
    $this->assertSame('Beta', $result[1]);
    $this->assertSame('Gamma', $result[2]);
  }

  /**
   * Tests deserializing a simple scalar value.
   */
  public function testDeserializeScalar(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_subtitle')->getFieldDefinition();

    $result = $this->handler->deserialize('New subtitle', $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertSame('New subtitle', $result->getValue());
  }

  /**
   * Tests deserializing NULL value.
   */
  public function testDeserializeNull(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_subtitle')->getFieldDefinition();

    $result = $this->handler->deserialize(NULL, $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertNull($result->getValue());
  }

  /**
   * Tests round-trip: serialize → deserialize → set → serialize matches.
   */
  public function testRoundTripStringField(): void {
    $entity = $this->createTestEntity([
      'field_subtitle' => 'Original value',
    ]);

    $items = $entity->get('field_subtitle');
    $definition = $items->getFieldDefinition();

    // Serialize.
    $serialized = $this->handler->serialize($items, $definition);
    $this->assertSame('Original value', $serialized);

    // Deserialize.
    $result = $this->handler->deserialize($serialized, $definition);
    $this->assertFalse($result->hasErrors());

    // Set on a new entity and re-serialize.
    $entity2 = $this->createTestEntity();
    $entity2->set('field_subtitle', $result->getValue());
    $entity2->save();

    $reserialized = $this->handler->serialize(
      $entity2->get('field_subtitle'),
      $entity2->get('field_subtitle')->getFieldDefinition(),
    );
    $this->assertSame('Original value', $reserialized);
  }

  /**
   * Tests that the handler manager resolves unregistered types to default.
   */
  public function testManagerResolvesToDefault(): void {
    $entity = $this->createTestEntity();

    // 'string' has no registered handler, should fall through to default.
    $handler = $this->fieldHandlerManager->getHandler($entity->get('field_subtitle')->getFieldDefinition());
    $this->assertInstanceOf(DefaultFieldHandler::class, $handler);

    // 'integer' also has no registered handler.
    $handler = $this->fieldHandlerManager->getHandler($entity->get('field_count')->getFieldDefinition());
    $this->assertInstanceOf(DefaultFieldHandler::class, $handler);

    // 'boolean' also has no registered handler.
    $handler = $this->fieldHandlerManager->getHandler($entity->get('field_published')->getFieldDefinition());
    $this->assertInstanceOf(DefaultFieldHandler::class, $handler);
  }

  /**
   * Tests the field handler manager's field introspection.
   */
  public function testInspectFieldDefinition(): void {
    $entity = $this->createTestEntity();

    // String field.
    $info = $this->fieldHandlerManager->inspectFieldDefinition(
      $entity->get('field_subtitle')->getFieldDefinition(),
    );
    $this->assertSame('string', $info['type']);
    $this->assertSame('Subtitle', $info['label']);
    $this->assertFalse($info['required']);
    $this->assertSame(1, $info['cardinality']);
    $this->assertSame('value', $info['main_property']);
    $this->assertSame(255, $info['settings']['max_length']);

    // Multi-value field.
    $info = $this->fieldHandlerManager->inspectFieldDefinition(
      $entity->get('field_tags_text')->getFieldDefinition(),
    );
    $this->assertSame(-1, $info['cardinality']);
  }

  /**
   * Tests isSkippedFieldType.
   */
  public function testIsSkippedFieldType(): void {
    $this->assertTrue($this->fieldHandlerManager->isSkippedFieldType('layout_section'));
    $this->assertFalse($this->fieldHandlerManager->isSkippedFieldType('string'));
    $this->assertFalse($this->fieldHandlerManager->isSkippedFieldType('text_long'));
  }

}
