<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\media\Entity\Media;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\MediaToken;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the update_style, apply_variant, and detach_variant operations.
 *
 * The operations register with daedalus_blueprint's operation handler registry
 * and ride its batchOperations() grammar. The style guide in play comes from
 * daedalus_stylish_guide_test: section declares spacing with padding max 4rem, and
 * the testimonial block type declares typography with font-size max 2rem
 * plus the "emphasis" variant axis beside the built-in "style" axis.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishOperationsTest extends KernelTestBase {

  use UserCreationTrait;
  use DesignSystemActivationTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
    // The unbound site: the class writes literal paddings, gaps and sizes
    // on a site that mints nothing, and the suite's bindings would refuse
    // every one for a reason that is the scale's, not the claim's.
    'daedalus_stylish_unbound_test',
  ];

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperationsInterface
   */
  protected $operations;

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
    $this->installEntitySchema('daedalus_variant');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    // Access-transparent like the daedalus_blueprint operations suite: these
    // tests verify operation behavior, not access.
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->uuid = $this->container->get('uuid')->generate();
  }

  /**
   * Creates a styling-enabled test entity, optionally with existing styles.
   */
  protected function createStyledEntity(array $map = []): EntityTest {
    $entity = EntityTest::create(['name' => 'styled']);
    if ($map) {
      $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    }
    $entity->save();
    return $entity;
  }

  /**
   * Reads an entity's style map back.
   */
  protected function styleMap(EntityTest $entity): array {
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * The structured reference apply_variant writes.
   *
   * The apply_variant emits the canonical {"variant", "fragment"} object form
   * ("" = the root), the one reference shape the map stores.
   */
  protected static function ref(string $variant, string $fragment = ''): array {
    return ['variant' => $variant, 'fragment' => $fragment];
  }

  /**
   * Collects error codes keyed by path from a result.
   */
  protected function errorCodes(BlueprintResultInterface $result): array {
    $codes = [];
    foreach ($result->getErrors() as $error) {
      $codes[$error->getPath()][] = $error->getCode();
    }
    return $codes;
  }

  /**
   * The update_style creates, merges, and unsets; empty structures clean up.
   */
  public function testUpdateStylePatchSemantics(): void {
    $entity = $this->createStyledEntity();
    $key = "container:{$this->uuid}";

    // Create: condition defaults to "default".
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['padding' => '2rem', 'margin' => '1rem']],
      ['op' => 'update_style', 'key' => $key, 'condition' => 'narrow', 'properties' => ['padding' => '1rem']],
    ]);
    $this->assertTrue($result->isSuccess());
    // The batch aggregates one target per operation.
    $this->assertSame(
      [['uuid' => $entity->uuid(), 'field_name' => DaedalusStylishFieldManager::FIELD_NAME]],
      array_values(array_unique($result->getTargets(), SORT_REGULAR)),
    );
    $this->assertSame([
      $key => [
        'default' => ['padding' => '2rem', 'margin' => '1rem'],
        'narrow' => ['padding' => '1rem'],
      ],
    ], $this->styleMap($entity));

    // Patch: merge a value, null unsets another key's value.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['padding' => '3rem', 'margin' => NULL]],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame(['padding' => '3rem'], $this->styleMap($entity)[$key]['default']);

    // Unsetting the last property removes the condition; removing the last
    // condition removes the entry; an empty map empties the field.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['padding' => NULL]],
      ['op' => 'update_style', 'key' => $key, 'condition' => 'narrow', 'properties' => ['padding' => NULL]],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([], $this->styleMap($entity));
    $this->assertTrue($entity->get(DaedalusStylishFieldManager::FIELD_NAME)->isEmpty());
  }

  /**
   * Compound conditions: any spelled order writes one canonical block.
   *
   * The write-side half of the any-order posture: update_style accepts a
   * compound's parts in any order, and the map stores the canonical
   * spelling (axis-declaration order), so "dark+narrow" and "narrow+dark"
   * patch the same block instead of forking into two keys. Misuse gets the
   * validator's corrective errors through the op's own error mapping.
   */
  public function testUpdateStyleCompoundConditions(): void {
    $entity = $this->createStyledEntity();
    $key = "container:{$this->uuid}";

    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $key, 'condition' => 'dark+narrow', 'properties' => ['padding' => '1rem']],
      [
        'op' => 'update_style',
        'key' => $key,
        'condition' => 'hover+narrow+dark',
        'properties' => ['transition' => 'none'],
      ],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([
      $key => [
        'narrow+dark' => ['padding' => '1rem'],
        'hover+narrow+dark' => ['transition' => 'none'],
      ],
    ], $this->styleMap($entity));

    // Another spelling of the same compound merges into the one block, and
    // a null value unsets from it.
    $result = $this->operations->batchOperations($entity, [
      [
        'op' => 'update_style',
        'key' => $key,
        'condition' => 'narrow+dark',
        'properties' => ['gap' => '2rem', 'padding' => NULL],
      ],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame(['gap' => '2rem'], $this->styleMap($entity)[$key]['narrow+dark']);

    // Two conditions on one axis reject with the validator's corrective.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $key, 'condition' => 'narrow+medium', 'properties' => ['padding' => '1rem']],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['STYLE_CONDITION_AXIS_CONFLICT'], $this->errorCodes($result)['condition']);
  }

  /**
   * The retired freeform parameter is gone: it neither writes nor counts.
   */
  public function testFreeformParameterRetired(): void {
    $entity = $this->createStyledEntity();
    $key = "container:{$this->uuid}";

    // A freeform-only op changes nothing: without properties it is an
    // empty patch, and the sibling parameter no longer exists.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $key, 'freeform' => 'backdrop-filter: blur(4px);'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['MISSING_PARAMETER'], $this->errorCodes($result)['properties']);
    $this->assertSame([], $this->styleMap($entity));
  }

  /**
   * Style guide violations fail semantically and roll the batch back.
   */
  public function testUpdateStyleValidation(): void {
    $entity = $this->createStyledEntity();
    $key = "container:{$this->uuid}";

    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['margin' => '1rem']],
      // The guide's section declaration caps padding at 4rem.
      ['op' => 'update_style', 'key' => $key, 'properties' => ['padding' => '6rem']],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    // Validator paths are re-rooted onto the operation's parameters.
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $this->errorCodes($result)['properties.padding']);
    // Semantic failure rolls back the whole batch: the first op's write is
    // gone from the live entity.
    $this->assertSame([], $this->styleMap($entity));
  }

  /**
   * Unsetting is always allowed, even a value the guide no longer permits.
   */
  public function testUnsetSkipsValidation(): void {
    $key = "container:{$this->uuid}";
    // Seed a stored map that a now-tightened strict guide would reject.
    $entity = $this->createStyledEntity([
      $key => [
        'default' => ['font-size' => '1.5rem'],
      ],
    ]);
    $this->enforce('strict');

    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['font-size' => NULL]],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame([], $this->styleMap($entity));
  }

  /**
   * Malformed operations fail structurally with parameter-space paths.
   */
  public function testUpdateStyleStructuralFailures(): void {
    $entity = $this->createStyledEntity();

    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => 'bogus:123', 'properties' => ['padding' => '1rem']],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $this->assertSame(['INVALID_STYLE_KEY'], $this->errorCodes($result)['key']);

    // An op that changes nothing is a mistake worth reporting.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => "container:{$this->uuid}"],
    ]);
    $this->assertSame(['MISSING_PARAMETER'], $this->errorCodes($result)['properties']);

    $result = $this->operations->batchOperations($entity, [
      [
        'op' => 'update_style',
        'key' => "container:{$this->uuid}",
        'condition' => 7,
        'properties' => ['padding' => '1rem'],
      ],
    ]);
    $this->assertSame(['INVALID_STYLE_CONDITION'], $this->errorCodes($result)['condition']);
  }

  /**
   * Styling must be enabled for the target's bundle.
   */
  public function testStylingNotEnabled(): void {
    $this->installEntitySchema('entity_test_rev');
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test_rev')
      ->create(['name' => 'unstyled']);
    $entity->save();

    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => "container:{$this->uuid}", 'properties' => ['padding' => '1rem']],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame(['STYLING_NOT_ENABLED'], $this->errorCodes($result)['key']);
  }

  /**
   * The apply_variant layers the reference over the element's own styles.
   *
   * The layered entry model: an entry's own properties are overrides that
   * coexist with (and beat) its variant references, so applying a variant
   * keeps them — and editing later keeps the reference.
   */
  public function testVariantReferenceLifecycle(): void {
    DaedalusStylishVariant::create(['label' => 'CTA bold', 'machine_name' => 'cta_bold', 'scope' => 'kind:block'])->save();
    $key = "block:{$this->uuid}";
    $entity = $this->createStyledEntity([
      $key => ['default' => ['padding' => '2rem']],
    ]);

    // Applying a variant adds the reference; the element's own properties
    // stay as overrides.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'cta_bold'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([$key => ['default' => ['padding' => '2rem'], 'variants' => [self::ref('cta_bold')]]], $this->styleMap($entity));

    // Target-side validation: the variant must exist and its scope
    // must match the element; failures are semantic, so the batch rolls
    // back and the entry stays as it was.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'no_such_variant'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['STYLE_VARIANT_NOT_FOUND'], $this->errorCodes($result)['variant']);
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => "container:{$this->uuid}", 'variant' => 'cta_bold'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['STYLE_VARIANT_SCOPE_MISMATCH'], $this->errorCodes($result)['variant']);
    $this->assertSame([$key => ['default' => ['padding' => '2rem'], 'variants' => [self::ref('cta_bold')]]], $this->styleMap($entity));

    // A property write lands as an override ON TOP of the reference — no
    // detach, the element keeps following the variant.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['margin' => '1rem']],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([
      $key => ['default' => ['padding' => '2rem', 'margin' => '1rem'], 'variants' => [self::ref('cta_bold')]],
    ], $this->styleMap($entity));

    // Re-applying the referenced variant is a no-op replace within its
    // axis.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'cta_bold'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([self::ref('cta_bold')], $this->styleMap($entity)[$key]['variants']);

    // The variant parameter is required, on every variant operation.
    foreach (['apply_variant', 'remove_variant', 'detach_variant'] as $op) {
      $result = $this->operations->batchOperations($entity, [
        ['op' => $op, 'key' => $key],
      ]);
      $this->assertFalse($result->isSuccess());
      $this->assertSame(['MISSING_PARAMETER'], $this->errorCodes($result)['variant']);
    }
  }

  /**
   * The update_style never touches an entry's variant references.
   */
  public function testUpdateStyleKeepsReferences(): void {
    $variant = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'kind:block']);
    $variant->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      '::.quote' => ['default' => ['margin' => '0']],
    ]);
    $variant->save();
    $key = "block:{$this->uuid}";

    // A write to the referencing element: the reference stays and nothing
    // materializes — the override coexists with the variant.
    $entity = $this->createStyledEntity([$key => ['variants' => [['variant' => 'card', 'fragment' => '']]]]);
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['padding' => '3rem']],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([
      $key => ['variants' => [['variant' => 'card', 'fragment' => '']], 'default' => ['padding' => '3rem']],
    ], $this->styleMap($entity));

    // A write to a SUB-ELEMENT key leaves the parent's reference alone too:
    // the sub-element entry overrides the variant's part styles, entry by
    // entry.
    $entity2 = $this->createStyledEntity([$key => ['variants' => [['variant' => 'card', 'fragment' => '']]]]);
    $result = $this->operations->batchOperations($entity2, [
      ['op' => 'update_style', 'key' => "$key::.quote", 'properties' => ['margin' => '4px']],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([
      $key => ['variants' => [['variant' => 'card', 'fragment' => '']]],
      "$key::.quote" => ['default' => ['margin' => '4px']],
    ], $this->styleMap($entity2));
  }

  /**
   * The apply_variant replaces within an axis and composes across axes.
   *
   * The testimonial block type declares the "emphasis" axis beside the
   * built-in "style" axis (daedalus_stylish_guide_test), so a style-axis and an
   * emphasis-axis variant compose on one entry, and applying another
   * variant of an occupied axis swaps that axis's reference in place.
   */
  public function testApplyVariantWithinAxisReplace(): void {
    DaedalusStylishVariant::create(['label' => 'Base', 'machine_name' => 'base', 'scope' => 'kind:block'])->save();
    DaedalusStylishVariant::create(['label' => 'Sleek', 'machine_name' => 'sleek', 'scope' => 'kind:block'])->save();
    DaedalusStylishVariant::create([
      'label' => 'Loud',
      'machine_name' => 'loud',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
    ])->save();
    DaedalusStylishVariant::create([
      'label' => 'Quiet',
      'machine_name' => 'quiet',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
    ])->save();
    $key = "block:{$this->uuid}";
    $entity = $this->createStyledEntity([
      $key => ['default' => ['padding' => '2rem']],
    ]);

    // Different axes compose, in application order.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'base'],
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'loud'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([self::ref('base'), self::ref('loud')], $this->styleMap($entity)[$key]['variants']);

    // Same axis replaces IN PLACE, keeping that axis's precedence slot;
    // the other axis's reference and the overrides survive.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'quiet'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([
      $key => ['default' => ['padding' => '2rem'], 'variants' => [self::ref('base'), self::ref('quiet')]],
    ], $this->styleMap($entity));
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'sleek'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([self::ref('sleek'), self::ref('quiet')], $this->styleMap($entity)[$key]['variants']);
  }

  /**
   * The detach_variant materializes one reference and drops it; others stay.
   */
  public function testDetachVariantOp(): void {
    $variant = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'kind:block']);
    $variant->setStyles([
      '' => ['default' => ['padding' => '2rem', 'margin' => '1rem'], 'hover' => ['margin' => '2rem']],
      '::.quote' => ['default' => ['margin' => '0']],
    ]);
    $variant->save();
    DaedalusStylishVariant::create([
      'label' => 'Loud',
      'machine_name' => 'loud',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
    ])->save();
    $key = "block:{$this->uuid}";

    // Existing overrides win the merge (they already beat the variant);
    // everything the variant defined beyond them materializes, the
    // detached reference drops, and references on other axes stay.
    $entity = $this->createStyledEntity([
      $key => [
        'variants' => [['variant' => 'card', 'fragment' => ''], ['variant' => 'loud', 'fragment' => '']],
        'default' => ['padding' => '3rem'],
      ],
      "$key::.quote" => ['default' => ['color' => '#333']],
    ]);
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'detach_variant', 'key' => $key, 'variant' => 'card'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      $key => [
        'variants' => [['variant' => 'loud', 'fragment' => '']],
        'default' => ['padding' => '3rem', 'margin' => '1rem'],
        'hover' => ['margin' => '2rem'],
      ],
      "$key::.quote" => ['default' => ['color' => '#333', 'margin' => '0']],
    ], $this->styleMap($entity));

    // Detaching a reference the entry does not carry is a semantic error
    // naming what it does carry.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'detach_variant', 'key' => $key, 'variant' => 'card'],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('STYLE_VARIANT_NOT_REFERENCED', $error->getCode());
    $this->assertSame(['loud'], $error->getContext()['referenced_variants']);

    // A stale reference (variant deleted) has nothing to materialize: the
    // reference simply drops — and an entry left empty disappears.
    $entity2 = $this->createStyledEntity([$key => ['variants' => [['variant' => 'ghost', 'fragment' => '']]]]);
    $result = $this->operations->batchOperations($entity2, [
      ['op' => 'detach_variant', 'key' => $key, 'variant' => 'ghost'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([], $this->styleMap($entity2));
  }

  /**
   * Detach materializes only the properties the reference delivered.
   *
   * Delivery emits co-occurring variants in axis-declaration order (the
   * built-in "style" axis first, then the scope's declared axes), later
   * beating earlier — NOT stored list order. A property that a
   * later-delivered reference also defines was shadowed on the page, so
   * materializing it as an override would flip the rendered winner and
   * change the look detach promises to keep: it stays with the reference
   * that won it. Detaching the delivery winner itself materializes in
   * full.
   */
  public function testDetachMaterializesDeliveryWinnersOnly(): void {
    $card = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'kind:block']);
    $card->setStyles([
      '' => ['default' => ['padding' => '2rem', 'margin' => '1rem'], 'hover' => ['color' => 'red']],
      '::.quote' => ['default' => ['margin' => '0', 'color' => '#111']],
    ]);
    $card->save();
    $loud = DaedalusStylishVariant::create([
      'label' => 'Loud',
      'machine_name' => 'loud',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
    ]);
    $loud->setStyles([
      '' => ['default' => ['margin' => '9rem']],
      '::.quote' => ['default' => ['color' => '#f00']],
    ]);
    $loud->save();
    $key = "block:{$this->uuid}";

    // 'card' rides the style axis, 'loud' the emphasis axis: 'loud'
    // delivered margin and the quote color, so detaching 'card'
    // materializes neither — they stay with 'loud'.
    $entity = $this->createStyledEntity([
      $key => ['variants' => [['variant' => 'card', 'fragment' => ''], ['variant' => 'loud', 'fragment' => '']]],
    ]);
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'detach_variant', 'key' => $key, 'variant' => 'card'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      $key => [
        'variants' => [['variant' => 'loud', 'fragment' => '']],
        'default' => ['padding' => '2rem'],
        'hover' => ['color' => 'red'],
      ],
      "$key::.quote" => ['default' => ['margin' => '0']],
    ], $this->styleMap($entity));

    // Detaching 'loud' — the delivery winner — materializes in full.
    $entity2 = $this->createStyledEntity([
      $key => ['variants' => [['variant' => 'card', 'fragment' => ''], ['variant' => 'loud', 'fragment' => '']]],
    ]);
    $result = $this->operations->batchOperations($entity2, [
      ['op' => 'detach_variant', 'key' => $key, 'variant' => 'loud'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      $key => ['variants' => [['variant' => 'card', 'fragment' => '']], 'default' => ['margin' => '9rem']],
      "$key::.quote" => ['default' => ['color' => '#f00']],
    ], $this->styleMap($entity2));
  }

  /**
   * Applying a variant keeps the element's custom sub-element entries.
   *
   * The reference is one axis's treatment, not the element's entire
   * styling: overrides and sub-element entries layer over it and survive
   * the apply (the layered entry model), as do other elements' entries.
   */
  public function testApplyVariantKeepsSubElementEntries(): void {
    DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'kind:block'])->save();
    $key = "block:{$this->uuid}";
    $other = 'container:' . $this->container->get('uuid')->generate();
    $entity = $this->createStyledEntity([
      $key => ['default' => ['padding' => '2rem']],
      "$key::.quote" => ['default' => ['margin' => '0']],
      $other => ['default' => ['padding' => '1rem']],
    ]);

    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'card'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([
      $key => ['default' => ['padding' => '2rem'], 'variants' => [self::ref('card')]],
      "$key::.quote" => ['default' => ['margin' => '0']],
      $other => ['default' => ['padding' => '1rem']],
    ], $this->styleMap($entity));
  }

  /**
   * All four ops surface in the valid-operations list as router lines.
   *
   * The ops' mechanics live on the cached Page language section (the
   * style op, class wear, set's detach list); the JSON-door guidance
   * carries only router lines naming the taught form (see
   * OperationHandlerInterface::getGuidance()). This pins the tier split:
   * every daedalus_stylish op's guidance stays a short router line, so doctrine
   * creeping back into the always-on channel fails loudly here.
   */
  public function testRegistryExposure(): void {
    $entity = $this->createStyledEntity();

    $result = $this->operations->batchOperations($entity, [
      ['op' => 'no_such_op'],
    ]);
    $valid = $result->getErrors()[0]->getContext()['valid_operations'];
    $this->assertContains('update_style', $valid);
    $this->assertContains('apply_variant', $valid);
    $this->assertContains('remove_variant', $valid);
    $this->assertContains('detach_variant', $valid);

    $guidance = $this->container->get('daedalus_blueprint.operation_handler_registry')->getGuidance();
    foreach (['update_style', 'apply_variant', 'remove_variant', 'detach_variant'] as $op) {
      $this->assertArrayHasKey($op, $guidance);
      $line = $guidance[$op];
      // A router line routes to the taught form instead of carrying
      // doctrine: short, and it names no skill (the styling skill is
      // gone; the page language's grammar is cached).
      $this->assertStringContainsString('the taught form', $line, "$op routes to the page language's form.");
      $this->assertStringNotContainsString('skill', $line, "$op names no skill.");
      $this->assertLessThan(220, strlen($line), "$op guidance is a router line, not doctrine ($op is " . strlen($line) . ' chars).');
      // Doctrine sentinels stay out of the always-on channel: phrases and
      // vocabulary only the retired long-form guidance used, including the
      // stencil-ownership doctrine (now the cached stencil sentence's).
      $this->assertStringNotContainsString('anatomy', $line);
      $this->assertStringNotContainsString('style_guide.strict', $line);
      $this->assertStringNotContainsString('slot path', $line);
      $this->assertStringNotContainsString('default_variants', $line);
      $this->assertStringNotContainsString('stencil', $line);
    }
    // The load-bearing gesture distinctions survive the diet: undress (the
    // look changes) vs unlink (the look stays).
    $this->assertStringContainsString('WITHOUT materializing', $guidance['remove_variant']);
    $this->assertStringContainsString('keeping the look', $guidance['detach_variant']);
  }

  /**
   * Surgical writes resolve block keys against the host entity.
   *
   * The update_style passes the entity into the validator itself, and the
   * whole-field paths (updateFields) get it via the operation options — so
   * a block:{uuid} key validates against its per-block-type declaration
   * (testimonial: font-size max 2rem) instead of the kind fallback.
   */
  public function testBlockKeysValidatePerBlockType(): void {
    $this->enableModules(['layout_builder', 'layout_discovery', 'block', 'block_content']);
    $this->installEntitySchema('block_content');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->container->get('entity_type.manager')
      ->getStorage('block_content_type')
      ->create(['id' => 'testimonial', 'label' => 'Testimonial', 'revision' => TRUE])
      ->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();

    $component_uuid = $this->container->get('uuid')->generate();
    $block = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->create(['type' => 'testimonial', 'info' => 'A quote']);
    $section = new Section('layout_onecol');
    $section->appendComponent(new SectionComponent($component_uuid, 'content', [
      'id' => 'inline_block:testimonial',
      'provider' => 'layout_builder',
      'label' => 'A quote',
      'label_display' => 'visible',
      'view_mode' => 'full',
      'block_serialized' => serialize($block),
    ]));
    $entity = EntityTest::create(['name' => 'host', 'layout_builder__layout' => [$section]]);
    $entity->save();

    // enableModules() rebuilt the container; the operations service from
    // setUp() predates layout_builder and cannot locate LB components.
    $this->operations = $this->container->get('daedalus_blueprint.operations');

    // Through update_style.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => "block:$component_uuid", 'properties' => ['font-size' => '3rem']],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $this->errorCodes($result)['properties.font-size']);

    // Through updateFields — the surgical whole-field path now carries the
    // entity into the field handler's validation context.
    $result = $this->operations->updateFields($entity, [
      DaedalusStylishFieldManager::FIELD_NAME => ["block:$component_uuid" => ['default' => ['font-size' => '3rem']]],
    ]);
    $this->assertFalse($result->isSuccess());
    $codes = $this->errorCodes($result);
    $this->assertSame(
      ['STYLE_VALUE_ABOVE_MAX'],
      $codes['fields.' . DaedalusStylishFieldManager::FIELD_NAME . ".block:$component_uuid.default.font-size"],
    );
  }


  /**
   * The three variant ops at a rule key.
   *
   * Apply places ONE reference on the rule's entry (a rule distributes
   * nothing); a variant of the wrong scope for the rule's landing is
   * refused; detach materializes the root styles at the rule and every
   * part entry continued under the rule by descent (never "::" twice),
   * leaving a map the validator accepts; remove drops the reference; and
   * a part the rule's grammar refuses (a class under a tag rule) is not
   * materialized, because it never delivered.
   */
  public function testVariantOpsAtARule(): void {
    $variant = DaedalusStylishVariant::create(['label' => 'Quote card', 'machine_name' => 'quote_card', 'scope' => 'block_type:testimonial']);
    $variant->setStyles([
      '' => ['default' => ['padding' => '2rem'], 'hover' => ['color' => 'purple']],
      '::.testimonial__quote' => ['default' => ['font-weight' => '700']],
    ]);
    $variant->save();
    DaedalusStylishVariant::create(['label' => 'Band', 'machine_name' => 'band', 'scope' => 'kind:container'])->save();
    $link = DaedalusStylishVariant::create(['label' => 'Link', 'machine_name' => 'link_style', 'scope' => 'base:a']);
    $link->setStyles([
      '' => ['default' => ['color' => 'red']],
      '::.icon' => ['default' => ['margin' => '0']],
    ]);
    $link->save();
    $rule = "container:{$this->uuid}::> pb-testimonial";
    $entity = $this->createStyledEntity([
      "container:{$this->uuid}" => ['default' => ['gap' => '1rem']],
    ]);

    // Apply: one reference at the rule, nothing else in the map moves.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $rule, 'variant' => 'quote_card'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([
      "container:{$this->uuid}" => ['default' => ['gap' => '1rem']],
      $rule => ['variants' => [self::ref('quote_card')]],
    ], $this->styleMap($entity));

    // The landing decides the scope: a container variant is refused at
    // the testimonial rule.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $rule, 'variant' => 'band'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['STYLE_VARIANT_SCOPE_MISMATCH'], $this->errorCodes($result)['variant']);

    // Detach: the root styles at the rule, the part under the rule by
    // descent, and the map stays canonical.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'detach_variant', 'key' => $rule, 'variant' => 'quote_card'],
    ]);
    $this->assertTrue($result->isSuccess());
    $map = $this->styleMap($entity);
    $this->assertSame([
      "container:{$this->uuid}" => ['default' => ['gap' => '1rem']],
      $rule => ['default' => ['padding' => '2rem'], 'hover' => ['color' => 'purple']],
      "$rule .testimonial__quote" => ['default' => ['font-weight' => '700']],
    ], $map);
    $this->assertSame([], $this->container->get('daedalus_stylish.style_map_validator')->validate($map, ['entity' => $entity]));
    foreach (array_keys($map) as $key) {
      $this->assertLessThanOrEqual(1, substr_count($key, '::'), "$key carries :: at most once.");
    }

    // Remove at the rule drops the reference and leaves the entry's own
    // styles; naming a variant the rule does not carry is refused.
    $this->assertTrue($this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $rule, 'variant' => 'quote_card'],
    ])->isSuccess());
    $this->assertSame([self::ref('quote_card')], $this->styleMap($entity)[$rule]['variants']);
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'remove_variant', 'key' => $rule, 'variant' => 'quote_card'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame(['default' => ['padding' => '2rem'], 'hover' => ['color' => 'purple']], $this->styleMap($entity)[$rule]);
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'remove_variant', 'key' => $rule, 'variant' => 'quote_card'],
    ]);
    $this->assertSame('STYLE_VARIANT_NOT_REFERENCED', $result->getErrors()[0]->getCode());

    // A part the rule's grammar refuses (a class under a tag rule) never
    // delivered, so detach materializes only what reached the page.
    $tag_rule = "container:{$this->uuid}::a";
    $entity2 = $this->createStyledEntity([$tag_rule => ['variants' => [self::ref('link_style')]]]);
    $result = $this->operations->batchOperations($entity2, [
      ['op' => 'detach_variant', 'key' => $tag_rule, 'variant' => 'link_style'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([$tag_rule => ['default' => ['color' => 'red']]], $this->styleMap($entity2));
  }

  /**
   * A generated box is born with its existence switch at the op spine.
   *
   * A pseudo-element exists only while the cascade sets its content, so
   * the first update_style that CREATES a pseudo entry ("::&::before" on
   * the element, "::> pb-testimonial::before" under a rule) seeds
   * content: "" at default in the same op, whatever condition the write
   * lands on; a write that sets content at default itself is the
   * author's word and is not overridden. Only birth seeds: an existing
   * entry is never re-seeded (deleting the content line is a deliberate
   * act, the box honestly disappears), and an unset-only patch at a key
   * that never existed mints nothing. Variants are authored, not born:
   * wearing a variant whose "::&::before" fragment carries no content
   * mints no box entry on the wearer, no reference is accepted at a box
   * key (it lands on no element scope), and detach materializes the
   * fragment verbatim, without a seed, so the look the wear delivered is
   * exactly the look detach keeps.
   */
  public function testPseudoElementBirthSeed(): void {
    $key = "container:{$this->uuid}";
    $box = "$key::&::before";
    $rule_box = "$key::> pb-testimonial::before";
    $entity = $this->createStyledEntity();

    // Born with content: the seed lands at default beneath the write, and
    // beneath a write on another condition alike.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $box, 'properties' => ['padding' => '1rem']],
      ['op' => 'update_style', 'key' => $rule_box, 'condition' => 'hover', 'properties' => ['padding' => '2rem']],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([
      $box => ['default' => ['content' => '""', 'padding' => '1rem']],
      $rule_box => ['default' => ['content' => '""'], 'hover' => ['padding' => '2rem']],
    ], $this->styleMap($entity));

    // The author's own content at default is the word: no seed over it.
    $after = "$key::&::after";
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $after, 'properties' => ['content' => 'none', 'padding' => '1rem']],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame(['default' => ['content' => 'none', 'padding' => '1rem']], $this->styleMap($entity)[$after]);

    // Never re-seeded: unsetting the content line is deliberate, and a
    // later write on the existing entry does not bring it back.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $box, 'properties' => ['content' => NULL]],
      ['op' => 'update_style', 'key' => $box, 'properties' => ['margin' => '1rem']],
      ['op' => 'update_style', 'key' => $rule_box, 'condition' => 'hover', 'properties' => ['margin' => '1rem']],
    ]);
    $this->assertTrue($result->isSuccess());
    $map = $this->styleMap($entity);
    $this->assertSame(['default' => ['padding' => '1rem', 'margin' => '1rem']], $map[$box]);
    $this->assertSame(['default' => ['content' => '""'], 'hover' => ['padding' => '2rem', 'margin' => '1rem']], $map[$rule_box]);

    // An unset-only patch at a box that never existed mints nothing.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => "$key::.band-content::before", 'properties' => ['padding' => NULL]],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame($map, $this->styleMap($entity));

    // A variant fragment is not touched: the wear mints no box entry, a
    // reference at a box key is refused, and detach materializes the
    // fragment as authored (no content, so no box, exactly as delivered).
    $variant = DaedalusStylishVariant::create(['label' => 'Band', 'machine_name' => 'band', 'scope' => 'kind:container']);
    $variant->setStyles([
      '' => ['default' => ['padding' => '3rem']],
      '::&::before' => ['default' => ['padding' => '4rem']],
    ]);
    $variant->save();
    $wearer = $this->createStyledEntity();
    $result = $this->operations->batchOperations($wearer, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'band'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$key => ['variants' => [self::ref('band')]]], $this->styleMap($wearer));
    $result = $this->operations->batchOperations($wearer, [
      ['op' => 'apply_variant', 'key' => $box, 'variant' => 'band'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['STYLE_VARIANT_SCOPE_MISMATCH'], $this->errorCodes($result)['variant']);
    $result = $this->operations->batchOperations($wearer, [
      ['op' => 'detach_variant', 'key' => $key, 'variant' => 'band'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([
      $key => ['default' => ['padding' => '3rem']],
      $box => ['default' => ['padding' => '4rem']],
    ], $this->styleMap($wearer));
  }

  /**
   * A first write of a content image at a new box stands unseeded.
   *
   * The birth seed merges BENEATH the validated write, so `content:
   * url('media:…')` as the box's first line is the box's content: no `""`
   * beneath it, nothing over it. The token rides the same gates as
   * background-image (a token naming no media is the media corrective at
   * the op's properties path, and the write mints nothing). The media
   * modules are enabled here, not on the class, so the other operations
   * run on the site they always did.
   */
  public function testPseudoElementContentMedia(): void {
    $this->enableModules(['file', 'image', 'media']);
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'image', 'file', 'media']);
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $image_type = $this->createMediaType('image');
    $source_field = $image_type->getSource()->getSourceFieldDefinition($image_type)->getName();
    $this->container->get('file_system')->copy($this->root . '/core/misc/druplicon.png', 'public://rosette.png');
    $file = File::create(['uri' => 'public://rosette.png', 'filename' => 'rosette.png']);
    $file->save();
    $media = Media::create(['bundle' => $image_type->id(), 'name' => 'Rosette', $source_field => ['target_id' => $file->id()]]);
    $media->save();
    $token = MediaToken::build($media->uuid());

    $key = "container:{$this->uuid}";
    $box = "$key::&::before";
    $rule_box = "$key::> pb-testimonial::after";
    $entity = $this->createStyledEntity();

    // The first write is the content: the seed never lands beneath a
    // write that carries content, at the anchor and at a rule's box alike.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $box, 'properties' => ['content' => $token]],
      ['op' => 'update_style', 'key' => $rule_box, 'properties' => ['content' => $token, 'padding' => '1rem']],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([
      $box => ['default' => ['content' => $token]],
      $rule_box => ['default' => ['content' => $token, 'padding' => '1rem']],
    ], $this->styleMap($entity));

    // A token naming no media is refused by the media gates at the op's
    // path, and a refused first write mints no box.
    $missing = "$key::&::after";
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'update_style', 'key' => $missing, 'properties' => ['content' => MediaToken::build('123e4567-e89b-12d3-a456-426614174000')]],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['MEDIA_NOT_FOUND'], $this->errorCodes($result)['properties.content']);
    $this->assertArrayNotHasKey($missing, $this->styleMap($entity));
  }

}
