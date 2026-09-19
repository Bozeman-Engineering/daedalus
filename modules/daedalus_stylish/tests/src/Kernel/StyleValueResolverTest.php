<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\StyleValueResolver;
use Drupal\daedalus_stylish_value_handler_test\TestStyleValueHandler;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the style grammar's input tier: the style-value handler seam.
 *
 * The input grammar for a style property value is a superset of the storage
 * grammar: a plain CSS string, a handled-value object dispatched on its
 * marker key (a service tagged daedalus_stylish.style_value_handler), or, on
 * media-capable properties, a layers list of strings and objects joined to
 * one comma-separated value. StyleValueResolver runs in two phases: CHECK
 * substitutes sound stand-ins so StyleMapValidator runs unchanged and
 * side-effect-free, COMMIT invokes the handlers only after validation
 * succeeded, so failed writes mint nothing. All three write lanes wire the
 * seam: StyleMapFieldHandler whole-map writes, DaedalusStylishOperationHandler's
 * update_style patch, and VariantStylesFieldHandler variant styles. The
 * test handler (daedalus_stylish_value_handler_test) registers the "_test_value"
 * marker and logs its invocations in state; daedalus_stylish itself registers no
 * handlers, so an unrecognized marker is a validation error.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleValueResolverTest extends KernelTestBase {

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
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
    'daedalus_stylish_value_handler_test',
  ];

  /**
   * The resolver under test.
   */
  protected StyleValueResolver $resolver;

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
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    // Access-transparent like the operations suite: these tests verify the
    // input tier, not access.
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->resolver = $this->container->get('daedalus_stylish.style_value_resolver');
    $this->uuid = $this->container->get('uuid')->generate();
  }

  /**
   * The test handler's resolve() log: what a write actually minted.
   */
  protected function resolves(): array {
    return $this->container->get('state')->get(TestStyleValueHandler::RESOLVES, []);
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
   * CHECK substitutes stand-ins and stays side-effect-free.
   */
  public function testCheckPhaseStandInSubstitution(): void {
    $key = "container:{$this->uuid}";

    // A bare handled object: the handler's stand-in replaces it, nothing
    // resolves, and plain values pass through untouched.
    $resolution = $this->resolver->check([
      $key => [
        'default' => [
          'background-image' => ['_test_value' => 'forest'],
          'padding' => '2rem',
        ],
      ],
    ]);
    $this->assertSame([], $resolution->getErrors());
    $this->assertSame([
      $key => ['default' => ['background-image' => 'none', 'padding' => '2rem']],
    ], $resolution->getMap());
    $checks = $this->container->get('state')->get(TestStyleValueHandler::CHECKS, []);
    $this->assertCount(1, $checks);
    $this->assertSame("$key.default.background-image", $checks[0]['path']);
    // CHECK is side-effect-free: nothing resolved yet.
    $this->assertSame([], $this->resolves());

    // COMMIT swaps in the canonical resolved string.
    $this->assertSame([
      $key => ['default' => ['background-image' => 'url("resolved:forest")', 'padding' => '2rem']],
    ], $resolution->commit());
    $this->assertCount(1, $this->resolves());
  }

  /**
   * The CHECK phase's input-tier errors: markers and handler shapes.
   */
  public function testCheckPhaseErrors(): void {
    $key = "container:{$this->uuid}";

    // An object carrying no registered marker is a validation error, with
    // the registered markers as the corrective.
    $resolution = $this->resolver->check([
      $key => ['default' => ['background-image' => ['_bogus' => 'x']]],
    ]);
    $error = $resolution->getErrors()[0];
    $this->assertSame('UNKNOWN_STYLE_VALUE_MARKER', $error->getCode());
    $this->assertSame("$key.default.background-image", $error->getPath());
    $this->assertSame(['_test_value', '_generate'], $error->getContext()['known_markers']);

    // The handler's own shape check errors re-root onto the map path.
    $resolution = $this->resolver->check([
      $key => ['default' => ['background-image' => ['_test_value' => 42]]],
    ]);
    $error = $resolution->getErrors()[0];
    $this->assertSame('INVALID_TEST_VALUE', $error->getCode());
    $this->assertSame("$key.default.background-image._test_value", $error->getPath());

    // An errored resolution refuses to commit; the caller returns errors.
    $this->expectException(\LogicException::class);
    $resolution->commit();
  }

  /**
   * Layers lists: media-capable only, strings and objects, joined.
   */
  public function testLayersGrammar(): void {
    $key = "container:{$this->uuid}";
    $gradient = 'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5))';

    // Strings and handled objects join to one comma-separated value, in
    // order: stand-ins for validation, canonical strings on commit.
    $resolution = $this->resolver->check([
      $key => ['default' => ['background-image' => [$gradient, ['_test_value' => 'forest']]]],
    ]);
    $this->assertSame([], $resolution->getErrors());
    $this->assertSame("$gradient, none", $resolution->getMap()[$key]['default']['background-image']);
    $this->assertSame("$gradient, url(\"resolved:forest\")", $resolution->commit()[$key]['default']['background-image']);
    $this->assertSame(1, $this->resolves()[0]['layer']);

    // A recognized property that is not media-capable rejects the form.
    $resolution = $this->resolver->check([
      $key => ['default' => ['padding' => ['1rem', '2rem']]],
    ]);
    $error = $resolution->getErrors()[0];
    $this->assertSame('INVALID_STYLE_VALUE', $error->getCode());
    $this->assertSame("$key.default.padding", $error->getPath());

    // A layer must be a CSS string or a handled object.
    $resolution = $this->resolver->check([
      $key => ['default' => ['background-image' => [$gradient, 42]]],
    ]);
    $error = $resolution->getErrors()[0];
    $this->assertSame('INVALID_STYLE_VALUE', $error->getCode());
    $this->assertSame("$key.default.background-image.1", $error->getPath());

    // A strings-only list still joins (the list form is the grammar, not a
    // handler privilege), with nothing pending to resolve.
    $this->container->get('state')->delete(TestStyleValueHandler::RESOLVES);
    $resolution = $this->resolver->check([
      $key => ['default' => ['background-image' => [$gradient, $gradient]]],
    ]);
    $this->assertSame([], $resolution->getErrors());
    $this->assertSame("$gradient, $gradient", $resolution->commit()[$key]['default']['background-image']);
    $this->assertSame([], $this->resolves());

    // The 'variants' entry key is the reference list, never a condition
    // block: its objects are not handled values.
    $resolution = $this->resolver->check([
      $key => ['variants' => [['variant' => 'hero', 'fragment' => '']]],
    ]);
    $this->assertSame([], $resolution->getErrors());
    $this->assertSame([['variant' => 'hero', 'fragment' => '']], $resolution->getMap()[$key]['variants']);
  }

  /**
   * The whole-map lane: StyleMapFieldHandler resolves the input tier.
   */
  public function testStyleMapFieldHandlerLane(): void {
    $handler = $this->container->get('daedalus_stylish.field_handler.style_map');
    $definition = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('entity_test', 'entity_test')[DaedalusStylishFieldManager::FIELD_NAME];
    $key = "container:{$this->uuid}";
    $gradient = 'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5))';

    // A valid write stores the resolved canonical strings; storage stays
    // exactly the plain-CSS doctrine.
    $result = $handler->deserialize([
      $key => [
        'default' => [
          'background-image' => [$gradient, ['_test_value' => 'forest']],
          'padding' => '2rem',
        ],
      ],
    ], $definition);
    $this->assertFalse($result->hasErrors(), print_r($result->getErrors(), TRUE));
    $this->assertSame([
      $key => [
        'default' => [
          'background-image' => "$gradient, url(\"resolved:forest\")",
          'padding' => '2rem',
        ],
      ],
    ], json_decode($result->getValue(), TRUE));
    $this->assertCount(1, $this->resolves());

    // A map that fails validation commits nothing: the handled value's
    // stand-in validated, the padding violation failed the write, and no
    // resolve happened.
    $this->container->get('state')->delete(TestStyleValueHandler::RESOLVES);
    $result = $handler->deserialize([
      $key => [
        'default' => [
          'background-image' => ['_test_value' => 'forest'],
          'padding' => '9rem',
        ],
      ],
    ], $definition);
    $this->assertTrue($result->hasErrors());
    $this->assertSame("fields.daedalus_stylish_styles.$key.default.padding", $result->getErrors()[0]->getPath());
    $this->assertSame([], $this->resolves());

    // Input-tier errors ride the field path like validator errors.
    $result = $handler->deserialize([
      $key => ['default' => ['background-image' => ['_bogus' => 'x']]],
    ], $definition);
    $this->assertSame('UNKNOWN_STYLE_VALUE_MARKER', $result->getErrors()[0]->getCode());
    $this->assertSame("fields.daedalus_stylish_styles.$key.default.background-image", $result->getErrors()[0]->getPath());
    $this->assertSame([], $this->resolves());
  }

  /**
   * The patch lane: update_style resolves the input tier.
   */
  public function testUpdateStyleLane(): void {
    $operations = $this->container->get('daedalus_blueprint.operations');
    $entity = EntityTest::create(['name' => 'styled']);
    $entity->save();
    $key = "container:{$this->uuid}";
    $gradient = 'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5))';

    // The patch writes the resolved canonical string.
    $result = $operations->batchOperations($entity, [
      [
        'op' => 'update_style',
        'key' => $key,
        'properties' => [
          'background-image' => [$gradient, ['_test_value' => 'forest']],
          'padding' => '2rem',
        ],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $map = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $this->assertSame("$gradient, url(\"resolved:forest\")", $map[$key]['default']['background-image']);
    $this->assertSame('2rem', $map[$key]['default']['padding']);
    $this->assertCount(1, $this->resolves());

    // A failing patch mints nothing: the guide violation beside the
    // handled value blocks the write before any resolve.
    $this->container->get('state')->delete(TestStyleValueHandler::RESOLVES);
    $result = $operations->batchOperations($entity, [
      [
        'op' => 'update_style',
        'key' => $key,
        'properties' => [
          'background-image' => ['_test_value' => 'forest'],
          'padding' => '9rem',
        ],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertArrayHasKey('properties.padding', $this->errorCodes($result));
    $this->assertSame([], $this->resolves());

    // Input-tier errors reroute onto the operation's properties path.
    $result = $operations->batchOperations($entity, [
      [
        'op' => 'update_style',
        'key' => $key,
        'properties' => [
          'background-image' => ['_bogus' => 'x'],
        ],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(
      ['UNKNOWN_STYLE_VALUE_MARKER'],
      $this->errorCodes($result)['properties.background-image'],
    );
    $this->assertSame([], $this->resolves());
  }

  /**
   * The variant lane: variant styles resolve the input tier identically.
   */
  public function testVariantStylesLane(): void {
    $deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $gradient = 'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5))';
    $blueprint = static fn (array $styles): array => [
      'entity_type' => 'daedalus_variant',
      'bundle' => 'daedalus_variant',
      'fields' => [
        'label' => 'Testimonial generated backdrop',
        'machine_name' => 'testimonial_backdrop',
        'scope' => 'block_type:testimonial',
        'styles' => $styles,
      ],
    ];

    // A variant authored with a handled layer stores the resolved
    // canonical string like any other value, with no special case.
    $result = $deserializer->deserialize($blueprint([
      '' => ['default' => ['background-image' => [$gradient, ['_test_value' => 'forest']]]],
    ]));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame(
      ['' => ['default' => ['background-image' => "$gradient, url(\"resolved:forest\")"]]],
      json_decode($result->getEntity()->get('styles')->value, TRUE),
    );
    $this->assertCount(1, $this->resolves());

    // A failing variant write mints nothing: the font-size violation
    // (typography max 2rem on the testimonial scope) blocks the write
    // before any resolve.
    $this->container->get('state')->delete(TestStyleValueHandler::RESOLVES);
    $result = $deserializer->deserialize($blueprint([
      '' => [
        'default' => [
          'background-image' => ['_test_value' => 'forest'],
          'font-size' => '9rem',
        ],
      ],
    ]));
    $this->assertFalse($result->isSuccess());
    $this->assertSame([], $this->resolves());

    // Input-tier errors ride the field path.
    $result = $deserializer->deserialize($blueprint([
      '' => ['default' => ['background-image' => ['_bogus' => 'x']]],
    ]));
    $this->assertFalse($result->isSuccess());
    $codes = $this->errorCodes($result);
    $this->assertSame(['UNKNOWN_STYLE_VALUE_MARKER'], $codes['fields.styles.default.background-image']);
    $this->assertSame([], $this->resolves());
  }

  /**
   * The input tier is schema-taught exactly where a handler registers.
   *
   * With the test handler registered, the style-map field guidance teaches
   * the tier mechanics plus the handler's own marker sentence, and the
   * variant-styles guidance delegates to it; StyleMapFieldHandlerTest pins
   * the inverse (no handlers, no tier sentence).
   */
  public function testGuidanceTeachesRegisteredHandlers(): void {
    $guidance = (string) $this->container->get('daedalus_stylish.field_handler.style_map')->getGuidance();
    $this->assertStringContainsString('input tier', $guidance);
    $this->assertStringContainsString('LIST of layers', $guidance);
    $this->assertStringContainsString('{"_test_value": "<payload>"}', $guidance);
    $variant_guidance = (string) $this->container->get('daedalus_stylish.field_handler.variant_styles')->getGuidance();
    $this->assertStringContainsString('input tier', $variant_guidance);
  }

}
