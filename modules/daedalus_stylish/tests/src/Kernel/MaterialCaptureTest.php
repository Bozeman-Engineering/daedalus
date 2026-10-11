<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_stylish\Controller\AnimationApiController;
use Drupal\daedalus_stylish\Controller\SwatchApiController;
use Drupal\daedalus_stylish\Entity\DaedalusStylishAnimation;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\VariantCapture;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Tests\daedalus_stylish\Traits\HostTrait;

/**
 * The swatch, variant and animation gestures, end to end.
 *
 * The save_material / update_material / remove_material with kind swatch,
 * variant or animation dispatch to DaedalusStylishMaterialsProvider on the real batch
 * door: a swatch minted from a value or captured from the literal an element
 * wears (a reference refuses: it is already a material), a variant captured
 * from an element's whole look (VariantCapture: its own overrides, its parts,
 * its staged children by slot path, the same-axis look it wears folded
 * beneath), an animation minted from its steps outright (a keyframes block
 * lives on no element), pushes and face rewrites through update_material,
 * removal of unreferenced materials and the loud refusal of referenced ones on
 * the op spine, the origin stamp per lane, the inspector's own lane (the
 * client's capture as `key` + `styles`, the master push with neither key nor
 * element), and the lifecycle routes as adapters over the same provider
 * methods.
 */
