<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\daedalus_ai\ElementSelectionResolver;
use Drupal\daedalus_ai\RouteEntityResolver;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Materials\ElementMaterials;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the two context resolvers that feed entities into the AI prompt.
 *
 * ElementSelectionResolver (selected spine keys -> descriptions) and
 * RouteEntityResolver (a request path -> its canonical entity) are the two
 * model-neutral resolvers that decide which entity metadata reaches the LLM.
 * They share one safety invariant: NEVER surface the label/metadata of an
 * entity the current user cannot view. Because the selection data and the path
 * both ride in the client's DeepChat request, a regression here would leak
 * private content into the prompt. Other regressions break the prompt quietly:
 *
 * ElementSelectionResolver (the wire format carries spine keys and the
 * server parses them):
 * - Selections missing key/number are skipped (malformed client data must
 *   not become a broken description).
 * - Results are keyed and ksorted by selection 'number' so the numbered chips
 *   in the chat line up with the numbered prompt entries.
 * - With no element_resolver wired (the realistic CI configuration, since the
 *   optional daedalus_blueprint element_resolver may be absent), block and field
 *   keys fall back to key-vocabulary descriptions.
 * - THE ACCESS NET IS STRUCTURAL NOW: the resolver never loads entities from
 *   client-supplied identifiers — a key that names anything but the root
 *   entity's own elements resolves to a generic description carrying no
 *   entity metadata at all, and anatomy sub-element keys are rejected.
 * - The two styling facts ride separately: a `selector` is the SUBJECT
 *   facet under the element (the rows the user reads) and an `address`
 *   ({key, selector}) is the WRITE address (a rule on the element or an
 *   ancestor). The element described is always the selection's own key;
 *   the address is its own sentence naming the rule, its owner and the
 *   style-map key; an address outside the grammar, with an owner outside
 *   the vocabulary, or malformed is dropped.
 * - What an element WEARS rides its style stack: the reference layers of
 *   the root entity's stack for the key (a part key answers with its
 *   root's), each wearing rendered by label with its origin (applied here
 *   with its spelling, via a composition, or a fragment of one), so "the
 *   wide one" resolves and a distributed wearing is never read as applied
 *   here; an unrostered reference is not worn, and no value ever prints
 *   (the values are explain_element_styles' answer).
 * - Where a placement was PLACED FROM rides its entry too: the staged
 *   node's blueprint stamp resolves to the roster's record by id and label
 *   (so "update the blueprint this came from" needs no print read); a
 *   stamp the roster no longer serves is residue and is not named.
 *
 * RouteEntityResolver:
 * - Delegates to daedalus_ui' derivation spine (deriveEntityFromRoute),
 *   so it resolves any route the page builder considers "about one entity".
 * - Returns NULL for non-entity, external, and invalid paths.
 * - Gates on view access (an unviewable entity resolves to NULL).
 * - Caches per request (a second resolve of the same path returns the cached
 *   result even after the entity is deleted underneath it).
 *
 * Both resolvers are constructed directly with the element_resolver argument
 * set to NULL, which deliberately exercises the fallback branch (no rich
 * resolver) and avoids enabling the full daedalus_ai module just to read two plain
 * services.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class ContextResolverTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'entity_test',
    'daedalus_blueprint',
    // RouteEntityResolver delegates to daedalus_ui' derivation spine, so
    // daedalus_ui and its kernel-test dependency chain (see
    // DaedalusAiKernelTestBase for the chain's rationale) are required.
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'file',
    'daedalus_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    // layout_builder's entity-delete hook reads inline block usage.
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['filter', 'text']);

    // Routes must be built so the path validator can resolve
    // /entity_test/{id} to the entity.entity_test.canonical route.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Builds an ElementSelectionResolver with NO element_resolver (fallback).
   *
   * @return \Drupal\daedalus_ai\ElementSelectionResolver
   *   A resolver whose every key falls back to key-vocabulary descriptions.
   */
  private function selectionResolver(): ElementSelectionResolver {
    return new ElementSelectionResolver($this->container->get('daedalus_blueprint.host_resolver'), NULL);
  }

  /**
   * The host of a root entity, as the route resolver hands it over.
   */
  private function hostOf(object $root): HostInterface {
    return $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($root);
  }

  /**
   * Builds a fresh RouteEntityResolver (fresh per-request cache).
   *
   * @return \Drupal\daedalus_ai\RouteEntityResolver
   *   A resolver reading the live current_user proxy for access.
   */
  private function routeResolver(): RouteEntityResolver {
    return new RouteEntityResolver(
      $this->container->get('path.validator'),
      $this->container->get('daedalus_blueprint.access_checker'),
      $this->container->get('router.no_access_checks'),
      $this->container->get('daedalus_ui.ui'),
      $this->container->get('daedalus_blueprint.verdict_stamper'),
    );
  }

  /**
   * ElementSelectionResolver: skip, sort, fallback, and the access net.
   *
   * One method exercises the whole resolve() contract on a shared fixture: a
   * mixed spine-key selection list with malformed entries, out-of-order
   * numbers, a root-entity field key, and keys naming things outside the
   * root entity (which must resolve without leaking any entity metadata).
   */
  public function testElementSelectionSkipSortFallbackAndAccess(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $root = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create(['name' => 'Root Entity']);
    $root->save();
    $root_uuid = $root->uuid();

    $block_uuid = '11111111-1111-1111-1111-111111111111';
    $foreign_uuid = '22222222-2222-2222-2222-222222222222';
    $container_uuid = '33333333-3333-3333-3333-333333333333';
    $selections = [
      // Out of order on purpose: number 2 listed before number 1.
      ['key' => "field:$root_uuid:name", 'label' => 'Name', 'number' => 2],
      ['key' => "block:$block_uuid", 'label' => 'Block', 'number' => 1],
      // A field key whose entity qualifier is NOT the root entity: nothing
      // may be loaded for it — the description stays generic.
      ['key' => "field:$foreign_uuid:secret_field", 'label' => 'X', 'number' => 4],
      // An address: the styling target under the block spelled as a `::`
      // key resolves to the block plus the rule as its target.
      ['key' => "block:$block_uuid::.hero-image", 'label' => 'X', 'number' => 5],
      // The chips' wire for the same address: the owner key plus a
      // selector field, canonicalized through the grammar.
      ['key' => "container:$container_uuid", 'selector' => '>   pb-text', 'number' => 11],
      // A selector outside the grammar is dropped: the owner alone.
      ['key' => "container:$container_uuid", 'selector' => '[href]', 'number' => 12],
      // The two styling facts: a minted list item (the subject facet on
      // the block) styled at its container's `li` rule (the write
      // address). The element stays the block; the address is its own
      // sentence.
      [
        'key' => "block:$block_uuid",
        'selector' => '.s-abc',
        'address' => ['key' => "container:$container_uuid", 'selector' => 'li'],
        'number' => 13,
      ],
      // An address on the element itself (its own tag tier) with no facet.
      ['key' => "block:$block_uuid", 'address' => ['key' => "block:$block_uuid", 'selector' => '  p '], 'number' => 14],
      // An address outside the grammar, one whose owner is not in the
      // vocabulary, and one not shaped {key, selector}: each dropped, the
      // element described alone.
      [
        'key' => "block:$block_uuid",
        'address' => ['key' => "container:$container_uuid", 'selector' => '[href]'],
        'number' => 15,
      ],
      ['key' => "block:$block_uuid", 'address' => ['key' => 'bogus', 'selector' => 'li'], 'number' => 16],
      ['key' => "block:$block_uuid", 'address' => "container:$container_uuid::li", 'number' => 17],
      // The block placement's displayed label.
      ['key' => "block:$block_uuid:label", 'label' => 'Heading', 'number' => 6],
      // A container (a Layout Builder section, the container-model
      // vocabulary): container:{section_uuid}.
      ['key' => "container:$container_uuid", 'label' => 'Hero', 'number' => 8],
      // The retired section:/region: key spellings are not in the selectable
      // grammar (SelectableKey emits container:); they must resolve to
      // nothing, like any unknown prefix.
      ['key' => "section:$container_uuid", 'number' => 9],
      ['key' => "region:$container_uuid:content", 'number' => 10],
      // Malformed: missing 'number' -> skipped.
      ['key' => "block:$block_uuid"],
      // Malformed: missing 'key' -> skipped.
      ['number' => 3],
      // Malformed: a bare unknown prefix -> skipped.
      ['key' => 'bogus', 'number' => 7],
    ];

    $resolved = $this->selectionResolver()->resolve($selections, $this->hostOf($root));

    // The ksort put the kept selections in ascending number order despite the
    // input listing 2 first; the malformed and unknown-prefix entries
    // produced nothing.
    $this->assertSame([1, 2, 4, 5, 6, 8, 11, 12, 13, 14, 15, 16, 17], array_keys($resolved), 'Results are ksorted by selection number; malformed and retired-prefix keys are dropped.');

    // A subject facet describes its element and carries the rule as the
    // styling target, naming the style-map key styling acts at when no
    // address stands; the two spellings (a `::` key, a key plus selector)
    // read the same, the selector canonical; a selector outside the
    // grammar leaves the element alone.
    $this->assertStringContainsString('Block (UUID: ' . $block_uuid . ')', $resolved[5]['description']);
    $this->assertSame('the rule ".hero-image" under it (style-map key block:' . $block_uuid . '::.hero-image): styling acts on every element the rule matches, not on this element alone', $resolved[5]['target']);
    $this->assertArrayNotHasKey('address', $resolved[5], 'A subject alone carries no address.');
    $this->assertStringContainsString('Container (UUID: ' . $container_uuid . ')', $resolved[11]['description']);
    $this->assertSame('the rule "> pb-text" under it (style-map key container:' . $container_uuid . '::> pb-text): styling acts on every element the rule matches, not on this element alone', $resolved[11]['target']);
    $this->assertArrayNotHasKey('target', $resolved[12], 'A selector outside the grammar is dropped.');
    $this->assertArrayNotHasKey('target', $resolved[8], 'A bare key carries no target.');

    // The two facts: the element described is the SELECTION (the block,
    // never the address's container), the facet is the subject being
    // read, and the address names the rule, its owner (the ancestor's
    // kind and label) and the style-map key the write lands at.
    $this->assertStringContainsString('Block (UUID: ' . $block_uuid . ')', $resolved[13]['description'], 'The element stays the selection under an address.');
    $this->assertStringNotContainsString('Container', $resolved[13]['description']);
    $this->assertSame('the rule ".s-abc" under it (style-map key block:' . $block_uuid . '::.s-abc): the elements whose styles the user is reading; styling acts at the address below, not here', $resolved[13]['target']);
    $this->assertSame('styling acts at the rule "li" on its ancestor Container (UUID: ' . $container_uuid . ') (style-map key container:' . $container_uuid . '::li): every element it matches, this one included', $resolved[13]['address']);
    // An address on the element's own key: no facet, the owner is "this
    // element", the selector canonicalized.
    $this->assertArrayNotHasKey('target', $resolved[14]);
    $this->assertSame('styling acts at the rule "p" on this element (style-map key block:' . $block_uuid . '::p): every element it matches, this one included', $resolved[14]['address']);
    // Dropped addresses leave the element described alone.
    foreach ([15, 16, 17] as $number) {
      $this->assertArrayNotHasKey('address', $resolved[$number], "Address $number is dropped: outside the grammar, an unknown owner, or malformed.");
      $this->assertStringContainsString('Block (UUID: ' . $block_uuid . ')', $resolved[$number]['description']);
    }

    // The block falls back to the key-vocabulary description (no
    // element_resolver wired), including the root entity context.
    $this->assertStringContainsString('Block (UUID: ' . $block_uuid . ')', $resolved[1]['description']);
    $this->assertStringContainsString('entity_test', $resolved[1]['description']);

    // A root-entity field key names the field.
    $this->assertStringContainsString('name', $resolved[2]['description']);

    // THE ACCESS NET: the foreign field key resolves to a generic
    // description that carries the client-supplied key parts only — no
    // label, no loaded-entity metadata (the resolver never loads entities
    // from client identifiers).
    $this->assertStringContainsString('secret_field', $resolved[4]['description']);
    $this->assertStringNotContainsString('Root Entity', $resolved[4]['description']);

    // The label key describes the placement's displayed label.
    $this->assertStringContainsString('label', strtolower($resolved[6]['description']));
    $this->assertStringContainsString($block_uuid, $resolved[6]['description']);

    // A container selection falls back to the key-vocabulary description
    // (no element_resolver wired) and speaks container, not section/region.
    $this->assertStringContainsString('Container (UUID: ' . $container_uuid . ')', $resolved[8]['description']);
  }

  /**
   * ElementSelectionResolver: the worn materials ride each element's stack.
   *
   * Real variant entities (the stack resolves references through the
   * roster's entities, so a roster-only record is never worn) and a real
   * style map on the root entity, the card scenario after a composition's
   * apply: a container wearing "card_left" at its root, a block wearing
   * "row" via that composition (the map's stamped provenance), a block
   * wearing a fragment of the "duo" composition, and a block wearing
   * "button" at its root beside a stale reference the roster no longer
   * serves. A part key of the button block answers with its root's
   * wearing through ElementMaterials::worn() (the resolver rejects part
   * keys in selection state; the read still answers for one).
   */
  public function testElementSelectionNamesWornMaterials(): void {
    $this->installStylishStyles();

    $button = DaedalusStylishVariant::create(['label' => 'Button', 'machine_name' => 'button', 'scope' => 'kind:block']);
    $button->setStyles(['' => ['default' => ['font-weight' => 'bold']]]);
    $button->save();
    $row = DaedalusStylishVariant::create(['label' => 'Row', 'machine_name' => 'row', 'scope' => 'kind:block']);
    $row->setStyles(['' => ['default' => ['display' => 'flex']]]);
    $row->save();
    $card = DaedalusStylishVariant::create([
      'label' => 'Card Left',
      'machine_name' => 'card_left',
      'scope' => 'kind:container',
    ]);
    $card->setStyles([
      '' => ['default' => ['display' => 'grid']],
      'block(text,1)' => ['variants' => [['variant' => 'row', 'fragment' => '']]],
    ]);
    $card->save();
    $duo = DaedalusStylishVariant::create(['label' => 'Duo', 'machine_name' => 'duo', 'scope' => 'kind:container']);
    $duo->setStyles([
      '' => ['default' => ['display' => 'grid']],
      'block(text,1)' => ['default' => ['color' => 'red']],
    ]);
    $duo->save();

    $container_uuid = '33333333-3333-3333-3333-333333333333';
    $block_uuid = '11111111-1111-1111-1111-111111111111';
    $via_uuid = '55555555-5555-5555-5555-555555555555';
    $fragment_uuid = '66666666-6666-6666-6666-666666666666';
    $root = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create([
        'name' => 'Styled Root',
        'daedalus_stylish_styles' => json_encode([
          "container:$container_uuid" => ['variants' => [['variant' => 'card_left', 'fragment' => '']]],
          "block:$via_uuid" => ['variants' => [['variant' => 'row', 'fragment' => '', 'via' => 'card_left']]],
          "block:$fragment_uuid" => ['variants' => [['variant' => 'duo', 'fragment' => 'block(text,1)']]],
          "block:$block_uuid" => [
            'variants' => [['variant' => 'button', 'fragment' => ''], ['variant' => 'gone', 'fragment' => '']],
            'default' => ['padding' => '1rem'],
          ],
          "block:$block_uuid::.text-block-link" => ['default' => ['color' => 'blue']],
        ]),
      ]);
    $root->save();

    $materials = $this->container->get('daedalus_blueprint.element_materials');
    $resolver = new ElementSelectionResolver(
      $this->container->get('daedalus_blueprint.host_resolver'),
      NULL,
      $materials,
      $this->container->get('daedalus_blueprint.storage_handler'),
    );
    $selection = [
      ['key' => "container:$container_uuid", 'number' => 1],
      ['key' => "block:$block_uuid", 'number' => 2],
      ['key' => "block:$via_uuid", 'number' => 3],
      ['key' => "block:$fragment_uuid", 'number' => 4],
      ['key' => "field:{$root->uuid()}:name", 'number' => 5],
      // A styling target under the button block (its link part): the
      // materials are read AT the address, the part answering with its
      // root's wearing (ElementMaterials::worn()).
      ['key' => "block:$block_uuid", 'selector' => '.text-block-link', 'number' => 6],
    ];
    $resolved = $resolver->resolve($selection, $this->hostOf($root));
    $this->assertSame('button "Button" (class="button")', $resolved[6]['materials'], 'A target reads the wear at its address.');
    $this->assertSame('the rule ".text-block-link" under it (style-map key block:' . $block_uuid . '::.text-block-link): styling acts on every element the rule matches, not on this element alone', $resolved[6]['target']);

    // Identity and origin only: a wearing applied here shows the spelling
    // the page language writes and the label a person says, never the
    // description or a value (the per-turn budget); a distributed wearing
    // names the composition that put it there instead of a spelling (it is
    // not this element's own to write); a fragment names its slot.
    $this->assertSame('card_left "Card Left" (class="card_left")', $resolved[1]['materials']);
    $this->assertSame('button "Button" (class="button")', $resolved[2]['materials'], 'A stale reference the roster no longer serves is not worn.');
    $this->assertSame('row "Row" via card_left "Card Left"', $resolved[3]['materials'], 'A wearing a composition distributed names its origin, no spelling.');
    $this->assertSame('fragment block(text,1) of duo "Duo"', $resolved[4]['materials'], 'A fragment wearing names its slot of the composition, no spelling.');
    $this->assertArrayNotHasKey('materials', $resolved[5], 'An element wearing nothing carries no materials entry.');
    foreach ($resolved as $number => $element) {
      $this->assertStringNotContainsString('bold', $element['materials'] ?? '', "Element $number prints no value.");
      $this->assertStringNotContainsString('padding', $element['materials'] ?? '', "Element $number prints no value.");
    }

    // A part key answers with its root's wearings: the references live on
    // the root entry and the root's variants style the part.
    $staged = $this->container->get('daedalus_blueprint.storage_handler')->load('entity_test', (string) $root->id());
    $part = $materials->worn($staged, "block:$block_uuid::.text-block-link");
    $this->assertCount(1, $part);
    $this->assertSame('button', $part[0]->record->id);
    $this->assertSame('', $part[0]->fragment);
    $this->assertNull($part[0]->via);

    // A stale origin prints by machine name: the fact stays visible; the
    // container's own wearing of the deleted composition is gone with it.
    $card->delete();
    $resolved = $resolver->resolve($selection, $this->hostOf($root));
    $this->assertSame('row "Row" via card_left', $resolved[3]['materials']);
    $this->assertArrayNotHasKey('materials', $resolved[1]);
  }

  /**
   * ElementSelectionResolver: a rule's wear reaches a member, never dresses it.
   *
   * A container's `> pb-text` rule wears "button": the rule's matches
   * render the look, and the style stack lists it in a member's ancestor
   * tier with the variant's identity (so the inspector can name it) but
   * never in its variant tier. ElementMaterials::worn() reads the variant
   * tier alone, so the member reports no wearing while the rule, as a
   * styling target, reports the look; a stack that carried the variant on
   * an ancestor layer beside a real wearing (the stack stubbed, the exact
   * shape the ancestor tier produces) yields the wearing alone. A member
   * reporting its section's wear would tell the AI the element wears what
   * it cannot detach.
   */
  public function testMemberUnderWearingRuleWearsNothing(): void {
    $this->installStylishStyles();
    $button = DaedalusStylishVariant::create(['label' => 'Button', 'machine_name' => 'button', 'scope' => 'kind:block']);
    $button->setStyles(['' => ['default' => ['font-weight' => 'bold']]]);
    $button->save();
    $row = DaedalusStylishVariant::create(['label' => 'Row', 'machine_name' => 'row', 'scope' => 'kind:block']);
    $row->setStyles(['' => ['default' => ['display' => 'flex']]]);
    $row->save();

    $container_uuid = '33333333-3333-3333-3333-333333333333';
    $member_uuid = '11111111-1111-1111-1111-111111111111';
    $root = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create([
        'name' => 'Styled Root',
        'daedalus_stylish_styles' => json_encode([
          "container:$container_uuid::> pb-text" => ['variants' => [['variant' => 'button', 'fragment' => '']]],
          "block:$member_uuid" => ['default' => ['padding' => '1rem']],
        ]),
      ]);
    $root->save();

    $materials = $this->container->get('daedalus_blueprint.element_materials');
    $resolver = new ElementSelectionResolver(
      $this->container->get('daedalus_blueprint.host_resolver'),
      NULL,
      $materials,
      $this->container->get('daedalus_blueprint.storage_handler'),
    );
    $resolved = $resolver->resolve([
      ['key' => "block:$member_uuid", 'number' => 1],
      ['key' => "container:$container_uuid", 'selector' => '> pb-text', 'number' => 2],
    ], $this->hostOf($root));
    $this->assertArrayNotHasKey('materials', $resolved[1], 'A member under a wearing rule wears nothing of its own.');
    $this->assertSame('button "Button" (class="button")', $resolved[2]['materials'], 'The rule, as a target, wears the look.');

    // The predicate itself: a stack carrying the rule's variant as an
    // ancestor layer (its identity intact, resolved) beside the element's
    // own wearing answers the wearing alone.
    $stack = new class() {

      /**
       * A member's stack: the rule's worn layer, an own wearing, the own entry.
       */
      public function stack(array $map, string $key, array $context = []): array {
        $layer = static fn (string $id, string $tier, ?string $variant): array => [
          'id' => $id,
          'tier' => $tier,
          'scope' => NULL,
          'variant' => $variant,
          'fragment' => $variant === NULL ? NULL : '',
          'via' => NULL,
          'viaLabel' => NULL,
          'label' => NULL,
          'description' => NULL,
          'axis' => NULL,
          'resolved' => TRUE,
          'editable' => $tier === 'element',
          'carrier' => NULL,
          'styles' => [],
        ];
        return [
          'layers' => [
            $layer('ancestor:container:x::> pb-text|button', 'ancestor', 'button'),
            $layer('variant:row', 'variant', 'row'),
            $layer('element', 'element', NULL),
          ],
        ];
      }

    };
    $stubbed = new ElementMaterials(
      $this->container->get('daedalus_blueprint.node_tree_builder'),
      $this->container->get('daedalus_blueprint.materials_roster_builder'),
      $stack,
    );
    $staged = $this->container->get('daedalus_blueprint.storage_handler')->load('entity_test', (string) $root->id());
    $worn = $stubbed->worn($staged, "block:$member_uuid");
    $this->assertSame(['row'], array_map(static fn ($wearing) => $wearing->record->id, $worn), 'Only the variant tier is a wearing; an ancestor layer carrying a variant is the rule\'s.');
  }

  /**
   * Installs daedalus_stylish and its style map field on entity_test.
   */
  protected function installStylishStyles(): void {
    $this->enableModules(['daedalus_stylish']);
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    // Saving a styled entity records variant, swatch and animation usage.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    // The product way: the field manager owns the style map field, and a
    // Layout Builder display saved on entity_test (a bundle-less type, so
    // no Edit Mode carrier gates it) after daedalus_stylish is enabled has
    // already created it; enable() is then a no-op.
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * ElementSelectionResolver: placement provenance rides each element's entry.
   *
   * A real layout on the root entity: a section stamped with the test
   * provider's `hero` blueprint (the section's third-party settings, the
   * section-rooted slot), a block stamped with a blueprint the roster no
   * longer serves (the component's additional data, the block-rooted slot)
   * and a plain block. daedalus_layout gives the section its selectable uuid.
   */
  public function testElementSelectionNamesPlacementProvenance(): void {
    $this->enableModules(['daedalus_blueprint_materials_test', 'daedalus_blueprint_api', 'daedalus_layout']);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    $this->setUpCurrentUser([], [], TRUE);

    $container_uuid = '33333333-3333-3333-3333-333333333333';
    $dead_uuid = '11111111-1111-1111-1111-111111111111';
    $plain_uuid = '44444444-4444-4444-4444-444444444444';
    $section = new Section('layout_onecol', [], [], [
      'daedalus_layout' => ['uuid' => $container_uuid],
      LayoutSerializer::BLUEPRINT_PROVENANCE_KEY => ['blueprint' => 'hero'],
    ]);
    $section->appendComponent(new SectionComponent($dead_uuid, 'content', ['id' => 'system_powered_by_block'], [
      LayoutSerializer::BLUEPRINT_PROVENANCE_KEY => 'gone',
    ]));
    $section->appendComponent(new SectionComponent($plain_uuid, 'content', ['id' => 'system_powered_by_block']));
    $root = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create([
        'name' => 'Placed Root',
        'layout_builder__layout' => [$section],
      ]);
    $root->save();

    $resolver = new ElementSelectionResolver(
      $this->container->get('daedalus_blueprint.host_resolver'),
      NULL,
      $this->container->get('daedalus_blueprint.element_materials'),
      $this->container->get('daedalus_blueprint.storage_handler'),
    );
    $resolved = $resolver->resolve([
      ['key' => "container:$container_uuid", 'number' => 1],
      ['key' => "block:$dead_uuid", 'number' => 2],
      ['key' => "block:$plain_uuid", 'number' => 3],
      ['key' => "field:{$root->uuid()}:name", 'number' => 4],
    ], $this->hostOf($root));

    // Identity only: the id the update gesture targets and the label a
    // person says; never the placement spelling (provenance is a fact to
    // target, not a placement to write back).
    $this->assertSame('hero "Hero"', $resolved[1]['placed_from']);
    $this->assertArrayNotHasKey('placed_from', $resolved[2], 'A stamp the roster no longer serves is residue, never named.');
    $this->assertArrayNotHasKey('placed_from', $resolved[3], 'A placement with no stamp carries no provenance entry.');
    $this->assertArrayNotHasKey('placed_from', $resolved[4], 'A field is not a placement.');
    $this->assertArrayNotHasKey('materials', $resolved[1], 'Provenance is not a worn material.');
  }

  /**
   * RouteEntityResolver: canonical resolution, NULL paths, access, caching.
   *
   * One method walks the resolver's full contract on a shared fixture. The
   * positive resolution and caching run as an admin (so the path is accessible
   * and loads), the access-denied branch as a restricted user.
   */
  public function testRouteEntityResolution(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create(['name' => 'Routed Entity']);
    $entity->save();
    $path = '/entity_test/' . $entity->id();

    $resolver = $this->routeResolver();

    // A canonical entity path resolves to the entity.
    $resolved = $resolver->resolve($path);
    $this->assertNotNull($resolved);
    $this->assertSame('entity_test', $resolved->entityTypeId());
    $this->assertSame((string) $entity->id(), (string) $resolved->id());

    // Non-entity, external, and garbage paths all resolve to NULL.
    $this->assertNull($resolver->resolve('/user/logout'), 'A non-entity route resolves to NULL.');
    $this->assertNull($resolver->resolve('https://example.com/node/1'), 'An external URL resolves to NULL.');
    $this->assertNull($resolver->resolve('/this/path/does/not/exist'), 'An invalid path resolves to NULL.');

    // Per-request cache: delete the entity, then resolve the SAME path again on
    // the SAME resolver. The cached instance is returned (consistent result)
    // rather than a fresh, now-failed load.
    $entity->delete();
    $cached = $resolver->resolve($path);
    $this->assertSame($resolved, $cached, 'The same path returns the cached entity instance.');
    // A fresh resolver (cold cache) now fails to load the deleted entity,
    // proving the previous hit came from the cache, not a reload.
    $this->assertNull($this->routeResolver()->resolve($path));

    // Access gate: recreate the entity and resolve as a user who cannot view
    // entity_test entities -> NULL, never the entity.
    $entity2 = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create(['name' => 'Private Entity']);
    $entity2->save();
    $this->setUpCurrentUser([], []);
    $this->assertNull(
      $this->routeResolver()->resolve('/entity_test/' . $entity2->id()),
      'An entity the current user cannot view resolves to NULL.',
    );
  }

  /**
   * RouteEntityResolver: a template route resolves its display host.
   *
   * Core's manage-display layout route (field_ui builds it) carries a
   * defaults storage, which the derivation spine answers with the
   * display's host; the resolver describes it as a template, with the
   * document's kind beside the host's own identity.
   */
  public function testTemplateRouteResolvesItsDisplayHost(): void {
    $this->enableModules(['field_ui']);
    $this->setUpCurrentUser([], [], TRUE);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->save();
    $this->container->get('router.builder')->rebuild();
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $path = $this->container->get('daedalus_ui.ui')->getEditingUrl($display)->toString();

    $resolver = $this->routeResolver();
    $host = $resolver->resolve($path);
    $this->assertInstanceOf(DisplayHost::class, $host, 'The defaults route resolves to the display host.');
    $this->assertSame('entity_test.entity_test.default', $host->id());

    $facts = $resolver->describe($host);
    $this->assertSame('entity_view_display', $facts['entity_type']);
    $this->assertSame('entity_test.entity_test.default', $facts['entity_id']);
    $this->assertSame($display->uuid(), $facts['uuid']);
    $this->assertSame(['entity_type' => 'entity_test', 'bundle' => 'entity_test'], $facts['kind'], 'The kind is what the template renders.');
    $this->assertSame('the default template for entity_test entity_tests', $facts['template']);

    $entity = $this->container->get('entity_type.manager')->getStorage('entity_test')->create(['name' => 'Page']);
    $entity->save();
    $page = $resolver->describe($resolver->resolve('/entity_test/' . $entity->id()));
    $this->assertNull($page['template'], 'A page is no template.');
    $this->assertSame(['entity_type' => 'entity_test', 'bundle' => 'entity_test'], $page['kind']);

    // Viewing entities is not administering their display.
    $this->setUpCurrentUser([], ['view test entity']);
    $this->assertNull($this->routeResolver()->resolve($path), 'A template the user cannot administer resolves to NULL.');
  }


  /**
   * RouteEntityResolver: a saved blueprint is described as a blueprint.
   *
   * The wording keys on the host class, never on fields(): a blueprint
   * answers its entity's fields like a page and is still described as the
   * blueprint it is (scope, label, the subject it is for), with the
   * document's kind the subject's; a page carries neither the template nor
   * the blueprint sentence.
   */
  public function testBlueprintHostIsDescribedAsABlueprint(): void {
    $this->enableModules(['options', 'image', 'media', 'daedalus_blueprint_library']);
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['image', 'file', 'media', 'daedalus_blueprint_library']);
    $this->setUpCurrentUser([], [], TRUE);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    $blueprint = $this->container->get('entity_type.manager')->getStorage('blueprint')->create([
      'type' => 'section',
      'label' => 'Card',
      'machine_name' => 'card',
      'scope' => 'block',
      'subject_type' => 'entity_test',
      'subject_bundle' => 'entity_test',
      'status' => 1,
    ]);
    $blueprint->save();

    $facts = $this->routeResolver()->describe($this->hostOf($blueprint));
    $this->assertSame('blueprint', $facts['entity_type']);
    $this->assertSame((string) $blueprint->id(), $facts['entity_id']);
    $this->assertSame($blueprint->uuid(), $facts['uuid']);
    $this->assertSame(['entity_type' => 'entity_test', 'bundle' => 'entity_test'], $facts['kind'], 'The kind is the subject the blueprint is for.');
    $this->assertSame('the block blueprint "Card" for entity_test entity_tests', $facts['blueprint']);
    $this->assertNull($facts['template'], 'A blueprint is no template, whatever its fields() answers.');

    $page = $this->container->get('entity_type.manager')->getStorage('entity_test')->create(['name' => 'Page']);
    $page->save();
    $facts = $this->routeResolver()->describe($this->hostOf($page));
    $this->assertNull($facts['blueprint'], 'A page is no blueprint.');
    $this->assertNull($facts['template']);
  }

}
