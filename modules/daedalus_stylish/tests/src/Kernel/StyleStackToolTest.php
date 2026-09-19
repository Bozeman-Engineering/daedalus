<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stack projection and its AI read tool.
 *
 * StyleStack is the server twin of the code view's layered document: one
 * key's worn layers in DELIVERY order (axis-declaration order, own layer
 * last) with every (condition, property) marked winning or lost to the
 * last defining layer. explain_element_styles is the AI's read surface
 * over it, the code view's layered projection answered in text: layer
 * headings with axis and wearing (root vs distributed fragment), lost
 * markers naming the winner, part sub-stacks, stale references reported
 * honestly, and the defaults floor beneath the stack (the declaration's
 * own defaults, then the active skin's, for the captured scope's kind and
 * refinement) enumerated with lost marks, the skin's base:{tag} tier named
 * as the half that resolves by rendered tag in the browser.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleStackToolTest extends KernelTestBase {

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
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
    // Injects a composed container kind with declared defaults from state,
    // the declared tier's in-fixture source for the floor.
    'daedalus_stylish_control_test',
    'key',
    'ai',
    // The unbound site: the class writes literal paddings, gaps and sizes
    // on a site that mints nothing, and the suite's bindings would refuse
    // every one for a reason that is the scale's, not the claim's.
    'daedalus_stylish_unbound_test',
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
    $this->installEntitySchema('daedalus_variant');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    // Activating a design system (the floor's skin) syncs every enabled
    // module's shipped definitions, and the importers read all three.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    // Access-transparent like the operations suite: these tests verify the
    // projection and its formatting, not access.
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->uuid = $this->container->get('uuid')->generate();
  }

  /**
   * Creates a styling-enabled test entity with a style map.
   */
  protected function createStyledEntity(array $map): EntityTest {
    $entity = EntityTest::create(['name' => 'styled']);
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();
    return $entity;
  }

  /**
   * Runs the tool and returns its readable output.
   */
  protected function explain(EntityTest $entity, string $key): string {
    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->getFunctionCallFromFunctionName('explain_element_styles');
    $tool->setContextValue('entity_type', 'entity_test');
    $tool->setContextValue('entity_id', (string) $entity->id());
    $tool->setContextValue('key', $key);
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * The layered fixture: two axes worn out of order, overrides, a part.
   *
   * The map STORES the emphasis reference before the style reference, so
   * delivery-order projection (style axis first) is observable, and the
   * element's own padding shadows both variants' padding.
   */
  protected function layeredFixture(): array {
    $sleek = DaedalusStylishVariant::create(['label' => 'Sleek', 'machine_name' => 'sleek', 'scope' => 'block_type:testimonial']);
    $sleek->setStyles([
      '' => [
        'default' => ['padding' => '1rem', 'background-color' => '#ffffff'],
        'hover' => ['background-color' => '#eeeeee'],
      ],
      '::.testimonial__quote' => ['default' => ['font-size' => '1.5rem']],
    ]);
    $sleek->save();
    $loud = DaedalusStylishVariant::create([
      'label' => 'Loud',
      'machine_name' => 'loud',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
    ]);
    $loud->setStyles(['' => ['default' => ['padding' => '2rem', 'font-weight' => 'bold']]]);
    $loud->save();

    $key = "block:{$this->uuid}";
    $entity = $this->createStyledEntity([
      $key => [
        'variants' => [
          ['variant' => 'loud', 'fragment' => ''],
          ['variant' => 'sleek', 'fragment' => ''],
        ],
        'default' => ['padding' => '3rem'],
      ],
      "$key::.testimonial__quote" => ['default' => ['color' => 'blue']],
    ]);
    return [$entity, $key];
  }

  /**
   * The projection: delivery-ordered layers with winner/lost marks.
   */
  public function testStackProjection(): void {
    [$entity, $key] = $this->layeredFixture();
    $map = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $stack = $this->container->get('daedalus_stylish.style_stack');

    $layers = $stack->layers($map, $key);
    // Delivery order, not stored order: the style axis (sleek) delivers
    // first, emphasis (loud) over it, the own layer on top.
    $this->assertSame(['sleek', 'loud', NULL], array_column($layers, 'variant'));
    // Padding is set by all three layers; the own layer (index 2) wins and
    // every variant declaration is lost to it.
    $this->assertSame(['value' => '1rem', 'lost' => TRUE, 'winner' => 2], $layers[0]['styles']['default']['padding']);
    $this->assertSame(['value' => '2rem', 'lost' => TRUE, 'winner' => 2], $layers[1]['styles']['default']['padding']);
    $this->assertSame(['value' => '3rem', 'lost' => FALSE, 'winner' => NULL], $layers[2]['styles']['default']['padding']);
    // Single-layer properties win in place, conditions stay distinct.
    $this->assertFalse($layers[1]['styles']['default']['font-weight']['lost']);
    $this->assertFalse($layers[0]['styles']['hover']['background-color']['lost']);

    // The part key resolves through the ROOT's references at its relative
    // position, own part entry on top; both suffix sources are found.
    $part = $stack->layers($map, "$key::.testimonial__quote");
    $this->assertSame(['sleek', 'loud', NULL], array_column($part, 'variant'));
    $this->assertSame('1.5rem', $part[0]['styles']['default']['font-size']['value']);
    $this->assertSame([], $part[1]['styles'], 'A reference supplying nothing at the part reads empty.');
    $this->assertSame('blue', $part[2]['styles']['default']['color']['value']);
    $this->assertSame(['::.testimonial__quote'], $stack->partSuffixes($map, $key));
  }

  /**
   * The tool: the stack in text, winners named, parts appended.
   */
  public function testExplainElementStyles(): void {
    [$entity, $key] = $this->layeredFixture();
    $output = $this->explain($entity, $key);

    // Bottom-to-top delivery order in the text.
    $sleek = strpos($output, 'Variant "Sleek" (sleek)');
    $loud = strpos($output, 'Variant "Loud" (loud)');
    $own = strpos($output, "This element's own styles");
    $this->assertNotFalse($sleek);
    $this->assertNotFalse($loud);
    $this->assertNotFalse($own);
    $this->assertLessThan($loud, $sleek);
    $this->assertLessThan($own, $loud);

    // Layer headings carry the axis and the wearing.
    $this->assertStringContainsString('style axis, worn at the variant root', $output);
    $this->assertStringContainsString('emphasis axis, worn at the variant root', $output);

    // Lost declarations are marked with the winning layer; the winning
    // declaration is unmarked.
    $this->assertStringContainsString("padding: 1rem;   << lost to this element's own value (layer 3)", $output);
    $this->assertStringContainsString("padding: 2rem;   << lost to this element's own value (layer 3)", $output);
    $this->assertStringContainsString('padding: 3rem;', $output);
    $this->assertStringNotContainsString('padding: 3rem;   <<', $output);

    // Conditions print as their own blocks; the intro names both floor
    // tiers in delivery order (the declaration's own defaults, then the
    // active skin's over them) and the floor itself prints after the
    // stack, empty here (no declaration defaults kind:block, no skin).
    $this->assertStringContainsString('hover:', $output);
    $this->assertStringContainsString("Beneath the stack sits the defaults floor, listed after it: the element's declaration's own defaults", $output);
    $this->assertStringContainsString("then the active skin's defaults for the element's scope over them", $output);
    $floor = strpos($output, "Defaults floor for $key (scope kind:block), beneath every layer above:");
    $this->assertNotFalse($floor);
    $this->assertLessThan($floor, $own);
    $this->assertStringContainsString('(no declaration default is declared for kind:block at this key, and no skin is active)', $output);
    $this->assertStringContainsString('No skin is active, so no skin tier and no base:{tag} tier sit beneath these.', $output);
    $this->assertStringNotContainsString('not listed here', $output);

    // The part sub-stack rides the same read.
    $this->assertStringContainsString("Sub-element $key::.testimonial__quote:", $output);
    $this->assertStringContainsString('font-size: 1.5rem;', $output);
    $this->assertStringContainsString('color: blue;', $output);
  }

  /**
   * A wearing a composition distributed names its origin.
   *
   * The card scenario after the root's apply, on the testimonial scope
   * this class declares axes for: the child wears "row" via "Card Left"
   * (the map's provenance, stamped by distribution), beside an "emphatic"
   * the editor applied by hand. The projection carries the origin and its
   * label; the heading says "worn via", the hand-applied wearing still
   * reads as worn at the variant root, and a stale origin (its composition
   * since deleted) prints by machine name: inert, never hidden.
   */
  public function testOriginBearingLayer(): void {
    $row = DaedalusStylishVariant::create(['label' => 'Row', 'machine_name' => 'row', 'scope' => 'block_type:testimonial']);
    $row->setStyles(['' => ['default' => ['display' => 'flex']]]);
    $row->save();
    $emphatic = DaedalusStylishVariant::create([
      'label' => 'Emphatic',
      'machine_name' => 'emphatic',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
    ]);
    $emphatic->setStyles(['' => ['default' => ['font-weight' => 'bold']]]);
    $emphatic->save();
    $card = DaedalusStylishVariant::create(['label' => 'Card Left', 'machine_name' => 'card_left', 'scope' => 'kind:container']);
    $card->setStyles([
      '' => ['default' => ['gap' => '1rem']],
      'block(testimonial,1)' => ['variants' => [['variant' => 'row', 'fragment' => '']]],
    ]);
    $card->save();

    $key = "block:{$this->uuid}";
    $entity = $this->createStyledEntity([
      $key => [
        'variants' => [
          ['variant' => 'emphatic', 'fragment' => ''],
          ['variant' => 'row', 'fragment' => '', 'via' => 'card_left'],
        ],
      ],
    ]);
    $map = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $layers = $this->container->get('daedalus_stylish.style_stack')->layers($map, $key);

    // Delivery order (style axis first, emphasis over it, own on top);
    // only the distributed wearing carries an origin, resolved to its
    // label; the hand-applied wearing and the own layer carry none.
    $this->assertSame(['row', 'emphatic', NULL], array_column($layers, 'variant'));
    $this->assertSame('card_left', $layers[0]['via']);
    $this->assertSame('Card Left', $layers[0]['via_label']);
    $this->assertNull($layers[1]['via']);
    $this->assertNull($layers[1]['via_label']);
    $this->assertNull($layers[2]['via']);
    // The origin changes nothing about what the layer delivers.
    $this->assertSame('flex', $layers[0]['styles']['default']['display']['value']);

    $output = $this->explain($entity, $key);
    $this->assertStringContainsString('Variant "Row" (row), style axis, worn via "Card Left" (card_left)', $output);
    $this->assertStringContainsString('Variant "Emphatic" (emphatic), emphasis axis, worn at the variant root', $output);
    $this->assertStringNotContainsString('"Row" (row), style axis, worn at the variant root', $output);

    // A stale origin prints by machine name: the fact stays visible.
    $card->delete();
    $output = $this->explain($entity, $key);
    $this->assertStringContainsString('Variant "Row" (row), style axis, worn via "card_left"', $output);
  }

  /**
   * Distributed fragments, stale references, and the bare element.
   */
  public function testFragmentStaleAndBare(): void {
    $duo = DaedalusStylishVariant::create(['label' => 'Duo', 'machine_name' => 'duo', 'scope' => 'kind:container']);
    $duo->setStyles([
      '' => ['default' => ['gap' => '1rem']],
      'block(text,1)' => ['default' => ['color' => 'red']],
    ]);
    $duo->save();

    $child = $this->container->get('uuid')->generate();
    $entity = $this->createStyledEntity([
      "block:$child" => [
        'variants' => [
          ['variant' => 'duo', 'fragment' => 'block(text,1)'],
          ['variant' => 'ghost', 'fragment' => ''],
        ],
      ],
    ]);

    // A distributed fragment names its slot; its styles read through ITS
    // OWN fragment, so the child sees color, never the root's gap.
    $output = $this->explain($entity, "block:$child");
    $this->assertStringContainsString('distributed fragment "block(text,1)" of a composition', $output);
    $this->assertStringContainsString('color: red;', $output);
    $this->assertStringNotContainsString('gap', $output);
    // The stale reference reports honestly and delivers nothing.
    $this->assertStringContainsString('Variant "ghost"', $output);
    $this->assertStringContainsString('(stale: the referenced variant no longer exists', $output);

    // A key carrying nothing says so instead of printing an empty stack,
    // and prints the floor that shapes it (empty here: no skin, and no
    // declaration defaults the container kind in this fixture).
    $bare = $this->explain($entity, "container:{$this->uuid}");
    $this->assertStringContainsString('carries no styles of its own and wears no variants: only the defaults floor below', $bare);
    $this->assertStringContainsString("Defaults floor for container:{$this->uuid} (scope kind:container), beneath every layer above:", $bare);
    $this->assertStringContainsString('No skin is active, so no skin tier and no base:{tag} tier sit beneath these.', $bare);

    // The key grammar still gates.
    $this->assertStringContainsString('not a valid style map key', $this->explain($entity, 'nonsense'));
  }

  /**
   * The floor: the declared and skin tiers for the scope, beneath the stack.
   *
   * A container under a skin whose kind:container block sets the gap, on a
   * site whose container kind (composed, so it owns its root) declares
   * display: grid. The projection reads both tiers for the captured scope
   * (kind:container: an entity_test host has no layout to refine by) in
   * rank order, declared beneath skin, and marks each declaration against
   * the tiers above it and the stack: the skin's display beats the
   * declaration's, the element's own gap beats the skin's default gap, and
   * the skin's narrow gap stands. The tool prints the floor after the
   * numbered stack in the stack's idiom, and the caveat names the tags the
   * skin carries base defaults for as the tier only the browser resolves.
   * The bare branch prints the same floor.
   */
  public function testDefaultsFloor(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['composed' => TRUE, 'defaults' => ['' => ['default' => ['display' => 'grid']]]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setDefaults([
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
      'kind:container' => [
        '' => [
          'default' => ['gap' => '1.5rem', 'display' => 'flex'],
          'narrow' => ['gap' => '0.5rem'],
        ],
      ],
      'base:p' => ['' => ['default' => ['line-height' => '1.6']]],
    ])->save();
    $this->activateSkin($skin);

    $key = "container:{$this->uuid}";
    $entity = $this->createStyledEntity([$key => ['default' => ['gap' => '2rem']]]);
    $map = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $floor = $this->container->get('daedalus_stylish.style_stack')->floor($map, $key, ['entity' => $entity]);

    $this->assertSame('kind:container', $floor['scope']);
    $this->assertTrue($floor['skin']);
    $this->assertSame(['h2', 'p'], $floor['base_tags']);
    $this->assertSame(
      [['declared', 'kind:container'], ['skin', 'kind:container']],
      array_map(static fn (array $tier): array => [$tier['tier'], $tier['scope']], $floor['tiers']),
    );
    // The declaration's display loses to the skin's (tier 1); the skin's
    // default gap loses to the own layer (stack index 0); its narrow gap
    // and its display stand.
    $this->assertSame(['value' => 'grid', 'lost' => TRUE, 'winner' => ['tier' => 1]], $floor['tiers'][0]['styles']['default']['display']);
    $this->assertSame(['value' => '1.5rem', 'lost' => TRUE, 'winner' => ['layer' => 0]], $floor['tiers'][1]['styles']['default']['gap']);
    $this->assertSame(['value' => 'flex', 'lost' => FALSE, 'winner' => NULL], $floor['tiers'][1]['styles']['default']['display']);
    $this->assertSame(['value' => '0.5rem', 'lost' => FALSE, 'winner' => NULL], $floor['tiers'][1]['styles']['narrow']['gap']);

    // The tool prints the floor beneath the stack: the own layer first,
    // then the floor heading, the declared tier under the skin's.
    $output = $this->explain($entity, $key);
    $own = strpos($output, "This element's own styles");
    $heading = strpos($output, "Defaults floor for $key (scope kind:container), beneath every layer above:");
    $declared = strpos($output, 'The declared defaults of kind:container (code, not editable):');
    $skin_tier = strpos($output, "The active skin's defaults for kind:container:");
    $this->assertNotFalse($own);
    $this->assertNotFalse($heading);
    $this->assertNotFalse($declared);
    $this->assertNotFalse($skin_tier);
    $this->assertLessThan($heading, $own);
    $this->assertLessThan($declared, $heading);
    $this->assertLessThan($skin_tier, $declared);
    $this->assertStringContainsString('gap: 2rem;', $output);
    $this->assertStringNotContainsString('gap: 2rem;   <<', $output);
    $this->assertStringContainsString("display: grid;   << lost to the active skin's defaults for kind:container (floor)", $output);
    $this->assertStringContainsString("gap: 1.5rem;   << lost to this element's own value (layer 1)", $output);
    $this->assertStringContainsString('display: flex;', $output);
    $this->assertStringNotContainsString('display: flex;   <<', $output);
    $this->assertStringContainsString("     narrow:\n       gap: 0.5rem;\n", $output);
    // The caveat names the base tags the skin carries as the tier only
    // the rendered tag resolves; the retired "not listed here" is gone.
    $this->assertStringContainsString("Beneath these, the active skin's base:{tag} defaults for h2, p apply by the element's RENDERED tag, a browser fact this read cannot resolve; the code view prints that tier for the selected element.", $output);
    $this->assertStringNotContainsString('not listed here', $output);

    // The bare branch prints the same floor, nothing lost to a stack.
    $bare_key = 'container:' . $this->container->get('uuid')->generate();
    $bare = $this->explain($entity, $bare_key);
    $this->assertStringContainsString("$bare_key carries no styles of its own and wears no variants: only the defaults floor below", $bare);
    $this->assertStringContainsString("Defaults floor for $bare_key (scope kind:container), beneath every layer above:", $bare);
    $this->assertStringContainsString('gap: 1.5rem;', $bare);
    $this->assertStringNotContainsString('gap: 1.5rem;   <<', $bare);
    $this->assertStringContainsString("display: grid;   << lost to the active skin's defaults for kind:container (floor)", $bare);
    $this->assertStringContainsString("base:{tag} defaults for h2, p apply", $bare);
  }

}