#[CoversClass(DaedalusStylishMaterialsProvider::class)]
#[CoversClass(VariantCapture::class)]
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class MaterialCaptureTest extends NestedStorageKernelTestBase {

  use HostTrait;
  use DesignSystemActivationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    'daedalus_stylish_layouts',
    // The unbound site: the class writes literal paddings and gaps on a
    // site that mints nothing, and the suite's bindings would refuse every
    // one for a reason that is the scale's, not the claim's.
    'daedalus_stylish_unbound_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installEntitySchema('user');
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * Runs an op batch through the real batch door.
   */
  protected function batch(EntityTest $entity, array $operations, array $options = []): BlueprintResult {
    return $this->container->get('daedalus_blueprint.operations')->batchOperations($this->host($entity), $operations, $options);
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
   * A container hosting two inline blocks and a nested container.
   *
   * @return array{0: \Drupal\entity_test\Entity\EntityTest, 1: array<string, string>}
   *   The host entity and the uuids by role.
   */
  protected function buildComposition(array $map_by_role): array {
    $ids = [
      'sec' => $this->uuid->generate(),
      'b1' => $this->uuid->generate(),
      'b2' => $this->uuid->generate(),
      'nsec' => $this->uuid->generate(),
      'img' => $this->uuid->generate(),
      'plugin' => $this->uuid->generate(),
    ];
    $nested = $this->createSection('daedalus_stylish_container', [$this->createBasicBlockComponent($ids['img'])], $ids['nsec']);
    $section = $this->createSection('daedalus_stylish_container', [
      $this->createBasicBlockComponent($ids['b1'], 'content'),
      new SectionComponent($ids['plugin'], 'content', ['id' => 'system_powered_by_block', 'label_display' => FALSE]),
      $this->createBasicBlockComponent($ids['b2'], 'content'),
      $this->createNestedSectionComponent($nested, 'content'),
    ], $ids['sec']);
    $placeholders = [];
    foreach ($ids as $role => $uuid) {
      $placeholders['{' . $role . '}'] = $uuid;
    }
    $map = [];
    foreach ($map_by_role as $key_template => $entry) {
      $map[strtr($key_template, $placeholders)] = $entry;
    }
    $entity = EntityTest::create([
      'layout_builder__layout' => [$section],
      DaedalusStylishFieldManager::FIELD_NAME => json_encode($map),
    ]);
    $entity->save();
    return [$entity, $ids];
  }

  /**
   * The delta the request's change set carries, drained.
   */
  protected function delta(): array {
    return $this->container->get('daedalus_blueprint.materials_change_set')->flush();
  }

  /**
   * A capture joins the active design system; "global": true opts out.
   *
   * Affiliation is the default and global the explicit exception, on all
   * three mints (swatch, variant, animation), through the one gesture
   * door; the delta record carries the membership. With no system active
   * a mint is global without saying so.
   */
  public function testCaptureJoinsTheActiveDesignSystem(): void {
    [$entity, $ids] = $this->buildComposition([
      'container:{sec}' => ['default' => ['padding' => '2rem', 'background-color' => '#e94560']],
    ]);
    $this->delta();
    $load = static function (string $type, string $name): object {
      $storage = \Drupal::entityTypeManager()->getStorage("daedalus_$type");
      $storage->resetCache();
      $entities = $storage->loadByProperties(['machine_name' => $name]);
      return reset($entities);
    };
    $member_of = static fn (string $type, string $name): ?string => $load($type, $name)->getDesignSystem();
    $steps = ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']];

    // No active system: global, whatever the key says.
    $result = $this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'swatch', 'label' => 'Orphan red', 'value' => '#b91c1c'],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertNull($this->delta()['upsert'][0]['design_system']);
    $this->assertNull($member_of('swatch', 'orphan_red'));

    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora'])->save();
    \Drupal::service(ActiveDesignSystem::SERVICE_ID)->activate('aurora');
    // The activation syncs every enabled module's shipped definitions and
    // touches what it lands (the suite's globals) on the request's delta;
    // flushing it keeps the mints' own delta readable.
    $this->delta();

    // Aurora active: each mint joins it.
    $result = $this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'swatch', 'label' => 'Aurora red', 'value' => '#b91c1c'],
      ['op' => 'save_material', 'kind' => 'variant', 'label' => 'Aurora band', 'element' => $ids['sec']],
      ['op' => 'save_material', 'kind' => 'animation', 'label' => 'Aurora fade', 'steps' => $steps],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame([
      ['swatch', 'aurora_red', 'aurora'],
      ['variant', 'aurora_band', 'aurora'],
      ['animation', 'aurora_fade', 'aurora'],
    ], array_map(static fn (array $r): array => [$r['kind'], $r['id'], $r['design_system']], $this->delta()['upsert']));
    $this->assertSame('aurora', $member_of('swatch', 'aurora_red'));
    $this->assertSame('aurora', $member_of('variant', 'aurora_band'));
    $this->assertSame('aurora', $member_of('animation', 'aurora_fade'));

    // "global": true opts out of the active system.
    $result = $this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'swatch', 'label' => 'Ink', 'value' => '#111111', 'global' => TRUE],
      ['op' => 'save_material', 'kind' => 'variant', 'label' => 'Band', 'element' => $ids['sec'], 'global' => TRUE],
      ['op' => 'save_material', 'kind' => 'animation', 'label' => 'Fade', 'steps' => $steps, 'global' => TRUE],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame([NULL, NULL, NULL], array_column($this->delta()['upsert'], 'design_system'));
    $this->assertNull($member_of('swatch', 'ink'));
    $this->assertNull($member_of('variant', 'band'));
    $this->assertNull($member_of('animation', 'fade'));

    // A copy keeps the original's membership.
    $copy = $this->container->get('daedalus_stylish.variant_manager')->copyAsMyVariant($load('variant', 'aurora_band'));
    $this->assertSame('aurora', $copy->getDesignSystem());

    // The key is a boolean or nothing.
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'swatch', 'label' => 'Nope', 'value' => '#000000', 'global' => 'yes'],
    ]));
    $this->assertSame(['INVALID_PARAMETER'], $codes['global']);
  }

  /**
   * A swatch minted from a value, captured from a selection, then removed.
   */
  public function testSwatchGestures(): void {
    [$entity, $ids] = $this->buildComposition([
      'container:{sec}' => ['default' => ['background-color' => '#e94560', 'color' => 'var(--color-accent)']],
    ]);

    // By value: the swatch kind infers from the value (a color), the
    // origin is the lane's (a person), and the delta carries the record.
    $result = $this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'swatch', 'label' => 'Brand red', 'value' => '#b91c1c'],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $delta = $this->delta();
    $this->assertSame([
      ['swatch', 'brand_red', 'user', 'color'],
    ], array_map(static fn (array $r): array => [$r['kind'], $r['id'], $r['origin'], $r['group']], $delta['upsert']));

    // A gradient value infers the gradient kind; an explicit group wins.
    $result = $this->batch($entity, [
      [
        'op' => 'save_material',
        'kind' => 'swatch',
        'label' => 'Hero fade',
        'value' => 'linear-gradient(90deg, #b91c1c, #fbbf24)',
      ],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame('gradient', $this->delta()['upsert'][0]['group']);

    // From the selection: the literal the element wears for the property.
    $result = $this->batch($entity, [
      [
        'op' => 'save_material',
        'kind' => 'swatch',
        'label' => 'Section red',
        'element' => $ids['sec'],
        'property' => 'background-color',
      ],
    ], [MaterialGestureOperationHandler::ORIGIN_OPTION => 'ai']);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $record = $this->delta()['upsert'][0];
    $this->assertSame(['section_red', '#e94560', 'ai'], [$record['id'], $record['value'], $record['origin']]);
    $this->assertSame('ai', DaedalusStylishSwatch::load(array_key_first(\Drupal::entityTypeManager()->getStorage('daedalus_swatch')->loadByProperties(['machine_name' => 'section_red'])))->getOrigin());

    // A reference is already a material; a property the element does not
    // declare itself has nothing to capture; a missing property refuses.
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'swatch', 'label' => 'Nope', 'element' => $ids['sec'], 'property' => 'color'],
    ]));
    $this->assertSame(['MATERIAL_CAPTURE_FAILED'], $codes['property']);
    $codes = $this->errorCodes($this->batch($entity, [
      [
        'op' => 'save_material',
        'kind' => 'swatch',
        'label' => 'Nope',
        'element' => $ids['sec'],
        'property' => 'padding',
      ],
    ]));
    $this->assertSame(['MATERIAL_CAPTURE_FAILED'], $codes['property']);
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'swatch', 'label' => 'Nope', 'element' => $ids['sec']],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['property']);
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'swatch', 'label' => 'Nope'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['value']);

    // update_material rewrites the value and the label in one revision.
    $result = $this->batch($entity, [
      [
        'op' => 'update_material',
        'kind' => 'swatch',
        'id' => 'brand_red',
        'label' => 'Signal red',
        'value' => '#dc2626',
      ],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $record = $this->delta()['upsert'][0];
    $this->assertSame(['Signal red', '#dc2626', 'user'], [$record['label'], $record['value'], $record['origin']]);
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'update_material', 'kind' => 'swatch', 'id' => 'brand_red'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['']);
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'update_material', 'kind' => 'swatch', 'id' => 'nope', 'label' => 'X'],
    ]));
    $this->assertSame(['STYLE_SWATCH_NOT_FOUND'], $codes['id']);

    // remove_material deletes an unreferenced swatch; a referenced one
    // refuses on the op spine, naming its uses (the palette's delete
    // rewrites them; nothing drives that batch from a page op).
    $result = $this->batch($entity, [
      ['op' => 'remove_material', 'kind' => 'swatch', 'id' => 'hero_fade'],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame(['upsert' => [], 'remove' => [['kind' => 'swatch', 'id' => 'hero_fade']]], $this->delta());

    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode(["container:{$ids['sec']}" => ['default' => ['background-color' => 'var(--swatch-brand_red)']]]));
    $entity->save();
    $result = $this->batch($entity, [
      ['op' => 'remove_material', 'kind' => 'swatch', 'id' => 'brand_red'],
    ]);
    $codes = $this->errorCodes($result);
    $this->assertSame(['STYLE_SWATCH_IN_USE'], $codes['id']);
    $this->assertSame(1, $result->getErrors()[0]->getContext()['usages']);
    $this->assertNotEmpty(\Drupal::entityTypeManager()->getStorage('daedalus_swatch')->loadByProperties(['machine_name' => 'brand_red']), 'The referenced swatch stands.');
  }

  /**
   * An animation minted from its steps, edited, worn, then removed.
   *
   * The names stay off the suite's shipped set (fade_in, pulse, rise,
   * scale_in, spin): the strict activation below syncs every enabled
   * module, and a site animation sharing a shipped name would refuse
   * the shipped one and take its spelling.
   */
  public function testAnimationGestures(): void {
    [$entity, $ids] = $this->buildComposition([
      'container:{sec}' => ['default' => ['padding' => '2rem']],
    ]);

    // By steps: the origin is the lane's, the record rides the delta with
    // its animation-slot spelling and canonical steps.
    $result = $this->batch($entity, [
      [
        'op' => 'save_material',
        'kind' => 'animation',
        'label' => 'Drift',
        'description' => 'A soft entrance.',
        'steps' => ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']],
      ],
    ], [MaterialGestureOperationHandler::ORIGIN_OPTION => 'ai']);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $record = $this->delta()['upsert'][0];
    $this->assertSame([
      'animation',
      'drift',
      'ai',
      NULL,
      'A soft entrance.',
    ], [$record['kind'], $record['id'], $record['origin'], $record['group'], $record['description']]);
    $this->assertSame(['animation' => 'motion-drift'], $record['spellings']);
    $this->assertSame(['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']], $record['value']);
    $this->assertSame('ai', DaedalusStylishAnimation::load(array_key_first(\Drupal::entityTypeManager()->getStorage('daedalus_animation')->loadByProperties(['machine_name' => 'drift'])))->getOrigin());

    // Refusals in the gesture's parameter space: missing steps, the steps
    // seam's own codes.
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'animation', 'label' => 'Nope'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['steps']);
    $codes = $this->errorCodes($this->batch($entity, [
      [
        'op' => 'save_material',
        'kind' => 'animation',
        'label' => 'Nope',
        'steps' => ['0%' => ['animation-duration' => '1s']],
      ],
    ]));
    $this->assertSame(['INVALID_ANIMATION_STEP_PROPERTY'], $codes['steps.0%.animation-duration']);

    // update_material rewrites the steps and the face in one revision.
    $result = $this->batch($entity, [
      [
        'op' => 'update_material',
        'kind' => 'animation',
        'id' => 'drift',
        'label' => 'Soft fade',
        'steps' => ['0%' => ['opacity' => '0.2'], '100%' => ['opacity' => '1']],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $record = $this->delta()['upsert'][0];
    $this->assertSame([
      'Soft fade',
      ['0%' => ['opacity' => '0.2'], '100%' => ['opacity' => '1']],
      'ai',
    ], [$record['label'], $record['value'], $record['origin']]);
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'update_material', 'kind' => 'animation', 'id' => 'drift'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['']);
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'update_material', 'kind' => 'animation', 'id' => 'nope', 'label' => 'X'],
    ]));
    $this->assertSame(['STYLE_ANIMATION_NOT_FOUND'], $codes['id']);

    // Wearing it in the same batch validates against the fresh roster (the
    // mint reset the memo), strict included; then a worn animation refuses
    // removal on the op spine, naming its uses. The activation behind the
    // mode syncs every enabled module and touches what it lands on the
    // request's delta (daedalus_stylish_layouts' width family, withheld under this
    // strict fixture); flushing it keeps the gestures' own delta readable.
    $this->enforce('strict');
    $this->delta();
    $result = $this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'animation', 'label' => 'Glide', 'steps' => ['0%' => ['opacity' => '0']]],
      [
        'op' => 'update_style',
        'key' => "container:{$ids['sec']}",
        'condition' => 'default',
        'properties' => ['animation' => 'motion-glide 600ms ease-out both'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $entity->save();
    $result = $this->batch($entity, [
      ['op' => 'remove_material', 'kind' => 'animation', 'id' => 'glide'],
    ]);
    $codes = $this->errorCodes($result);
    $this->assertSame(['STYLE_ANIMATION_IN_USE'], $codes['id']);
    $this->assertSame(1, $result->getErrors()[0]->getContext()['usages']);

    // An unworn animation goes at once.
    $result = $this->batch($entity, [
      ['op' => 'remove_material', 'kind' => 'animation', 'id' => 'drift'],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame([['kind' => 'animation', 'id' => 'drift']], $this->delta()['remove']);

    // The lifecycle routes are adapters: the same provider behind rename
    // and delete, the same delta, no echo payload.
    $controller = AnimationApiController::create($this->container);
    $response = $controller->rename('glide', Request::create('/daedalus_stylish/animations/glide/rename', 'POST', [], [], [], [], json_encode(['label' => 'Glide up'])));
    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(['materials'], array_keys($body));
    $this->assertSame('Glide up', $body['materials']['upsert'][0]['label']);
    // A worn animation on the route (an administrator here) stages the
    // rewrite-then-delete batch instead of refusing: nothing is deleted in
    // this request (the grades are AnimationManagerTest's).
    $response = $controller->delete('glide');
    $this->assertSame(202, $response->getStatusCode());
    $this->assertSame(1, json_decode((string) $response->getContent(), TRUE)['references']);
    $this->assertNotEmpty(\Drupal::entityTypeManager()->getStorage('daedalus_animation')->loadByProperties(['machine_name' => 'glide']), 'The worn animation stands until the batch runs.');
  }

  /**
   * A variant captured from an element's look, then pushed, refaced, removed.
   *
   * The capture covers overrides, parts, children and the same-axis fold.
   */
  public function testVariantGestures(): void {
    // A worn same-axis look whose values fold beneath the capture, and a
    // child's own worn look that travels as a reference.
    DaedalusStylishVariant::create(['label' => 'Band', 'machine_name' => 'band', 'scope' => 'kind:container'])->setStyles([
      '' => ['default' => ['padding' => '4rem', 'background-color' => 'aliceblue']],
      '::.inner' => ['default' => ['gap' => '2rem']],
    ])->save();
    DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'block_type:basic'])->setStyles([
      '' => ['default' => ['border-radius' => '8px']],
    ])->save();
    [$entity, $ids] = $this->buildComposition([
      'container:{sec}' => ['default' => ['padding' => '2rem'], 'variants' => [['variant' => 'band', 'fragment' => '']]],
      'container:{sec}::.inner' => ['default' => ['margin' => '0']],
      'container:{sec}::.s-abc12' => ['default' => ['color' => 'red']],
      'container:{sec}::p' => ['default' => ['color' => 'blue']],
      'container:{sec}::> *' => ['default' => ['gap' => '2rem']],
      'container:{sec}::> pb-basic .s-zz9' => ['default' => ['color' => 'green']],
      'block:{b1}' => ['default' => ['text-align' => 'center']],
      'block:{b2}' => ['default' => ['padding' => '1rem'], 'variants' => [['variant' => 'card', 'fragment' => '']]],
      'block:{b2}::.quote' => ['default' => ['font-style' => 'italic']],
      'block:{b2}:field_x' => ['default' => ['color' => 'tomato']],
      'container:{nsec}' => ['default' => ['gap' => '1rem']],
      'block:{img}' => ['default' => ['width' => '100%']],
      'block:{plugin}' => ['default' => ['padding' => '9px']],
    ]);
    // The fixtures' own saves touched the change set; the gestures below
    // read their own deltas.
    $this->delta();

    $result = $this->batch($entity, [
      [
        'op' => 'save_material',
        'kind' => 'variant',
        'label' => 'Hero band',
        'element' => $ids['sec'],
        'description' => 'The opener.',
        'weight' => -5,
      ],
    ], [MaterialGestureOperationHandler::ORIGIN_OPTION => 'ai']);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $record = $this->delta()['upsert'][0];
    $this->assertSame([
      'variant',
      'hero_band',
      'The opener.',
      -5,
      'ai',
      'style',
      'layout:daedalus_stylish_container',
    ], [
      $record['kind'],
      $record['id'],
      $record['description'],
      $record['weight'],
      $record['origin'],
      $record['group'],
      $record['scope'],
    ]);
    $this->assertSame([
      // The root: its override on top of the folded band, the band's
      // untouched value beneath.
      '' => ['default' => ['padding' => '2rem', 'background-color' => 'aliceblue']],
      // Its class part, over the band's part; its tag and children
      // selectors travel (structure transfers); instance parts stay
      // behind wherever they stand in a selector.
      '::.inner' => ['default' => ['gap' => '2rem', 'margin' => '0']],
      '::p' => ['default' => ['color' => 'blue']],
      '::> *' => ['default' => ['gap' => '2rem']],
      // The children by slot path (the plugin placement is transparent),
      // shallow first; a child's own worn root reference travels as a
      // reference, its part and field ride the same slot.
      'block(basic,1)' => ['default' => ['text-align' => 'center']],
      'block(basic,2)' => ['variants' => [['variant' => 'card', 'fragment' => '']], 'default' => ['padding' => '1rem']],
      'block(basic,2)::.quote' => ['default' => ['font-style' => 'italic']],
      'container(1)' => ['default' => ['gap' => '1rem']],
      'block(basic,2)/field(field_x)' => ['default' => ['color' => 'tomato']],
      'container(1)/block(basic,1)' => ['default' => ['width' => '100%']],
    ], $record['value']);

    // The inspector's lane (the push bar's save session) hands its own
    // capture through the same op: the selectable key and the styles it
    // already walked for the live preview, no server capture, a person's
    // origin, the record in the delta.
    $result = $this->batch($entity, [
      [
        'op' => 'save_material',
        'kind' => 'variant',
        'label' => 'Routed band',
        'key' => "container:{$ids['sec']}",
        'styles' => ['' => ['default' => ['padding' => '3rem']]],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $record = $this->delta()['upsert'][0];
    $this->assertSame([
      'routed_band',
      'user',
      ['' => ['default' => ['padding' => '3rem']]],
    ], [$record['id'], $record['origin'], $record['value']]);

    // The push bar's update session is the MASTER push: styles with no key
    // and no element replace the variant's styles wholesale, validated
    // against the variant's own scope.
    $result = $this->batch($entity, [
      [
        'op' => 'update_material',
        'kind' => 'variant',
        'id' => 'routed_band',
        'styles' => ['' => ['default' => ['padding' => '4rem', 'gap' => '1rem']]],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame(['' => ['default' => ['padding' => '4rem', 'gap' => '1rem']]], $this->delta()['upsert'][0]['value']);

    // update_material: a push from an element WEARING the variant bakes
    // the tweaks in over the variant's styles; the face rides the same op.
    $result = $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'hero_band'],
      [
        'op' => 'update_style',
        'key' => "container:{$ids['sec']}",
        'condition' => 'default',
        'properties' => ['padding' => '5rem'],
      ],
      [
        'op' => 'update_material',
        'kind' => 'variant',
        'id' => 'hero_band',
        'element' => $ids['sec'],
        'label' => 'Hero opener',
        'weight' => 3,
      ],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $record = $this->delta()['upsert'][0];
    $this->assertSame(['Hero opener', 3, 'ai'], [$record['label'], $record['weight'], $record['origin']]);
    $this->assertSame('5rem', $record['value']['']['default']['padding']);
    $this->assertSame('aliceblue', $record['value']['']['default']['background-color'], 'Properties the push did not touch survive.');
    $this->assertSame(['default' => ['gap' => '1rem']], $record['value']['container(1)']);

    // The refusals: nothing to change, an unknown id, a worn removal.
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'update_material', 'kind' => 'variant', 'id' => 'hero_band'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['']);
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'update_material', 'kind' => 'variant', 'id' => 'nope', 'label' => 'X'],
    ]));
    $this->assertSame(['STYLE_VARIANT_NOT_FOUND'], $codes['id']);
    $entity->save();
    $result = $this->batch($entity, [
      ['op' => 'remove_material', 'kind' => 'variant', 'id' => 'hero_band'],
    ]);
    $codes = $this->errorCodes($result);
    $this->assertSame(['STYLE_VARIANT_IN_USE'], $codes['id']);
    $this->assertSame(1, $result->getErrors()[0]->getContext()['usages']);

    // An unworn variant goes at once.
    $result = $this->batch($entity, [
      ['op' => 'remove_material', 'kind' => 'variant', 'id' => 'routed_band'],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame([['kind' => 'variant', 'id' => 'routed_band']], $this->delta()['remove']);
  }

  /**
   * A rule's references travel with the capture, own and a child's alike.
   *
   * The section's `> pb-basic` rule wears "card" (delivered at every
   * basic child) over its own margin, and the nested section's rule wears
   * it too: both travel as the captured '::selector' entry's reference
   * list, the origin stripped, exactly as a child's root references do;
   * a fragment reference at a rule never travels.
   */
  public function testCaptureKeepsRuleReferences(): void {
    DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'block_type:basic'])->setStyles([
      '' => ['default' => ['border-radius' => '8px']],
    ])->save();
    DaedalusStylishVariant::create(['label' => 'Band', 'machine_name' => 'band', 'scope' => 'kind:container'])->setStyles([
      '' => ['default' => ['padding' => '4rem']],
    ])->save();
    [$entity, $ids] = $this->buildComposition([
      'container:{sec}' => ['default' => ['padding' => '2rem']],
      'container:{sec}::> pb-basic' => [
        'variants' => [['variant' => 'card', 'fragment' => '', 'via' => 'band'], ['variant' => 'band', 'fragment' => 'container(1)']],
        'default' => ['margin' => '0'],
      ],
      'container:{nsec}::> pb-basic' => ['variants' => [['variant' => 'card', 'fragment' => '']]],
    ]);
    $this->delta();

    $result = $this->batch($entity, [
      [
        'op' => 'save_material',
        'kind' => 'variant',
        'label' => 'Framed band',
        'element' => $ids['sec'],
      ],
    ], [MaterialGestureOperationHandler::ORIGIN_OPTION => 'ai']);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame([
      '' => ['default' => ['padding' => '2rem']],
      '::> pb-basic' => ['variants' => [['variant' => 'card', 'fragment' => '']], 'default' => ['margin' => '0']],
      'container(1)::> pb-basic' => ['variants' => [['variant' => 'card', 'fragment' => '']]],
    ], $this->delta()['upsert'][0]['value']);
  }

  /**
   * A distributed wear captures origin-less: the strip is the capture's.
   *
   * THE card scenario: Card Left's apply nested "columns" onto the card
   * wrapper, stamped "via card_left" in the map (the map lane records
   * placement facts). A capture from the section writes the wrapper's
   * root references as {variant, fragment} only: a variant's nested list
   * says what to distribute, never who did, and the variant-styles lane
   * rejects "via", so an unstripped capture would refuse to save. Here
   * the save succeeds and the minted variant nests a plain "columns" on
   * container(1); the composition's own fragment reference on the wrapper
   * folds beneath the wrapper's overrides (the same-axis re-attach
   * replaces it), as ever. The map keeps its origin: the strip is the
   * capture's, not the map's. The client twin (captureSources,
   * variants.test.mjs) strips the same way.
   */
  public function testCaptureStripsProvenance(): void {
    DaedalusStylishVariant::create([
      'label' => 'Columns',
      'machine_name' => 'columns',
      'scope' => 'kind:container',
      'axis' => 'container_layout',
    ])->setStyles([
      '' => ['default' => ['display' => 'flex']],
    ])->save();
    DaedalusStylishVariant::create(['label' => 'Card Left', 'machine_name' => 'card_left', 'scope' => 'kind:container'])->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      'container(1)' => ['variants' => [['variant' => 'columns', 'fragment' => '']], 'default' => ['margin' => '1px']],
    ])->save();
    [$entity, $ids] = $this->buildComposition([
      'container:{nsec}' => ['default' => ['gap' => '1rem']],
    ]);
    $result = $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'card_left'],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $wrapper = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap()["container:{$ids['nsec']}"];
    $this->assertEquals([
      ['variant' => 'columns', 'fragment' => '', 'via' => 'card_left'],
      ['variant' => 'card_left', 'fragment' => 'container(1)'],
    ], $wrapper['variants'], 'The wrapper wears columns via Card Left before the capture.');
    $this->delta();

    $result = $this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'variant', 'label' => 'Card Right', 'element' => $ids['sec']],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $value = $this->delta()['upsert'][0]['value'];
    $this->assertSame([['variant' => 'columns', 'fragment' => '']], $value['container(1)']['variants'], 'The nested columns travels without its origin.');
    $this->assertEquals(['gap' => '1rem', 'margin' => '1px'], $value['container(1)']['default'], 'The wrapper keeps its override over the folded Card Left fragment.');
    $this->assertArrayNotHasKey('block(basic,1)', $value);
    $this->assertEquals([
      ['variant' => 'columns', 'fragment' => '', 'via' => 'card_left'],
      ['variant' => 'card_left', 'fragment' => 'container(1)'],
    ], $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap()["container:{$ids['nsec']}"]['variants'], 'The map keeps its origin: the strip is the capture\'s.');
  }

  /**
   * A withheld wearing is not folded: the look saved is the look delivered.
   *
   * The same-axis fold bakes the variants an element wears beneath its
   * captured overrides, resolved through StyleMapValidator::loadVariant(),
   * which answers NULL for anything off the roster. A variant the style
   * guide withholds (VariantConformance) leaves the roster exactly as an
   * unpublished one does, so under the law that refuses it the capture
   * carries the element's own overrides alone and the mint that follows
   * validates; a fold of the refused values would have failed the save
   * naming values the element never wrote. No preflight and no special
   * case: the fragment fold rides the same resolution. The host keeps
   * wearing the withheld variant meanwhile: an op on another entry, or on
   * the wearing entry's own overrides, validates what it sets and passes,
   * and only a NEW wearing is refused by name on the op spine.
   */
  public function testCaptureSkipsWithheldWearing(): void {
    // A style-axis look with a typography literal the container kind does
    // not declare: legal under guarded law, refused under strict.
    DaedalusStylishVariant::create(['label' => 'Band', 'machine_name' => 'band', 'scope' => 'kind:container'])->setStyles([
      '' => ['default' => ['background-color' => 'aliceblue', 'font-size' => '1.25rem']],
    ])->save();
    [$entity, $ids] = $this->buildComposition([
      'container:{sec}' => [
        'default' => ['padding-top' => '2rem'],
        'variants' => [['variant' => 'band', 'fragment' => '']],
      ],
    ]);
    $this->delta();

    // Guarded: the worn look folds beneath the override.
    $result = $this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'variant', 'label' => 'Guarded band', 'element' => $ids['sec']],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame([
      '' => ['default' => ['background-color' => 'aliceblue', 'font-size' => '1.25rem', 'padding-top' => '2rem']],
    ], $this->delta()['upsert'][0]['value']);

    // Strict: the band is withheld, and the capture carries the element's
    // own override alone. The roster builder's memo is not law-keyed; the
    // reset is the request boundary a real site crosses after the save.
    // The mode's activation (the first in this story) syncs every enabled
    // module and touches the suite's globals on the request's delta;
    // flushing it keeps the capture's own delta readable.
    $this->enforce('strict');
    $this->delta();
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $this->assertArrayHasKey('band', $this->container->get('daedalus_stylish.variant_conformance')->withheld());
    $result = $this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'variant', 'label' => 'Strict band', 'element' => $ids['sec']],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame(['' => ['default' => ['padding-top' => '2rem']]], $this->delta()['upsert'][0]['value']);

    // The host still wears the withheld band: edits elsewhere and on the
    // wearing entry's own overrides pass; a new wearing is refused.
    $result = $this->batch($entity, [
      [
        'op' => 'update_style',
        'key' => "block:{$ids['b1']}",
        'condition' => 'default',
        'properties' => ['opacity' => '0.8'],
      ],
      [
        'op' => 'update_style',
        'key' => "container:{$ids['sec']}",
        'condition' => 'default',
        'properties' => ['padding-top' => '3rem'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame([['variant' => 'band', 'fragment' => '']], $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap()["container:{$ids['sec']}"]['variants']);
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'apply_variant', 'key' => "container:{$ids['nsec']}", 'variant' => 'band'],
    ]));
    $this->assertSame(['STYLE_VARIANT_NONCONFORMING'], $codes['variant']);
  }

  /**
   * The gestures gate on the swatch, variant and animation grades, per verb.
   */
  public function testPermissionGrades(): void {
    [$entity, $ids] = $this->buildComposition(['container:{sec}' => ['default' => ['padding' => '2rem']]]);
    $this->setUpCurrentUser([], ['create swatches', 'update variants']);

    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'animation', 'label' => 'X', 'steps' => ['0%' => ['opacity' => '0']]],
    ]));
    $this->assertSame(['PERMISSION_DENIED'], $codes['']);

    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'variant', 'label' => 'X', 'element' => $ids['sec']],
    ]));
    $this->assertSame(['PERMISSION_DENIED'], $codes['']);
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'remove_material', 'kind' => 'swatch', 'id' => 'anything'],
    ]));
    $this->assertSame(['PERMISSION_DENIED'], $codes['']);
    $result = $this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'swatch', 'label' => 'Allowed', 'value' => '#123456'],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
  }

  /**
   * The swatch lifecycle routes are adapters over the one provider.
   *
   * One provider behind the op and the route: the same errors, the same delta,
   * no echo payload.
   */
  public function testSwatchRoutesAdapt(): void {
    [$entity] = $this->buildComposition([]);
    $controller = SwatchApiController::create($this->container);
    $request = static fn (array $body) => Request::create('/daedalus_stylish/swatches/route_red/rename', 'POST', [], [], [], [], json_encode($body));

    // The palette's mint card and value editor are the ops themselves (the
    // client lane of testSwatchGestures); the value seam refuses on the op
    // door exactly as it did on the route: a gradient into a color swatch.
    $result = $this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'swatch', 'label' => 'Route red', 'group' => 'color', 'value' => '#ef4444'],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $this->assertSame([
      ['swatch', 'route_red', 'user'],
    ], array_map(static fn (array $r): array => [$r['kind'], $r['id'], $r['origin']], $this->delta()['upsert']));
    $this->assertFalse($this->batch($entity, [
      [
        'op' => 'update_material',
        'kind' => 'swatch',
        'id' => 'route_red',
        'value' => 'linear-gradient(90deg, red, gold)',
      ],
    ])->isSuccess());
    $codes = $this->errorCodes($this->batch($entity, [
      ['op' => 'save_material', 'kind' => 'swatch', 'label' => '', 'group' => 'color', 'value' => '#ef4444'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['label']);

    $response = $controller->rename('route_red', $request(['label' => 'Route crimson']));
    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(['materials'], array_keys($body), 'A route write answers the delta and nothing else.');
    $this->assertSame('Route crimson', $body['materials']['upsert'][0]['label']);

    $response = $controller->delete('route_red');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([['kind' => 'swatch', 'id' => 'route_red']], json_decode((string) $response->getContent(), TRUE)['materials']['remove']);

    // The provider speaks the same refusal on the route lane's own door.
    try {
      $this->container->get('daedalus_stylish.materials_provider')->update(MaterialKind::Swatch, 'route_red', ['label' => 'Gone'], NULL, []);
      $this->fail('Expected STYLE_SWATCH_NOT_FOUND.');
    }
    catch (MaterialGestureException $exception) {
      $this->assertSame('STYLE_SWATCH_NOT_FOUND', $exception->getErrors()[0]->getCode());
    }
  }

}
