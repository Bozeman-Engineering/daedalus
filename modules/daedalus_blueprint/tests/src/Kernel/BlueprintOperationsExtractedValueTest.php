<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\Event\FieldAppliedEvent;
use Drupal\daedalus_blueprint\ExtractedValue;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\FieldHandler\TextFieldHandler;
use Drupal\daedalus_blueprint\FieldValueKind;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the ExtractedValue write kind, FieldAppliedEvent, and batch langcode.
 *
 * An ExtractedValue is the form transport's hand-off to the spine: a field
 * item list the entity form already extracted and validated, applied by
 * validateAndApplyFields() with no handler deserialize and no processable
 * filter, keeping field access and constraint validation. Every landed set,
 * of either value kind, dispatches a FieldAppliedEvent before constraints
 * run. A batch's `langcode` option names the translation it mutates.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsExtractedValueTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'entity_test',
    'language',
    'daedalus_blueprint',
  ];

  /**
   * The blueprint operations service.
   */
  protected BlueprintOperations $operations;

  /**
   * A text handler that records every deserialize it is asked for.
   *
   * Registered above the shipped text handler, so the grammar path for a
   * text_long field must go through it and the ExtractedValue path must
   * not.
   */
  protected TextFieldHandler $spyHandler;

  /**
   * The FieldAppliedEvents received, in dispatch order.
   *
   * @var \Drupal\daedalus_blueprint\Event\FieldAppliedEvent[]
   */
  protected array $appliedEvents = [];

  /**
   * The component UUID of the inline block fixture.
   */
  protected const BLOCK_UUID = 'faf95c53-35e7-74e1-184e-041152031df4';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('entity_test_mul');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->createMulField('field_body', 'text_long');

    // Layout Builder with an inline block type for the block subject, and
    // daedalus_tempstore so the storage handler stages instead of saving.
    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_body', 'text_long', 'text_section');
    $this->enableModules(['daedalus_tempstore']);

    $this->operations = $this->container->get('daedalus_blueprint.operations');

    $this->spyHandler = new class($this->container->get('entity_type.manager')) extends TextFieldHandler {

      /**
       * The field names deserialize() was asked for, in call order.
       *
       * @var string[]
       */
      public array $deserialized = [];

      /**
       * {@inheritdoc}
       */
      public function deserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
        $this->deserialized[] = $definition->getName();
        return parent::deserialize($value, $definition, $options);
      }

    };
    $this->container->get('daedalus_blueprint.field_handler_manager')
      ->addHandler($this->spyHandler, 'text_long', 1000);

    $this->container->get('event_dispatcher')->addListener(
      FieldAppliedEvent::class,
      function (FieldAppliedEvent $event): void {
        $this->appliedEvents[] = $event;
      },
    );
  }

  /**
   * Creates a translatable field on entity_test_mul.
   */
  protected function createMulField(string $field_name, string $field_type): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'entity_test_mul',
      'type' => $field_type,
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'entity_test_mul',
      'bundle' => 'entity_test_mul',
      'label' => 'Body',
      'translatable' => TRUE,
    ])->save();
  }

  /**
   * Builds an ExtractedValue the way a form submit handler would.
   *
   * The item list comes off a detached entity of the same type, the stand-in
   * for the form-built entity the widget pipeline produces.
   */
  protected function extracted(string $entity_type, string $field_name, mixed $value, array $values = []): ExtractedValue {
    $source = $this->entityTypeManager->getStorage($entity_type)->create($values + [$field_name => $value]);
    return new ExtractedValue($source->get($field_name));
  }

  /**
   * Builds a root update_element op carrying the given properties.
   */
  protected function rootOp(object $entity, array $properties): array {
    return ['op' => 'update_element', 'element' => $entity->uuid(), 'properties' => $properties];
  }

  /**
   * Builds an update_element op on an element that does not exist.
   *
   * The op fails with ELEMENT_NOT_FOUND and dooms its batch.
   */
  protected function missingElementOp(string $field_name): array {
    return [
      'op' => 'update_element',
      'element' => '00000000-0000-0000-0000-000000000000',
      'properties' => [$field_name => 'x'],
    ];
  }

  /**
   * A formatted text item in the plain_text format.
   */
  protected function text(string $html): array {
    return ['value' => $html, 'format' => 'plain_text'];
  }

  /**
   * Creates the fixture: an entity_test holding one inline block.
   *
   * @return object
   *   The saved root entity.
   */
  protected function createBlockFixture(): object {
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_body' => ['value' => '<p>Original</p>', 'format' => 'plain_text'],
    ]);
    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => self::BLOCK_UUID,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'My Block'),
      ],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Reads the inline block out of the fixture's component configuration.
   */
  protected function fixtureBlock(object $entity): object {
    $component = $entity->get('layout_builder__layout')->get(0)->section->getComponent(self::BLOCK_UUID);
    return $this->unserializeBlock($component->get('configuration')['block_serialized']);
  }

  /**
   * An ExtractedValue on a text_long root field lands in tempstore.
   *
   * No handler runs for it (the grammar path through the same field does),
   * the write is a target, and persisting the result stages it.
   */
  public function testRootTextLongLandsInTempstoreWithoutHandler(): void {
    $entity = $this->createTestEntity([
      'field_description' => ['value' => '<p>Saved</p>', 'format' => 'plain_text'],
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, [
        'field_description' => $this->extracted('entity_test', 'field_description', $this->text('<p>Extracted</p>')),
      ]),
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame($entity, $result->getEntity());
    $this->assertSame('<p>Extracted</p>', $entity->get('field_description')->value);
    $this->assertSame('plain_text', $entity->get('field_description')->format);
    $this->assertSame([['uuid' => $entity->uuid(), 'field_name' => 'field_description']], $result->getTargets());
    $this->assertSame([], $this->spyHandler->deserialized, 'An ExtractedValue never reaches the field handler.');

    // The grammar path through the same field goes through the handler.
    $grammar = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, ['field_description' => ['value' => '<p>Grammar</p>', 'format' => 'plain_text']]),
    ]);
    $this->assertTrue($grammar->isSuccess());
    $this->assertSame(['field_description'], $this->spyHandler->deserialized);

    // Persisting the result stages it: the saved row is untouched and the
    // tempstore copy carries the write.
    $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, [
        'field_description' => $this->extracted('entity_test', 'field_description', $this->text('<p>Staged</p>')),
      ]),
    ]);
    $where = $this->container->get('daedalus_blueprint.storage_handler')->persist($this->host($entity));
    $this->assertSame('tempstore', $where);
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $saved = $storage->loadUnchanged($entity->id());
    $this->assertSame('<p>Saved</p>', $saved->get('field_description')->value);
    $staged = $this->container->get('daedalus_tempstore.entity_tempstore_repository')->get($saved);
    $this->assertSame('<p>Staged</p>', $staged->get('field_description')->value);
  }

  /**
   * An ExtractedValue on an inline block's field re-serializes the block.
   */
  public function testBlockSubject(): void {
    $entity = $this->createBlockFixture();

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'update_element',
        'element' => self::BLOCK_UUID,
        'properties' => [
          'field_body' => $this->extracted(
            'block_content',
            'field_body',
            $this->text('<p>Extracted</p>'),
            ['type' => 'text_section'],
          ),
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame('<p>Extracted</p>', $this->fixtureBlock($entity)->get('field_body')->value);
    $this->assertSame([['uuid' => self::BLOCK_UUID, 'field_name' => 'field_body']], $result->getTargets());
    $this->assertSame([], $this->spyHandler->deserialized);

    $this->assertCount(1, $this->appliedEvents);
    $event = $this->appliedEvents[0];
    $this->assertSame('block_content', $event->getEntity()->getEntityTypeId());
    $this->assertSame('field_body', $event->getFieldName());
    $this->assertSame(self::BLOCK_UUID, $event->getElementUuid(), 'The element uuid is the component, not the block_content uuid.');
    $this->assertSame(FieldValueKind::Extracted, $event->getValueKind());
  }

  /**
   * A field the blueprint carries as a form-only stub takes an ExtractedValue.
   *
   * The stub is identity, never a value: sent back it is skipped, and an
   * ExtractedValue is never mistaken for one.
   */
  public function testFormOnlyFieldWritten(): void {
    $entity = $this->createTestEntity(['field_subtitle' => 'Sub']);

    $blueprint = $this->operations->getBlueprint($this->host($entity));
    $this->assertTrue(FormOnlyStub::isStub($blueprint['fields']['field_email']), 'The empty email field is a form-only stub in the FULL blueprint.');
    $this->assertTrue(FormOnlyStub::isStub($blueprint['fields']['field_description']));

    $extracted = $this->extracted('entity_test', 'field_email', 'tim@example.com');
    $this->assertFalse(FormOnlyStub::isStub($extracted));

    $result = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, [
        'field_email' => $extracted,
        'field_description' => $blueprint['fields']['field_description'],
      ]),
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame('tim@example.com', $entity->get('field_email')->value);
    $this->assertSame('Sub', $entity->get('field_subtitle')->value);
    $this->assertTrue($entity->get('field_description')->isEmpty(), 'A stub sent back leaves the field alone.');
    $this->assertSame(['field_email'], array_column(array_map(fn(FieldAppliedEvent $e) => ['f' => $e->getFieldName()], $this->appliedEvents), 'f'));
  }

  /**
   * A write that fills an empty field, or empties one, targets its presence.
   *
   * An empty field renders no wrapper: the field's keyed subtree has no
   * element on the page to swap into when it fills, and none to extract
   * when it empties. The presence sentinel rides beside the field target
   * exactly when emptiness flipped, for either value kind, and never for a
   * write between two values.
   */
  public function testPresenceTargetRidesAnEmptinessFlip(): void {
    $entity = $this->createTestEntity(['field_subtitle' => NULL]);
    $this->assertTrue($entity->get('field_subtitle')->isEmpty());
    $field = ['uuid' => $entity->uuid(), 'field_name' => 'field_subtitle'];
    $presence = ['uuid' => $entity->uuid(), 'field_name' => TargetType::PRESENCE];

    $filled = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, ['field_subtitle' => $this->extracted('entity_test', 'field_subtitle', 'Filled')]),
    ]);
    $this->assertTrue($filled->isSuccess(), print_r($filled->toArray(), TRUE));
    $this->assertSame([$field, $presence], $filled->getTargets(), 'Filling an empty field targets its presence.');

    $changed = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, ['field_subtitle' => 'Changed']),
    ]);
    $this->assertTrue($changed->isSuccess(), print_r($changed->toArray(), TRUE));
    $this->assertSame([$field], $changed->getTargets(), 'A write between two values targets the field alone.');

    $emptied = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, ['field_subtitle' => NULL]),
    ]);
    $this->assertTrue($emptied->isSuccess(), print_r($emptied->toArray(), TRUE));
    $this->assertTrue($entity->get('field_subtitle')->isEmpty());
    $this->assertSame([$field, $presence], $emptied->getTargets(), 'Emptying a field targets its presence, for a grammar value too.');
  }

  /**
   * A non-processable field takes an ExtractedValue; a grammar value drops.
   *
   * The created timestamp is on the always-skip list (the grammar never
   * spells it), which is exactly the form's advanced-pane territory.
   */
  public function testNonProcessableFieldWritten(): void {
    $entity = $this->createTestEntity(['created' => 1600000000]);
    $this->assertArrayNotHasKey('created', $this->fieldHandlerManager->getProcessableFieldDefinitions($entity));

    // The grammar's PATCH-omit semantics: a non-processable key is left
    // alone, silently.
    $grammar = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, ['created' => 1700000000]),
    ]);
    $this->assertTrue($grammar->isSuccess());
    $this->assertSame(1600000000, (int) $entity->get('created')->value);
    $this->assertSame([], $this->appliedEvents, 'A dropped grammar value applies nothing.');

    $result = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, ['created' => $this->extracted('entity_test', 'created', 1700000000)]),
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame(1700000000, (int) $entity->get('created')->value);
    $this->assertSame([['uuid' => $entity->uuid(), 'field_name' => 'created']], $result->getTargets());
    $this->assertCount(1, $this->appliedEvents);
    $this->assertSame('created', $this->appliedEvents[0]->getFieldName());
  }

  /**
   * A field the user cannot edit rejects an ExtractedValue like any value.
   */
  public function testAccessDenied(): void {
    $entity = $this->createTestEntity([
      'field_description' => ['value' => '<p>Saved</p>', 'format' => 'plain_text'],
    ]);
    \Drupal::state()->set('views_field_access_test-field', 'field_description');
    $this->setUpCurrentUser([], ['administer entity_test content']);

    $result = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, [
        'field_description' => $this->extracted('entity_test', 'field_description', $this->text('<p>Blocked</p>')),
      ]),
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_FIELD', $errors[0]->getCode());
    $this->assertStringEndsWith('properties.field_description', $errors[0]->getPath());
    $this->assertSame('<p>Saved</p>', $entity->get('field_description')->value, 'The denied write is rolled back.');
    $this->assertSame([], $this->appliedEvents);
  }

  /**
   * Entity constraints run on an ExtractedValue and a violation rolls back.
   */
  public function testConstraintViolation(): void {
    $entity = $this->createTestEntity(['field_subtitle' => 'Short']);

    $result = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, [
        'field_subtitle' => $this->extracted('entity_test', 'field_subtitle', str_repeat('x', 300)),
      ]),
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $codes = array_map(fn($error) => $error->getCode(), $result->getErrors());
    $this->assertSame(['CONSTRAINT_VIOLATION'], $codes);
    $this->assertStringContainsString('properties.field_subtitle', $result->getErrors()[0]->getPath());
    $this->assertSame('Short', $entity->get('field_subtitle')->value, 'The violating write is rolled back.');
    // The event fired (the set landed) before validation rejected the batch.
    $this->assertCount(1, $this->appliedEvents);
    $this->assertSame('field_subtitle', $this->appliedEvents[0]->getFieldName());
  }

  /**
   * FieldAppliedEvent fires per landed set, for both kinds, before constraints.
   */
  public function testFieldAppliedEvent(): void {
    $entity = $this->createTestEntity([
      'field_description' => ['value' => '<p>Saved</p>', 'format' => 'plain_text'],
      'field_subtitle' => 'Sub',
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, [
        'field_description' => $this->extracted('entity_test', 'field_description', $this->text('<p>Extracted</p>')),
        'field_subtitle' => 'Grammar',
      ]),
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));

    $this->assertCount(2, $this->appliedEvents);
    [$extracted, $grammar] = $this->appliedEvents;
    $this->assertSame($entity, $extracted->getEntity());
    $this->assertSame('field_description', $extracted->getFieldName());
    $this->assertSame($entity->uuid(), $extracted->getElementUuid());
    $this->assertSame(FieldValueKind::Extracted, $extracted->getValueKind());
    $this->assertSame('<p>Extracted</p>', $extracted->getItems()->value);
    $this->assertSame('field_subtitle', $grammar->getFieldName());
    $this->assertSame(FieldValueKind::Value, $grammar->getValueKind());
    $this->assertSame('Grammar', $grammar->getItems()->value);

    // A subscriber's rewrite is validated with the write: rewriting the
    // applied field into a violating value fails the operation.
    $this->container->get('event_dispatcher')->addListener(
      FieldAppliedEvent::class,
      static function (FieldAppliedEvent $event): void {
        if ($event->getFieldName() === 'field_subtitle') {
          $event->getItems()->setValue(str_repeat('y', 300));
        }
      },
    );
    $rewritten = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, ['field_subtitle' => 'Fine on its own']),
    ]);
    $this->assertFalse($rewritten->isSuccess());
    $this->assertSame('CONSTRAINT_VIOLATION', $rewritten->getErrors()[0]->getCode());
    $this->assertSame('Grammar', $entity->get('field_subtitle')->value);
  }

  /**
   * A batch's langcode names the translation it mutates.
   */
  public function testLangcodeHonored(): void {
    $storage = $this->entityTypeManager->getStorage('entity_test_mul');
    $entity = $storage->create([
      'name' => 'Page',
      'langcode' => 'en',
      'field_body' => ['value' => '<p>English</p>', 'format' => 'plain_text'],
    ]);
    $entity->addTranslation('es', [
      'name' => 'Página',
      'field_body' => ['value' => '<p>Español</p>', 'format' => 'plain_text'],
    ]);
    $entity->save();

    // A grammar value on the named translation.
    $result = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, ['field_body' => ['value' => '<p>Actualizado</p>', 'format' => 'plain_text']]),
    ], ['langcode' => 'es']);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame($entity, $result->getEntity(), 'The result carries the object the caller handed in.');
    $this->assertSame('<p>Actualizado</p>', $entity->getTranslation('es')->get('field_body')->value);
    $this->assertSame('<p>English</p>', $entity->get('field_body')->value);
    $this->assertSame('es', $this->appliedEvents[0]->getEntity()->language()->getId(), 'The event carries the translation the write landed on.');

    // An ExtractedValue on the named translation.
    $source = $storage->create(['field_body' => ['value' => '<p>Extraído</p>', 'format' => 'plain_text']]);
    $result = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, ['field_body' => new ExtractedValue($source->get('field_body'))]),
    ], ['langcode' => 'es']);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame('<p>Extraído</p>', $entity->getTranslation('es')->get('field_body')->value);
    $this->assertSame('<p>English</p>', $entity->get('field_body')->value);

    // No langcode: the object as handed in, here the default translation.
    $result = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, ['field_body' => ['value' => '<p>Updated</p>', 'format' => 'plain_text']]),
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame('<p>Updated</p>', $entity->get('field_body')->value);
    $this->assertSame('<p>Extraído</p>', $entity->getTranslation('es')->get('field_body')->value);

    // Rollback restores the named translation.
    $failed = $this->operations->batchOperations($this->host($entity), [
      $this->rootOp($entity, ['field_body' => ['value' => '<p>Doomed</p>', 'format' => 'plain_text']]),
      $this->missingElementOp('field_body'),
    ], ['langcode' => 'es']);
    $this->assertFalse($failed->isSuccess());
    $this->assertSame('<p>Extraído</p>', $entity->getTranslation('es')->get('field_body')->value);
    $this->assertSame('<p>Updated</p>', $entity->get('field_body')->value);
  }

  /**
   * The batch's minted-identity walk ignores an ExtractedValue.
   *
   * Both mintedUuids() and referencesTaintedUuid() walk every op
   * recursively; an ExtractedValue is an object leaf, so a failed op
   * before it neither throws on it nor suppresses its findings by mistake.
   */
  public function testMintedUuidWalkIgnoresExtractedValue(): void {
    $entity = $this->createTestEntity([
      'field_description' => ['value' => '<p>Saved</p>', 'format' => 'plain_text'],
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      $this->missingElementOp('field_subtitle'),
      $this->rootOp($entity, [
        'field_description' => $this->extracted('entity_test', 'field_description', $this->text('<p>Extracted</p>')),
      ]),
      $this->rootOp($entity, [
        'field_subtitle' => $this->extracted('entity_test', 'field_subtitle', str_repeat('z', 300)),
      ]),
    ]);

    $this->assertFalse($result->isSuccess());
    $codes = array_map(fn($error) => $error->getCode(), $result->getErrors());
    $this->assertSame(['ELEMENT_NOT_FOUND', 'CONSTRAINT_VIOLATION'], $codes, 'The ExtractedValue op after a failure is neither suppressed nor mistaken for minted identity; the third op reports its own finding.');
    $this->assertSame('<p>Saved</p>', $entity->get('field_description')->value, 'Total rollback.');
  }

}
