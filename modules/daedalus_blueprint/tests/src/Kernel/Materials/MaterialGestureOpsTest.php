<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Materials;

use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The material gesture family on the op spine: one op per verb, by kind.
 *
 * Pins the spine's half of save_material / update_material /
 * remove_material against the recording test provider: the three ops are
 * page-language ops, `kind` routes to the provider that teaches the kind's
 * gesture (a kind without one refuses, naming the kinds that have one),
 * update and remove need an `id`, `element` resolves a page-language alias
 * to a uuid before the provider sees it, the writer's origin rides the
 * request options (a person by default, the AI tools' option otherwise),
 * a provider's refusal becomes the batch's semantic failure, and capture
 * warnings ride the batch's warning channel.
 */
#[CoversClass(MaterialGestureOperationHandler::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class MaterialGestureOpsTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_blueprint_materials_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TestMaterialsProvider::$records = NULL;
    TestMaterialsProvider::$gestures = [];
  }

  /**
   * Runs one op through the real batch door.
   */
  protected function batch(object $entity, array $operation, array $options = []): BlueprintResult {
    return $this->container->get('daedalus_blueprint.operations')->batchOperations($entity, [$operation], $options);
  }

  /**
   * Collects error codes keyed by path from a failed batch result.
   */
  protected function errorCodes(BlueprintResult $result): array {
    $this->assertFalse($result->isSuccess(), 'Expected the batch to fail.');
    $codes = [];
    foreach ($result->getErrors() as $error) {
      $codes[$error->getPath()][] = $error->getCode();
    }
    return $codes;
  }

  /**
   * The three verbs are page-language ops, registered once on the spine.
   */
  public function testRegistration(): void {
    $registry = $this->container->get('daedalus_blueprint.operation_handler_registry');
    foreach (MaterialGestureOperationHandler::OPS as $op) {
      $this->assertContains($op, $registry->getPageLanguageOperations(), "$op rides page_edit.");
      $this->assertInstanceOf(MaterialGestureOperationHandler::class, $registry->getHandler($op));
    }
    $guidance = $registry->getGuidance();
    $this->assertStringContainsString('never mint a material unless the user asks, and a mint is published site-wide at once', $guidance['save_material'], 'The router line states the mint gate from MaterialsProse.');
    $this->assertStringContainsString('"kind" (swatch|stencil)', $guidance['save_material'], 'The kinds are the ones the providers teach a gesture for.');
    $this->assertStringContainsString('"kind"', $guidance['update_material']);
    $this->assertStringContainsString('"kind"', $guidance['remove_material']);

    // The builder names the provider that teaches a kind's gesture, and
    // none for a kind nobody teaches.
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $this->assertInstanceOf(TestMaterialsProvider::class, $builder->gestureProvider(MaterialKind::Swatch));
    $this->assertNull($builder->gestureProvider(MaterialKind::Token));
  }

  /**
   * Save_material dispatches by kind, with the lane's origin.
   */
  public function testSaveDispatchesByKind(): void {
    $entity = $this->createTestEntity();

    $result = $this->batch($entity, [
      'op' => 'save_material',
      'kind' => 'swatch',
      'label' => 'Brand red',
      'value' => '#e94560',
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertCount(1, TestMaterialsProvider::$gestures);
    $gesture = TestMaterialsProvider::$gestures[0];
    $this->assertSame('capture', $gesture['verb']);
    $this->assertSame('swatch', $gesture['kind']);
    $this->assertNull($gesture['id']);
    // The provider sees the gesture's keys only: op and kind are the
    // envelope's, and the origin is a request option, a person by default.
    $this->assertSame(['label' => 'Brand red', 'value' => '#e94560'], $gesture['operation']);
    $this->assertSame($entity->uuid(), $gesture['entity']);
    $this->assertSame(MaterialRecord::ORIGIN_USER, $gesture['options'][MaterialGestureOperationHandler::ORIGIN_OPTION]);

    // The AI tools' option stamps ai; the provider receives it verbatim.
    $result = $this->batch($entity, [
      'op' => 'save_material',
      'kind' => 'stencil',
      'element' => $entity->uuid(),
      'label' => 'Hero',
    ], [
      MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI,
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame('stencil', TestMaterialsProvider::$gestures[1]['kind']);
    $this->assertSame(MaterialRecord::ORIGIN_AI, TestMaterialsProvider::$gestures[1]['options'][MaterialGestureOperationHandler::ORIGIN_OPTION]);

    // A provider's refusal is the batch's semantic failure, errors verbatim.
    $codes = $this->errorCodes($this->batch($entity, ['op' => 'save_material', 'kind' => 'swatch', 'label' => '']));
    $this->assertSame(['MISSING_PARAMETER'], $codes['label']);
  }

  /**
   * The envelope refuses a bad kind and a missing id.
   *
   * A missing, unknown or gesture-less kind each fail.
   */
  public function testEnvelopeRefusals(): void {
    $entity = $this->createTestEntity();

    $codes = $this->errorCodes($this->batch($entity, ['op' => 'save_material', 'label' => 'X']));
    $this->assertSame(['MISSING_PARAMETER'], $codes['kind']);

    $codes = $this->errorCodes($this->batch($entity, ['op' => 'save_material', 'kind' => 'sticker', 'label' => 'X']));
    $this->assertSame(['MISSING_PARAMETER'], $codes['kind']);

    // The test provider teaches swatch and stencil gestures only: a
    // variant (a rostered kind) has no gesture here, and the refusal names
    // the kinds that do.
    $result = $this->batch($entity, ['op' => 'save_material', 'kind' => 'variant', 'label' => 'X']);
    $this->assertSame(['kind' => ['NO_GESTURE']], $this->errorCodes($result));
    $this->assertSame(['swatch', 'stencil'], $result->getErrors()[0]->getContext()['kinds']);
    $this->assertSame([], TestMaterialsProvider::$gestures, 'Nothing reached the provider.');

    foreach (['update_material', 'remove_material'] as $op) {
      $codes = $this->errorCodes($this->batch($entity, ['op' => $op, 'kind' => 'swatch']));
      $this->assertSame(['MISSING_PARAMETER'], $codes['id'], "$op needs an id.");
    }

    // A malformed origin option is the caller's bug, never a stamp.
    $this->expectException(\InvalidArgumentException::class);
    $this->batch($entity, ['op' => 'save_material', 'kind' => 'swatch', 'label' => 'X'], [MaterialGestureOperationHandler::ORIGIN_OPTION => 'robot']);
  }

  /**
   * Update_material and remove_material hand the id and the keys through.
   */
  public function testUpdateAndRemoveDispatch(): void {
    $entity = $this->createTestEntity();

    $result = $this->batch($entity, [
      'op' => 'update_material',
      'kind' => 'swatch',
      'id' => 'lime_green',
      'label' => 'Lime',
      'value' => '#4dff8d',
    ]);
    $this->assertTrue($result->isSuccess());
    $gesture = TestMaterialsProvider::$gestures[0];
    $this->assertSame(['update', 'swatch', 'lime_green'], [$gesture['verb'], $gesture['kind'], $gesture['id']]);
    $this->assertSame(['label' => 'Lime', 'value' => '#4dff8d'], $gesture['operation']);

    $result = $this->batch($entity, ['op' => 'remove_material', 'kind' => 'stencil', 'id' => 'hero']);
    $this->assertTrue($result->isSuccess());
    $gesture = TestMaterialsProvider::$gestures[1];
    $this->assertSame(['remove', 'stencil', 'hero'], [$gesture['verb'], $gesture['kind'], $gesture['id']]);
    // The op spine never drives a Batch API job: the provider is told so.
    $this->assertArrayNotHasKey(MaterialGestureOperationHandler::BATCH_OPTION, $gesture['options']);
  }

  /**
   * The element key resolves a page-language alias before the provider sees it.
   */
  public function testElementResolvesAliases(): void {
    $entity = $this->createTestEntity();
    $target = $this->container->get('uuid')->generate();
    $this->container->get('daedalus_blueprint.dialect_alias_map')->record($entity, 'conv-1', 'hero', $target);

    $result = $this->batch($entity, [
      'op' => 'save_material',
      'kind' => 'stencil',
      'element' => 'hero',
      'label' => 'Hero',
    ], [
      'conversation' => 'conv-1',
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame($target, TestMaterialsProvider::$gestures[0]['operation']['element']);

    // A uuid passes through untouched; an alias nobody minted refuses; an
    // empty element refuses before any lookup.
    $result = $this->batch($entity, [
      'op' => 'update_material',
      'kind' => 'stencil',
      'id' => 'hero',
      'element' => $target,
    ], [
      'conversation' => 'conv-1',
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame($target, TestMaterialsProvider::$gestures[1]['operation']['element']);
    $codes = $this->errorCodes($this->batch($entity, [
      'op' => 'save_material',
      'kind' => 'stencil',
      'element' => 'ghost',
      'label' => 'Ghost',
    ], [
      'conversation' => 'conv-1',
    ]));
    $this->assertSame(['UNKNOWN_REF'], $codes['element']);
    $codes = $this->errorCodes($this->batch($entity, [
      'op' => 'save_material',
      'kind' => 'stencil',
      'element' => '',
      'label' => 'Ghost',
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['element']);
    $this->assertCount(2, TestMaterialsProvider::$gestures, 'The refusals never reached the provider.');
  }

  /**
   * Capture warnings ride the batch's warning channel under one code.
   */
  public function testCaptureWarningsRideTheBatch(): void {
    $entity = $this->createTestEntity();
    $result = $this->batch($entity, [
      'op' => 'save_material',
      'kind' => 'stencil',
      'element' => $entity->uuid(),
      'label' => 'Hero',
      'warn' => ['Dropped a style key.'],
    ]);
    $this->assertTrue($result->isSuccess());
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame(MaterialGestureOperationHandler::CAPTURE_WARNING, $warnings[0]->getCode());
    $this->assertSame('Dropped a style key.', $warnings[0]->getMessage());
  }

}
