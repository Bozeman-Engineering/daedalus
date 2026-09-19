<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\FieldHandler;

use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\FieldHandler\TextFieldHandler;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the TextFieldHandler with real Drupal field definitions.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(TextFieldHandler::class)]
#[CoversClass(FieldHandlerManager::class)]
#[Group('daedalus_blueprint')]
class TextFieldHandlerTest extends BlueprintKernelTestBase {

  /**
   * The handler under test.
   *
   * @var \Drupal\daedalus_blueprint\FieldHandler\TextFieldHandler
   */
  protected TextFieldHandler $handler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $entity = $this->createTestEntity([
      'field_description' => [
        'value' => 'init',
        'format' => 'plain_text',
      ],
    ]);
    $definition = $entity->get('field_description')->getFieldDefinition();
    $this->handler = $this->fieldHandlerManager->getHandler($definition);
    $this->assertInstanceOf(TextFieldHandler::class, $this->handler);
  }

  /**
   * Tests serializing a text_long field.
   */
  public function testSerializeTextLong(): void {
    $entity = $this->createTestEntity([
      'field_description' => [
        'value' => '<p>Hello world</p>',
        'format' => 'plain_text',
      ],
    ]);

    $items = $entity->get('field_description');
    $definition = $items->getFieldDefinition();
    $result = $this->handler->serialize($items, $definition);

    $this->assertIsArray($result);
    $this->assertSame('<p>Hello world</p>', $result['value']);
    $this->assertSame('plain_text', $result['format']);
    $this->assertArrayNotHasKey('summary', $result);
  }

  /**
   * Tests serializing a text_with_summary field.
   */
  public function testSerializeTextWithSummary(): void {
    $entity = $this->createTestEntity([
      'field_body' => [
        'value' => '<p>Full body text</p>',
        'summary' => 'Short summary',
        'format' => 'plain_text',
      ],
    ]);

    $items = $entity->get('field_body');
    $definition = $items->getFieldDefinition();
    $result = $this->handler->serialize($items, $definition);

    $this->assertIsArray($result);
    $this->assertSame('<p>Full body text</p>', $result['value']);
    $this->assertSame('plain_text', $result['format']);
    $this->assertSame('Short summary', $result['summary']);
  }

  /**
   * Tests serializing an empty text field returns NULL.
   */
  public function testSerializeEmptyField(): void {
    $entity = $this->createTestEntity();

    $items = $entity->get('field_description');
    $definition = $items->getFieldDefinition();
    $result = $this->handler->serialize($items, $definition);

    $this->assertNull($result);
  }

  /**
   * Tests serializing text_with_summary with empty summary.
   */
  public function testSerializeTextWithSummaryEmptySummary(): void {
    $entity = $this->createTestEntity([
      'field_body' => [
        'value' => '<p>Body only</p>',
        'format' => 'plain_text',
      ],
    ]);

    $items = $entity->get('field_body');
    $definition = $items->getFieldDefinition();
    $result = $this->handler->serialize($items, $definition);

    $this->assertSame('<p>Body only</p>', $result['value']);
    $this->assertSame('plain_text', $result['format']);
    $this->assertSame('', $result['summary']);
  }

  /**
   * Tests deserializing a structured text value.
   */
  public function testDeserializeStructured(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_description')->getFieldDefinition();

    $value = ['value' => '<p>New content</p>', 'format' => 'plain_text'];
    $result = $this->handler->deserialize($value, $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertSame($value, $result->getValue());
  }

  /**
   * Tests deserializing a bare string normalizes to structured format.
   */
  public function testDeserializeBareString(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_description')->getFieldDefinition();

    $result = $this->handler->deserialize('Plain text input', $definition);

    $this->assertFalse($result->hasErrors());
    $value = $result->getValue();
    $this->assertIsArray($value);
    $this->assertSame('Plain text input', $value['value']);
    $this->assertNull($value['format']);
  }

  /**
   * Tests deserializing NULL value.
   */
  public function testDeserializeNull(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_description')->getFieldDefinition();

    $result = $this->handler->deserialize(NULL, $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertNull($result->getValue());
  }

  /**
   * Tests that the manager resolves text_long to TextFieldHandler.
   */
  public function testManagerResolvesTextLong(): void {
    $entity = $this->createTestEntity([
      'field_description' => ['value' => 'test', 'format' => 'plain_text'],
    ]);
    $definition = $entity->get('field_description')->getFieldDefinition();
    $handler = $this->fieldHandlerManager->getHandler($definition);
    $this->assertInstanceOf(TextFieldHandler::class, $handler);
  }

  /**
   * Tests that the manager resolves text_with_summary to TextFieldHandler.
   */
  public function testManagerResolvesTextWithSummary(): void {
    $entity = $this->createTestEntity([
      'field_body' => ['value' => 'test', 'format' => 'plain_text'],
    ]);
    $definition = $entity->get('field_body')->getFieldDefinition();
    $handler = $this->fieldHandlerManager->getHandler($definition);
    $this->assertInstanceOf(TextFieldHandler::class, $handler);
  }

  /**
   * Tests round-trip: serialize → deserialize → set → serialize.
   */
  public function testRoundTripTextLong(): void {
    $entity = $this->createTestEntity([
      'field_description' => [
        'value' => '<p>Original content</p>',
        'format' => 'plain_text',
      ],
    ]);

    $items = $entity->get('field_description');
    $definition = $items->getFieldDefinition();

    // Serialize.
    $serialized = $this->handler->serialize($items, $definition);

    // Deserialize.
    $result = $this->handler->deserialize($serialized, $definition);
    $this->assertFalse($result->hasErrors());

    // Set on a new entity and verify.
    $entity2 = $this->createTestEntity();
    $entity2->set('field_description', $result->getValue());
    $entity2->save();

    $reserialized = $this->handler->serialize(
      $entity2->get('field_description'),
      $entity2->get('field_description')->getFieldDefinition(),
    );
    $this->assertSame('<p>Original content</p>', $reserialized['value']);
    $this->assertSame('plain_text', $reserialized['format']);
  }

  /**
   * Tests round-trip for text_with_summary preserves summary.
   */
  public function testRoundTripTextWithSummary(): void {
    $entity = $this->createTestEntity([
      'field_body' => [
        'value' => '<p>Full body</p>',
        'summary' => 'A summary',
        'format' => 'plain_text',
      ],
    ]);

    $items = $entity->get('field_body');
    $definition = $items->getFieldDefinition();

    $serialized = $this->handler->serialize($items, $definition);
    $result = $this->handler->deserialize($serialized, $definition);
    $this->assertFalse($result->hasErrors());

    $entity2 = $this->createTestEntity();
    $entity2->set('field_body', $result->getValue());
    $entity2->save();

    $reserialized = $this->handler->serialize(
      $entity2->get('field_body'),
      $entity2->get('field_body')->getFieldDefinition(),
    );
    $this->assertSame('<p>Full body</p>', $reserialized['value']);
    $this->assertSame('A summary', $reserialized['summary']);
    $this->assertSame('plain_text', $reserialized['format']);
  }

}
