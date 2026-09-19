<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests structure completeness: form-only stub nodes in FULL mode.
 *
 * FULL serialization gives every processable field definition a node; a
 * field without a serialized value (empty, or view-access filtered) carries
 * an identity-only stub so structural consumers (the page-elements panel,
 * the presentation policy) cover the same set the canvas can select.
 * Summary and component transports stay sparse, and every write path treats
 * a stub as "leave the field alone".
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class FormOnlyStubTest extends BlueprintKernelTestBase {

  /**
   * The serializer under test.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintSerializerInterface
   */
  protected $serializer;

  /**
   * The deserializer under test.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintDeserializerInterface
   */
  protected $deserializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
  }

  /**
   * FULL mode stubs empty fields; summary mode stays sparse.
   */
  public function testFullModeEmitsStubsForEmptyFields(): void {
    $entity = $this->createTestEntity([
      'name' => 'Stub fixture',
      'field_subtitle' => 'A subtitle',
      // field_count, field_email, etc. stay empty.
    ]);

    $full = $this->serializer->serialize($entity);
    $fields = $full['fields'];

    // Populated fields keep their values.
    $this->assertSame('A subtitle', $fields['field_subtitle']);

    // Empty fields carry identity-only stubs: the marker and the
    // definition's label, nothing else.
    $this->assertArrayHasKey('field_count', $fields);
    $this->assertTrue(FormOnlyStub::isStub($fields['field_count']));
    $this->assertSame(
      [FormOnlyStub::KEY => TRUE, '_label' => 'Count'],
      $fields['field_count'],
    );

    // Summary transport stays sparse — no stub nodes.
    $summary = $this->serializer->serializeSummary($entity);
    $this->assertArrayNotHasKey('field_count', $summary['fields'] ?? []);
  }

  /**
   * A stub echoed back in a write is a no-op, never a value write.
   */
  public function testStubValuesAreIgnoredOnWrite(): void {
    $entity = $this->createTestEntity([
      'name' => 'Round trip',
      'field_subtitle' => 'Original subtitle',
      'field_count' => 7,
    ]);

    // Read FULL (contains stubs for the empty fields), modify one field,
    // and — the hostile part — replace a POPULATED field's value with a
    // stub, as if a confused client echoed presentation identity back.
    $blueprint = $this->serializer->serialize($entity);
    $this->assertTrue(FormOnlyStub::isStub($blueprint['fields']['field_email']));
    $blueprint['fields']['field_subtitle'] = 'Changed subtitle';
    $blueprint['fields']['field_count'] = FormOnlyStub::forDefinition(
      $entity->get('field_count')->getFieldDefinition(),
    );

    $result = $this->deserializer->deserialize($blueprint);

    $this->assertTrue($result->isSuccess(), print_r($result->getErrors(), TRUE));
    $updated = $result->getEntity();
    $this->assertSame('Changed subtitle', $updated->get('field_subtitle')->value);
    // The stubbed field kept its stored value; the empty stubbed fields
    // stayed empty.
    $this->assertSame(7, (int) $updated->get('field_count')->value);
    $this->assertTrue($updated->get('field_email')->isEmpty());
  }

  /**
   * Inline-block fields get stubs in FULL mode, none in component mode.
   */
  public function testInlineBlockFieldStubs(): void {
    $this->enableLayoutBuilder();
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Welcome',
      // field_body stays empty.
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '19522840-e96e-9546-3ee1-f97220a240ac',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Hero'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Stub layout page',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // FULL mode: the empty block field carries a stub beside the populated
    // one.
    $full = $this->serializer->serialize($entity);
    $component = $full['layout_builder__layout'][0]['regions']['content'][0];
    $this->assertSame('Welcome', $component['block_fields']['field_heading']);
    $this->assertTrue(FormOnlyStub::isStub($component['block_fields']['field_body']));
    $this->assertSame('Body', $component['block_fields']['field_body']['_label']);

    // Component mode (AI transport): sparse, no stubs.
    $component_read = $this->serializer->serializeElement($entity, '19522840-e96e-9546-3ee1-f97220a240ac');
    $this->assertArrayNotHasKey('field_body', $component_read['block_fields']);

    // Summary mode: sparse, no stubs.
    $summary = $this->serializer->serializeSummary($entity);
    $summary_component = $summary['layout_builder__layout'][0]['regions']['content'][0];
    $this->assertArrayNotHasKey('block_fields', $summary_component);
  }

  /**
   * The strip helper removes stubs recursively (the AI transport face).
   */
  public function testStrip(): void {
    $data = [
      'fields' => [
        'kept' => 'value',
        'stubbed' => [FormOnlyStub::KEY => TRUE, '_label' => 'Stubbed'],
      ],
      'layout_builder__layout' => [
        [
          'regions' => [
            'content' => [
              [
                'uuid' => 'a9f7e979-65d6-cf79-9a52-9102a973b8b9',
                'block_fields' => [
                  'field_a' => 'text',
                  'field_b' => [FormOnlyStub::KEY => TRUE, '_label' => 'B'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $stripped = FormOnlyStub::strip($data);

    $this->assertSame(['kept' => 'value'], $stripped['fields']);
    $this->assertSame(
      ['field_a' => 'text'],
      $stripped['layout_builder__layout'][0]['regions']['content'][0]['block_fields'],
    );
  }

}
