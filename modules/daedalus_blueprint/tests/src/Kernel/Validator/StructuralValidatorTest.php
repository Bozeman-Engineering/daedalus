<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Validator;

// cspell:ignore frmt titel
use Drupal\daedalus_blueprint\Validator\StructuralValidator;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the StructuralValidator (Phase A validation).
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(StructuralValidator::class)]
#[Group('daedalus_blueprint')]
class StructuralValidatorTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['link', 'file'];

  /**
   * The structural validator under test.
   *
   * @var \Drupal\daedalus_blueprint\Validator\StructuralValidator
   */
  protected StructuralValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Compound fields whose handlers speak underscore-prefixed vocabulary
    // beyond the storage properties: the link handler's read-only
    // "_displayable", the file handler's write-accepted "_label".
    $this->createField('field_link', 'link');
    $this->createField('field_links', 'link', [], ['cardinality' => -1]);
    $this->createField('field_attachment', 'file');
    $this->validator = $this->container->get('daedalus_blueprint.structural_validator');
  }

  /**
   * Tests that a valid blueprint produces no errors.
   */
  public function testValidBlueprint(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test entity',
        'field_subtitle' => 'A subtitle',
        'field_count' => 42,
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertEmpty($errors);
  }

  /**
   * Tests valid blueprint with compound field properties.
   */
  public function testValidCompoundField(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_body' => [
          'value' => '<p>Hello</p>',
          'format' => 'plain_text',
          'summary' => 'Hello',
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertEmpty($errors);
  }

  /**
   * Tests that a bare string for a compound field is valid.
   *
   * Handlers like TextFieldHandler accept bare strings as shorthand.
   */
  public function testCompoundFieldBareStringIsValid(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_body' => 'Just a string',
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertEmpty($errors);
  }

  /**
   * Tests that NULL field values are valid (clearing the field).
   */
  public function testNullFieldValueIsValid(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_subtitle' => NULL,
        'field_body' => NULL,
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertEmpty($errors);
  }

  /**
   * Tests that an empty fields array produces no errors.
   */
  public function testEmptyFieldsIsValid(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [],
    ];

    $errors = $this->validator->validate($data);
    $this->assertEmpty($errors);
  }

  /**
   * Tests that omitting fields entirely produces no errors.
   */
  public function testNoFieldsKeyIsValid(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ];

    $errors = $this->validator->validate($data);
    $this->assertEmpty($errors);
  }

  /**
   * A field placed at the top level instead of under "fields" is rejected.
   *
   * Guards the silent-drop trap: the deserializer reads field values only
   * from the "fields" key, so a stray top-level field name would vanish and
   * surface later as a confusing null-constraint violation. It must fail
   * clearly in Phase A instead.
   */
  public function testUnknownTopLevelKeyRejected(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      // "name" is a real field but belongs inside "fields", not at the top.
      'name' => 'Misplaced title',
      'fields' => [
        'field_subtitle' => 'A subtitle',
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);
    $this->assertSame('name', $errors[0]->getPath());
    $this->assertSame('UNKNOWN_KEY', $errors[0]->getCode());
  }

  /**
   * The legitimate top-level keys never trigger the unknown-key check.
   */
  public function testLegitimateTopLevelKeysAccepted(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'langcode' => 'en',
      'fields' => ['name' => 'Test entity'],
    ];

    $errors = $this->validator->validate($data);
    $this->assertEmpty($errors);
  }

  /**
   * Tests nonexistent entity type returns INVALID_ENTITY_TYPE.
   */
  public function testInvalidEntityType(): void {
    $data = [
      'entity_type' => 'nonexistent_type',
      'bundle' => 'page',
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);

    $error = $errors[0];
    $this->assertSame('entity_type', $error->getPath());
    $this->assertSame('INVALID_ENTITY_TYPE', $error->getCode());
    $this->assertStringContainsString('nonexistent_type', $error->getMessage());

    // Available types should include fieldable entity types.
    $available = $error->getContext()['available'];
    $this->assertContains('entity_test', $available);
    $this->assertContains('user', $available);
  }

  /**
   * Tests missing entity type returns INVALID_ENTITY_TYPE.
   */
  public function testMissingEntityType(): void {
    $data = [
      'bundle' => 'entity_test',
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_ENTITY_TYPE', $errors[0]->getCode());
    $this->assertNotEmpty($errors[0]->getContext()['available']);
  }

  /**
   * Tests non-fieldable entity type returns INVALID_ENTITY_TYPE.
   */
  public function testNonFieldableEntityType(): void {
    $data = [
      'entity_type' => 'filter_format',
      'bundle' => 'filter_format',
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);

    $error = $errors[0];
    $this->assertSame('entity_type', $error->getPath());
    $this->assertSame('INVALID_ENTITY_TYPE', $error->getCode());
    $this->assertStringContainsString('not fieldable', $error->getMessage());

    // Available types should not include non-fieldable types.
    $available = $error->getContext()['available'];
    $this->assertNotContains('filter_format', $available);
  }

  /**
   * Tests that invalid entity type prevents field validation.
   */
  public function testInvalidEntityTypeBailsEarly(): void {
    $data = [
      'entity_type' => 'nonexistent',
      'bundle' => 'page',
      'fields' => [
        'title' => 'Test',
      ],
    ];

    $errors = $this->validator->validate($data);
    // Only entity type error, no field errors.
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_ENTITY_TYPE', $errors[0]->getCode());
  }

  /**
   * Tests nonexistent bundle returns INVALID_BUNDLE.
   */
  public function testInvalidBundle(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'nonexistent_bundle',
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);

    $error = $errors[0];
    $this->assertSame('bundle', $error->getPath());
    $this->assertSame('INVALID_BUNDLE', $error->getCode());
    $this->assertStringContainsString('nonexistent_bundle', $error->getMessage());

    // Available bundles should include entity_test's default bundle.
    $available = $error->getContext()['available'];
    $this->assertContains('entity_test', $available);
  }

  /**
   * Tests missing bundle returns INVALID_BUNDLE.
   */
  public function testMissingBundle(): void {
    $data = [
      'entity_type' => 'entity_test',
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);

    $error = $errors[0];
    $this->assertSame('bundle', $error->getPath());
    $this->assertSame('INVALID_BUNDLE', $error->getCode());
    $this->assertStringContainsString('required', $error->getMessage());
    $this->assertNotEmpty($error->getContext()['available']);
  }

  /**
   * Tests that invalid bundle prevents field validation.
   */
  public function testInvalidBundleBailsEarly(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'nonexistent',
      'fields' => [
        'field_nonexistent' => 'value',
      ],
    ];

    $errors = $this->validator->validate($data);
    // Only bundle error, no field errors.
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_BUNDLE', $errors[0]->getCode());
  }

  /**
   * Tests unknown field name returns UNKNOWN_FIELD.
   */
  public function testUnknownField(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_nonexistent' => 'some value',
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);

    $error = $errors[0];
    $this->assertSame('fields.field_nonexistent', $error->getPath());
    $this->assertSame('UNKNOWN_FIELD', $error->getCode());
    $this->assertStringContainsString('field_nonexistent', $error->getMessage());

    // Available fields should include the test fields.
    $available = $error->getContext()['available'];
    $this->assertContains('field_subtitle', $available);
    $this->assertContains('field_body', $available);
    $this->assertContains('name', $available);
  }

  /**
   * Tests multiple unknown fields are all reported.
   */
  public function testMultipleUnknownFields(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_fake_one' => 'value',
        'name' => 'Valid field',
        'field_fake_two' => 'value',
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(2, $errors);

    $this->assertSame('fields.field_fake_one', $errors[0]->getPath());
    $this->assertSame('UNKNOWN_FIELD', $errors[0]->getCode());

    $this->assertSame('fields.field_fake_two', $errors[1]->getPath());
    $this->assertSame('UNKNOWN_FIELD', $errors[1]->getCode());
  }

  /**
   * Tests that valid and invalid fields are mixed correctly.
   *
   * Valid fields produce no errors while invalid fields are reported.
   */
  public function testMixedValidAndInvalidFields(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_subtitle' => 'Valid',
        'field_typo' => 'Invalid',
        'field_count' => 42,
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);
    $this->assertSame('fields.field_typo', $errors[0]->getPath());
    $this->assertSame('UNKNOWN_FIELD', $errors[0]->getCode());
  }

  /**
   * Tests compound field with unknown property names.
   */
  public function testInvalidFieldFormatUnknownProperties(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_body' => [
          'val' => '<p>Hello</p>',
          'frmt' => 'full_html',
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);

    $error = $errors[0];
    $this->assertSame('fields.field_body', $error->getPath());
    $this->assertSame('INVALID_FIELD_FORMAT', $error->getCode());

    // Context includes unknown and expected properties.
    $context = $error->getContext();
    $this->assertContains('val', $context['unknown']);
    $this->assertContains('frmt', $context['unknown']);
    $this->assertContains('value', $context['expected']);
    $this->assertContains('format', $context['expected']);
    $this->assertContains('summary', $context['expected']);
  }

  /**
   * Tests compound field with a mix of valid and unknown properties.
   */
  public function testInvalidFieldFormatMixedProperties(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_body' => [
          'value' => '<p>Hello</p>',
          'format' => 'plain_text',
          'color' => 'red',
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);

    $error = $errors[0];
    $this->assertSame('INVALID_FIELD_FORMAT', $error->getCode());
    $this->assertContains('color', $error->getContext()['unknown']);
    $this->assertNotContains('value', $error->getContext()['unknown']);
  }

  /**
   * Tests text_long field with unknown properties.
   */
  public function testInvalidFieldFormatTextLong(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_description' => [
          'value' => 'Some text',
          'unknown_prop' => 'bad',
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_FIELD_FORMAT', $errors[0]->getCode());
    $this->assertContains('unknown_prop', $errors[0]->getContext()['unknown']);
  }

  /**
   * Tests underscore-prefixed item keys are admitted as handler vocabulary.
   *
   * The serializer enriches link items with the read-only "_displayable"
   * and reference items with "_label"; neither is a storage property, and
   * both belong to the handler (Phase B), so Phase A must not refuse them:
   * a read payload spread back as a write has to pass this phase.
   */
  public function testHandlerVocabularyKeysAdmitted(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_link' => [
          'uri' => 'https://example.com',
          'title' => 'Example',
          '_displayable' => 'https://example.com',
        ],
        'field_links' => [
          ['uri' => 'internal:/about', '_displayable' => '/about'],
          ['uri' => 'route:<nolink>', 'title' => 'Nothing', '_displayable' => '<nolink>'],
        ],
        'field_attachment' => [
          'target_id' => 1,
          '_label' => 'document.pdf',
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertSame([], array_map('strval', $errors));
  }

  /**
   * Tests an unknown property beside handler vocabulary is still refused.
   *
   * The underscore rule admits the handler's keys only; a typo in the
   * storage-property namespace is reported as before, with the vocabulary
   * key absent from the unknown list and the expected list still the
   * storage properties.
   */
  public function testHandlerVocabularyKeysDoNotMaskUnknownProperties(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_link' => [
          'uri' => 'https://example.com',
          '_displayable' => 'https://example.com',
          'titel' => 'Example',
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);
    $this->assertSame('fields.field_link', $errors[0]->getPath());
    $this->assertSame('INVALID_FIELD_FORMAT', $errors[0]->getCode());
    $this->assertSame(['titel'], $errors[0]->getContext()['unknown']);
    $this->assertSame(['uri', 'title', 'options'], $errors[0]->getContext()['expected']);
  }

  /**
   * Tests the reserved `_sample` request is refused in a blueprint's fields.
   *
   * A blueprint carries values; the sample request is an op-time word
   * (update_element resolves it), and no owner on the blueprint road would.
   * Refusing it here keeps a handler from misreading the marker as an item.
   */
  public function testSampleRequestRefusedInFields(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_subtitle' => ['_sample' => TRUE],
        'field_description' => ['_sample' => TRUE, 'format' => 'plain_text'],
        'field_count' => 3,
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(2, $errors);
    $this->assertSame('fields.field_subtitle', $errors[0]->getPath());
    $this->assertSame('SAMPLE_REQUEST_NOT_A_VALUE', $errors[0]->getCode());
    $this->assertStringContainsString('update_element', $errors[0]->getHint());
    $this->assertSame('fields.field_description', $errors[1]->getPath());
    $this->assertSame('SAMPLE_REQUEST_NOT_A_VALUE', $errors[1]->getCode());
  }

  /**
   * Tests multi-value field with valid sequential array.
   */
  public function testMultiValueFieldValid(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_tags_text' => ['Alpha', 'Beta', 'Gamma'],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertEmpty($errors);
  }

  /**
   * Tests multi-value field with single scalar value is valid.
   *
   * Single values are acceptable shorthand for multi-value fields.
   */
  public function testMultiValueFieldSingleValueValid(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_tags_text' => 'Single value',
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertEmpty($errors);
  }

  /**
   * Tests combined field name and format errors.
   */
  public function testCombinedFieldErrors(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_nonexistent' => 'value',
        'field_body' => [
          'val' => 'Wrong property',
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(2, $errors);

    $this->assertSame('UNKNOWN_FIELD', $errors[0]->getCode());
    $this->assertSame('fields.field_nonexistent', $errors[0]->getPath());

    $this->assertSame('INVALID_FIELD_FORMAT', $errors[1]->getCode());
    $this->assertSame('fields.field_body', $errors[1]->getPath());
  }

  /**
   * Tests that error toArray() produces expected structure.
   */
  public function testErrorToArrayStructure(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_nonexistent' => 'value',
      ],
    ];

    $errors = $this->validator->validate($data);
    $array = $errors[0]->toArray();

    $this->assertArrayHasKey('path', $array);
    $this->assertArrayHasKey('code', $array);
    $this->assertArrayHasKey('message', $array);
    $this->assertArrayHasKey('context', $array);
    $this->assertArrayHasKey('hint', $array);
    $this->assertArrayHasKey('available', $array['context']);
  }

}
