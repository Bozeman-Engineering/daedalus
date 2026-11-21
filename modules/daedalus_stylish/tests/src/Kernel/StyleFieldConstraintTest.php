<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

// cspell:ignore pading
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\daedalus_stylish\Traits\HostTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the constraint the two style field types carry.
 *
 * Validation follows the data: $entity->validate() runs the style map
 * validator over the stored document, on the host the entity is (a style
 * map) or at the variant's own scope (a variant's styles), so a value
 * that reached the field without a field handler or an operation handler
 * is still judged wherever the entity is validated. What is judged at
 * rest is structure (the landing posture): a value the law of the day
 * would refuse to WRITE (a literal off a bound scale under strict, a
 * reference to a variant the roster withholds) is not a violation, so a
 * change of law never makes a stored page invalid.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleFieldConstraintTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use HostTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'daedalus_blueprint',
    'filter',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_control_test',
  ];

  /**
   * A valid uuid to build keys with.
   */
  protected string $uuid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->setUpCurrentUser([], [], TRUE);
    // The site owns one scale and the container kind binds padding to it,
    // so the strict law has something to refuse (a literal padding) that
    // structure does not.
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
      ],
    ]);
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => [
        'property_groups' => [
          'spacing' => [
            'constraints' => [
              'padding' => ['scale' => 'space'],
            ],
          ],
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens(['space' => ['4' => '1rem']])->save();
    $this->activateSkin($skin);
    $this->enforce('strict');
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->uuid = $this->container->get('uuid')->generate();
  }

  /**
   * Saves a styling-enabled entity holding the given raw field value.
   *
   * A plain save runs no constraint, so anything lands: the road the
   * constraint exists for.
   */
  protected function stored(?string $value): EntityTest {
    $entity = EntityTest::create(['name' => 'styled']);
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, $value);
    $entity->save();
    return $entity;
  }

  /**
   * Saves a published variant with the given styles, unvalidated.
   */
  protected function variant(string $machine_name, array $styles, string $scope = 'kind:container', bool $published = TRUE): DaedalusStylishVariantInterface {
    $variant = $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->create([
      'label' => $machine_name,
      'machine_name' => $machine_name,
      'scope' => $scope,
      'status' => $published,
    ]);
    assert($variant instanceof DaedalusStylishVariantInterface);
    $variant->setStyles($styles)->save();
    return $variant;
  }

  /**
   * The violation messages $entity->validate() reports on one field.
   *
   * @return string[]
   *   The messages as plain text (a violation message is markup, its
   *   placeholders escaped), in order.
   */
  protected function violations(object $entity, string $field): array {
    $messages = [];
    foreach ($entity->validate()->getByField($field) as $violation) {
      $this->assertStringStartsWith("$field.0", $violation->getPropertyPath());
      $messages[] = html_entity_decode((string) $violation->getMessage(), ENT_QUOTES | ENT_HTML5);
    }
    return $messages;
  }

  /**
   * A map the spine wrote validates clean.
   */
  public function testMapTheSpineWroteValidatesClean(): void {
    $entity = $this->stored(NULL);
    $key = "container:{$this->uuid}";
    $result = $this->container->get('daedalus_blueprint.operations')->batchOperations($this->host($entity), [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['padding' => 'var(--space-4)']],
      ['op' => 'update_style', 'key' => $key, 'condition' => 'hover', 'properties' => ['margin' => '1rem']],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode(array_map(static fn ($error) => $error->toArray(), $result->getErrors())));
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    assert($item instanceof StyleMapItem);
    $this->assertSame([$key => ['default' => ['padding' => 'var(--space-4)'], 'hover' => ['margin' => '1rem']]], $item->getStyleMap());
    $this->assertSame([], $this->violations($entity, DaedalusStylishFieldManager::FIELD_NAME));
    // No field at all is nothing to judge.
    $this->assertSame([], $this->violations($this->stored(NULL), DaedalusStylishFieldManager::FIELD_NAME));
  }

  /**
   * An entry written without the spine is reported by validate().
   *
   * A key outside the grammar, condition text the printer would not
   * print, a property nothing recognizes and a reference to no saved
   * variant: each is one violation on the field, at the entry's path.
   */
  public function testEntryWrittenWithoutTheSpineIsReported(): void {
    $key = "container:{$this->uuid}";
    $condition = '@supports (display: grid){}</style><b>';
    $entity = $this->stored(json_encode([
      'nonsense' => ['default' => ['padding' => 'var(--space-4)']],
      $key => [
        'variants' => [['variant' => 'no_such_variant', 'fragment' => '']],
        'default' => ['pading' => '1rem'],
        $condition => ['margin' => '1rem'],
      ],
    ]));
    $messages = $this->violations($entity, DaedalusStylishFieldManager::FIELD_NAME);
    $this->assertCount(4, $messages, implode("\n", $messages));
    $all = implode("\n", $messages);
    $this->assertStringContainsString('nonsense: "nonsense" is not a valid style map key.', $all);
    $this->assertStringContainsString("$key.variants.0: \"no_such_variant\" is not a saved style variant.", $all);
    $this->assertStringContainsString("$key.default.pading: ", $all);
    $this->assertStringContainsString("$key.$condition: ", $all);
    $this->assertStringContainsString('not text a condition can print', $all);
  }

  /**
   * A stored value that is no JSON object is one violation.
   */
  public function testValueThatIsNoObjectIsReported(): void {
    foreach (['not json', '"text"', '[1,2]', '3'] as $value) {
      $this->assertSame(
        ['The stored styles are not a JSON object of style entries.'],
        $this->violations($this->stored($value), DaedalusStylishFieldManager::FIELD_NAME),
        $value,
      );
    }
    $this->assertSame([], $this->violations($this->stored('{}'), DaedalusStylishFieldManager::FIELD_NAME));
  }

  /**
   * The law of the day judges a write, never a value at rest.
   *
   * Under strict a literal padding is off the bound scale and a variant
   * holding one is withheld by the roster, so the write door refuses
   * both; stored, the map is structurally sound and validates clean, and
   * stays savable when the law changes around it. A reference to an
   * unpublished variant dangles, which is structure.
   */
  public function testLawOfTheDayIsNotJudgedAtRest(): void {
    $this->variant('raw', ['' => ['default' => ['padding' => '0.75em']]]);
    $key = "container:{$this->uuid}";
    $map = [
      $key => [
        'variants' => [['variant' => 'raw', 'fragment' => '']],
        'default' => ['padding' => '0.75em'],
      ],
    ];
    $entity = $this->stored(json_encode($map));
    $codes = [];
    foreach ($this->container->get('daedalus_stylish.style_map_validator')->validate($map, ['host' => $this->host($entity)]) as $error) {
      $codes[$error->getPath()] = $error->getCode();
    }
    $this->assertSame([
      "$key.variants.0" => 'STYLE_VARIANT_NONCONFORMING',
      "$key.default.padding" => 'STYLE_VALUE_NOT_ON_SCALE',
    ], $codes);
    $this->assertSame([], $this->violations($entity, DaedalusStylishFieldManager::FIELD_NAME));

    $this->variant('drafted', ['' => ['default' => ['padding' => 'var(--space-4)']]], published: FALSE);
    $entity = $this->stored(json_encode([$key => ['variants' => [['variant' => 'drafted', 'fragment' => '']]]]));
    $this->assertSame(
      ["$key.variants.0: \"drafted\" is not a saved style variant."],
      $this->violations($entity, DaedalusStylishFieldManager::FIELD_NAME),
    );
  }

  /**
   * A variant's styles validate at the variant's own scope.
   *
   * Sound styles are clean, and so is a variant the strict law withholds
   * (a shipped definition lands this way). A key outside the relative
   * grammar, an unrecognized property and a reference scoped to another
   * kind are violations on the styles field.
   */
  public function testVariantStylesValidateAtTheVariantScope(): void {
    $this->assertSame([], $this->violations($this->variant('sound', ['' => ['default' => ['padding' => 'var(--space-4)']]]), 'styles'));
    $this->assertSame([], $this->violations($this->variant('raw', ['' => ['default' => ['padding' => '0.75em']]]), 'styles'));
    $this->assertSame([], $this->violations($this->variant('bare', []), 'styles'));

    $this->variant('block_look', ['' => ['default' => ['margin' => '1rem']]], 'kind:block');
    $messages = $this->violations($this->variant('broken', [
      '' => ['default' => ['pading' => '1rem']],
      'container:nope' => ['default' => ['margin' => '1rem']],
      'container(1)' => ['variants' => [['variant' => 'block_look', 'fragment' => '']]],
    ]), 'styles');
    $this->assertCount(3, $messages, implode("\n", $messages));
    $all = implode("\n", $messages);
    $this->assertStringContainsString('default.pading: ', $all);
    $this->assertStringContainsString('container:nope: "container:nope" is not an element-relative style key for scope "kind:container".', $all);
    $this->assertStringContainsString('container(1).variants.0: The "block_look" variant is scoped to "kind:block"', $all);

    // A reference back to the variant itself is a cycle the stored
    // identity lets the walk see.
    $self = $this->variant('self', []);
    $self->setStyles(['container(1)' => ['variants' => [['variant' => 'self', 'fragment' => '']]]]);
    $this->assertNotSame([], $this->violations($self, 'styles'));
  }

}
