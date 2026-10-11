<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus_stylish\Traits\HostTrait;

/**
 * Tests variant apply/detach over a staged composition (layered entries).
 *
 * The apply_variant writes the target entry's root reference (same-axis
 * replace) and DISTRIBUTES a composition variant: each slot path among the
 * variant's styles resolves once against the staged subtree, and every
 * resolved child's own entry receives a {variant, fragment} reference on
 * the variant's axis — position becomes identity at the mutation moment,
 * bound once. Everything else in the map — the target's overrides and
 * sub-element entries, the subtree's custom entries, children's
 * other-axis references — survives, layering over the variant (overrides
 * beat references; that shadowing is a visible state, not a hazard the op
 * must wipe away). detach_variant is purely LOCAL: references are
 * identity-bound, so each one materializes its own fragment's styles
 * (plus their '::.class' part styles) onto the keys of the element
 * carrying it, merging UNDER whatever that element already carries, with
 * no slot resolution. Detaching a root wearing is additionally the group
 * gesture (detach-all): the fragment references the variant distributed
 * over the CURRENT subtree materialize onto their carriers and drop with
 * it. Nested references distribute as real map references STAMPED with
 * their origin ({variant, fragment, via}, "via" = the immediate
 * distributor): same-axis replace and remove on the composition root reap
 * by it (recursively, over the current subtree) so a switch takes exactly
 * what the composition nested and never a wear the editor applied by hand
 * (a direct apply writes no origin); detach releases it (the wear stays,
 * the child owns it). Nested compositions are nested CONTAINERS
 * (containers all the way down): container(n) slots address them
 * positionally and their own keys travel as first-class elements.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantApplySubtreeTest extends NestedStorageKernelTestBase {

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
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperationsInterface
   */
  protected $operations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    // Variant references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
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
    // Access-transparent like the operations suite: these tests verify
    // apply semantics, not access.
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * Builds the composition fixture the JS capture tests mirror.
   *
   * A container hosting, in order: an inline basic block, a non-inline
   * plugin placement, a second inline basic block, and a nested container
   * hosting a third inline basic block. A second, unrelated container
   * hosts one more inline block.
   *
   * @return array{0: \Drupal\entity_test\Entity\EntityTest, 1: array<string, string>}
   *   The host entity and the uuids by role.
   */
  protected function buildComposition(array $map_by_role): array {
    $ids = [
      'sec' => $this->uuid->generate(),
      'b1' => $this->uuid->generate(),
      'plugin' => $this->uuid->generate(),
      'b2' => $this->uuid->generate(),
      'nsec' => $this->uuid->generate(),
      'img' => $this->uuid->generate(),
      'other_sec' => $this->uuid->generate(),
      'out' => $this->uuid->generate(),
    ];

    $nested = $this->createSection('daedalus_stylish_container', [$this->createBasicBlockComponent($ids['img'])], $ids['nsec']);
    $section = $this->createSection('daedalus_stylish_container', [
      $this->createBasicBlockComponent($ids['b1'], 'content'),
      new SectionComponent($ids['plugin'], 'content', ['id' => 'system_powered_by_block', 'label_display' => FALSE]),
      $this->createBasicBlockComponent($ids['b2'], 'content'),
      $this->createNestedSectionComponent($nested, 'content'),
    ], $ids['sec']);
    $other = $this->createSection('daedalus_stylish_container', [$this->createBasicBlockComponent($ids['out'])], $ids['other_sec']);

    $placeholders = [];
    foreach ($ids as $role => $uuid) {
      $placeholders['{' . $role . '}'] = $uuid;
    }
    $map = [];
    foreach ($map_by_role as $key_template => $entry) {
      $map[strtr($key_template, $placeholders)] = $entry;
    }
    $entity = EntityTest::create([
      'layout_builder__layout' => [$section, $other],
      DaedalusStylishFieldManager::FIELD_NAME => json_encode($map),
    ]);
    $entity->save();

    return [$entity, $ids];
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
   * ("" = the root), the one reference shape the map stores; a nested
   * reference distribution wrote additionally carries "via", the machine
   * name of the variant that distributed it.
   */
  protected static function ref(string $variant, string $fragment = '', ?string $via = NULL): array {
    return ['variant' => $variant, 'fragment' => $fragment] + ($via === NULL ? [] : ['via' => $via]);
  }

  /**
   * Applying a slot-less variant leaves the staged subtree's entries alone.
   *
   * THE regression test for the bug that triggered the layered entry
   * model: applying a section variant ("White") used to wipe every
   * slot-addressable custom entry in the section's subtree — a child
   * block's hand-set alignment vanished with the section's background
   * swap. Under layered entries the reference is one axis's treatment,
   * not the element's entire styling: a variant without slot styles
   * writes the target entry's variants list and nothing else (a
   * composition variant additionally distributes fragment references —
   * see testApplyDistributesFragmentReferences()).
   */
  public function testApplyVariantKeepsSubtreeEntries(): void {
    DaedalusStylishVariant::create(['label' => 'White', 'machine_name' => 'white', 'scope' => 'kind:container'])->save();
    DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'block_type:basic'])->save();

    $styled = ['default' => ['padding' => '1rem']];
    $alignment = ['default' => ['justify-content' => 'center']];
    [$entity, $ids] = $this->buildComposition([
      // The target's own styles survive as overrides. (No container part
      // entry: the one-box hoist sweep converges any stored
      // '::.daedalus-stylish-layout-container' key onto the root at staging; part
      // survival is covered by the block's '::.quote' entry below.)
      'container:{sec}' => $styled,
      // The original bug's shape: a child block's hand-set alignment.
      'block:{b1}' => $alignment,
      // Subtree entries at every depth, all kept.
      'block:{b1}:field_x' => $styled,
      'block:{b1}::.quote' => $styled,
      'container:{nsec}' => $styled,
      'block:{img}' => $styled,
      'block:{plugin}' => $styled,
      'block:{b2}' => ['variants' => [['variant' => 'card', 'fragment' => '']]],
      // Elements outside the subtree, kept as ever.
      'block:{out}' => $styled,
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'white'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([
      "container:{$ids['sec']}" => ['default' => ['padding' => '1rem'], 'variants' => [self::ref('white')]],
      "block:{$ids['b1']}" => $alignment,
      "block:{$ids['b1']}:field_x" => $styled,
      "block:{$ids['b1']}::.quote" => $styled,
      "container:{$ids['nsec']}" => $styled,
      "block:{$ids['img']}" => $styled,
      "block:{$ids['plugin']}" => $styled,
      "block:{$ids['b2']}" => ['variants' => [['variant' => 'card', 'fragment' => '']]],
      "block:{$ids['out']}" => $styled,
    ], $this->styleMap($entity));
  }

  /**
   * Applying a composition variant distributes refs onto resolved children.
   *
   * Position resolves to identity at the mutation moment: each slot path
   * among the variant's styles resolves once through the staged layout
   * (the same walk capture inverts) and the resolved child's own entry
   * receives a {variant, fragment} reference on the variant's axis. Part
   * suffixes ride their element — one reference per resolved ELEMENT, no
   * entries for '::.class' slot keys. A slot the layout does not carry
   * binds nothing, forever (bind-once: no pending record). A child's
   * existing same-axis reference is replaced by the distributed fragment
   * (one axis rule everywhere), while its overrides survive and keep
   * winning; elements outside the subtree stay untouched.
   */
  public function testApplyDistributesFragmentReferences(): void {
    DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'block_type:basic'])->save();
    $variant = DaedalusStylishVariant::create(['label' => 'Hero', 'machine_name' => 'hero', 'scope' => 'kind:container']);
    $variant->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      '::.daedalus-stylish-layout-container' => ['default' => ['margin' => '0']],
      'block(basic,1)' => ['default' => ['padding' => '3rem']],
      'block(basic,1)::.quote' => ['default' => ['margin' => '4px']],
      'block(basic,1)/field(field_x)' => ['default' => ['margin' => '2px']],
      'block(basic,2)' => ['default' => ['margin' => '9px']],
      'container(1)' => ['default' => ['gap' => '3px']],
      'container(1)::.daedalus-stylish-layout-container' => ['default' => ['padding' => '4px']],
      // No third basic block exists: the slot resolves to nothing and the
      // fragment binds nowhere — and never will (bind-once).
      'block(basic,3)' => ['default' => ['padding' => '5rem']],
    ]);
    $variant->save();

    [$entity, $ids] = $this->buildComposition([
      // An override on a resolved child: it survives and keeps winning.
      'block:{b1}' => ['default' => ['padding' => '7rem']],
      // A child on its own same-axis variant: the distributed fragment
      // replaces it (an element wears one variant per axis, and the
      // newest application wins the axis — one rule everywhere).
      'block:{b2}' => ['variants' => [['variant' => 'card', 'fragment' => '']]],
      // Outside the subtree: untouched.
      'block:{out}' => ['default' => ['margin' => '1px']],
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'hero'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('hero')]],
      "block:{$ids['b1']}" => ['default' => ['padding' => '7rem'], 'variants' => [self::ref('hero', 'block(basic,1)')]],
      "block:{$ids['b1']}:field_x" => ['variants' => [self::ref('hero', 'block(basic,1)/field(field_x)')]],
      "block:{$ids['b2']}" => ['variants' => [self::ref('hero', 'block(basic,2)')]],
      "container:{$ids['nsec']}" => ['variants' => [self::ref('hero', 'container(1)')]],
      "block:{$ids['out']}" => ['default' => ['margin' => '1px']],
    ], $this->styleMap($entity));
  }

  /**
   * Nested references distribute as stamped refs beside the fragment ref.
   *
   * Entry-shaped fragments: a variant entry may carry a "variants" list,
   * and apply copies each nested reference onto the element the entry
   * resolves to — the root entry onto the target itself, a slot entry onto
   * its resolved child, as a real reference on the nested variant's own
   * axis (bind-once: downstream only ever sees real map refs) stamped
   * "via" the distributing variant, the placement fact that tells it from
   * a wear the editor applied by hand. A nested reference sharing its
   * carrier fragment's axis lands BESIDE the fragment reference (the
   * composition pair: the fragment's values are the captured overrides
   * that already beat it), while the pair still replaces the child's
   * pre-existing same-axis reference (one axis rule against the world
   * outside the distribution). Re-apply is idempotent over the whole
   * shape, the stamp included.
   */
  public function testApplyDistributesNestedReferences(): void {
    DaedalusStylishVariant::create(['label' => 'Old card', 'machine_name' => 'old_card', 'scope' => 'block_type:basic'])->save();
    $card = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'block_type:basic']);
    $card->setStyles(['' => ['default' => ['padding' => '1rem']]]);
    $card->save();
    // A cross-axis reference on the ROOT entry: applying the composition
    // also dresses the target in it, on its own axis.
    $half = DaedalusStylishVariant::create([
      'label' => 'Half',
      'machine_name' => 'half',
      'scope' => 'kind:container',
      'axis' => 'container_layout',
    ]);
    $half->setStyles(['' => ['default' => ['gap' => '2rem']]]);
    $half->save();
    $variant = DaedalusStylishVariant::create(['label' => 'Hero', 'machine_name' => 'hero', 'scope' => 'kind:container']);
    $variant->setStyles([
      '' => ['variants' => [['variant' => 'half', 'fragment' => '']], 'default' => ['padding' => '2rem']],
      'block(basic,1)' => ['variants' => [['variant' => 'card', 'fragment' => '']], 'default' => ['margin' => '1px']],
      'block(basic,2)' => ['default' => ['margin' => '9px']],
    ]);
    $variant->save();

    [$entity, $ids] = $this->buildComposition([
      // The child's own same-axis variant is replaced by the incoming
      // pair; its override survives and keeps winning.
      'block:{b1}' => ['default' => ['padding' => '7rem'], 'variants' => [['variant' => 'old_card', 'fragment' => '']]],
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'hero'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([], $result->getWarnings());
    $expected = [
      "container:{$ids['sec']}" => ['variants' => [self::ref('half', via: 'hero'), self::ref('hero')]],
      "block:{$ids['b1']}" => [
        'default' => ['padding' => '7rem'],
        'variants' => [self::ref('card', via: 'hero'), self::ref('hero', 'block(basic,1)')],
      ],
      "block:{$ids['b2']}" => ['variants' => [self::ref('hero', 'block(basic,2)')]],
    ];
    $this->assertEquals($expected, $this->styleMap($entity));

    // Re-apply: the same-variant re-place and the sparing keep the pair
    // stable — no duplicates, no order churn, no group cleanup.
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'hero'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([], $result->getWarnings());
    $this->assertEquals($expected, $this->styleMap($entity));

    // Delivery order within the shared axis is STRUCTURAL, not list order:
    // the nested root reference delivers UNDER its carrier fragment (the
    // fragment's values are the captured overrides that already beat it),
    // even when the stored list says otherwise.
    // The structured read carries "via" on every item (NULL when the
    // reference records none).
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->assertSame(
      [self::ref('card') + ['via' => NULL], self::ref('hero', 'block(basic,1)') + ['via' => NULL]],
      $validator->orderedEntryRefs(['variants' => [self::ref('hero', 'block(basic,1)'), self::ref('card')]]),
    );
  }

  /**
   * A nested composition reference applies recursively at its carrier.
   *
   * A root ('') nested reference to a composition is a full apply at the
   * resolved child: its own reference lands there (beside the outer
   * fragment reference — sparing keeps the same-axis pair intact), and its
   * slot paths resolve against the CHILD's subtree, distributing further
   * down. One apply semantics at every depth.
   */
  public function testNestedCompositionAppliesRecursively(): void {
    $inner = DaedalusStylishVariant::create(['label' => 'Inner', 'machine_name' => 'inner_comp', 'scope' => 'kind:container']);
    $inner->setStyles([
      '' => ['default' => ['gap' => '1px']],
      'block(basic,1)' => ['default' => ['margin' => '2px']],
    ]);
    $inner->save();
    $outer = DaedalusStylishVariant::create(['label' => 'Outer', 'machine_name' => 'outer_comp', 'scope' => 'kind:container']);
    $outer->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      'container(1)' => ['variants' => [['variant' => 'inner_comp', 'fragment' => '']]],
    ]);
    $outer->save();

    [$entity, $ids] = $this->buildComposition([]);
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'outer_comp'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([], $result->getWarnings());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('outer_comp')]],
      // Both on the style axis: the nested composition's own reference
      // (stamped by the outer, its immediate distributor) and the outer
      // fragment reference compose as the pair.
      "container:{$ids['nsec']}" => [
        'variants' => [self::ref('inner_comp', via: 'outer_comp'), self::ref('outer_comp', 'container(1)')],
      ],
      // The inner composition resolved its slot path against the NESTED
      // container's subtree; its own fragment reference carries no origin
      // (a bound lookup key, reaped by the inner's name).
      "block:{$ids['img']}" => ['variants' => [self::ref('inner_comp', 'block(basic,1)')]],
    ], $this->styleMap($entity));
  }

  /**
   * Integrity is the judge's, and it is the whole save gate.
   *
   * A variant written around the save gate (direct entity saves) with a
   * reference cycle, a dangling nested reference, or a root-entry
   * reference on its own axis is withheld from the roster exactly as a
   * refused value withholds it (VariantConformance), so apply refuses it
   * by name with the structural error in the refusal's `refused`
   * triples, and never distributes it. Apply keeps no guards of its own
   * for those shapes: the roster is its only door.
   *
   * The judge does not cascade (it validates in the unjudged universe),
   * so the one nested reference apply can still fail to resolve names a
   * variant the roster withholds: a clean composition nesting a variant
   * whose own value strict law refuses stays offered, and applying it
   * skips that reference loudly while everything resolvable binds.
   */
  public function testNestedReferenceGuards(): void {
    // A cycle: comp_a holds comp_b at its container slot, comp_b's root
    // entry references comp_a back (cross-axis, so the cycle is the only
    // structural error).
    $a = DaedalusStylishVariant::create([
      'label' => 'A',
      'machine_name' => 'comp_a',
      'scope' => 'kind:container',
      'axis' => 'container_layout',
    ]);
    $a->setStyles([
      '' => ['default' => ['gap' => '1px']],
      'container(1)' => ['variants' => [['variant' => 'comp_b', 'fragment' => '']]],
    ]);
    $a->save();
    $b = DaedalusStylishVariant::create(['label' => 'B', 'machine_name' => 'comp_b', 'scope' => 'kind:container']);
    $b->setStyles([
      '' => ['variants' => [['variant' => 'comp_a', 'fragment' => '']], 'default' => ['padding' => '1px']],
    ]);
    $b->save();
    [$entity, $ids] = $this->buildComposition([]);
    $this->assertRefusedByTheJudge($entity, $ids['sec'], 'comp_a', 'container(1).variants.0', 'STYLE_VARIANT_REFERENCE_CYCLE');
    $this->assertSame([], $this->styleMap($entity));

    // Dangling: the reference names no variant at all.
    $hero = DaedalusStylishVariant::create(['label' => 'Hero', 'machine_name' => 'hero_dangling', 'scope' => 'kind:container']);
    $hero->setStyles([
      'block(basic,1)' => ['variants' => [['variant' => 'ghost', 'fragment' => '']], 'default' => ['margin' => '1px']],
    ]);
    $hero->save();
    [$entity, $ids] = $this->buildComposition([]);
    $this->assertRefusedByTheJudge($entity, $ids['sec'], 'hero_dangling', 'block(basic,1).variants.0', 'STYLE_VARIANT_NOT_FOUND');

    // A root-entry reference on the variant's own axis could only replace
    // the variant itself on every wearer.
    DaedalusStylishVariant::create(['label' => 'Look', 'machine_name' => 'other_look', 'scope' => 'kind:container'])->save();
    $selfish = DaedalusStylishVariant::create(['label' => 'Selfish', 'machine_name' => 'selfish', 'scope' => 'kind:container']);
    $selfish->setStyles([
      '' => ['variants' => [['variant' => 'other_look', 'fragment' => '']], 'default' => ['padding' => '2px']],
    ]);
    $selfish->save();
    [$entity, $ids] = $this->buildComposition([]);
    $this->assertRefusedByTheJudge($entity, $ids['sec'], 'selfish', 'variants.0', 'STYLE_VARIANT_AXIS_CONFLICT');

    // The reachable skip: outer nests inner at its container slot; inner
    // carries a typography literal the container kind does not declare,
    // legal under guarded law and withheld under strict. Outer stays
    // offered (the judge does not cascade), applies, and skips inner
    // loudly; its own fragment reference still binds on the nested
    // container. The roster builder's memo is not law-keyed; the reset is
    // the request boundary a real site crosses after the settings save.
    $inner = DaedalusStylishVariant::create(['label' => 'Inner', 'machine_name' => 'inner', 'scope' => 'kind:container']);
    $inner->setStyles(['' => ['default' => ['font-size' => '1.25rem']]]);
    $inner->save();
    $outer = DaedalusStylishVariant::create(['label' => 'Outer', 'machine_name' => 'outer', 'scope' => 'kind:container']);
    $outer->setStyles([
      '' => ['default' => ['padding-top' => '2rem']],
      'container(1)' => ['variants' => [['variant' => 'inner', 'fragment' => '']]],
    ]);
    $outer->save();
    $this->enforce('strict');
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $withheld = $this->container->get('daedalus_stylish.variant_conformance')->withheld();
    $this->assertArrayHasKey('inner', $withheld);
    $this->assertArrayNotHasKey('outer', $withheld);
    [$entity, $ids] = $this->buildComposition([]);
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'outer'],
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('STYLE_VARIANT_NOT_FOUND', $warnings[0]->getCode());
    $this->assertStringContainsString('"inner" reference names a variant the site does not offer', $warnings[0]->getMessage());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('outer')]],
      "container:{$ids['nsec']}" => ['variants' => [self::ref('outer', 'container(1)')]],
    ], $this->styleMap($entity));
  }

  /**
   * Asserts an apply is refused by the judge, naming the structural error.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   The host entity.
   * @param string $section
   *   The uuid of the container the variant is applied to.
   * @param string $variant
   *   The machine name of the variant written around the save gate.
   * @param string $at
   *   The refused path relative to the variant's styles.
   * @param string $code
   *   The structural error code the judge's verdict carries there.
   */
  protected function assertRefusedByTheJudge(EntityTest $entity, string $section, string $variant, string $at, string $code): void {
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:$section", 'variant' => $variant],
    ]);
    $this->assertFalse($result->isSuccess(), "Expected the \"$variant\" apply to be refused.");
    $this->assertSame([], $result->getWarnings());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_NONCONFORMING', $errors[0]->getCode());
    $context = $errors[0]->getContext();
    $this->assertSame($variant, $context['variant']);
    $this->assertSame([$at => $code], array_column($context['refused'], 'code', 'path'));
  }

  /**
   * Re-apply is the one gesture that resolves fresh (bind-once's flip side).
   *
   * A fragment binds at apply and never rebinds: structural mutations
   * after the apply reach nobody (a block added later wears nothing), and
   * a slot path that resolved to no element left no pending record. The
   * EXPLICIT re-apply of the same variant is how structure catches up: it
   * is not a same-axis replacement (the variant is not replacing itself),
   * so no group cleanup runs and every bound reference survives where it
   * is; resolution simply runs fresh over the current structure and
   * previously unbound fragments fill. A reference that rode out on a
   * moved element is untouched, so the element now sitting in its old
   * position can end up wearing the same fragment the orphan still wears:
   * the accepted price of never rebinding (deliberate duplicates beat
   * silent restyling).
   */
  public function testReapplyResolvesFreshOverCurrentStructure(): void {
    $variant = DaedalusStylishVariant::create(['label' => 'Hero', 'machine_name' => 'hero', 'scope' => 'kind:container']);
    $variant->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      'block(basic,1)' => ['default' => ['padding' => '3rem']],
      'block(basic,2)' => ['default' => ['margin' => '9px']],
      // Still no ninth basic block: unresolved on the re-apply too.
      'block(basic,9)' => ['default' => ['padding' => '5rem']],
    ]);
    $variant->save();

    // The seeded map is the aftermath of an apply over an OLDER structure:
    // b2 arrived after it (a newcomer, so it wears nothing) and the block
    // that was the second basic block rode out to the other section with
    // its reference (an orphan).
    [$entity, $ids] = $this->buildComposition([
      'container:{sec}' => ['variants' => [['variant' => 'hero', 'fragment' => '']]],
      'block:{b1}' => ['default' => ['padding' => '7rem'], 'variants' => [self::ref('hero', 'block(basic,1)')]],
      'block:{out}' => ['variants' => [self::ref('hero', 'block(basic,2)')]],
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'hero'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('hero')]],
      "block:{$ids['b1']}" => ['default' => ['padding' => '7rem'], 'variants' => [self::ref('hero', 'block(basic,1)')]],
      // The newcomer is the current second basic block: the previously
      // unbound fragment fills….
      "block:{$ids['b2']}" => ['variants' => [self::ref('hero', 'block(basic,2)')]],
      // …while the orphan keeps the same fragment where it lives.
      "block:{$ids['out']}" => ['variants' => [self::ref('hero', 'block(basic,2)')]],
    ], $this->styleMap($entity));
  }

  /**
   * Same-axis replace on a composition root is a group gesture.
   *
   * A section's wearing is derived: its root reference plus the fragment
   * references surviving on current descendants. Replacing the variant on
   * its axis removes the fragment references it distributed over the
   * CURRENT subtree along with the root reference, then distributes the
   * incoming variant's own — while a descendant's direct root reference
   * to the same variant (its OWN wearing, '' fragment) and references
   * that rode out on moved elements (orphans, outside the subtree) stay
   * untouched.
   */
  public function testSameAxisReplaceRemovesDistributedReferences(): void {
    $hero = DaedalusStylishVariant::create(['label' => 'Hero', 'machine_name' => 'hero', 'scope' => 'kind:container']);
    $hero->setStyles(['' => ['default' => ['padding' => '2rem']]]);
    $hero->save();
    $flat = DaedalusStylishVariant::create(['label' => 'Flat', 'machine_name' => 'flat', 'scope' => 'kind:container']);
    $flat->setStyles([
      '' => ['default' => ['padding' => '0']],
      'block(basic,1)' => ['default' => ['margin' => '0']],
    ]);
    $flat->save();

    [$entity, $ids] = $this->buildComposition([
      'container:{sec}' => ['variants' => [['variant' => 'hero', 'fragment' => '']]],
      'block:{b1}' => ['default' => ['padding' => '7rem'], 'variants' => [self::ref('hero', 'block(basic,1)')]],
      'block:{b1}:field_x' => ['variants' => [self::ref('hero', 'block(basic,1)/field(field_x)')]],
      'block:{b2}' => ['variants' => [self::ref('hero', 'block(basic,2)')]],
      // The nested section wears hero DIRECTLY: its own root reference,
      // not part of the outer section's distribution.
      'container:{nsec}' => ['variants' => [['variant' => 'hero', 'fragment' => '']]],
      // An orphan: an element that rode out of some subtree keeps its
      // reference; gestures on this root never touch it.
      'block:{out}' => ['variants' => [self::ref('hero', 'block(basic,9)')]],
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'flat'],
    ]);
    $this->assertTrue($result->isSuccess());
    // hero's distributed references are gone from the subtree (entries
    // left empty disappear), flat's root and resolved fragment landed, the
    // nested section's own wearing and the orphan survived verbatim.
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('flat')]],
      "block:{$ids['b1']}" => ['default' => ['padding' => '7rem'], 'variants' => [self::ref('flat', 'block(basic,1)')]],
      "container:{$ids['nsec']}" => ['variants' => [['variant' => 'hero', 'fragment' => '']]],
      "block:{$ids['out']}" => ['variants' => [self::ref('hero', 'block(basic,9)')]],
    ], $this->styleMap($entity));
  }

  /**
   * Detach-all materializes each distributed reference onto its carrier.
   *
   * Detaching a composition root's wearing is the group gesture: the
   * fragment references the variant distributed over the CURRENT subtree
   * materialize locally (each carrier looks up its own fragment plus that
   * fragment's '::.class' part styles, no slot resolution) and drop with
   * the root reference. The scoping is partial-wearing honesty: a subtree
   * element with no reference to the variant is untouched (a newcomer was
   * never dressed), a descendant's own root wearing of the same variant is
   * its OWN wearing and stays, and a reference that rode out on a moved
   * element is an orphan owned by that element, untouched by gestures on
   * this root. A fragment the variant holds but nothing carries (never
   * bound, or its carrier left) has no element to materialize onto, so its
   * styles land nowhere. An element that already carries styles keeps them
   * winning per property (they already overrode the variant).
   */
  public function testDetachAllMaterializesDistributedReferences(): void {
    DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'block_type:basic'])->save();
    $variant = DaedalusStylishVariant::create(['label' => 'Hero', 'machine_name' => 'hero', 'scope' => 'kind:container']);
    $variant->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      '::.daedalus-stylish-layout-container' => ['default' => ['margin' => '0']],
      'block(basic,1)' => ['default' => ['padding' => '3rem', 'margin' => '3rem'], 'hover' => ['padding' => '4rem']],
      'block(basic,1)::.quote' => ['default' => ['margin' => '4px']],
      'block(basic,1)/field(field_x)' => ['default' => ['margin' => '2px']],
      'container(1)' => ['default' => ['padding' => '4px']],
      // No carrier wears this fragment (it never bound): there is no
      // element to materialize onto, so its styles land nowhere.
      'block(basic,3)' => ['default' => ['padding' => '5rem']],
    ]);
    $variant->save();

    [$entity, $ids] = $this->buildComposition([
      // The root wears hero at its '' (root) fragment; detaching it is
      // the group gesture over the current subtree.
      'container:{sec}' => ['variants' => [['variant' => 'hero', 'fragment' => '']], 'default' => ['margin' => '2rem']],
      // An override written after the apply: it already beat the
      // fragment's delivered styles, so it wins the merge per property.
      'block:{b1}' => ['default' => ['padding' => '7rem'], 'variants' => [self::ref('hero', 'block(basic,1)')]],
      'block:{b1}:field_x' => ['variants' => [self::ref('hero', 'block(basic,1)/field(field_x)')]],
      // A subtree element wearing only its own variant: never dressed by
      // hero (newcomers get nothing), so the gesture never touches it.
      'block:{b2}' => ['variants' => [['variant' => 'card', 'fragment' => '']]],
      // The nested section wears hero DIRECTLY: its own root wearing, not
      // part of the outer section's distribution, so it stays attached.
      'container:{nsec}' => ['variants' => [['variant' => 'hero', 'fragment' => '']]],
      // An orphan: an element that rode out of the subtree keeps its
      // reference; gestures on this root never touch it.
      'block:{out}' => ['variants' => [self::ref('hero', 'block(basic,9)')]],
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'detach_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'hero'],
    ]);
    $this->assertTrue($result->isSuccess());
    // assertEquals: the materialization order in the map is an
    // implementation detail; the entries are the contract.
    $this->assertEquals([
      "container:{$ids['sec']}" => ['default' => ['padding' => '2rem', 'margin' => '2rem']],
      "container:{$ids['sec']}::.daedalus-stylish-layout-container" => ['default' => ['margin' => '0']],
      "block:{$ids['b1']}" => ['default' => ['padding' => '7rem', 'margin' => '3rem'], 'hover' => ['padding' => '4rem']],
      "block:{$ids['b1']}::.quote" => ['default' => ['margin' => '4px']],
      "block:{$ids['b1']}:field_x" => ['default' => ['margin' => '2px']],
      "block:{$ids['b2']}" => ['variants' => [['variant' => 'card', 'fragment' => '']]],
      "container:{$ids['nsec']}" => ['variants' => [['variant' => 'hero', 'fragment' => '']]],
      "block:{$ids['out']}" => ['variants' => [self::ref('hero', 'block(basic,9)')]],
    ], $this->styleMap($entity));
  }

  /**
   * Element-level detach of a fragment reference is a local lookup.
   *
   * A moved element's reference is a live link its element owns: detaching
   * it materializes exactly its fragment's styles (plus the fragment's
   * '::.class' part styles) onto the element's own keys, even though the
   * element sits nowhere the fragment's slot path could resolve — proof
   * that no slot resolution is involved. Being a non-root fragment, the
   * detach is no group gesture: other carriers of the same variant stay
   * attached.
   */
  public function testDetachFragmentReferenceLocally(): void {
    $variant = DaedalusStylishVariant::create(['label' => 'Hero', 'machine_name' => 'hero', 'scope' => 'kind:container']);
    $variant->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      'block(basic,1)' => ['default' => ['padding' => '3rem', 'margin' => '3rem'], 'hover' => ['padding' => '4rem']],
      'block(basic,1)::.quote' => ['default' => ['margin' => '4px']],
      'block(basic,1)/field(field_x)' => ['default' => ['margin' => '2px']],
    ]);
    $variant->save();

    [$entity, $ids] = $this->buildComposition([
      'container:{sec}' => ['variants' => [['variant' => 'hero', 'fragment' => '']]],
      // The block rode out of the composition into the OTHER section,
      // keeping its bound reference (live link) and its own override.
      'block:{out}' => ['default' => ['padding' => '7rem'], 'variants' => [self::ref('hero', 'block(basic,1)')]],
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'detach_variant', 'key' => "block:{$ids['out']}", 'variant' => 'hero'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [['variant' => 'hero', 'fragment' => '']]],
      "block:{$ids['out']}" => [
        'default' => ['padding' => '7rem', 'margin' => '3rem'],
        'hover' => ['padding' => '4rem'],
      ],
      "block:{$ids['out']}::.quote" => ['default' => ['margin' => '4px']],
    ], $this->styleMap($entity));
  }

  /**
   * Detach resolves an entry-shaped fragment's nested refs to values.
   *
   * Nested references are never materialized text: apply distributed them
   * onto the carrier as real references stamped with their origin
   * (bind-once), so their values reach the page through the wearer's map,
   * and detach only ever moves VALUES around. Detaching the composition
   * materializes each fragment's own values and drops only the
   * composition's references: the nested wearings survive as the live
   * layers they are, delivering beneath the materialized overrides (a
   * ref-only fragment materializes nothing at all), so the look is
   * preserved and no reference dangles; their origin is RELEASED (the
   * "via" drops), since the composition is gone and the child owns the
   * wear now. Detaching the nested wearing itself instead materializes
   * ITS values locally while the fragment reference stays. Either way no
   * "variants" list is ever written into materialized overrides.
   */
  public function testDetachResolvesNestedReferencesToValues(): void {
    $card = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'block_type:basic']);
    $card->setStyles(['' => ['default' => ['padding' => '1rem', 'color' => 'blue']]]);
    $card->save();
    $hero = DaedalusStylishVariant::create(['label' => 'Hero', 'machine_name' => 'hero', 'scope' => 'kind:container']);
    $hero->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      // The pair: captured overrides beside a nested reference.
      'block(basic,1)' => ['variants' => [['variant' => 'card', 'fragment' => '']], 'default' => ['margin' => '1px']],
      // A REF-ONLY fragment: the nested wearing is the whole dressing.
      'block(basic,2)' => ['variants' => [['variant' => 'card', 'fragment' => '']]],
    ]);
    $hero->save();

    [$entity, $ids] = $this->buildComposition([]);
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'hero'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('hero')]],
      "block:{$ids['b1']}" => ['variants' => [self::ref('card', via: 'hero'), self::ref('hero', 'block(basic,1)')]],
      "block:{$ids['b2']}" => ['variants' => [self::ref('card', via: 'hero'), self::ref('hero', 'block(basic,2)')]],
    ], $this->styleMap($entity));

    // Detaching the NESTED wearing materializes its values locally; the
    // enclosing fragment reference stays worn.
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'detach_variant', 'key' => "block:{$ids['b2']}", 'variant' => 'card'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals(
      ['default' => ['padding' => '1rem', 'color' => 'blue'], 'variants' => [self::ref('hero', 'block(basic,2)')]],
      $this->styleMap($entity)["block:{$ids['b2']}"],
    );

    // The group detach: each fragment's own VALUES materialize (nothing
    // for the ref-only fragment), the composition's references drop, and
    // the surviving nested wearing keeps delivering, released: no "via".
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'detach_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'hero'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['default' => ['padding' => '2rem']],
      "block:{$ids['b1']}" => ['variants' => [self::ref('card')], 'default' => ['margin' => '1px']],
      "block:{$ids['b2']}" => ['default' => ['padding' => '1rem', 'color' => 'blue']],
    ], $this->styleMap($entity));
  }

  /**
   * Builds the card scenario: a composition nesting a layout on a child.
   *
   * "Card Left" (card_left, style axis) nests "Row" (row, container_layout
   * axis) on its container(1) slot and "Half" (half, container_layout) on
   * its own root entry; "Stacked" (stacked, style axis) is the switch
   * target, dressing the same slot with values only. "Glow" (glow, hover
   * axis) is the editor's own hand-applied wear on the nested container.
   */
  protected function createCardScenario(): void {
    $row = DaedalusStylishVariant::create([
      'label' => 'Row',
      'machine_name' => 'row',
      'scope' => 'kind:container',
      'axis' => 'container_layout',
    ]);
    $row->setStyles(['' => ['default' => ['display' => 'flex']]]);
    $row->save();
    $half = DaedalusStylishVariant::create([
      'label' => 'Half',
      'machine_name' => 'half',
      'scope' => 'kind:container',
      'axis' => 'container_layout',
    ]);
    $half->setStyles(['' => ['default' => ['gap' => '2rem']]]);
    $half->save();
    DaedalusStylishVariant::create(['label' => 'Glow', 'machine_name' => 'glow', 'scope' => 'kind:container', 'axis' => 'hover'])->save();
    $card_left = DaedalusStylishVariant::create([
      'label' => 'Card Left',
      'machine_name' => 'card_left',
      'scope' => 'kind:container',
    ]);
    $card_left->setStyles([
      '' => ['variants' => [['variant' => 'half', 'fragment' => '']], 'default' => ['padding' => '2rem']],
      'container(1)' => ['variants' => [['variant' => 'row', 'fragment' => '']], 'default' => ['margin' => '1px']],
    ]);
    $card_left->save();
    $stacked = DaedalusStylishVariant::create(['label' => 'Stacked', 'machine_name' => 'stacked', 'scope' => 'kind:container']);
    $stacked->setStyles([
      '' => ['default' => ['padding' => '1rem']],
      'container(1)' => ['default' => ['margin' => '0']],
    ]);
    $stacked->save();
  }

  /**
   * Same-axis replace reaps the nested wears the leaving composition put.
   *
   * THE card scenario: Card Left nested "row" onto the card wrapper (and
   * "half" onto its own root entry); switching the root to Stacked used
   * to leave both behind, indistinguishable from the editor's own wears.
   * With provenance the switch takes exactly what Card Left distributed
   * (its fragment references AND the "via card_left" root references, the
   * root entry's own included) and nothing else: the editor's hand-applied
   * "glow" on the wrapper and a sibling's own direct "row" survive.
   */
  public function testSameAxisReplaceReapsNestedReferences(): void {
    $this->createCardScenario();
    [$entity, $ids] = $this->buildComposition([
      // The editor's own wear on the child Card Left will also dress, on an
      // axis the composition never touches.
      'container:{nsec}' => ['variants' => [['variant' => 'glow', 'fragment' => '']]],
      // A sibling wearing "row" DIRECTLY: authored, no origin.
      'container:{other_sec}' => ['variants' => [['variant' => 'row', 'fragment' => '']]],
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'card_left'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([], $result->getWarnings());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('half', via: 'card_left'), self::ref('card_left')]],
      "container:{$ids['nsec']}" => [
        'variants' => [self::ref('glow'), self::ref('row', via: 'card_left'), self::ref('card_left', 'container(1)')],
      ],
      "container:{$ids['other_sec']}" => ['variants' => [self::ref('row')]],
    ], $this->styleMap($entity));

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'stacked'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('stacked')]],
      "container:{$ids['nsec']}" => ['variants' => [self::ref('glow'), self::ref('stacked', 'container(1)')]],
      "container:{$ids['other_sec']}" => ['variants' => [self::ref('row')]],
    ], $this->styleMap($entity));
  }

  /**
   * Remove on a composition root reaps its nested wears too.
   *
   * The undress gesture is the replace without a replacement: the root
   * reference drops, and with it every reference the composition
   * distributed over the current subtree, the "via" root references
   * included. Entries left empty disappear; the editor's own wear stays.
   */
  public function testRemoveVariantReapsNestedReferences(): void {
    $this->createCardScenario();
    [$entity, $ids] = $this->buildComposition([
      'container:{nsec}' => ['variants' => [['variant' => 'glow', 'fragment' => '']]],
    ]);
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'card_left'],
      ['op' => 'remove_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'card_left'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      "container:{$ids['nsec']}" => ['variants' => [self::ref('glow')]],
    ], $this->styleMap($entity));
  }

  /**
   * Reaping a nested composition recurses over ITS distribution.
   *
   * "via" names the IMMEDIATE distributor, so the reap inverts
   * distribution level by level: replacing the outer composition reaps
   * the inner composition's root reference on the nested container (via
   * outer), and that reaped reference was a composition root in its own
   * right, so the reap recurses for it by name over the nested container's
   * subtree: the inner's fragment reference on the image block and the
   * "card" the inner nested there (via inner) leave too. A wear the
   * editor applied by hand at depth survives the whole cascade.
   */
  public function testNestedCompositionReapsRecursively(): void {
    DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'block_type:basic'])->save();
    DaedalusStylishVariant::create([
      'label' => 'Loud',
      'machine_name' => 'loud',
      'scope' => 'block_type:basic',
      'axis' => 'hover',
    ])->save();
    $inner = DaedalusStylishVariant::create(['label' => 'Inner', 'machine_name' => 'inner_comp', 'scope' => 'kind:container']);
    $inner->setStyles([
      '' => ['default' => ['gap' => '1px']],
      'block(basic,1)' => ['variants' => [['variant' => 'card', 'fragment' => '']], 'default' => ['margin' => '2px']],
    ]);
    $inner->save();
    $outer = DaedalusStylishVariant::create(['label' => 'Outer', 'machine_name' => 'outer_comp', 'scope' => 'kind:container']);
    $outer->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      'container(1)' => ['variants' => [['variant' => 'inner_comp', 'fragment' => '']]],
    ]);
    $outer->save();
    $plain = DaedalusStylishVariant::create(['label' => 'Plain', 'machine_name' => 'plain', 'scope' => 'kind:container']);
    $plain->setStyles(['' => ['default' => ['padding' => '0']]]);
    $plain->save();

    [$entity, $ids] = $this->buildComposition([
      // The editor's own wear at depth, on an axis nothing distributes.
      'block:{img}' => ['variants' => [['variant' => 'loud', 'fragment' => '']]],
    ]);
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'outer_comp'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([], $result->getWarnings());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('outer_comp')]],
      "container:{$ids['nsec']}" => [
        'variants' => [self::ref('inner_comp', via: 'outer_comp'), self::ref('outer_comp', 'container(1)')],
      ],
      "block:{$ids['img']}" => [
        'variants' => [
          self::ref('loud'),
          self::ref('card', via: 'inner_comp'),
          self::ref('inner_comp', 'block(basic,1)'),
        ],
      ],
    ], $this->styleMap($entity));

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'plain'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('plain')]],
      "block:{$ids['img']}" => ['variants' => [self::ref('loud')]],
    ], $this->styleMap($entity));
  }

  /**
   * A direct apply on the child takes the axis over; a root switch leaves it.
   *
   * The boundary in one gesture: the editor applying "row" on the wrapper
   * the composition already dressed in "row" writes a FRESH reference with
   * no origin (the same variant re-placed), so the wear is now authored
   * and the composition's later switch leaves it alone while still reaping
   * its own fragment reference beside it. Re-applying the composition
   * re-stamps: the wear is derived again and the next switch takes it.
   */
  public function testDirectApplyOnChildTakesOver(): void {
    $this->createCardScenario();
    [$entity, $ids] = $this->buildComposition([]);
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'card_left'],
      ['op' => 'apply_variant', 'key' => "container:{$ids['nsec']}", 'variant' => 'row'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals(
      ['variants' => [self::ref('row'), self::ref('card_left', 'container(1)')]],
      $this->styleMap($entity)["container:{$ids['nsec']}"],
    );

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'stacked'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('stacked')]],
      "container:{$ids['nsec']}" => ['variants' => [self::ref('row'), self::ref('stacked', 'container(1)')]],
    ], $this->styleMap($entity));

    // Re-apply of the composition re-stamps the wear it nests.
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'card_left'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals(
      ['variants' => [self::ref('row', via: 'card_left'), self::ref('card_left', 'container(1)')]],
      $this->styleMap($entity)["container:{$ids['nsec']}"],
    );
  }

  /**
   * Detach releases provenance: the nested wear stays, the origin drops.
   *
   * Detach keeps the look, so the group gesture never reaps a nested
   * wear; it materializes the composition's own values and hands the
   * nested references to their carriers (the root entry's own included)
   * by dropping their "via". From then on they are the elements' own
   * wears: a later composition applied and switched on the root leaves
   * them alone. The delete-in-use batch's per-wearer step
   * (detachVariantEverywhere()) releases the same way, over the whole
   * map.
   */
  public function testDetachReleasesProvenance(): void {
    $this->createCardScenario();
    [$entity, $ids] = $this->buildComposition([]);
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'card_left'],
      ['op' => 'detach_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'card_left'],
    ]);
    $this->assertTrue($result->isSuccess());
    $released = [
      "container:{$ids['sec']}" => ['variants' => [self::ref('half')], 'default' => ['padding' => '2rem']],
      "container:{$ids['nsec']}" => ['variants' => [self::ref('row')], 'default' => ['margin' => '1px']],
    ];
    $this->assertEquals($released, $this->styleMap($entity));

    // Released wears are the elements' own: a composition applied and
    // removed afterwards reaps only what it nested itself (nothing here),
    // and the released wears ride through both gestures untouched.
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'stacked'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      "container:{$ids['sec']}" => [
        'variants' => [self::ref('half'), self::ref('stacked')],
        'default' => ['padding' => '2rem'],
      ],
      "container:{$ids['nsec']}" => [
        'variants' => [self::ref('row'), self::ref('stacked', 'container(1)')],
        'default' => ['margin' => '1px'],
      ],
    ], $this->styleMap($entity));
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'remove_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'stacked'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals($released, $this->styleMap($entity));

    // The site gesture: the same release over the whole map.
    [$entity, $ids] = $this->buildComposition([]);
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:{$ids['sec']}", 'variant' => 'card_left'],
    ]);
    $this->assertTrue($result->isSuccess());
    $card_left = $this->container->get('daedalus_stylish.style_map_validator')->loadVariant('card_left');
    $this->assertTrue($this->container->get('daedalus_stylish.operation_handler')->detachVariantEverywhere($this->host($entity), $card_left));
    $this->assertEquals([
      "container:{$ids['sec']}" => ['variants' => [self::ref('half')], 'default' => ['padding' => '2rem']],
      "container:{$ids['nsec']}" => ['variants' => [self::ref('row')], 'default' => ['margin' => '1px']],
    ], $this->styleMap($entity));
  }

  /**
   * Detaching a plain block root detaches its distributed field fragments.
   *
   * The block-root shape of detach-all: field entries carry their own
   * fragment references (distributed by apply through the field(name)
   * grammar), and they ride their block in the subtree scoping, so
   * detaching the block's root wearing materializes each field fragment
   * (plus its '::.class' part styles) onto the field's own keys and drops
   * it with the root reference.
   */
  public function testDetachOnBlockRootMaterializesFieldFragments(): void {
    $variant = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'block_type:basic']);
    $variant->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      'field(field_x)' => ['default' => ['margin' => '1rem']],
      'field(field_x)::.field__item' => ['default' => ['margin' => '2px']],
    ]);
    $variant->save();

    [$entity, $ids] = $this->buildComposition([
      'block:{b1}' => ['variants' => [['variant' => 'card', 'fragment' => '']]],
      'block:{b1}:field_x' => ['variants' => [self::ref('card', 'field(field_x)')]],
    ]);
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'detach_variant', 'key' => "block:{$ids['b1']}", 'variant' => 'card'],
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertEquals([
      "block:{$ids['b1']}" => ['default' => ['padding' => '2rem']],
      "block:{$ids['b1']}:field_x" => ['default' => ['margin' => '1rem']],
      "block:{$ids['b1']}:field_x::.field__item" => ['default' => ['margin' => '2px']],
    ], $this->styleMap($entity));
  }

  /**
   * A nested section captures its layout scope: ONE rule at every depth.
   *
   * A section's declaration IS its layout's declaration, top-level or
   * nested (position, not kind): scope capture, matching, and the ordinary
   * per-block-type rule for inline blocks all read identically at depth.
   */
  public function testNestedSectionScopeCapture(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    [$entity, $ids] = $this->buildComposition([]);
    $context = ['host' => $this->host($entity)];

    $this->assertSame('layout:daedalus_stylish_container', (string) $validator->captureScope("container:{$ids['nsec']}", $context));
    // A rule key captures its LANDING: a part is no element scope (its
    // element wears), a type compound its own kind (a rule's matches may
    // be any layout), and the full address keeps the layout.
    $this->assertNull($validator->captureScope("container:{$ids['nsec']}::.daedalus-stylish-layout-container", $context));
    $this->assertSame('kind:container', (string) $validator->captureScope("container:{$ids['nsec']}::> pb-container", $context));
    $this->assertSame('layout:daedalus_stylish_container::.daedalus-stylish-layout-container', (string) $validator->keyAddress("container:{$ids['nsec']}::.daedalus-stylish-layout-container", $context));
    // An ordinary inline block still scopes by block type, and a block
    // INSIDE the nested section resolves too (the locator walks depth).
    $this->assertSame('block_type:basic', (string) $validator->captureScope("block:{$ids['b1']}", $context));
    $this->assertSame('block_type:basic', (string) $validator->captureScope("block:{$ids['img']}", $context));

    // Matching follows: layout-exact within the section family, the kind
    // as wildcard, and never across families.
    $this->assertTrue($validator->scopeMatches('layout:daedalus_stylish_container', "container:{$ids['nsec']}", $context));
    $this->assertTrue($validator->scopeMatches('kind:container', "container:{$ids['nsec']}", $context));
    $this->assertFalse($validator->scopeMatches('layout:layout_twocol_section', "container:{$ids['nsec']}", $context));
    $this->assertFalse($validator->scopeMatches('kind:block', "container:{$ids['nsec']}", $context));
    $this->assertFalse($validator->scopeMatches('layout:daedalus_stylish_container', "block:{$ids['img']}", $context));
  }


  /**
   * A rule wears flat variants only, and distributes nothing.
   *
   * A composition is refused at a rule by its keys (its slot paths could
   * bind no one subtree), and a flat variant with a children entry lands
   * as one reference on the rule, no fragment reference on any child:
   * detach then continues the children entry under the rule by descent.
   */
  public function testRuleWearDistributesNothing(): void {
    $tidy = DaedalusStylishVariant::create(['label' => 'Tidy', 'machine_name' => 'tidy', 'scope' => 'kind:container']);
    $tidy->setStyles([
      '' => ['default' => ['padding' => '1rem']],
      '::> *' => ['default' => ['margin' => '0']],
    ])->save();
    $card = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'layout:daedalus_stylish_container']);
    $card->setStyles([
      '' => ['default' => ['padding' => '0']],
      'container(1)' => ['default' => ['gap' => '2rem']],
    ])->save();
    [$entity, $ids] = $this->buildComposition([]);
    $rule = "container:{$ids['sec']}::> pb-container";

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => $rule, 'variant' => 'card'],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('STYLE_VARIANT_COMPOSITION_AT_RULE', $error->getCode());
    $this->assertSame(['container(1)'], $error->getContext()['slot_paths']);
    $this->assertSame([], $this->styleMap($entity));

    $this->assertTrue($this->operations->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => $rule, 'variant' => 'tidy'],
    ])->isSuccess());
    $this->assertSame([$rule => ['variants' => [self::ref('tidy')]]], $this->styleMap($entity));

    $this->assertTrue($this->operations->batchOperations($this->host($entity), [
      ['op' => 'detach_variant', 'key' => $rule, 'variant' => 'tidy'],
    ])->isSuccess());
    $this->assertSame([
      $rule => ['default' => ['padding' => '1rem']],
      "$rule > *" => ['default' => ['margin' => '0']],
    ], $this->styleMap($entity));
  }

}
