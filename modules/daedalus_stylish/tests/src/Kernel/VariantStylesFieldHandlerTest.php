<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus_stylish\Traits\HostTrait;

/**
 * Tests the variant styles' daedalus_blueprint field handler.
 *
 * The blueprint lane of the variant-styles road: a variant serializes with
 * structured element-relative styles (no JSON-string-in-JSON), and writes
 * validate through the validator's address door (validateAt()) — the same path
 * drush dd-blueprint-serialize/dd-blueprint-deserialize and the AI's generic create/update tools ride. The style
 * guide in play comes from daedalus_stylish_guide_test: section declares spacing with
 * padding max 4rem, and the testimonial block type declares typography
 * (font-size max 2rem) plus a .testimonial__quote sub-element.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantStylesFieldHandlerTest extends KernelTestBase {

  use HostTrait;
  use UserCreationTrait;
  use DesignSystemActivationTrait;

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
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    // Access-transparent like the operations suite: these tests verify
    // handler behavior, not access.
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * Builds a full-create blueprint for a variant.
   */
  protected function blueprint(array $fields): array {
    return [
      'entity_type' => 'daedalus_variant',
      'bundle' => 'daedalus_variant',
      'fields' => $fields,
    ];
  }

  /**
   * Deserializes a blueprint, as drush dd-blueprint-deserialize and the AI create tool do.
   */
  protected function deserialize(array $data): BlueprintResultInterface {
    return $this->container->get('daedalus_blueprint.deserializer')->deserialize($data);
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
   * A blueprint round-trips structured styles — the drush dd-blueprint-deserialize/dd-blueprint-serialize path.
   */
  public function testBlueprintRoundTrip(): void {
    $styles = [
      '' => [
        'default' => ['font-size' => '1.5rem', 'padding' => '1rem'],
        'hover' => ['padding' => '1.5rem'],
      ],
      '::.testimonial__quote' => ['default' => ['font-size' => '1.25rem']],
    ];

    // Create through the deserializer (dd-blueprint-deserialize), then save — the caller decides.
    $result = $this->deserialize($this->blueprint([
      'label' => 'Testimonial — bold',
      'machine_name' => 'testimonial_bold',
      'scope' => 'block_type:testimonial',
      'styles' => $styles,
    ]));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $variant = $result->getEntity();
    $variant->save();

    $loaded = $this->container->get('entity_type.manager')
      ->getStorage('daedalus_variant')
      ->loadByProperties(['machine_name' => 'testimonial_bold']);
    $loaded = reset($loaded);
    $this->assertInstanceOf(DaedalusStylishVariant::class, $loaded);
    $this->assertSame($styles, $loaded->getStyles());

    // Serialize (dd-blueprint-serialize) exposes the decoded map, never a JSON string.
    $blueprint = $this->container->get('daedalus_blueprint.serializer')->serialize($this->host($loaded));
    $this->assertSame($styles, $blueprint['fields']['styles']);
  }

  /**
   * Scope resolution never depends on blueprint payload key order.
   */
  public function testStylesBeforeScopeInPayload(): void {
    // Styles first, scope last: on a create the entity carries no scope yet
    // when the styles handler runs, so it must read the submitted sibling.
    $result = $this->deserialize($this->blueprint([
      'styles' => ['' => ['default' => ['font-size' => '1.5rem']]],
      'label' => 'Order proof',
      'scope' => 'block_type:testimonial',
    ]));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));

    // The declared scope is what validates: the testimonial cap (2rem)
    // rejects what a scope-less degradation would have waved through.
    $result = $this->deserialize($this->blueprint([
      'styles' => ['' => ['default' => ['font-size' => '3rem']]],
      'label' => 'Order proof',
      'scope' => 'block_type:testimonial',
    ]));
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $this->errorCodes($result)['fields.styles.default.font-size']);
  }

  /**
   * Invalid variants fail with structured, styles-map-relative errors.
   */
  public function testInvalidVariantStructuredErrors(): void {
    $result = $this->deserialize($this->blueprint([
      'label' => 'Broken',
      'scope' => 'kind:container',
      'styles' => [
        // The section declaration caps padding at 4rem.
        '' => ['default' => ['padding' => '6rem'], 'sparkle' => ['margin' => '1rem']],
        // Selectable keys are not element-relative keys.
        'block:not-relative' => ['default' => ['padding' => '1rem']],
        // Entries are entry-shaped (nested references are legal), but a
        // nested reference must name an EXISTING variant — dangling refs
        // fail at save.
        '::.inner' => ['variants' => [['variant' => 'other', 'fragment' => '']]],
      ],
    ]));
    $this->assertFalse($result->isSuccess());
    $codes = $this->errorCodes($result);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['fields.styles.default.padding']);
    $this->assertSame(['INVALID_STYLE_CONDITION'], $codes['fields.styles.sparkle']);
    $this->assertSame(['INVALID_STYLE_KEY'], $codes['fields.styles.block:not-relative']);
    $this->assertSame(['STYLE_VARIANT_NOT_FOUND'], $codes['fields.styles::.inner.variants.0']);

    // A bad scope surfaces on the styles too: they validate against it.
    $result = $this->deserialize($this->blueprint([
      'label' => 'Broken',
      'scope' => 'testimonial',
      'styles' => ['' => ['default' => ['padding' => '1rem']]],
    ]));
    $this->assertSame(['INVALID_STYLE_SCOPE'], $this->errorCodes($result)['fields.styles.scope']);

    // Whole-value shapes that are not a styles map fail as a whole.
    $result = $this->deserialize($this->blueprint([
      'label' => 'Broken',
      'scope' => 'kind:container',
      'styles' => ['a', 'list'],
    ]));
    $this->assertSame(['INVALID_VARIANT_STYLES'], $this->errorCodes($result)['fields.styles']);
  }

  /**
   * Without a scope anywhere, the styles refuse to validate.
   */
  public function testScopeRequired(): void {
    $result = $this->deserialize($this->blueprint([
      'label' => 'Scopeless',
      'styles' => ['' => ['default' => ['padding' => '1rem']]],
    ]));
    $this->assertFalse($result->isSuccess());
    $this->assertContains('VARIANT_SCOPE_REQUIRED', $this->errorCodes($result)['fields.styles']);
  }

  /**
   * Styles-only writes to an existing variant validate its stored scope.
   */
  public function testStoredScopeFallback(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Wide section',
      'machine_name' => 'wide_section',
      'scope' => 'kind:container',
      'styles' => json_encode(['' => ['default' => ['padding' => '2rem']]]),
    ]);
    $variant->save();

    $definition = $variant->get('styles')->getFieldDefinition();
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($definition);

    // No sibling fields submitted: the entity's own scope validates.
    $result = $handler->deserialize(['' => ['default' => ['padding' => '6rem']]], $definition, ['entity' => $variant]);
    $this->assertSame('STYLE_VALUE_ABOVE_MAX', $result->getErrors()[0]->getCode());
    $this->assertSame('fields.styles.default.padding', $result->getErrors()[0]->getPath());

    $result = $handler->deserialize(['' => ['default' => ['padding' => '3rem']]], $definition, ['entity' => $variant]);
    $this->assertSame([], $result->getErrors());
    $this->assertSame(['' => ['default' => ['padding' => '3rem']]], json_decode($result->getValue(), TRUE));
  }

  /**
   * Schema additions project the style guide per variant scope.
   */
  public function testGuidanceAndSchemaAdditions(): void {
    $variant = DaedalusStylishVariant::create(['label' => 'x', 'scope' => 'kind:container']);
    $definition = $variant->get('styles')->getFieldDefinition();
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($definition);

    $this->assertStringContainsString('element-relative', (string) $handler->getGuidance());
    // The guidance routes validity to the fragment; no mode vocabulary.
    $this->assertStringContainsString('scopes catalog', (string) $handler->getGuidance());
    $this->assertStringNotContainsString('strict', (string) $handler->getGuidance());

    $guide = $handler->getSchemaAdditions($definition)['style_guide'];
    $this->assertArrayHasKey('default', $guide['conditions']);

    // The applicability story rides this fragment too, as designer-facing
    // lines: the site's own bindings (the unbound fixture withdraws the
    // suite's dimension and type bindings, so the one line left is
    // daedalus_stylish's shipped font-family -> font scale), and every node's value
    // space as a deviations line at the scope-key grammar, a cap authored
    // on a shorthand reading as one phrase per served value space (the
    // family expanded at the source).
    $this->assertSame(['font-family' => 'font values'], $guide['bindings']);
    $this->assertSame('padding at most 4rem; padding-top at most 4rem; padding-right at most 4rem; padding-bottom at most 4rem; padding-left at most 4rem', $guide['deviations']['kind:container']);
    $this->assertSame('font-family one of: Inter, Georgia; font-size at most 2rem', $guide['deviations']['block_type:testimonial']);
    $this->assertSame('font-size at most 1.75rem', $guide['deviations']['block_type:testimonial::.testimonial__quote']);

    // Scope keys are the scope field's vocabulary — the catalog exists in
    // every posture, each entry carrying only the scope's additional axes
    // when it declares any ("style" first, matching class-emission
    // order). What each scope's element may style is the stylable
    // section, by property name at the scope-key grammar, in every
    // posture too.
    $this->assertSame([], $guide['scopes']['kind:container']);
    $this->assertSame(['variant_axes' => ['style', 'emphasis']], $guide['scopes']['block_type:testimonial']);
    $this->assertStringContainsString('variant_axes', $guide['guidance']);
    $this->assertStringContainsString('padding', $guide['stylable']['entries']['kind:container']);
    $this->assertStringContainsString('font-size', $guide['stylable']['entries']['block_type:testimonial::.testimonial__quote']);
    $this->assertStringContainsString('kind:container for layouts', $guide['stylable']['guidance']);

    // Mode opacity: the fragment never names the enforcement world.
    $encoded = json_encode($guide);
    $this->assertStringNotContainsStringIgnoringCase('strict', $encoded);
    $this->assertStringNotContainsStringIgnoringCase('guarded', $encoded);
    $this->assertStringNotContainsStringIgnoringCase('enforcement', $encoded);

    // A closed-world site teaches the same entries and the same scopes
    // catalog; only the roster sentence changes. The shells themselves
    // are the schema's anatomy, constraints the deviations'.
    $open_entries = $guide['stylable']['entries'];
    $this->enforce('strict');
    $guide = $handler->getSchemaAdditions($definition)['style_guide'];
    $this->assertSame($open_entries, $guide['stylable']['entries']);
    $this->assertStringStartsWith('Each element styles ONLY the properties its kind permits', $guide['stylable']['guidance']);
    $this->assertStringContainsString('padding', $guide['stylable']['entries']['kind:container']);
    // motion, effects and shadow ride in from the universal block kind
    // (daedalus_stylish's own grants): the block kind is the widest roster and
    // the testimonial prints against it.
    $this->assertStringContainsString('animation-name', $guide['stylable']['entries']['kind:block']);
    $this->assertStringStartsWith('the kind:block roster, plus ', $guide['stylable']['entries']['block_type:testimonial']);
    $this->assertSame(['variant_axes' => ['style', 'emphasis']], $guide['scopes']['block_type:testimonial']);
    $this->assertStringNotContainsString('"constraints"', json_encode($guide));
    $this->assertStringNotContainsString('"sets"', json_encode($guide));
  }

  /**
   * A submitted axis outside the scope's vocabulary fails correctively.
   */
  public function testAxisValidation(): void {
    // The testimonial block type declares the emphasis axis: valid.
    $result = $this->deserialize($this->blueprint([
      'label' => 'Pop quote',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
      'styles' => ['' => ['default' => ['font-size' => '1.5rem']]],
    ]));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $variant = $result->getEntity();
    $variant->save();
    $this->assertSame('emphasis', $variant->getAxis());

    // An axis the scope does not declare gets the corrective error with the
    // legal vocabulary, in the blueprint lane (not a save-time exception).
    $result = $this->deserialize($this->blueprint([
      'label' => 'Wrong lane',
      'scope' => 'block_type:testimonial',
      'axis' => 'proportions',
      'styles' => ['' => ['default' => ['font-size' => '1.5rem']]],
    ]));
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('STYLE_VARIANT_AXIS_INVALID', $error->getCode());
    $this->assertSame('fields.axis', $error->getPath());
    $this->assertSame(['style', 'emphasis'], $error->getContext()['valid_axes']);
  }

  /**
   * The guidance states the distro lock only while it holds.
   *
   * On a consuming site the AI is told shipped variants are read-only and
   * pointed at the fork; on a development site (the developer gate lifted,
   * `$settings['daedalus_blueprint_developer'] = TRUE`) shipped variants
   * update like any other, so the sentence is gone. Read live: the same
   * handler instance answers both ways.
   */
  public function testGuidanceLockSentenceFollowsTheDistroLock(): void {
    $variant = DaedalusStylishVariant::create(['label' => 'x', 'scope' => 'kind:container']);
    $definition = $variant->get('styles')->getFieldDefinition();
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($definition);

    $this->assertStringContainsString('Module-shipped variants are READ-ONLY (the distro lock)', (string) $handler->getGuidance());

    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $this->assertStringNotContainsString('READ-ONLY', (string) $handler->getGuidance());
    $this->assertStringNotContainsString('distro lock', (string) $handler->getGuidance());
    // The rest of the guidance is untouched by the gate.
    $this->assertStringContainsString('scopes catalog', (string) $handler->getGuidance());
  }

}
