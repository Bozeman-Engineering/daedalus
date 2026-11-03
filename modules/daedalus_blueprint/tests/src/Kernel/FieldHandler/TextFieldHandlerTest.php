<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\FieldHandler;

use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\FieldHandler\TextFieldHandler;
use Drupal\filter\Entity\FilterFormat;
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
   * A bare string becomes an item carrying the actor's default format.
   *
   * The base installs plain_text alone, so the default is plain_text: the
   * format core's text_format element would give an empty item, never NULL
   * (which renders through the site fallback with every tag escaped).
   */
  public function testDeserializeBareString(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_description')->getFieldDefinition();

    $result = $this->handler->deserialize('Plain text input', $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertSame([], $result->getWarnings());
    $value = $result->getValue();
    $this->assertIsArray($value);
    $this->assertSame('Plain text input', $value['value']);
    $this->assertSame('plain_text', $value['format']);
  }

  /**
   * An explicit NULL format gets the default the same way a bare string does.
   */
  public function testDeserializeNullFormat(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_description')->getFieldDefinition();

    $result = $this->handler->deserialize(['value' => 'No format', 'format' => NULL], $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertSame([], $result->getWarnings());
    $this->assertSame('plain_text', $result->getValue()['format']);
  }

  /**
   * The default is the roster's first by weight, not by the alphabet.
   *
   * The format zz_first sits at weight -10 and aa_last at 0 (plain_text
   * ships at 10), both usable by the admin running the suite: the bare
   * string lands on zz_first, and the schema's roster lists the three in
   * weight order.
   */
  public function testDefaultFormatFollowsWeightOrder(): void {
    FilterFormat::create([
      'format' => 'zz_first',
      'name' => 'ZZ first',
      'weight' => -10,
    ])->save();
    FilterFormat::create([
      'format' => 'aa_last',
      'name' => 'AA last',
      'weight' => 0,
    ])->save();
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_description')->getFieldDefinition();

    $result = $this->handler->deserialize('Weighted', $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertSame('zz_first', $result->getValue()['format']);
    $this->assertSame(
      ['available_formats' => ['zz_first', 'aa_last', 'plain_text']],
      $this->handler->getSchemaAdditions($definition),
    );
  }

  /**
   * The system posture takes the roster's first; an actor takes their first.
   *
   * The current user may use aa_last and plain_text but not zz_first: a
   * system write (the seed, an importer) lands on zz_first regardless, and
   * the same write judged for the user lands on aa_last, their first by
   * weight.
   */
  public function testSystemPostureTakesRosterFirst(): void {
    FilterFormat::create([
      'format' => 'zz_first',
      'name' => 'ZZ first',
      'weight' => -10,
    ])->save();
    FilterFormat::create([
      'format' => 'aa_last',
      'name' => 'AA last',
      'weight' => 0,
    ])->save();
    $this->setUpCurrentUser([], [
      'administer entity_test content',
      'use text format aa_last',
    ]);
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_description')->getFieldDefinition();

    $system = $this->handler->deserialize('Seeded', $definition, ['system' => TRUE]);
    $this->assertFalse($system->hasErrors());
    $this->assertSame('zz_first', $system->getValue()['format']);

    $judged = $this->handler->deserialize('Judged', $definition);
    $this->assertFalse($judged->hasErrors());
    $this->assertSame('aa_last', $judged->getValue()['format']);
  }

  /**
   * A stored default echoes on serialize: the page print carries the format.
   *
   * DialectPrinter prints the format attribute exactly when the stored
   * format is a set scalar, so a round trip from a bare string shows the
   * format it was given.
   */
  public function testSerializeAfterDeserializeEchoesFormat(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_description')->getFieldDefinition();

    $result = $this->handler->deserialize('Echoed', $definition);
    $this->assertFalse($result->hasErrors());
    $entity->set('field_description', $result->getValue());
    $entity->save();

    $reloaded = $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
    $serialized = $this->handler->serialize($reloaded->get('field_description'), $definition);
    $this->assertSame(['value' => 'Echoed', 'format' => 'plain_text'], $serialized);
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
