<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\LayoutBuilder;

use Drupal\daedalus_blueprint\Validator\StructuralValidator;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests structural validation of Layout Builder data.
 */
#[CoversClass(StructuralValidator::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class LayoutStructuralValidationTest extends BlueprintKernelTestBase {

  /**
   * The structural validator.
   *
   * @var \Drupal\daedalus_blueprint\Validator\StructuralValidator
   */
  protected StructuralValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('body', 'text_with_summary', 'text_section');

    $this->validator = $this->container->get('daedalus_blueprint.structural_validator');
  }

  /**
   * Tests valid layout passes validation.
   */
  public function testValidLayout(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
                'block_type' => 'text_section',
                'block_fields' => [
                  'body' => [
                    'value' => '<p>Test</p>',
                    'format' => 'plain_text',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertSame([], $errors);
  }

  /**
   * Tests invalid layout plugin ID.
   */
  public function testInvalidLayoutId(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'layout_builder__layout' => [
        [
          'layout_id' => 'nonexistent_layout',
          'regions' => [],
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_LAYOUT', $errors[0]->getCode());
    $this->assertSame('layout_builder__layout[0].layout_id', $errors[0]->getPath());
    $this->assertArrayHasKey('available', $errors[0]->getContext());
    $available = $errors[0]->getContext()['available'];
    $ids = array_column($available, 'id');
    $this->assertContains('layout_onecol', $ids);
    // The legal set carries the spatial column counts from the layouts'
    // icon maps, matching the schema's available_layouts vocabulary.
    $onecol = $available[array_search('layout_onecol', $ids, TRUE)];
    $this->assertSame(1, $onecol['columns']);
    $twocol = $available[array_search('layout_twocol_section', $ids, TRUE)];
    $this->assertSame(2, $twocol['columns']);
  }

  /**
   * Tests missing layout plugin ID.
   */
  public function testMissingLayoutId(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'layout_builder__layout' => [
        [
          'regions' => [],
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_LAYOUT', $errors[0]->getCode());
  }

  /**
   * Tests invalid region.
   */
  public function testInvalidRegion(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'nonexistent_region' => [
              [
                'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
                'block_type' => 'text_section',
                'block_fields' => [],
              ],
            ],
          ],
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_REGION', $errors[0]->getCode());
    $this->assertSame('layout_builder__layout[0].regions.nonexistent_region', $errors[0]->getPath());
    $this->assertContains('content', $errors[0]->getContext()['available']);
  }

  /**
   * Tests twocol layout region validation.
   */
  public function testTwoColRegions(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_twocol_section',
          'regions' => [
            'first' => [
              [
                'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
                'block_type' => 'text_section',
                'block_fields' => [],
              ],
            ],
            'second' => [
              [
                'uuid' => '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
                'block_type' => 'text_section',
                'block_fields' => [],
              ],
            ],
          ],
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertSame([], $errors);
  }

  /**
   * Tests invalid block type.
   */
  public function testInvalidBlockType(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
                'block_type' => 'nonexistent_type',
                'block_fields' => [],
              ],
            ],
          ],
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_BLOCK_TYPE', $errors[0]->getCode());
    $this->assertSame('layout_builder__layout[0].regions.content[0].block_type', $errors[0]->getPath());
    $this->assertContains('text_section', $errors[0]->getContext()['available']);
  }

  /**
   * Tests unknown block field.
   */
  public function testUnknownBlockField(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
                'block_type' => 'text_section',
                'block_fields' => [
                  'nonexistent_field' => 'value',
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);
    $this->assertSame('UNKNOWN_BLOCK_FIELD', $errors[0]->getCode());
    $this->assertSame('layout_builder__layout[0].regions.content[0].block_fields.nonexistent_field', $errors[0]->getPath());
    $this->assertContains('body', $errors[0]->getContext()['available']);
  }

  /**
   * Tests multiple errors collected across sections and components.
   */
  public function testMultipleErrors(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'bad_region' => [
              [
                'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
                'block_type' => 'text_section',
                'block_fields' => [
                  'bad_field' => 'value',
                ],
              ],
            ],
          ],
        ],
        [
          'layout_id' => 'bad_layout',
          'regions' => [],
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $codes = array_map(fn($e) => $e->getCode(), $errors);

    $this->assertContains('INVALID_REGION', $codes);
    $this->assertContains('UNKNOWN_BLOCK_FIELD', $codes);
    $this->assertContains('INVALID_LAYOUT', $codes);
  }

  /**
   * Tests layout errors combined with field errors.
   */
  public function testLayoutAndFieldErrorsCombined(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'nonexistent_field' => 'value',
      ],
      'layout_builder__layout' => [
        [
          'layout_id' => 'bad_layout',
          'regions' => [],
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $codes = array_map(fn($e) => $e->getCode(), $errors);

    $this->assertContains('UNKNOWN_FIELD', $codes);
    $this->assertContains('INVALID_LAYOUT', $codes);
  }

  /**
   * Tests block field format validation for text fields.
   */
  public function testBlockFieldFormatValidation(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
                'block_type' => 'text_section',
                'block_fields' => [
                  'body' => [
                    'value' => '<p>Test</p>',
                    'format' => 'plain_text',
                    'unknown_prop' => 'bad',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_FIELD_FORMAT', $errors[0]->getCode());
    $this->assertStringContainsString('block_fields.body', $errors[0]->getPath());
  }

  /**
   * Tests that invalid layout skips component validation for that section.
   */
  public function testInvalidLayoutSkipsComponentValidation(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'layout_builder__layout' => [
        [
          'layout_id' => 'nonexistent_layout',
          'regions' => [
            'content' => [
              [
                'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
                'block_type' => 'nonexistent_type',
              ],
            ],
          ],
        ],
      ],
    ];

    $errors = $this->validator->validate($data);
    // Only layout error — components skipped because layout is invalid.
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_LAYOUT', $errors[0]->getCode());
  }

  /**
   * Tests empty layout array passes validation.
   */
  public function testEmptyLayoutArray(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'layout_builder__layout' => [],
    ];

    $errors = $this->validator->validate($data);
    $this->assertSame([], $errors);
  }

  /**
   * Tests validation when no layout key is present.
   */
  public function testNoLayoutKeyPasses(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_subtitle' => 'Test',
      ],
    ];

    $errors = $this->validator->validate($data);
    $this->assertSame([], $errors);
  }

}
