<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\Event\FieldAppliedEvent;
use Drupal\daedalus_blueprint\FieldValueKind;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_blueprint\Utility\SampleRequest;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the `_sample` request on a write op.
 *
 * `{field: {"_sample": true}}` resolves server-side through the one ladder's
 * request entrance (SampleFiller::extract(): the field's default value, then
 * its field_sample_value generator, whose answer is final even when empty,
 * then core's generateSampleItems() only when no generator is configured)
 * into an ExtractedValue, so the fill rides the spine like any write: field
 * access, constraint validation, the FieldAppliedEvent and field-level
 * targets included. The last case locks the placement entrance
 * (add_element's populate: 'sample'), which stops after the generator rung.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsSampleFillTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field_sample_value', 'field_test'];

  /**
   * The blueprint operations service.
   */
  protected BlueprintOperations $operations;

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

    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_body', 'text_long', 'text_section');
    $this->operations = $this->container->get('daedalus_blueprint.operations');

    $this->container->get('event_dispatcher')->addListener(
      FieldAppliedEvent::class,
      function (FieldAppliedEvent $event): void {
        $this->appliedEvents[] = $event;
      },
    );
  }

  /**
   * Configures field_sample_value's random_string generator on a field.
   */
  protected function configureGenerator(string $field_name, bool $prevent_save = FALSE): void {
    $field = FieldConfig::loadByName('entity_test', 'entity_test', $field_name);
    $field->setThirdPartySetting('field_sample_value', 'id', 'random_string');
    $field->setThirdPartySetting('field_sample_value', 'configuration', [
      'prevent_save' => $prevent_save,
      'count' => 3,
    ]);
    $field->save();
  }

  /**
   * Builds a root update_element op asking for a sample on the given fields.
   */
  protected function sampleOp(object $entity, array $field_names): array {
    return [
      'op' => 'update_element',
      'element' => $entity->uuid(),
      'properties' => array_fill_keys($field_names, [SampleRequest::KEY => TRUE]),
    ];
  }

  /**
   * The field's own default value is the first rung, above the generator.
   */
  public function testDefaultValueWins(): void {
    $field = FieldConfig::loadByName('entity_test', 'entity_test', 'field_subtitle');
    $field->setDefaultValue([['value' => 'The site default']])->save();
    $this->configureGenerator('field_subtitle');
    $entity = $this->createTestEntity(['field_subtitle' => NULL]);
    $this->assertTrue($entity->get('field_subtitle')->isEmpty());

    $result = $this->operations->batchOperations($entity, [$this->sampleOp($entity, ['field_subtitle'])]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame('The site default', $entity->get('field_subtitle')->value);
    // The empty field filled: its render appeared, so the presence sentinel
    // rides beside the field target and the response re-renders the entity.
    $this->assertSame([
      ['uuid' => $entity->uuid(), 'field_name' => 'field_subtitle'],
      ['uuid' => $entity->uuid(), 'field_name' => TargetType::PRESENCE],
    ], $result->getTargets());

    // The sample landed as an ExtractedValue: one event, the extracted kind.
    $this->assertCount(1, $this->appliedEvents);
    $this->assertSame('field_subtitle', $this->appliedEvents[0]->getFieldName());
    $this->assertSame(FieldValueKind::Extracted, $this->appliedEvents[0]->getValueKind());
  }

  /**
   * The configured generator is the second rung, above core's sample.
   */
  public function testGeneratorBeforeCoreSample(): void {
    $this->configureGenerator('field_subtitle');
    $entity = $this->createTestEntity();

    $result = $this->operations->batchOperations($entity, [$this->sampleOp($entity, ['field_subtitle'])]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    // random_string writes a sentence of at least the configured word count;
    // core's string sample is one random word.
    $this->assertGreaterThanOrEqual(3, str_word_count($entity->get('field_subtitle')->value));
  }

  /**
   * Core's generateSampleItems() is the request's last rung when no policy.
   *
   * The request means "make one up": a field with no default value and no
   * configured generator still gets core's type-level sample.
   */
  public function testCoreSampleFallback(): void {
    $entity = $this->createTestEntity();
    $this->assertTrue($entity->get('field_count')->isEmpty());

    $result = $this->operations->batchOperations($entity, [$this->sampleOp($entity, ['field_count'])]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertIsNumeric($entity->get('field_count')->value);
  }

  /**
   * A configured generator's empty answer is final: no core fallback.
   *
   * The entity_reference generator only ever picks a published target; with
   * none on the site it yields nothing, and that is the site's policy. Core's
   * EntityReferenceItem sample would fabricate an unsaved target instead, so
   * the request fails with NO_SAMPLE_VALUE rather than continuing to core.
   */
  public function testPolicyEmptyIsFinal(): void {
    $this->installEntitySchema('entity_test_mulrevpub');
    $this->createField('field_ref', 'entity_reference', ['target_type' => 'entity_test_mulrevpub']);
    $field = FieldConfig::loadByName('entity_test', 'entity_test', 'field_ref');
    $field->setSetting('handler', 'default:entity_test_mulrevpub');
    $field->setSetting('handler_settings', [
      'target_bundles' => ['entity_test_mulrevpub' => 'entity_test_mulrevpub'],
    ]);
    $field->setThirdPartySetting('field_sample_value', 'id', 'entity_reference');
    $field->setThirdPartySetting('field_sample_value', 'configuration', ['prevent_save' => FALSE]);
    $field->save();
    // The only candidate is unpublished: the generator runs and picks nothing.
    EntityTestMulRevPub::create(['name' => 'Draft only', 'status' => 0])->save();
    $entity = $this->createTestEntity();

    $result = $this->operations->batchOperations($entity, [$this->sampleOp($entity, ['field_ref'])]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('NO_SAMPLE_VALUE', $errors[0]->getCode());
    $this->assertStringEndsWith('properties.field_ref', $errors[0]->getPath());
    $this->assertTrue($entity->get('field_ref')->isEmpty(), 'No unsaved target was fabricated.');
    $this->assertSame([], $this->appliedEvents);
  }

  /**
   * A sample replaces whatever the field held: the request is a write.
   */
  public function testSampleReplacesCurrentValue(): void {
    $field = FieldConfig::loadByName('entity_test', 'entity_test', 'field_subtitle');
    $field->setDefaultValue([['value' => 'The site default']])->save();
    $entity = $this->createTestEntity(['field_subtitle' => 'Typed by hand']);

    $result = $this->operations->batchOperations($entity, [$this->sampleOp($entity, ['field_subtitle'])]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame('The site default', $entity->get('field_subtitle')->value);
  }

  /**
   * A field the grammar cannot spell (non-processable) still fills.
   *
   * `created` is on the handler manager's always-skip list, so a grammar
   * value is silently dropped; the sample request resolves into an
   * ExtractedValue and bypasses that filter, exactly as a form commit does.
   */
  public function testNonProcessableFieldFills(): void {
    $entity = $this->createTestEntity();
    $entity->set('created', NULL);
    $this->assertTrue($entity->get('created')->isEmpty());

    $result = $this->operations->batchOperations($entity, [$this->sampleOp($entity, ['created'])]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    // CreatedItem's item-level default (the request time) is the first rung.
    $this->assertGreaterThan(0, (int) $entity->get('created')->value);
  }

  /**
   * A block's field fills through the component uuid.
   */
  public function testBlockField(): void {
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
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

    $result = $this->operations->batchOperations($entity, [
      [
        'op' => 'update_element',
        'element' => self::BLOCK_UUID,
        'properties' => ['field_body' => [SampleRequest::KEY => TRUE]],
      ],
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $component = $entity->get('layout_builder__layout')->get(0)->section->getComponent(self::BLOCK_UUID);
    $written = $this->unserializeBlock($component->get('configuration')['block_serialized']);
    $this->assertNotEmpty($written->get('field_body')->value);
    $this->assertSame([
      ['uuid' => self::BLOCK_UUID, 'field_name' => 'field_body'],
      ['uuid' => self::BLOCK_UUID, 'field_name' => TargetType::PRESENCE],
    ], $result->getTargets());
    $this->assertCount(1, $this->appliedEvents);
    $this->assertSame(self::BLOCK_UUID, $this->appliedEvents[0]->getElementUuid());
    $this->assertSame(FieldValueKind::Extracted, $this->appliedEvents[0]->getValueKind());
  }

  /**
   * A request carrying a value beside the marker is refused structurally.
   */
  public function testExtraKeysRefused(): void {
    $entity = $this->createTestEntity(['field_subtitle' => 'Kept']);

    $result = $this->operations->batchOperations($entity, [
      [
        'op' => 'update_element',
        'element' => $entity->uuid(),
        'properties' => ['field_subtitle' => [SampleRequest::KEY => TRUE, 'value' => 'Also this']],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_SAMPLE_REQUEST', $errors[0]->getCode());
    $this->assertStringEndsWith('properties.field_subtitle', $errors[0]->getPath());
    $this->assertSame(['value'], $errors[0]->getContext()['unknown']);
    $this->assertSame('Kept', $entity->get('field_subtitle')->value);
    $this->assertSame([], $this->appliedEvents);
  }

  /**
   * A field no rung can fill fails visibly instead of writing nothing.
   *
   * Field_test's test_field type has no default, no generator and no
   * type-level sample.
   */
  public function testNoSampleValue(): void {
    $this->createField('field_blank', 'test_field');
    $entity = $this->createTestEntity();

    $result = $this->operations->batchOperations($entity, [$this->sampleOp($entity, ['field_blank'])]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('NO_SAMPLE_VALUE', $errors[0]->getCode());
    $this->assertStringEndsWith('properties.field_blank', $errors[0]->getPath());
    $this->assertSame([], $this->appliedEvents);
  }

  /**
   * Field access is judged before any sample is made.
   */
  public function testAccessDenied(): void {
    $this->configureGenerator('field_subtitle');
    $entity = $this->createTestEntity(['field_subtitle' => 'Saved']);
    \Drupal::state()->set('views_field_access_test-field', 'field_subtitle');
    $this->setUpCurrentUser([], ['administer entity_test content']);

    $result = $this->operations->batchOperations($entity, [$this->sampleOp($entity, ['field_subtitle'])]);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_FIELD', $errors[0]->getCode());
    $this->assertSame('Saved', $entity->get('field_subtitle')->value, 'The denied write is rolled back.');
    $this->assertSame([], $this->appliedEvents);
  }

  /**
   * A generator that prevents saving its sample is refused at write time.
   *
   * Field_sample_value's SampleValue constraint marks such a value as
   * unsaveable; the spine validates every write as saveable, so the request
   * fails with that constraint rather than staging a value Save would
   * refuse.
   */
  public function testPreventSaveGeneratorRefused(): void {
    $this->configureGenerator('field_subtitle', TRUE);
    $entity = $this->createTestEntity(['field_subtitle' => 'Saved']);

    $result = $this->operations->batchOperations($entity, [$this->sampleOp($entity, ['field_subtitle'])]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $codes = array_map(fn($error) => $error->getCode(), $result->getErrors());
    $this->assertSame(['CONSTRAINT_VIOLATION'], $codes);
    $this->assertStringContainsString('automatically generated sample values', $result->getErrors()[0]->getMessage());
    $this->assertSame('Saved', $entity->get('field_subtitle')->value, 'The violating write is rolled back.');
  }

  /**
   * Add_element's populate: 'sample' rides the same ladder's placement door.
   *
   * The block field's default value is the first rung there too, above its
   * configured generator. What placement does below the generator rung
   * (nothing: core's sample is not a placement rung) is
   * BlueprintOperationsAddParityTest::testPopulateSample's to lock.
   */
  public function testPopulateSampleRidesTheLadder(): void {
    $field = FieldConfig::loadByName('block_content', 'text_section', 'field_body');
    $field->setDefaultValue([['value' => '<p>Block default</p>', 'format' => 'plain_text']])->save();
    $seed_uuid = '7a3f08ce-2f70-4c98-8f5b-3d1f6a9be0c4';
    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => $seed_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => [
          'id' => 'system_powered_by_block',
          'provider' => 'system',
          'label' => 'Anchor seed',
          'label_display' => '0',
        ],
      ],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->addElement($entity, ['anchor' => $seed_uuid, 'placement' => 'after'], [
      'block_type' => 'text_section',
      'uuid' => self::BLOCK_UUID,
      'populate' => 'sample',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $component = $entity->get('layout_builder__layout')->get(0)->section->getComponent(self::BLOCK_UUID);
    $written = $this->unserializeBlock($component->get('configuration')['block_serialized']);
    $this->assertSame('<p>Block default</p>', $written->get('field_body')->value);
  }

}
