<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialsProse;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the style map's daedalus_blueprint field handler and validator.
 *
 * The style guide in play comes from daedalus_stylish_guide_test (modules-only — no
 * test theme installed): section declares spacing with padding max 4rem,
 * field declares typography, block declares spacing, and the testimonial
 * block type declares typography (font-family allowed [Inter, Georgia],
 * font-size max 2rem) plus a .testimonial__quote sub-element.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleMapFieldHandlerTest extends KernelTestBase {

  use DesignSystemActivationTrait;
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
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
  ];

  /**
   * A valid uuid to build keys with.
   */
  protected string $uuid;

  /**
   * The style map field definition on entity_test.
   */
  protected object $definition;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('daedalus_variant');
    // The guidance reads the materials roster, whose daedalus_stylish provider
    // queries the swatch and skin storages too.
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
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->uuid = $this->container->get('uuid')->generate();
    $this->definition = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('entity_test', 'entity_test')[DaedalusStylishFieldManager::FIELD_NAME];
  }

  /**
   * Deserializes a map through the handler, as daedalus_blueprint would.
   */
  protected function deserialize(array $map, array $options = []): FieldHandlerResult {
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($this->definition);
    return $handler->deserialize($map, $this->definition, $options);
  }

  /**
   * Collects error codes keyed by path from a handler result.
   */
  protected function errorCodes(FieldHandlerResult $result): array {
    $codes = [];
    foreach ($result->getErrors() as $error) {
      $codes[$error->getPath()][] = $error->getCode();
    }
    return $codes;
  }

  /**
   * The manager resolves the field type to our handler; values round-trip.
   */
  public function testRoundTrip(): void {
    // Variant references now validate existence + scope; a block key with no
    // entity in context captures the degraded "kind:block" scope.
    DaedalusStylishVariant::create(['label' => 'CTA bold', 'machine_name' => 'cta_bold', 'scope' => 'kind:block'])->save();
    // The suite binds font-size to the text scale site-wide and the site
    // is guarded, so the field's sizes are the active skin's tokens (the
    // container's padding answers to the fixture's own node cap instead).
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens(['text' => ['lead' => '1.25rem', 'body' => '1rem']])->save();
    $this->activateSkin($skin);
    $map = [
      "container:{$this->uuid}" => ['default' => ['padding' => '2rem 1rem']],
      "field:{$this->uuid}:field_body" => [
        'default' => ['font-size' => 'var(--text-lead)'],
        'narrow' => ['font-size' => 'var(--text-body)'],
      ],
      "block:{$this->uuid}" => ['variants' => [['variant' => 'cta_bold', 'fragment' => '']]],
      "block:{$this->uuid}::.testimonial__quote" => ['default' => ['font-style' => 'italic']],
    ];

    $result = $this->deserialize($map);
    $this->assertSame([], $this->errorCodes($result));
    $this->assertSame($map, json_decode($result->getValue(), TRUE));

    // Serialize exposes the decoded map, not a JSON string.
    $entity = EntityTest::create(['name' => 'styled']);
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, $result->getValue());
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($this->definition);
    $this->assertSame($map, $handler->serialize($entity->get(DaedalusStylishFieldManager::FIELD_NAME), $this->definition));

    // An empty map deserializes to no field value at all.
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, $this->deserialize([])->getValue());
    $this->assertTrue($entity->get(DaedalusStylishFieldManager::FIELD_NAME)->isEmpty());
  }

  /**
   * Structural phase: keys, entry shapes, and condition names.
   */
  public function testStructuralValidation(): void {
    $result = $this->deserialize([
      'bogus:123' => ['default' => []],
      "container:{$this->uuid}::.two .classes" => ['default' => []],
      "block:{$this->uuid}" => 'not-an-entry',
      // The dead single-reference grammar gets its own corrective error.
      "entity:{$this->uuid}" => ['variant' => 'cta_bold', 'default' => []],
      "field:{$this->uuid}:field_body" => ['Hover!' => ['color' => 'red']],
    ]);

    $prefix = 'fields.' . DaedalusStylishFieldManager::FIELD_NAME;
    $codes = $this->errorCodes($result);
    $this->assertSame(['INVALID_STYLE_KEY'], $codes["$prefix.bogus:123"]);
    $this->assertSame(['INVALID_STYLE_KEY'], $codes["$prefix.container:{$this->uuid}::.two .classes"]);
    $this->assertSame(['INVALID_STYLE_ENTRY'], $codes["$prefix.block:{$this->uuid}"]);
    $this->assertSame(['INVALID_STYLE_ENTRY'], $codes["$prefix.entity:{$this->uuid}.variant"]);
    $this->assertSame(['INVALID_STYLE_CONDITION'], $codes["$prefix.field:{$this->uuid}:field_body.Hover!"]);

    // An object property value belongs to the input tier: with no marker
    // registered it is an unknown handled value, and the input tier's
    // errors return alone (CHECK precedes validation, which never ran).
    $result = $this->deserialize([
      "container:{$this->uuid}" => ['default' => ['padding' => ['nested' => 'array']]],
    ]);
    $codes = $this->errorCodes($result);
    $this->assertSame(
      ["$prefix.container:{$this->uuid}.default.padding" => ['UNKNOWN_STYLE_VALUE_MARKER']],
      $codes,
    );

    // Not-a-map inputs fail as a whole.
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($this->definition);
    $result = $handler->deserialize('{"broken json', $this->definition);
    $this->assertSame('INVALID_STYLE_MAP', $result->getErrors()[0]->getCode());
    $result = $handler->deserialize(['a', 'list'], $this->definition);
    $this->assertSame('INVALID_STYLE_MAP', $result->getErrors()[0]->getCode());
  }

  /**
   * Semantic phase: style guide constraints enforced per element kind.
   */
  public function testSemanticValidation(): void {
    $prefix = 'fields.' . DaedalusStylishFieldManager::FIELD_NAME;

    // The guide's section declaration caps padding at 4rem.
    $result = $this->deserialize([
      "container:{$this->uuid}" => ['default' => ['padding' => '6rem']],
    ]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $this->errorCodes($result)["$prefix.container:{$this->uuid}.default.padding"]);

    // A property no group recognizes is an honest rejection.
    $result = $this->deserialize([
      "container:{$this->uuid}" => ['default' => ['bling' => 'lots']],
    ]);
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_STYLE_PROPERTY', $error->getCode());
    $this->assertStringContainsString('recognized CSS properties', $error->getHint());

    // The generated wide tier recognizes the long tail: backdrop-filter is
    // an 'any'-typed wide property now, name + hygiene checked.
    $result = $this->deserialize([
      "container:{$this->uuid}" => ['default' => ['backdrop-filter' => 'blur(4px)']],
    ]);
    $this->assertSame([], $result->getErrors());

    // Recognized property on an element whose declaration doesn't list its
    // group: open site, so it passes with grammar checks only — and grammar
    // still rejects garbage.
    $result = $this->deserialize([
      "container:{$this->uuid}" => ['default' => ['font-size' => '1.5rem']],
    ]);
    $this->assertSame([], $result->getErrors());
    $result = $this->deserialize([
      "container:{$this->uuid}" => ['default' => ['font-size' => 'bogus;value']],
    ]);
    $this->assertSame(['INVALID_STYLE_VALUE'], $this->errorCodes($result)["$prefix.container:{$this->uuid}.default.font-size"]);
  }

  /**
   * The retired freeform slot is rejected as malformed, never ignored.
   */
  public function testFreeformRejected(): void {
    $prefix = 'fields.' . DaedalusStylishFieldManager::FIELD_NAME;
    $result = $this->deserialize([
      "container:{$this->uuid}" => [
        'default' => ['padding' => '1rem'],
        'freeform' => 'outline: none;',
      ],
    ]);
    $codes = $this->errorCodes($result);
    $this->assertSame(['INVALID_STYLE_ENTRY'], $codes["$prefix.container:{$this->uuid}.freeform"]);
    $error = $result->getErrors()[0];
    $this->assertStringContainsString('no "freeform" slot', $error->getMessage());
  }

  /**
   * Strict mode closes the world: declared properties only.
   */
  public function testStrictMode(): void {
    $this->enforce('strict');
    $prefix = 'fields.' . DaedalusStylishFieldManager::FIELD_NAME;

    $result = $this->deserialize([
      // The section declaration lists only the spacing group.
      "container:{$this->uuid}" => [
        'default' => ['font-size' => '1.5rem'],
      ],
      // The section declaration lists no sub-elements.
      "container:{$this->uuid}::.foo" => ['default' => ['padding' => '1rem']],
    ]);

    $codes = $this->errorCodes($result);
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["$prefix.container:{$this->uuid}.default.font-size"]);
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $codes["$prefix.container:{$this->uuid}::.foo"]);

    // Declared styling within constraints still passes.
    $result = $this->deserialize([
      "container:{$this->uuid}" => ['default' => ['padding' => '2rem']],
    ]);
    $this->assertSame([], $result->getErrors());
  }

  /**
   * Block:{uuid} keys resolve their block type when the entity is in context.
   *
   * With the host entity, the testimonial declaration (font-size max 2rem)
   * is enforced; without it, the universal block kind is the fallback and
   * the same value passes with grammar checks only.
   */
  public function testBlockBundleResolution(): void {
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
    $entity = EntityTest::create([
      'name' => 'host',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $map = ["block:$component_uuid" => ['default' => ['font-size' => '3rem']]];

    $with_entity = $this->deserialize($map, ['entity' => $entity]);
    $this->assertSame(
      ['STYLE_VALUE_ABOVE_MAX'],
      $this->errorCodes($with_entity)['fields.' . DaedalusStylishFieldManager::FIELD_NAME . ".block:$component_uuid.default.font-size"],
    );

    $without_entity = $this->deserialize($map);
    $this->assertSame([], $without_entity->getErrors());
  }

  /**
   * Schema additions enumerate the valid space materials-first.
   */
  public function testSchemaAdditions(): void {
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($this->definition);
    $guidance = $handler->getGuidance();
    $this->assertNotNull($guidance);
    // The guidance carries the map and key grammar only; what is valid on
    // this site lives in the fragment, and the enforcement setting is
    // opaque — no mode vocabulary anywhere.
    $this->assertStringContainsString('selectable key', $guidance);
    $this->assertStringContainsString('update_style', $guidance);
    $this->assertStringNotContainsString('strict', $guidance);
    $this->assertStringNotContainsString('property_sets', $guidance);
    // No style-value handlers are registered in this module set, so the
    // guidance teaches no input tier: teaching a marker no handler backs
    // would guide the AI into UNKNOWN_STYLE_VALUE_MARKER.
    // StyleValueResolverTest pins the inverse.
    $this->assertStringNotContainsString('input tier', $guidance);

    $guide = $handler->getSchemaAdditions($this->definition)['style_guide'];

    // The materials roster is the schema's own key (core's
    // MaterialsSchemaHooks renders it from the roster), never the
    // field's; the handler's own fragment is the applicability story plus
    // the language and the pointers. The old validator-question keys —
    // the strict flag, the notes paragraph, the property_sets catalog,
    // the raw constraint_defaults dump, the variant_axes prose, and the
    // per-element sets/constraints entries — are deleted, not renamed.
    // The stylable section rides in EVERY posture (the roster is the
    // design system's vocabulary whatever the site does outside it).
    $this->assertSame([
      'bindings',
      'deviations',
      'stylable',
      'guidance',
      'conditions',
      'current_values',
      'anatomy',
    ], array_keys($guide));

    // bindings: the site's own site-wide blocks as ONE designer-facing
    // line per property; this fixture authors none of its own, so the
    // map is exactly daedalus_stylish's shipped bindings (the site lane every
    // fixture layers over: font-family to font, the type trio, and the
    // dimension shorthands to space and radius with the zero literal),
    // one line per SERVED property, so a family shows its shorthand and
    // each expanded longhand until the prose collapses families. A
    // block the nodes agree on (the container kind's padding cap,
    // inherited by the twocol layout) is each node's value space, never
    // a site-wide line the validator would not enforce elsewhere.
    $this->assertSame([
      'font-family' => 'font values',
      'font-size' => 'text values',
      'font-weight' => 'weight values',
      'line-height' => 'leading values',
      'padding' => 'space values; also allowed: 0',
      'padding-top' => 'space values; also allowed: 0',
      'padding-right' => 'space values; also allowed: 0',
      'padding-bottom' => 'space values; also allowed: 0',
      'padding-left' => 'space values; also allowed: 0',
      'gap' => 'space values; also allowed: 0',
      'row-gap' => 'space values; also allowed: 0',
      'column-gap' => 'space values; also allowed: 0',
      'border-radius' => 'radius values; also allowed: 0',
      'border-top-left-radius' => 'radius values; also allowed: 0',
      'border-top-right-radius' => 'radius values; also allowed: 0',
      'border-bottom-right-radius' => 'radius values; also allowed: 0',
      'border-bottom-left-radius' => 'radius values; also allowed: 0',
    ], $guide['bindings']);

    // deviations: every node's value spaces, one line per node at the
    // fragment's node grammar (parts flatten as ::.selector, matching
    // the style map's key suffixes), the same law the validator judges
    // that address by. A node's block on a shorthand is served on each
    // longhand of the family (StyleGuide::expandConstraintFamilies()),
    // and the prose renders one phrase per served property, so the
    // container's padding cap reads as five phrases until the prose
    // collapses families.
    $padding = 'padding at most 4rem; padding-top at most 4rem; padding-right at most 4rem; padding-bottom at most 4rem; padding-left at most 4rem';
    $this->assertSame([
      'kinds.container' => $padding,
      'block_types.testimonial' => 'font-family one of: Inter, Georgia; font-size at most 2rem',
      'block_types.testimonial::.testimonial__quote' => 'font-size at most 1.75rem',
      'block_plugins.fancy_menu' => 'font-size at most 1.5rem',
      'block_plugins.fancy_menu::.menu-item' => 'font-size at most 1.25rem',
      'layouts.layout_twocol_section' => $padding . '; max-width at most 90rem',
      'layouts.layout_twocol_section::.band-content' => 'max-width at most 80rem',
    ], $guide['deviations']);

    // language: the preference order with the mint gate (MaterialsProse,
    // the one source the cached styling pointer shares; minting is never
    // the AI's initiative), the derived media-token rule (the shipped
    // site base declaration grants background on pre/code site-wide, so
    // background-image is always in the granted vocabulary, and content
    // is granted at every generated box, so the two are named together),
    // and the open-world polarity sentence. Blueprints join the materials
    // only where the roster has them; this test runs without that module.
    $this->assertStringContainsString(MaterialsProse::preferenceSentence(), $guide['guidance']);
    $this->assertStringContainsString('never mint a material unless the user asks', $guide['guidance']);
    $this->assertStringContainsString('media:<uuid>', $guide['guidance']);
    $this->assertStringContainsString('background-image, content also accept an image media reference', $guide['guidance']);
    $this->assertStringContainsString('Any recognized CSS property', $guide['guidance']);
    $this->assertStringNotContainsString('blueprints', $guide['guidance']);

    // The schema is bundle-level and cached, so current values are a pointer
    // at the blueprint's own styles key, not an embedded copy.
    $this->assertStringContainsString('top-level "styles" key', $guide['current_values']);

    // The guide is styling-only: the markup shells live in the schema's
    // anatomy fragment, and the guide points there instead of repeating it.
    $this->assertStringContainsString('anatomy trees', $guide['anatomy']);

    // Mode opacity, whole-fragment: the AI never learns that enforcement
    // modes exist, in any key.
    $encoded = json_encode($guide);
    $this->assertStringNotContainsStringIgnoringCase('strict', $encoded);
    $this->assertStringNotContainsStringIgnoringCase('guarded', $encoded);
    $this->assertStringNotContainsStringIgnoringCase('enforcement', $encoded);

    // No set refs and no constraint-grammar dumps anywhere in the
    // fragment: the stylable entries are prose by property name.
    $this->assertStringNotContainsString('"sets"', $encoded);
    $this->assertStringNotContainsString('"constraints"', $encoded);
    $this->assertStringNotContainsString('"properties"', $encoded);

    // The stylable section on a guarded site: the roster sentence says a
    // recognized property outside the roster is accepted (never that a
    // posture exists), and the entries are the same rosters the closed
    // world teaches (VocabularyProse over the vocabulary roster).
    $stylable = $guide['stylable'];
    $this->assertSame(['guidance', 'entries'], array_keys($stylable));
    $this->assertStringStartsWith('Each element kind\'s roster is the properties its design system names; any other recognized CSS property with a valid value is accepted too', $stylable['guidance']);
    $this->assertStringContainsString('kinds.container for layouts', $stylable['guidance']);
    $this->assertStringContainsString('padding', $stylable['entries']['kinds.container']);
    $this->assertStringContainsString('font-size', $stylable['entries']['block_types.testimonial::.testimonial__quote']);
  }

  /**
   * A closed-world site teaches the same entries under the closed sentence.
   */
  public function testSchemaAdditionsClosedWorld(): void {
    $this->enforce('strict');
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($this->definition);
    $guide = $handler->getSchemaAdditions($this->definition)['style_guide'];

    // The same shape in every posture: the roster sentence is the one
    // thing the posture changes.
    $this->assertSame([
      'bindings',
      'deviations',
      'stylable',
      'guidance',
      'conditions',
      'current_values',
      'anatomy',
    ], array_keys($guide));

    // The stylable entries are what each declared place may style, by
    // PROPERTY NAME (the misses models make are "CSS they know" inside a
    // granted group), rendered by the one prose generator over the
    // vocabulary roster: the widest roster in full, the others as deltas
    // against it, parts and children flat at the map's own key grammar.
    // Constraints never serialize here; narrowing is the deviations story.
    $stylable = $guide['stylable'];
    $this->assertStringStartsWith('Each element styles ONLY the properties its kind permits (everything else is refused). ', $stylable['guidance']);
    $entries = $stylable['entries'];
    $rosters = $this->container->get('daedalus_stylish.vocabulary_roster');
    $container = $rosters->build(Address::kind('container'))->granted();
    $this->assertContains('padding', $container);
    $this->assertNotContains('font-size', $container);
    $this->assertSame(implode(', ', $container), $entries['kinds.container']);
    // The container's children pseudo-part grants the layout group.
    $this->assertArrayHasKey('kinds.container::> *', $entries);
    $this->assertStringNotContainsString('padding', $entries['kinds.container::> *']);
    // Blocks carry motion, effects and shadow from daedalus_stylish's own block
    // declaration beside the fixture's spacing: the widest roster, so it
    // prints in full and leads.
    $this->assertSame('kinds.block', array_key_first($entries));
    $this->assertStringContainsString('animation-name', $entries['kinds.block']);
    $this->assertStringContainsString('padding', $entries['kinds.block']);

    // Refinements print against the base; a refinement's part is its own
    // entry at the map's suffix grammar; a place that restates its kind's
    // is omitted (fancy_menu narrows font-size but grants what the block
    // kind plus typography grants, so its root prints as the same delta
    // the testimonial prints).
    $this->assertStringStartsWith('the kinds.block roster, plus ', $entries['block_types.testimonial']);
    $this->assertStringContainsString('font-size', $entries['block_types.testimonial']);
    $this->assertStringContainsString('font-size', $entries['block_types.testimonial::.testimonial__quote']);
    $this->assertStringContainsString('max-width', $entries['layouts.layout_twocol_section']);
    $this->assertStringContainsString('max-width', $entries['layouts.layout_twocol_section::.band-content']);
    $this->assertArrayHasKey('layouts.layout_twocol_section::.band-content > *', $entries);
    $this->assertArrayNotHasKey('layouts.layout_onecol', $entries, 'A layout restating the container kind is omitted.');

    // Constraint law still reads from bindings + deviations, same lines
    // as the open world; the polarity sentence carries the closure. The
    // variant-axis and default-variant vocabulary is the materials
    // roster's business now, not the closed world's.
    $encoded = json_encode($guide);
    $this->assertStringNotContainsString('"constraints"', $encoded);
    $this->assertStringNotContainsString('variant_axes', $encoded);
    $this->assertStringNotContainsString('default_variants', $encoded);
    $this->assertStringStartsWith('padding at most 4rem; padding-top at most 4rem', $guide['deviations']['kinds.container']);
    $this->assertStringContainsString('Anything not listed is invalid', $guide['guidance']);

    // Mode opacity holds in the closed world too.
    $this->assertStringNotContainsStringIgnoringCase('strict', $encoded);
    $this->assertStringNotContainsStringIgnoringCase('guarded', $encoded);
    $this->assertStringNotContainsStringIgnoringCase('enforcement', $encoded);
  }

}
