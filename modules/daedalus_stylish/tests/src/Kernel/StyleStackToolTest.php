<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\StyleStack;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stack projection and its AI read tool.
 *
 * StyleStack is the server twin of the client style stack: one key's
 * layers in DELIVERY order, bottom to top, the declared and skin tiers
 * (one layer per declaring block of the captured scope) beneath the worn
 * references (axis-declaration order) beneath the element's own layer,
 * every layer's styles raw as stored, and linesAt() marking each
 * (condition, property) winning or lost to the last defining layer.
 * explain_element_styles is the AI's read surface over it, the layered
 * projection answered in text as ONE numbered list: floor headings naming
 * the declaring block, variant headings with axis and wearing (root vs
 * distributed fragment vs via), lost markers naming the winner by layer
 * number, part and rule sub-stacks, stale references reported honestly,
 * and the skin's base:{tag} tier placed by the read subject's tag from
 * the tree (a layer by number, or why none sits beneath). One theme
 * answers a read: the reference order and the
 * declared tier resolve through the theme passed in, defaulted once. With
 * the host's tree, the ancestor tier joins: every ancestor rule the
 * selector matcher says reaches the key, beneath the worn references.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleStackToolTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * The theme declaring a third testimonial axis and a container default.
   */
  protected const THEME = 'daedalus_stylish_stack_test_theme';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'block',
    'block_content',
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
    'daedalus_layout',
    'daedalus_stylish',
    // The container layout (eb_test_container) the ancestor fixture places
    // its block in: a test layout, so the container kind's declared
    // defaults the one-theme read pins stay exactly the state's.
    'daedalus_blueprint_test',
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
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
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
   * The entity's stored map.
   */
  protected function mapOf(EntityTest $entity): array {
    return $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
  }

  /**
   * The stack service.
   */
  protected function styleStack(): StyleStack {
    return $this->container->get('daedalus_stylish.style_stack');
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
   * Declares the composed container kind with a display default in state.
   */
  protected function declareContainerDefault(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['composed' => TRUE, 'defaults' => ['' => ['default' => ['display' => 'grid']]]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
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
   * The projection: delivery-ordered raw layers, winners read per condition.
   */
  public function testStackProjection(): void {
    [$entity, $key] = $this->layeredFixture();
    $map = $this->mapOf($entity);
    $stack = $this->styleStack()->stack($map, $key, ['entity' => $entity]);

    $this->assertSame($key, $stack['key']);
    $this->assertSame($key, $stack['rootKey']);
    $this->assertSame('', $stack['relative']);
    $this->assertSame('kind:block', $stack['scope']);
    $this->assertFalse($stack['skin']);
    $this->assertSame([], $stack['baseTags']);
    // Delivery order, not stored order: the style axis (sleek) delivers
    // first, emphasis (loud) over it, the own layer on top. No floor tier
    // declares anything for kind:block, so no floor layer exists.
    $this->assertSame(['variant:sleek', 'variant:loud', 'element'], array_column($stack['layers'], 'id'));
    $this->assertSame(['variant', 'variant', 'element'], array_column($stack['layers'], 'tier'));
    $this->assertSame(['sleek', 'loud', NULL], array_column($stack['layers'], 'variant'));
    $this->assertSame([FALSE, FALSE, TRUE], array_column($stack['layers'], 'editable'));
    $this->assertSame(['block_type:testimonial', 'block_type:testimonial', NULL], array_column($stack['layers'], 'scope'));
    $this->assertSame(['style', 'emphasis', NULL], array_column($stack['layers'], 'axis'));
    // Styles are raw: the stored conditions and values, no marks inline.
    $this->assertSame([
      'default' => ['padding' => '1rem', 'background-color' => '#ffffff'],
      'hover' => ['background-color' => '#eeeeee'],
    ], $stack['layers'][0]['styles']);
    $this->assertSame(['default' => ['padding' => '2rem', 'font-weight' => 'bold']], $stack['layers'][1]['styles']);
    $this->assertSame(['default' => ['padding' => '3rem']], $stack['layers'][2]['styles']);

    // linesAt(): padding is set by all three layers at default; the own
    // layer (index 2) wins and every variant declaration is lost to it,
    // by index. Single-layer properties win in place; conditions stay
    // distinct (hover has its one line).
    $this->assertSame([
      ['layer' => 0, 'property' => 'padding', 'value' => '1rem', 'lost' => TRUE, 'winner' => 2],
      ['layer' => 0, 'property' => 'background-color', 'value' => '#ffffff', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 1, 'property' => 'padding', 'value' => '2rem', 'lost' => TRUE, 'winner' => 2],
      ['layer' => 1, 'property' => 'font-weight', 'value' => 'bold', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 2, 'property' => 'padding', 'value' => '3rem', 'lost' => FALSE, 'winner' => NULL],
    ], $this->styleStack()->linesAt($stack, 'default'));
    $this->assertSame([
      ['layer' => 0, 'property' => 'background-color', 'value' => '#eeeeee', 'lost' => FALSE, 'winner' => NULL],
    ], $this->styleStack()->linesAt($stack, 'hover'));
    $this->assertSame([], $this->styleStack()->linesAt($stack, 'narrow'));

    // The part key resolves through the ROOT's references at its relative
    // position, own part entry on top; both suffix sources are found.
    $part = $this->styleStack()->stack($map, "$key::.testimonial__quote", ['entity' => $entity]);
    $this->assertSame($key, $part['rootKey']);
    $this->assertSame('::.testimonial__quote', $part['relative']);
    $this->assertSame(['sleek', 'loud', NULL], array_column($part['layers'], 'variant'));
    $this->assertSame(['default' => ['font-size' => '1.5rem']], $part['layers'][0]['styles']);
    $this->assertSame([], $part['layers'][1]['styles'], 'A reference supplying nothing at the part reads empty.');
    $this->assertSame(['default' => ['color' => 'blue']], $part['layers'][2]['styles']);
    $this->assertSame(['::.testimonial__quote'], $this->styleStack()->partSuffixes($map, $key));

    // The key grammar gates the read.
    $this->expectException(\InvalidArgumentException::class);
    $this->styleStack()->stack($map, 'nonsense');
  }

  /**
   * The tool: the stack in text, winners named, parts appended.
   */
  public function testExplainElementStyles(): void {
    [$entity, $key] = $this->layeredFixture();
    $output = $this->explain($entity, $key);

    // Bottom-to-top delivery order in the text, one numbered list.
    $sleek = strpos($output, '1. Variant "Sleek" (sleek)');
    $loud = strpos($output, '2. Variant "Loud" (loud)');
    $own = strpos($output, "3. This element's own styles");
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

    // Conditions print as their own blocks; the intro names the scope and
    // both floor tiers in delivery order (the declaration's own defaults,
    // then the active skin's over them) as the lowest layers of the one
    // list; with nothing declared for kind:block and no skin the list
    // says so before the worn layers, and the caveat closes it.
    $this->assertStringContainsString('hover:', $output);
    $this->assertStringContainsString("Style stack for $key (scope kind:block), bottom layer first;", $output);
    $this->assertStringContainsString("The lowest layers are the defaults floor: the element's declaration's own defaults", $output);
    $this->assertStringContainsString("then the active skin's defaults for the element's scope over them", $output);
    $floorless = strpos($output, '(no declaration default is declared for kind:block at this key, and no skin is active)');
    $this->assertNotFalse($floorless);
    $this->assertLessThan($sleek, $floorless);
    $this->assertStringContainsString('No skin is active, so no skin tier and no base:{tag} tier sit beneath these.', $output);
    $this->assertStringNotContainsString('Defaults floor for', $output);
    $this->assertStringNotContainsString('(floor)', $output);

    // The part sub-stack rides the same read, named a part.
    $this->assertStringContainsString("Part .testimonial__quote ($key::.testimonial__quote):", $output);
    $this->assertStringNotContainsString('Sub-element', $output);
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
    $layers = $this->styleStack()->stack($this->mapOf($entity), $key, ['entity' => $entity])['layers'];

    // Delivery order (style axis first, emphasis over it, own on top);
    // only the distributed wearing carries an origin, resolved to its
    // label; the hand-applied wearing and the own layer carry none.
    $this->assertSame(['row', 'emphatic', NULL], array_column($layers, 'variant'));
    $this->assertSame('card_left', $layers[0]['via']);
    $this->assertSame('Card Left', $layers[0]['viaLabel']);
    $this->assertNull($layers[1]['via']);
    $this->assertNull($layers[1]['viaLabel']);
    $this->assertNull($layers[2]['via']);
    // The origin changes nothing about what the layer delivers.
    $this->assertSame('flex', $layers[0]['styles']['default']['display']);

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
    // OWN fragment, so the child sees color, never the root's gap. A
    // stale reference is still a layer: it reports honestly and delivers
    // nothing.
    $layers = $this->styleStack()->stack($this->mapOf($entity), "block:$child", ['entity' => $entity])['layers'];
    $this->assertSame(['variant:duo', 'variant:ghost', 'element'], array_column($layers, 'id'));
    $this->assertSame([TRUE, FALSE, TRUE], array_column($layers, 'resolved'));
    $this->assertSame(['default' => ['color' => 'red']], $layers[0]['styles']);
    $this->assertSame([], $layers[1]['styles']);
    $output = $this->explain($entity, "block:$child");
    $this->assertStringContainsString('distributed fragment "block(text,1)" of a composition', $output);
    $this->assertStringContainsString('color: red;', $output);
    $this->assertStringNotContainsString('gap', $output);
    $this->assertStringContainsString('Variant "ghost"', $output);
    $this->assertStringContainsString('(stale: the referenced variant no longer exists', $output);

    // A key carrying nothing says so instead of printing a full intro, and
    // prints the same one list (empty here: no skin, and no declaration
    // defaults the container kind in this fixture, so the own layer is the
    // whole stack and says it shadows nothing).
    $bare = $this->explain($entity, "container:{$this->uuid}");
    $this->assertStringContainsString('carries no styles of its own and wears no variants: only the defaults floor and the ancestor rules below', $bare);
    $this->assertStringContainsString('(no declaration default is declared for kind:container at this key, and no skin is active)', $bare);
    $this->assertStringContainsString("1. This element's own styles (overrides, the top layer)\n   (no own properties: nothing shadows the layers below)", $bare);
    $this->assertStringContainsString('No skin is active, so no skin tier and no base:{tag} tier sit beneath these.', $bare);
    $this->assertStringNotContainsString('Style stack for', $bare);

    // The key grammar still gates.
    $this->assertStringContainsString('not a valid style map key', $this->explain($entity, 'nonsense'));
  }

  /**
   * The floor: the declared and skin tiers as the stack's lowest layers.
   *
   * A container under a skin whose kind:container block sets the gap, on a
   * site whose container kind (composed, so it owns its root) declares
   * display: grid. The projection reads both tiers for the captured scope
   * (kind:container: an entity_test host has no layout to refine by) in
   * rank order, declared beneath skin, each its own layer with raw styles,
   * and linesAt() marks each declaration against the layers above it: the
   * skin's display beats the declaration's, the element's own gap beats
   * the skin's default gap, and the skin's narrow gap stands. The skin's
   * base:{tag} block joins the stack only when the blueprint tree names
   * the subject's rendered tag (its `_element.markup.tag` annotation),
   * beneath the skin's scoped blocks; without a tree baseTags names the
   * tags the skin carries. The tool prints the floor layers as
   * the first entries of the one numbered list, the closing line names
   * the base tags and why none is a layer for this subject, and the bare
   * branch prints the same layers.
   */
  public function testDefaultsFloor(): void {
    $this->declareContainerDefault();
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
    $map = $this->mapOf($entity);
    $stack = $this->styleStack()->stack($map, $key, ['entity' => $entity]);

    $this->assertSame('kind:container', $stack['scope']);
    $this->assertTrue($stack['skin']);
    $this->assertSame(['h2', 'p'], $stack['baseTags']);
    $this->assertSame(['declared:kind:container', 'skin:kind:container', 'element'], array_column($stack['layers'], 'id'));
    $this->assertSame(['declared', 'skin', 'element'], array_column($stack['layers'], 'tier'));
    $this->assertSame(['kind:container', 'kind:container', NULL], array_column($stack['layers'], 'scope'));
    $this->assertSame([FALSE, FALSE, TRUE], array_column($stack['layers'], 'editable'));
    $this->assertSame(['default' => ['display' => 'grid']], $stack['layers'][0]['styles']);
    $this->assertSame([
      'default' => ['gap' => '1.5rem', 'display' => 'flex'],
      'narrow' => ['gap' => '0.5rem'],
    ], $stack['layers'][1]['styles']);
    // The declaration's display loses to the skin's (layer 1); the skin's
    // default gap loses to the own layer (2); its display stands, and so
    // does its narrow gap at its own condition.
    $this->assertSame([
      ['layer' => 0, 'property' => 'display', 'value' => 'grid', 'lost' => TRUE, 'winner' => 1],
      ['layer' => 1, 'property' => 'gap', 'value' => '1.5rem', 'lost' => TRUE, 'winner' => 2],
      ['layer' => 1, 'property' => 'display', 'value' => 'flex', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 2, 'property' => 'gap', 'value' => '2rem', 'lost' => FALSE, 'winner' => NULL],
    ], $this->styleStack()->linesAt($stack, 'default'));
    $this->assertSame([
      ['layer' => 1, 'property' => 'gap', 'value' => '0.5rem', 'lost' => FALSE, 'winner' => NULL],
    ], $this->styleStack()->linesAt($stack, 'narrow'));

    // With a tree naming the container's rendered tag, the skin's base
    // block is a layer of its own beneath the skin's scoped blocks; a tag
    // the skin carries no block for adds nothing.
    $tree_rooted_at = fn (string $tag): array => [
      [
        'kind' => 'container',
        'uuid' => $this->uuid,
        'layout_id' => 'daedalus_stylish_container',
        '_element' => ['markup' => ['tag' => $tag]],
        'children' => [],
      ],
    ];
    $tagged = $this->styleStack()->stack($map, $key, ['entity' => $entity], NULL, $tree_rooted_at('h2'));
    $this->assertSame(['declared:kind:container', 'skin:base:h2', 'skin:kind:container', 'element'], array_column($tagged['layers'], 'id'));
    $this->assertSame('skin', $tagged['layers'][1]['tier']);
    $this->assertSame('base:h2', $tagged['layers'][1]['scope']);
    $this->assertSame(['default' => ['font-size' => '2rem']], $tagged['layers'][1]['styles']);
    $untagged = $this->styleStack()->stack($map, $key, ['entity' => $entity], NULL, $tree_rooted_at('div'));
    $this->assertSame(['declared:kind:container', 'skin:kind:container', 'element'], array_column($untagged['layers'], 'id'));
    // A rule of the container's own reads no base layer: its matches are
    // many, so no one tag stands for them.
    $rule = $this->styleStack()->stack($map, "$key::> *", ['entity' => $entity], NULL, $tree_rooted_at('h2'));
    $this->assertSame(['element'], array_column($rule['layers'], 'id'));

    // The tool prints the one list: the declared layer, the skin's layer,
    // then the own layer, each lost mark naming its winner by number.
    $output = $this->explain($entity, $key);
    $declared = strpos($output, '1. The declared defaults of kind:container (code, not editable)');
    $skin_layer = strpos($output, "2. The active skin's defaults for kind:container");
    $own = strpos($output, "3. This element's own styles");
    $this->assertNotFalse($declared);
    $this->assertNotFalse($skin_layer);
    $this->assertNotFalse($own);
    $this->assertLessThan($skin_layer, $declared);
    $this->assertLessThan($own, $skin_layer);
    $this->assertStringContainsString("Style stack for $key (scope kind:container), bottom layer first;", $output);
    $this->assertStringContainsString('gap: 2rem;', $output);
    $this->assertStringNotContainsString('gap: 2rem;   <<', $output);
    $this->assertStringContainsString("display: grid;   << lost to the active skin's defaults for kind:container (layer 2)", $output);
    $this->assertStringContainsString("gap: 1.5rem;   << lost to this element's own value (layer 3)", $output);
    $this->assertStringContainsString('display: flex;', $output);
    $this->assertStringNotContainsString('display: flex;   <<', $output);
    $this->assertStringContainsString("   narrow:\n     gap: 0.5rem;\n", $output);
    $this->assertStringNotContainsString('(floor)', $output);
    $this->assertStringNotContainsString('Defaults floor for', $output);
    // The closing line names the base tags the skin carries and why none
    // is a layer here: the host's tree names no tag the skin carries a
    // block for.
    $this->assertStringContainsString("The active skin's base:{tag} defaults for h2, p resolve by the element's tag from the blueprint tree; this element's tag is none of them (or the tree names none), so none sits beneath these.", $output);

    // The bare branch prints the same layers, nothing lost to an own value.
    $bare_key = 'container:' . $this->container->get('uuid')->generate();
    $bare = $this->explain($entity, $bare_key);
    $this->assertStringContainsString("$bare_key carries no styles of its own and wears no variants: only the defaults floor and the ancestor rules below", $bare);
    $this->assertStringContainsString('1. The declared defaults of kind:container (code, not editable)', $bare);
    $this->assertStringContainsString("2. The active skin's defaults for kind:container", $bare);
    $this->assertStringContainsString("3. This element's own styles (overrides, the top layer)\n   (no own properties: nothing shadows the layers below)", $bare);
    $this->assertStringContainsString('gap: 1.5rem;', $bare);
    $this->assertStringNotContainsString('gap: 1.5rem;   <<', $bare);
    $this->assertStringContainsString("display: grid;   << lost to the active skin's defaults for kind:container (layer 2)", $bare);
    $this->assertStringContainsString("base:{tag} defaults for h2, p resolve by the element's tag from the blueprint tree", $bare);
  }

  /**
   * One theme answers a read: the reference order and the declared tier.
   *
   * The stack test theme declares a third testimonial axis ("tone",
   * before "emphasis") and a container default (gap) no other theme has.
   * Made the site default, it is the theme a read defaults to ONCE for
   * both facts: a tone variant delivers before the emphasis one and the
   * container's declared layer carries the gap. Passing another theme
   * moves BOTH together: under stark the tone reference is unplaced and
   * sorts after every placed one, and the declared layer carries only the
   * state-injected display. Neither fact may come from a fallback of its
   * own (the reference order from the default theme while the declared
   * tier reads the active theme), which is what the two reads together
   * pin.
   */
  public function testOneThemePerRead(): void {
    $this->declareContainerDefault();
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->config('system.theme')->set('default', self::THEME)->save();
    $this->container->get('daedalus_stylish.style_guide')->resetCache();

    $sleek = DaedalusStylishVariant::create(['label' => 'Sleek', 'machine_name' => 'sleek', 'scope' => 'block_type:testimonial']);
    $sleek->setStyles(['' => ['default' => ['padding' => '1rem']]]);
    $sleek->save();
    $loud = DaedalusStylishVariant::create(['label' => 'Loud', 'machine_name' => 'loud', 'scope' => 'block_type:testimonial', 'axis' => 'emphasis']);
    $loud->setStyles(['' => ['default' => ['font-weight' => 'bold']]]);
    $loud->save();
    // The tone axis exists only under the stack test theme: the site
    // default is what the entity's own axis check reads.
    $toned = DaedalusStylishVariant::create(['label' => 'Toned', 'machine_name' => 'toned', 'scope' => 'block_type:testimonial', 'axis' => 'tone']);
    $toned->setStyles(['' => ['default' => ['color' => 'teal']]]);
    $toned->save();

    $block_key = "block:{$this->uuid}";
    $container_key = 'container:' . $this->container->get('uuid')->generate();
    $entity = $this->createStyledEntity([
      $block_key => [
        'variants' => [
          ['variant' => 'toned', 'fragment' => ''],
          ['variant' => 'loud', 'fragment' => ''],
          ['variant' => 'sleek', 'fragment' => ''],
        ],
      ],
      $container_key => ['default' => ['gap' => '2rem']],
    ]);
    $map = $this->mapOf($entity);
    $stack = $this->styleStack();
    $context = ['entity' => $entity];

    // Defaulted once to the site theme: tone before emphasis, gap declared.
    $this->assertSame(['sleek', 'toned', 'loud', NULL], array_column($stack->stack($map, $block_key, $context)['layers'], 'variant'));
    $declared = $stack->stack($map, $container_key, $context)['layers'][0];
    $this->assertSame('declared:kind:container', $declared['id']);
    $this->assertSame('1rem', $declared['styles']['default']['gap']);
    $this->assertSame('grid', $declared['styles']['default']['display']);

    // The theme passed in answers both: under stark the unplaced tone
    // reference sorts last and the declared layer carries no gap.
    $this->assertSame(['sleek', 'loud', 'toned', NULL], array_column($stack->stack($map, $block_key, $context, 'stark')['layers'], 'variant'));
    $declared = $stack->stack($map, $container_key, $context, 'stark')['layers'][0];
    $this->assertSame('declared:kind:container', $declared['id']);
    $this->assertSame(['default' => ['display' => 'grid']], $declared['styles']);

    // The part-suffix read orders through the same theme.
    $this->assertSame([], $stack->partSuffixes($map, $block_key, 'stark'));

    // The tool reads the site default: the one list in that theme's order.
    $output = $this->explain($entity, $block_key);
    $this->assertLessThan(strpos($output, '2. Variant "Toned" (toned), tone axis'), strpos($output, '1. Variant "Sleek" (sleek), style axis'));
    $this->assertLessThan(strpos($output, '3. Variant "Loud" (loud), emphasis axis'), strpos($output, '2. Variant "Toned" (toned), tone axis'));
  }

  /**
   * The ancestor tier: a container's rules reaching the block inside it.
   *
   * A container section (the container layout: its children mount at
   * the root) holding one testimonial block. The container's
   * `> pb-testimonial` color and its `> *` outline under `first-child`
   * (which the lone block satisfies, so the block folds into default)
   * reach the block's root; its `hover+only-child` compound delivers at
   * hover; a `.testimonial__quote` part-first entry is the container's
   * own anatomy and reaches nothing.
   * The stack lists the container's reaching rules farthest first between
   * the skin tier and the block's own entry; the tool prints each as an
   * inherited layer naming the rule and the key it is edited at, the
   * block's own value marked over it; the container itself, a root, has
   * no ancestor layers. Without the tree (the stack read directly with
   * none) the tier is absent.
   */
  public function testAncestorLayers(): void {
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    BlockContentType::create(['id' => 'testimonial', 'label' => 'Testimonial', 'revision' => TRUE])->save();
    $this->container->get('daedalus_stylish.style_guide')->resetCache();

    $block_uuid = $this->container->get('uuid')->generate();
    $block = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->create(['type' => 'testimonial', 'info' => 'A quote']);
    $section = new Section('eb_test_container', [], [
      new SectionComponent($block_uuid, 'content', [
        'id' => 'inline_block:testimonial',
        'provider' => 'layout_builder',
        'label' => 'A quote',
        'label_display' => 'visible',
        'view_mode' => 'full',
        'block_serialized' => serialize($block),
      ]),
    ], ['daedalus_layout' => ['uuid' => $this->uuid]]);
    $container_key = "container:{$this->uuid}";
    $block_key = "block:$block_uuid";
    $entity = EntityTest::create([
      'name' => 'host',
      'layout_builder__layout' => [$section],
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        "$container_key::> pb-testimonial" => ['default' => ['color' => 'red', 'padding' => '2rem']],
        "$container_key::> *" => [
          'first-child' => ['outline' => '1px solid'],
          'hover+only-child' => ['color' => 'white'],
        ],
        "$container_key::.testimonial__quote" => ['default' => ['font-style' => 'italic']],
        $block_key => ['default' => ['padding' => '1rem']],
      ]),
    ]);
    $entity->save();
    $map = $this->mapOf($entity);
    $backend = $this->container->get('daedalus_blueprint.backend_resolver')->resolve('entity_test');
    $tree = $this->container->get('daedalus_ui.element_annotator')->annotate($backend->serialize($entity), $entity, 'full')['_tree'];

    $stack = $this->styleStack()->stack($map, $block_key, ['entity' => $entity], NULL, $tree);
    $this->assertSame([
      "ancestor:$container_key::> pb-testimonial",
      "ancestor:$container_key::> *",
      'element',
    ], array_column($stack['layers'], 'id'));
    $this->assertSame(['ancestor', 'ancestor', 'element'], array_column($stack['layers'], 'tier'));
    $this->assertSame([$container_key, $container_key, NULL], array_column($stack['layers'], 'scope'));
    $this->assertSame([FALSE, FALSE, TRUE], array_column($stack['layers'], 'editable'));
    $this->assertSame(['default' => ['color' => 'red', 'padding' => '2rem']], $stack['layers'][0]['styles']);
    $this->assertSame(['default' => ['outline' => '1px solid'], 'hover' => ['color' => 'white']], $stack['layers'][1]['styles']);
    $this->assertSame([
      ['layer' => 0, 'property' => 'color', 'value' => 'red', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 0, 'property' => 'padding', 'value' => '2rem', 'lost' => TRUE, 'winner' => 2],
      ['layer' => 1, 'property' => 'outline', 'value' => '1px solid', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 2, 'property' => 'padding', 'value' => '1rem', 'lost' => FALSE, 'winner' => NULL],
    ], $this->styleStack()->linesAt($stack, 'default'));
    // Without the tree, no ancestor tier; the container is a root.
    $this->assertSame(['element'], array_column($this->styleStack()->stack($map, $block_key, ['entity' => $entity])['layers'], 'id'));
    $this->assertSame(['element'], array_column($this->styleStack()->stack($map, $container_key, ['entity' => $entity], NULL, $tree)['layers'], 'id'));

    // The tool: the container's rules as the layers beneath the block's
    // own styles, each named by its rule, its owner's minted name (the
    // element-naming spine: an unrenamed page band is "Section") and the
    // key it is edited at.
    $output = $this->explain($entity, $block_key);
    $rule = strpos($output, "1. Inherited from the rule \"> pb-testimonial\" on Section ($container_key): an ancestor's selector entry reaching this element, edited at that key");
    $children = strpos($output, "2. Inherited from the rule \"> *\" on Section ($container_key)");
    $own = strpos($output, "3. This element's own styles");
    $this->assertNotFalse($rule);
    $this->assertNotFalse($children);
    $this->assertNotFalse($own);
    $this->assertLessThan($children, $rule);
    $this->assertLessThan($own, $children);
    $this->assertStringContainsString('color: red;', $output);
    $this->assertStringNotContainsString('color: red;   <<', $output);
    $this->assertStringContainsString("padding: 2rem;   << lost to this element's own value (layer 3)", $output);
    $this->assertStringContainsString("   default:\n     outline: 1px solid;\n   hover:\n     color: white;", $output);
    $this->assertStringContainsString('Above the floor sit the ancestor rules reaching this element', $output);
    $this->assertStringNotContainsString('font-style', $output);
    // A lost ancestor value names the ancestor rule as its winner.
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "$container_key::pb-testimonial" => ['default' => ['color' => 'teal']],
      "$container_key::> pb-testimonial" => ['default' => ['color' => 'red']],
    ]))->save();
    $output = $this->explain($entity, $block_key);
    $this->assertStringContainsString("color: teal;   << lost to the rule \"> pb-testimonial\" on Section ($container_key) (layer 2)", $output);
    $this->assertStringContainsString('carries no styles of its own and wears no variants: only the defaults floor and the ancestor rules below', $output);

    // A renamed band names its rules by its name: the section's custom
    // label (layout_settings.label, the tree's rename) is the minted
    // name the tool reads off the owner node, the same word the tree,
    // the chips and the stylish panel show for it.
    $entity->get('layout_builder__layout')->getSection(0)->setLayoutSettings(['label' => 'Welcome']);
    $entity->save();
    $output = $this->explain($entity, $block_key);
    $this->assertStringContainsString("1. Inherited from the rule \"pb-testimonial\" on Welcome ($container_key)", $output);
    $this->assertStringContainsString("color: teal;   << lost to the rule \"> pb-testimonial\" on Welcome ($container_key) (layer 2)", $output);
    $this->assertStringNotContainsString('the section', $output);
  }

  /**
   * Wear at a rule reads at the rule and inside its members' stacks.
   *
   * The container's `> pb-testimonial` rule wears "sleek" (a testimonial
   * look with a quote-part entry) over its own color. The rule's own
   * stack folds the worn root entry beneath the rule's entry, as a
   * root's does; the block's ancestor tier holds that rule as a GROUP,
   * the worn layer (sleek's identity, carried by the rule) beneath the
   * rule's own layer; the variant's part entry continues under the rule
   * by descent, so the block's quote part takes the continued position
   * as a group too (sleek's part entry carried by the rule, the
   * continued key's own entry over it) and the continued key reads it
   * as its own wear; the tool names a part a part and a rule a rule,
   * prints the member's worn ancestor layer beneath the rule's own
   * (both under the rule's heading), and lists the rule's worn layer
   * beneath its own at the rule.
   */
  public function testRuleWear(): void {
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    BlockContentType::create(['id' => 'testimonial', 'label' => 'Testimonial', 'revision' => TRUE])->save();
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $sleek = DaedalusStylishVariant::create(['label' => 'Sleek', 'machine_name' => 'sleek', 'scope' => 'block_type:testimonial']);
    $sleek->setStyles([
      '' => ['default' => ['padding' => '1rem', 'background-color' => '#ffffff'], 'hover' => ['background-color' => '#eeeeee']],
      '::.testimonial__quote' => ['default' => ['font-size' => '1.5rem']],
    ]);
    $sleek->save();

    $block_uuid = $this->container->get('uuid')->generate();
    $block = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->create(['type' => 'testimonial', 'info' => 'A quote']);
    $section = new Section('eb_test_container', [], [
      new SectionComponent($block_uuid, 'content', [
        'id' => 'inline_block:testimonial',
        'provider' => 'layout_builder',
        'label' => 'A quote',
        'label_display' => 'visible',
        'view_mode' => 'full',
        'block_serialized' => serialize($block),
      ]),
    ], ['daedalus_layout' => ['uuid' => $this->uuid]]);
    $container_key = "container:{$this->uuid}";
    $rule = "$container_key::> pb-testimonial";
    $block_key = "block:$block_uuid";
    $entity = EntityTest::create([
      'name' => 'host',
      'layout_builder__layout' => [$section],
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        $rule => ['variants' => [['variant' => 'sleek', 'fragment' => '']], 'default' => ['color' => 'red', 'padding' => '2rem']],
        "$rule .testimonial__quote" => ['default' => ['color' => 'plum']],
        $block_key => ['default' => ['padding' => '3rem']],
      ]),
    ]);
    $entity->save();
    $map = $this->mapOf($entity);
    $backend = $this->container->get('daedalus_blueprint.backend_resolver')->resolve('entity_test');
    $tree = $this->container->get('daedalus_ui.element_annotator')->annotate($backend->serialize($entity), $entity, 'full')['_tree'];
    $stack = $this->styleStack();

    // The rule's own stack: the worn root entry beneath the rule's entry.
    $own = $stack->stack($map, $rule, ['entity' => $entity], NULL, $tree);
    $this->assertSame(['variant:sleek', 'element'], array_column($own['layers'], 'id'));
    $this->assertSame(['default' => ['padding' => '1rem', 'background-color' => '#ffffff'], 'hover' => ['background-color' => '#eeeeee']], $own['layers'][0]['styles']);
    $this->assertSame('block_type:testimonial', $own['layers'][0]['scope']);
    $this->assertSame(['default' => ['color' => 'red', 'padding' => '2rem']], $own['layers'][1]['styles']);
    $this->assertSame([
      ['layer' => 0, 'property' => 'padding', 'value' => '1rem', 'lost' => TRUE, 'winner' => 1],
      ['layer' => 0, 'property' => 'background-color', 'value' => '#ffffff', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 1, 'property' => 'color', 'value' => 'red', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 1, 'property' => 'padding', 'value' => '2rem', 'lost' => FALSE, 'winner' => NULL],
    ], $stack->linesAt($own, 'default'));
    // The continued key: the variant's part entry, read under the rule.
    $continued = $stack->stack($map, "$rule .testimonial__quote", ['entity' => $entity], NULL, $tree);
    $this->assertSame(['variant:sleek', 'element'], array_column($continued['layers'], 'id'));
    $this->assertSame(['default' => ['font-size' => '1.5rem']], $continued['layers'][0]['styles']);
    $this->assertSame(['default' => ['color' => 'plum']], $continued['layers'][1]['styles']);
    // The suffix space: the continued selector first, then the map's.
    $this->assertSame(['::> pb-testimonial .testimonial__quote', '::> pb-testimonial'], $stack->partSuffixes($map, $container_key));

    // The block: the rule is a group in its ancestor tier, sleek's layer
    // (its identity kept, the rule its carrier, never a wearing of the
    // block) beneath the rule's own layer, the block's own entry on top;
    // own beats worn by order (the rule's padding over sleek's).
    $member = $stack->stack($map, $block_key, ['entity' => $entity], NULL, $tree);
    $this->assertSame(["ancestor:$rule|sleek", "ancestor:$rule", 'element'], array_column($member['layers'], 'id'));
    $this->assertSame(['ancestor', 'ancestor', 'element'], array_column($member['layers'], 'tier'));
    $this->assertSame(['sleek', NULL, NULL], array_column($member['layers'], 'variant'));
    $this->assertSame('Sleek', $member['layers'][0]['label']);
    $this->assertSame($container_key, $member['layers'][0]['scope']);
    $this->assertSame(['key' => $container_key, 'selector' => '> pb-testimonial'], $member['layers'][0]['carrier']);
    $this->assertSame(['default' => ['padding' => '1rem', 'background-color' => '#ffffff'], 'hover' => ['background-color' => '#eeeeee']], $member['layers'][0]['styles']);
    $this->assertSame(['default' => ['color' => 'red', 'padding' => '2rem']], $member['layers'][1]['styles']);
    // Every padding loses to the topmost definer (the block's own); the
    // rule's own value sits over the worn one by order.
    $this->assertSame([
      ['layer' => 0, 'property' => 'padding', 'value' => '1rem', 'lost' => TRUE, 'winner' => 2],
      ['layer' => 0, 'property' => 'background-color', 'value' => '#ffffff', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 1, 'property' => 'color', 'value' => 'red', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 1, 'property' => 'padding', 'value' => '2rem', 'lost' => TRUE, 'winner' => 2],
      ['layer' => 2, 'property' => 'padding', 'value' => '3rem', 'lost' => FALSE, 'winner' => NULL],
    ], $stack->linesAt($member, 'default'));
    $wornless = $stack->stack($map + [$block_key => []], $block_key, ['entity' => $entity], NULL, $tree);
    $this->assertSame(['default' => ['padding' => '3rem']], $wornless['layers'][2]['styles']);
    // The id grammar reads the position from either spelling, and the
    // variant from the worn one.
    $this->assertSame(['key' => $container_key, 'selector' => '> pb-testimonial'], $stack->ancestorRule("ancestor:$rule|sleek"));
    $this->assertSame(['key' => $container_key, 'selector' => '> pb-testimonial'], $stack->ancestorRule("ancestor:$rule"));
    $this->assertSame('sleek', $stack->ancestorVariant("ancestor:$rule|sleek"));
    $this->assertNull($stack->ancestorVariant("ancestor:$rule"));
    $this->assertNull($stack->ancestorRule('variant:sleek'));
    // Its quote part: the continued position is a group too, sleek's
    // part entry carried by the RULE (not the continued position, which
    // can wear nothing) beneath the continued key's own color.
    $part = $stack->stack($map, "$block_key::.testimonial__quote", ['entity' => $entity], NULL, $tree);
    $this->assertSame(["ancestor:$rule .testimonial__quote|sleek", "ancestor:$rule .testimonial__quote", 'element'], array_column($part['layers'], 'id'));
    $this->assertSame(['default' => ['font-size' => '1.5rem']], $part['layers'][0]['styles']);
    $this->assertSame(['key' => $container_key, 'selector' => '> pb-testimonial'], $part['layers'][0]['carrier']);
    $this->assertSame(['default' => ['color' => 'plum']], $part['layers'][1]['styles']);

    // The tool over the block: the rule's worn layer prints as its own
    // numbered layer beneath the rule's own, named as a variant worn by
    // the rule, the rule's own line unchanged, the block's own value on
    // top; the preamble says the rules' variants sit there too.
    $output = $this->explain($entity, $block_key);
    $this->assertStringContainsString("1. Variant \"Sleek\" (sleek) worn by the rule \"> pb-testimonial\" on Section ($container_key), reaching this element beneath the rule's own values (edited at that key)", $output);
    $this->assertStringContainsString("2. Inherited from the rule \"> pb-testimonial\" on Section ($container_key): an ancestor's selector entry reaching this element, edited at that key", $output);
    $this->assertStringContainsString("3. This element's own styles", $output);
    $this->assertStringContainsString('Above the floor sit the ancestor rules reaching this element (an ancestor\'s selector entry such as "> pb-text" or "p", edited at that ancestor\'s key) and the variants those rules wear (delivered here beneath the rule\'s own values; the rule wears them, this element does not)', $output);
    $this->assertStringContainsString("   default:\n     padding: 1rem;   << lost to this element's own value (layer 3)\n     background-color: #ffffff;\n   hover:\n     background-color: #eeeeee;", $output);
    $this->assertStringContainsString("   default:\n     color: red;\n     padding: 2rem;   << lost to this element's own value (layer 3)", $output);
    $this->assertStringNotContainsString('|sleek', $output);
    // The tool over the container: the rule's sub-stack lists the worn
    // layer beneath the rule's own, named a rule; the continued key too.
    $output = $this->explain($entity, $container_key);
    $rule_heading = strpos($output, "Rule > pb-testimonial ($rule):");
    $this->assertNotFalse($rule_heading);
    $this->assertStringContainsString("Rule > pb-testimonial .testimonial__quote ($rule .testimonial__quote):", $output);
    $worn = strpos($output, '1. Variant "Sleek" (sleek), style axis, worn at the variant root', $rule_heading);
    $own_heading = strpos($output, "2. This element's own styles", $rule_heading);
    $this->assertNotFalse($worn);
    $this->assertNotFalse($own_heading);
    $this->assertLessThan($own_heading, $worn);
    $this->assertStringContainsString("padding: 1rem;   << lost to this element's own value (layer 2)", $output);
    $this->assertStringNotContainsString('Sub-element', $output);
  }

  /**
   * The tool names a rule's worn variant by the variant and its carrier.
   *
   * Three carriers reach the block's group at `> pb-testimonial`: the
   * rule itself wears "sleek" (carrier the rule at its own position), the
   * section's root wears "duo", a container look whose `::> pb-testimonial`
   * entry reaches the block (carrier the section at its root), and the
   * block's quote part takes sleek's part entry continued under the rule
   * (carrier the rule, at a continued position). Each worn layer prints
   * as a variant worn by its carrier beneath the rule's own line, a lost
   * value names the worn winner through its carrier, the wear-only rule's
   * own line says it is listed for what it delivers, and a member that
   * wears nothing of its own is still reported bare: the rules' variants
   * are the rules', never the element's.
   */
  public function testWornAncestorHeadings(): void {
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    BlockContentType::create(['id' => 'testimonial', 'label' => 'Testimonial', 'revision' => TRUE])->save();
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $sleek = DaedalusStylishVariant::create(['label' => 'Sleek', 'machine_name' => 'sleek', 'scope' => 'block_type:testimonial']);
    $sleek->setStyles([
      '' => ['default' => ['padding' => '1rem', 'background-color' => '#ffffff']],
      '::.testimonial__quote' => ['default' => ['font-size' => '1.5rem']],
    ]);
    $sleek->save();
    $duo = DaedalusStylishVariant::create(['label' => 'Duo', 'machine_name' => 'duo', 'scope' => 'kind:container']);
    $duo->setStyles([
      '' => ['default' => ['gap' => '1rem']],
      '::> pb-testimonial' => ['default' => ['display' => 'flex', 'padding' => '0.5rem']],
    ]);
    $duo->save();

    $block_uuid = $this->container->get('uuid')->generate();
    $block = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->create(['type' => 'testimonial', 'info' => 'A quote']);
    $section = new Section('eb_test_container', [], [
      new SectionComponent($block_uuid, 'content', [
        'id' => 'inline_block:testimonial',
        'provider' => 'layout_builder',
        'label' => 'A quote',
        'label_display' => 'visible',
        'view_mode' => 'full',
        'block_serialized' => serialize($block),
      ]),
    ], ['daedalus_layout' => ['uuid' => $this->uuid]]);
    $container_key = "container:{$this->uuid}";
    $rule = "$container_key::> pb-testimonial";
    $block_key = "block:$block_uuid";
    $entity = EntityTest::create([
      'name' => 'host',
      'layout_builder__layout' => [$section],
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        $container_key => ['variants' => [['variant' => 'duo', 'fragment' => '']]],
        $rule => ['variants' => [['variant' => 'sleek', 'fragment' => '']], 'default' => ['padding' => '2rem']],
      ]),
    ]);
    $entity->save();
    $map = $this->mapOf($entity);
    $backend = $this->container->get('daedalus_blueprint.backend_resolver')->resolve('entity_test');
    $tree = $this->container->get('daedalus_ui.element_annotator')->annotate($backend->serialize($entity), $entity, 'full')['_tree'];

    // The group at the block: the rule's own wear, then the section's
    // root wear at the position, then the rule's entry; carriers as
    // the stack names them.
    $member = $this->styleStack()->stack($map, $block_key, ['entity' => $entity], NULL, $tree);
    $this->assertSame(["ancestor:$rule|sleek", "ancestor:$rule|duo", "ancestor:$rule", 'element'], array_column($member['layers'], 'id'));
    $this->assertSame(['key' => $container_key, 'selector' => '> pb-testimonial'], $member['layers'][0]['carrier']);
    $this->assertSame(['key' => $container_key, 'selector' => NULL], $member['layers'][1]['carrier']);

    $output = $this->explain($entity, $block_key);
    // The member wears nothing itself: the bare sentence stands.
    $this->assertStringContainsString("$block_key carries no styles of its own and wears no variants", $output);
    $sleek_line = strpos($output, "1. Variant \"Sleek\" (sleek) worn by the rule \"> pb-testimonial\" on Section ($container_key), reaching this element beneath the rule's own values (edited at that key)");
    $duo_line = strpos($output, "2. Variant \"Duo\" (duo) worn by Section ($container_key) at its root (its \"> pb-testimonial\" entry lands here), reaching this element beneath the rule's own values (edited at that key)");
    $rule_line = strpos($output, "3. Inherited from the rule \"> pb-testimonial\" on Section ($container_key): an ancestor's selector entry reaching this element, edited at that key");
    $own_line = strpos($output, "4. This element's own styles");
    $this->assertNotFalse($sleek_line);
    $this->assertNotFalse($duo_line);
    $this->assertNotFalse($rule_line);
    $this->assertNotFalse($own_line);
    $this->assertLessThan($duo_line, $sleek_line);
    $this->assertLessThan($rule_line, $duo_line);
    $this->assertLessThan($own_line, $rule_line);
    // Lost values name a worn winner through its carrier, and the rule's
    // own value by the rule.
    $this->assertStringContainsString("padding: 1rem;   << lost to the rule \"> pb-testimonial\" on Section ($container_key) (layer 3)", $output);
    $this->assertStringContainsString("padding: 0.5rem;   << lost to the rule \"> pb-testimonial\" on Section ($container_key) (layer 3)", $output);
    $this->assertStringContainsString('display: flex;', $output);
    $this->assertStringNotContainsString('display: flex;   <<', $output);
    $this->assertStringNotContainsString('|sleek', $output);
    $this->assertStringNotContainsString('|duo', $output);

    // The quote part: sleek's part entry arrives at a position continued
    // under the rule, the rule carrying it; the map holds nothing at the
    // continued position, so the rule's own line says why it is listed.
    $part_key = "$block_key::.testimonial__quote";
    $part = $this->styleStack()->stack($map, $part_key, ['entity' => $entity], NULL, $tree);
    $this->assertSame(["ancestor:$rule .testimonial__quote|sleek", "ancestor:$rule .testimonial__quote", 'element'], array_column($part['layers'], 'id'));
    $this->assertSame([], $part['layers'][1]['styles']);
    $output = $this->explain($entity, $part_key);
    $this->assertStringContainsString("1. Variant \"Sleek\" (sleek) worn by the rule \"> pb-testimonial\" on Section ($container_key) at \"> pb-testimonial .testimonial__quote\", reaching this element beneath the rule's own values (edited at that key)", $output);
    $this->assertStringContainsString("2. Inherited from the rule \"> pb-testimonial .testimonial__quote\" on Section ($container_key): an ancestor's selector entry reaching this element, edited at that key\n   (no values of its own at this position: the rule is listed for the variant it delivers here)", $output);
    $this->assertStringContainsString('font-size: 1.5rem;', $output);

    // A worn winner: an earlier rule's plain padding loses to sleek's,
    // carried by the wear-only rule after it (map order), and the winner
    // is named through its carrier; the wear-only rule's own line rides
    // its group empty.
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "$container_key::pb-testimonial" => ['default' => ['padding' => '4px']],
      $rule => ['variants' => [['variant' => 'sleek', 'fragment' => '']]],
    ]))->save();
    $output = $this->explain($entity, $block_key);
    $this->assertStringContainsString("1. Inherited from the rule \"pb-testimonial\" on Section ($container_key)", $output);
    $this->assertStringContainsString("padding: 4px;   << lost to \"Sleek\" through the rule \"> pb-testimonial\" on Section ($container_key) (layer 2)", $output);
    $this->assertStringContainsString("2. Variant \"Sleek\" (sleek) worn by the rule \"> pb-testimonial\" on Section ($container_key), reaching this element", $output);
    $this->assertStringContainsString("3. Inherited from the rule \"> pb-testimonial\" on Section ($container_key): an ancestor's selector entry reaching this element, edited at that key\n   (no values of its own at this position: the rule is listed for the variant it delivers here)", $output);
  }

}
