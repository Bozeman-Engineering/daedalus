<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the style variant content entity.
 *
 * Variants are revisionable, publishable content (which is what makes them
 * workspace-aware), carry an auto-captured scope, and are governed by
 * three permissions. The validator enforces reference existence and
 * scope match on every style map write.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishVariantTest extends KernelTestBase {

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
    'workspaces',
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
    $this->installConfig(['daedalus_stylish']);
    $this->uuid = $this->container->get('uuid')->generate();
  }

  /**
   * Creates and saves a variant.
   */
  protected function createVariant(array $values = []): DaedalusStylishVariant {
    $variant = DaedalusStylishVariant::create($values + [
      'label' => 'Bold CTA',
      'scope' => 'block_type:cta',
    ]);
    $variant->setStyles([
      '' => [
        'default' => ['padding' => '2rem'],
        'hover' => ['background-color' => '#eaeaea'],
      ],
      '::.cta__button' => ['default' => ['font-weight' => '700']],
    ]);
    $variant->save();
    return $variant;
  }

  /**
   * Labels, machine names, scope requirement, and styles round-trip.
   */
  public function testEntityBasics(): void {
    $variant = $this->createVariant();
    $reloaded = DaedalusStylishVariant::load($variant->id());

    // The machine name derives from the label and never collides.
    $this->assertSame('bold_cta', $reloaded->getMachineName());
    $this->assertSame('bold_cta_2', $this->createVariant()->getMachineName());
    $this->assertSame('cta_bold', $this->createVariant(['machine_name' => 'cta_bold'])->getMachineName());

    $this->assertSame('Bold CTA', $reloaded->label());
    $this->assertSame('block_type:cta', $reloaded->getScope());
    // An omitted axis lands on the built-in "style" axis: the
    // single-slot behavior is the degenerate one-axis case, not a mode.
    $this->assertSame('style', $reloaded->getAxis());
    // Styles round-trip as element-relative keys: "" is the root element,
    // "::.class" keys are its sub-elements.
    $this->assertSame(['default' => ['padding' => '2rem'], 'hover' => ['background-color' => '#eaeaea']], $reloaded->getStyles()['']);
    $this->assertSame(['default' => ['font-weight' => '700']], $reloaded->getStyles()['::.cta__button']);

    // The scope is load-bearing (applicability, validation) — a variant
    // cannot be saved without one. Storage wraps the preSave exception.
    try {
      DaedalusStylishVariant::create(['label' => 'No scope'])->save();
      $this->fail('A scope-less variant saved.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
    }
  }

  /**
   * The axis field: scope-validated vocabulary, "style" default, identity.
   */
  public function testAxisField(): void {
    // A declared axis of the scope persists: the testimonial block type
    // declares the emphasis axis (daedalus_stylish_guide_test fixture).
    $variant = $this->createVariant([
      'machine_name' => 'quote_pop',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
    ]);
    $this->assertSame('emphasis', DaedalusStylishVariant::load($variant->id())->getAxis());

    // The scope's vocabulary is closed: an axis its declaration does not
    // carry (and that is not the built-in "style") cannot be saved.
    try {
      $this->createVariant(['scope' => 'block_type:testimonial', 'axis' => 'width']);
      $this->fail('A variant saved on an axis its scope does not declare.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertStringContainsString('style, emphasis', $e->getPrevious()->getMessage());
    }

    // A scope declaring no variant_axes speaks only the built-in axis.
    try {
      $this->createVariant(['axis' => 'proportions']);
      $this->fail('A variant saved on an undeclared axis of an axis-less scope.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
    }

    // A string that is not a scope at all fails at save: the axis check
    // needs a resolvable vocabulary, and a bogus scope has none.
    try {
      DaedalusStylishVariant::create(['label' => 'Bogus', 'scope' => 'testimonial'])->save();
      $this->fail('A variant with a malformed scope saved.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
    }

    // The axis is an identity field like machine_name and scope: what a
    // reference means (which lane it occupies) must survive reverts.
    $definitions = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('daedalus_variant');
    $this->assertFalse($definitions['axis']->isRevisionable());
  }

  /**
   * The weight field: roster order within a family, default 0, revisionable.
   */
  public function testWeightField(): void {
    // A fresh save lands on the default weight 0: user-minted variants stay
    // alphabetical among themselves, only shipped scales set weights.
    $variant = $this->createVariant();
    $reloaded = DaedalusStylishVariant::load($variant->id());
    $this->assertFalse($reloaded->get('weight')->isEmpty());
    $this->assertSame(0, $reloaded->getWeight());

    // An explicit weight round-trips.
    $reloaded->setWeight(-10)->save();
    $this->assertSame(-10, DaedalusStylishVariant::load($variant->id())->getWeight());

    // Weight is a presentation fact, not identity: revisionable like label,
    // unlike machine_name/scope/axis.
    $definitions = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('daedalus_variant');
    $this->assertTrue($definitions['weight']->isRevisionable());
    $this->assertSame([['value' => 0]], $definitions['weight']->getDefaultValueLiteral());
  }

  /**
   * Base:{tag}-scoped variants: tag treatments save and are wearable.
   *
   * The scope family gained in the tag/part-scoped offering work: a base
   * scope resolves the built-in "style" axis (the site's base section
   * declares no variant_axes here), the closed tag vocabulary still gates
   * at save, and the variant pairs with every element key — whether the
   * element renders as the tag is a live-DOM fact enforced softly at
   * offer time (the client's tag filter), never at validation.
   */
  public function testBaseScopedVariant(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Accent link',
      'scope' => 'base:a',
    ]);
    $variant->setStyles(['' => ['default' => ['color' => '#336699']]]);
    $variant->save();
    $this->assertSame('style', DaedalusStylishVariant::load($variant->id())->getAxis());

    // The wearing validates on any element key (soft enforcement): the
    // reference passes exactly like a family-matched one.
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->assertSame([], $validator->validate([
      "block:{$this->uuid}" => ['variants' => [['variant' => 'accent_link', 'fragment' => '']]],
    ]));
    $this->assertSame([], $validator->validate([
      "container:{$this->uuid}" => ['variants' => [['variant' => 'accent_link', 'fragment' => '']]],
    ]));

    // The corrective's available list carries base variants everywhere,
    // mirroring the pairing rule.
    $errors = $validator->validate([
      "block:{$this->uuid}" => ['variants' => [['variant' => 'no_such_variant', 'fragment' => '']]],
    ]);
    $this->assertSame('STYLE_VARIANT_NOT_FOUND', $errors[0]->getCode());
    $this->assertContains('accent_link', array_column($errors[0]->getContext()['candidates'], 'id'));

    // A tag outside the closed vocabulary is still no scope at all.
    try {
      DaedalusStylishVariant::create(['label' => 'Div style', 'scope' => 'base:div'])->save();
      $this->fail('A variant saved with a tag outside the base vocabulary.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
    }
  }

  /**
   * Shipped-variant identity: provider, source_hash, the two comparisons.
   */
  public function testShippedIdentity(): void {
    // A UI-created variant has no disk source: both detection helpers are
    // meaningless for it and must stay FALSE (no revert is ever offered).
    $variant = $this->createVariant();
    $this->assertNull($variant->getProvider());
    $this->assertNull($variant->getSourceHash());
    $this->assertFalse($variant->isShipped());
    $this->assertFalse($variant->isModified());
    $this->assertFalse($variant->hasNewDefaults(['' => ['default' => ['padding' => '9rem']]]));

    // Import: the importer records the disk source alongside the styles.
    $shipped = $variant->getStyles();
    $variant->setShippedSource('example_cta', $shipped)->save();
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertSame('example_cta', $variant->getProvider());
    $this->assertTrue($variant->isShipped());
    $this->assertSame(DaedalusStylishVariant::hashStyles($shipped), $variant->getSourceHash());

    // Freshly synced: neither comparison fires. The hash is over CONTENT,
    // so a YAML author reordering keys is not a defaults change.
    $this->assertFalse($variant->isModified());
    $this->assertFalse($variant->hasNewDefaults($shipped));
    $reordered = array_reverse($shipped, TRUE);
    $reordered[''] = array_reverse($reordered[''], TRUE);
    $this->assertFalse($variant->hasNewDefaults($reordered));

    // Comparison 1: the user edits the variant → modified; the disk file is
    // unchanged, so hasNewDefaults still says no.
    $variant->setStyles(['' => ['default' => ['padding' => '5rem']]])->save();
    $this->assertTrue($variant->isModified());
    $this->assertFalse($variant->hasNewDefaults($shipped));

    // Comparison 2: a module update changes the shipped styles.
    $this->assertTrue($variant->hasNewDefaults(['' => ['default' => ['padding' => '3rem']]]));

    // Identity fields are non-revisionable, like machine_name and scope: a
    // revision revert must never resurrect a stale shipped-source state.
    $definitions = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('daedalus_variant');
    $this->assertFalse($definitions['provider']->isRevisionable());
    $this->assertFalse($definitions['source_hash']->isRevisionable());
  }

  /**
   * Revisionable and publishable — and therefore workspace-supported.
   */
  public function testRevisionsAndWorkspaceSupport(): void {
    $entity_type = $this->container->get('entity_type.manager')->getDefinition('daedalus_variant');
    $this->assertTrue($entity_type->isRevisionable());
    $this->assertTrue($this->container->get('workspaces.information')->isEntityTypeSupported($entity_type));

    // A push saves a new revision; a bad push is one revert away.
    $variant = $this->createVariant();
    $first_revision_id = $variant->getRevisionId();
    $variant->setNewRevision();
    $variant->setStyles(['' => ['default' => ['padding' => '4rem']]]);
    $variant->setRevisionLogMessage('Pushed from block:abc');
    $variant->save();

    $this->assertNotSame($first_revision_id, $variant->getRevisionId());
    $storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');
    $old = $storage->loadRevision($first_revision_id);
    $this->assertSame('2rem', $old->getStyles()['']['default']['padding']);
    $this->assertSame('4rem', DaedalusStylishVariant::load($variant->id())->getStyles()['']['default']['padding']);
  }

  /**
   * Scope capture: per-block-type for blocks, kind for everything else.
   */
  public function testScopeCapture(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');

    $this->assertSame('kind:entity', (string) $validator->captureScope("entity:{$this->uuid}"));
    $this->assertSame('kind:container', (string) $validator->captureScope("container:{$this->uuid}"));
    // Region keys left the grammar with the container model.
    $this->assertNull($validator->captureScope("region:{$this->uuid}:content"));
    $this->assertNull($validator->captureScope("section:{$this->uuid}"));
    $this->assertSame('kind:field', (string) $validator->captureScope("field:{$this->uuid}:field_body"));
    // A field of an inline block styles — and scopes — like any field.
    $this->assertSame('kind:field', (string) $validator->captureScope("block:{$this->uuid}:field_quote"));
    // A rule key captures its LANDING, the element its last compound
    // names, and a part lands on no element scope (nothing wears there);
    // keyAddress() still answers the full address.
    $this->assertNull($validator->captureScope("container:{$this->uuid}::.inner"));
    $this->assertSame('kind:container::.inner', (string) $validator->keyAddress("container:{$this->uuid}::.inner"));
    $this->assertNull($validator->captureScope('bogus:123'));

    // A block without a resolvable bundle degrades to the universal block
    // kind, exactly like declaration resolution.
    $this->assertSame('kind:block', (string) $validator->captureScope("block:{$this->uuid}"));

    // With the host entity in context, blocks capture their bundle.
    $component_uuid = $this->setUpInlineBlockHost($host);
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->assertSame('block_type:testimonial', (string) $validator->captureScope("block:$component_uuid", ['entity' => $host]));

    // A PLUGIN placement captures its BASE plugin id (the :derivative
    // suffix strips — derivatives share one template, so one scope covers
    // them), the block family's other refinement.
    $plugin_uuid = $this->addPluginBlock($host, 'system_menu_block:account');
    $this->assertSame('block_plugin:system_menu_block', (string) $validator->captureScope("block:$plugin_uuid", ['entity' => $host]));
    $this->assertSame('block_plugin:system_menu_block::.menu-item', (string) $validator->keyAddress("block:$plugin_uuid::.menu-item", ['entity' => $host]));
    $this->assertNull($validator->captureScope("block:$plugin_uuid::.menu-item", ['entity' => $host]));

    // Matching: exact, plus the degraded block-family looseness — a
    // "kind:block" side matches any block scope, but two resolved block
    // types never cross.
    $this->assertTrue($validator->scopeMatches('kind:container', "container:{$this->uuid}"));
    $this->assertTrue($validator->scopeMatches('block_type:testimonial', "block:$component_uuid", ['entity' => $host]));
    $this->assertTrue($validator->scopeMatches('block_type:testimonial', "block:{$this->uuid}"));
    $this->assertTrue($validator->scopeMatches('kind:block', "block:$component_uuid", ['entity' => $host]));
    $this->assertFalse($validator->scopeMatches('block_type:cta', "block:$component_uuid", ['entity' => $host]));
    // Families never mix, degraded or resolved: blocks never hold layouts,
    // so a block key never pairs with section-family scopes and vice versa.
    $this->assertFalse($validator->scopeMatches('kind:container', "block:{$this->uuid}"));
    $this->assertFalse($validator->scopeMatches('layout:layout_onecol', "block:{$this->uuid}"));
    $this->assertFalse($validator->scopeMatches('kind:block', "container:{$this->uuid}"));
    // A RESOLVED ordinary block stays in its family.
    $this->assertFalse($validator->scopeMatches('kind:container', "block:$component_uuid", ['entity' => $host]));
    $this->assertFalse($validator->scopeMatches('layout:layout_onecol', "block:$component_uuid", ['entity' => $host]));
    // Plugin placements: exact refinement and the kind wildcard match; the
    // block family's two refinements never pair with each other.
    $this->assertTrue($validator->scopeMatches('block_plugin:system_menu_block', "block:$plugin_uuid", ['entity' => $host]));
    $this->assertTrue($validator->scopeMatches('kind:block', "block:$plugin_uuid", ['entity' => $host]));
    $this->assertFalse($validator->scopeMatches('block_plugin:other_plugin', "block:$plugin_uuid", ['entity' => $host]));
    $this->assertFalse($validator->scopeMatches('block_type:testimonial', "block:$plugin_uuid", ['entity' => $host]));
    $this->assertFalse($validator->scopeMatches('block_plugin:system_menu_block', "block:$component_uuid", ['entity' => $host]));
  }

  /**
   * The validator enforces reference existence and scope on map writes.
   */
  public function testVariantReferenceValidation(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $key = "block:{$this->uuid}";

    // Unknown reference: per-reference error paths index into the list.
    $errors = $validator->validate([$key => ['variants' => [['variant' => 'no_such_variant', 'fragment' => '']]]]);
    $this->assertSame('STYLE_VARIANT_NOT_FOUND', $errors[0]->getCode());
    $this->assertSame("$key.variants.0", $errors[0]->getPath());

    // Scope mismatch: a block-scoped variant on a section element.
    $variant = $this->createVariant(['machine_name' => 'cta_bold']);
    $errors = $validator->validate([
      "container:{$this->uuid}" => ['variants' => [['variant' => 'cta_bold', 'fragment' => '']]],
    ]);
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertSame('block_type:cta', $errors[0]->getContext()['variant_scope']);
    $this->assertSame('kind:container', $errors[0]->getContext()['element_scope']);

    // A matching reference passes — by machine name or by uuid.
    $this->assertSame([], $validator->validate([$key => ['variants' => [['variant' => 'cta_bold', 'fragment' => '']]]]));
    $this->assertSame([], $validator->validate([
      $key => ['variants' => [['variant' => $variant->uuid(), 'fragment' => '']]],
    ]));
  }

  /**
   * The layered entry grammar: the "variants" list shape and coexistence.
   */
  public function testVariantListGrammar(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->createVariant(['machine_name' => 'cta_bold']);
    $this->createVariant(['machine_name' => 'cta_wide']);
    $key = "block:{$this->uuid}";

    // References and condition overrides coexist: overrides beat the
    // referenced variants, so both halves of the entry validate.
    $this->assertSame([], $validator->validate([
      $key => ['variants' => [['variant' => 'cta_bold', 'fragment' => '']], 'default' => ['padding' => '2rem']],
    ]));

    // Multiple references at one key are the axes grammar: one variant
    // per axis, composed across axes. quote_pop rides the emphasis axis the
    // testimonial block type declares; cta_bold the built-in style axis.
    $this->createVariant([
      'machine_name' => 'quote_pop',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
    ]);
    $this->assertSame([], $validator->validate([
      $key => [
        'variants' => [['variant' => 'cta_bold', 'fragment' => ''], ['variant' => 'quote_pop', 'fragment' => '']],
      ],
    ]));
    $errors = $validator->validate([
      $key => ['variants' => [['variant' => 'cta_bold', 'fragment' => ''], ['variant' => 'missing', 'fragment' => '']]],
    ]);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_NOT_FOUND', $errors[0]->getCode());
    $this->assertSame("$key.variants.1", $errors[0]->getPath());

    // Two references sharing an axis conflict: both cta variants sit on the
    // built-in style axis, and an element wears one variant per axis.
    $errors = $validator->validate([
      $key => ['variants' => [['variant' => 'cta_bold', 'fragment' => ''], ['variant' => 'cta_wide', 'fragment' => '']]],
    ]);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_AXIS_CONFLICT', $errors[0]->getCode());
    $this->assertSame("$key.variants.1", $errors[0]->getPath());
    $this->assertSame('style', $errors[0]->getContext()['axis']);
    $this->assertSame('cta_bold', $errors[0]->getContext()['conflicting_reference']);

    // The list itself must be a non-empty list of reference objects.
    foreach (['cta_bold', [], ['nested' => 'cta_bold']] as $malformed) {
      $errors = $validator->validate([$key => ['variants' => $malformed]]);
      $this->assertSame('INVALID_STYLE_ENTRY', $errors[0]->getCode());
      $this->assertSame("$key.variants", $errors[0]->getPath());
    }
    // The retired bare-string reference form is invalid input with a
    // corrective pointing at the one shape, never silently accepted.
    foreach (['cta_bold', ''] as $retired) {
      $errors = $validator->validate([$key => ['variants' => [['variant' => 'cta_bold', 'fragment' => ''], $retired]]]);
      $this->assertSame('INVALID_STYLE_ENTRY', $errors[0]->getCode());
      $this->assertSame("$key.variants.1", $errors[0]->getPath());
    }
    $errors = $validator->validate([
      $key => ['variants' => [['variant' => 'cta_bold', 'fragment' => ''], ['variant' => 'cta_bold', 'fragment' => '']]],
    ]);
    $this->assertSame('INVALID_STYLE_ENTRY', $errors[0]->getCode());
    $this->assertSame("$key.variants.1", $errors[0]->getPath());

    // The dead single-reference grammar is invalid input with a corrective
    // pointing at the list — never silently accepted.
    $errors = $validator->validate([$key => ['variant' => 'cta_bold']]);
    $this->assertSame('INVALID_STYLE_ENTRY', $errors[0]->getCode());
    $this->assertSame("$key.variant", $errors[0]->getPath());
    $this->assertStringContainsString('"variants"', $errors[0]->getMessage());

    // entryVariants() is the one read semantics: well-formed references
    // only, in application order. Bare strings are the retired form and
    // read as malformed.
    $this->assertSame([
      'a',
      'b',
    ], StyleMapValidator::entryVariants([
      'variants' => [['variant' => 'a', 'fragment' => ''], ['variant' => 'b', 'fragment' => '']],
    ]));
    $this->assertSame([
      'a',
    ], StyleMapValidator::entryVariants(['variants' => [['variant' => 'a', 'fragment' => ''], 'b', '', 5]]));
    $this->assertSame([], StyleMapValidator::entryVariants(['default' => ['color' => 'red']]));
    $this->assertSame([], StyleMapValidator::entryVariants('nope'));
  }

  /**
   * Fragment references: {"variant", "fragment"} objects in the list.
   *
   * The distributed-reference schema: applying a composition variant binds
   * one fragment onto each resolved element as a {"variant", "fragment"}
   * object in that element's own "variants" list. Validation checks the
   * variant exists and the fragment parses against the variant scope's
   * slot-key root kind — never the carrier's scope (bound refs ride moved
   * elements anywhere) and never whether the variant still holds the
   * fragment (stale is valid but inert).
   */
  public function testFragmentReferenceValidation(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $comp = DaedalusStylishVariant::create([
      'label' => 'Hero band',
      'machine_name' => 'hero_band',
      'scope' => 'layout:layout_twocol_section',
    ]);
    $comp->setStyles([
      '' => ['default' => ['padding' => '1rem']],
      'block(testimonial,1)' => ['default' => ['font-size' => '1.5rem']],
    ]);
    $comp->save();
    $block_key = "block:{$this->uuid}";
    $section_key = "container:{$this->uuid}";

    // A bound fragment reference is a pure lookup key: it validates on any
    // carrier with no scope match against the carrying element.
    $ref = ['variant' => 'hero_band', 'fragment' => 'block(testimonial,1)'];
    $this->assertSame([], $validator->validate([$block_key => ['variants' => [$ref]]]));

    // A fragment the variant no longer holds is valid but inert.
    $this->assertSame([], $validator->validate([
      $block_key => ['variants' => [['variant' => 'hero_band', 'fragment' => 'block(testimonial,2)']]],
    ]));

    // The "" fragment is the variant root: it validates as a direct
    // wearing, scope match included.
    $this->assertSame([], $validator->validate([
      $section_key => ['variants' => [['variant' => 'hero_band', 'fragment' => '']]],
    ]));
    $errors = $validator->validate([$block_key => ['variants' => [['variant' => 'hero_band', 'fragment' => '']]]]);
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());

    // The fragment must parse against the variant scope's root kind:
    // container roots open at their children, never fields.
    $errors = $validator->validate([
      $block_key => ['variants' => [['variant' => 'hero_band', 'fragment' => 'field(field_quote)']]],
    ]);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_STYLE_FRAGMENT', $errors[0]->getCode());
    $this->assertSame("$block_key.variants.0.fragment", $errors[0]->getPath());
    $this->assertSame('layout:layout_twocol_section', $errors[0]->getContext()['variant_scope']);
    // The corrective enumerates what the variant currently holds.
    $this->assertSame(['', 'block(testimonial,1)'], $errors[0]->getContext()['variant_fragments']);

    // The variant must exist.
    $errors = $validator->validate([
      $block_key => ['variants' => [['variant' => 'ghost', 'fragment' => 'block(testimonial,1)']]],
    ]);
    $this->assertSame('STYLE_VARIANT_NOT_FOUND', $errors[0]->getCode());
    $this->assertSame("$block_key.variants.0", $errors[0]->getPath());

    // One shape, no shorthand: exactly {"variant", "fragment"}, both
    // strings, the variant non-empty.
    $malformed = [
      ['variant' => 'hero_band'],
      ['variant' => 'hero_band', 'fragment' => '', 'extra' => TRUE],
      ['variant' => '', 'fragment' => ''],
      ['variant' => 'hero_band', 'fragment' => 5],
      ['fragment' => ''],
    ];
    foreach ($malformed as $reference) {
      $errors = $validator->validate([$block_key => ['variants' => [$reference]]]);
      $this->assertSame('INVALID_STYLE_ENTRY', $errors[0]->getCode());
      $this->assertSame("$block_key.variants.0", $errors[0]->getPath());
    }

    // DECISION (revised for entry-shaped fragments): within one axis a
    // ROOT reference and a FRAGMENT reference COMPOSE — the composition
    // pair. Apply distributes a fragment's nested root references beside
    // the fragment reference itself, and the fragment's values (the
    // captured overrides of the element it dressed) deliver over the
    // nested variant (orderedEntryRefs()). A direct same-axis apply still
    // replaces BOTH halves of the pair (partial-wearing honesty is a
    // placement rule, DaedalusStylishOperationHandler::placeReference(), not a
    // grammar rule), and two SAME-KIND references on one axis still
    // conflict.
    $this->createVariant(['machine_name' => 'cta_bold']);
    $this->assertSame([], $validator->validate([
      $block_key => ['variants' => [['variant' => 'cta_bold', 'fragment' => ''], $ref]],
    ]));
    // Same kind, same axis: two fragment references conflict exactly as
    // two root references do.
    $band_two = DaedalusStylishVariant::create([
      'label' => 'Band two',
      'machine_name' => 'band_two',
      'scope' => 'layout:layout_twocol_section',
    ]);
    $band_two->setStyles(['block(testimonial,1)' => ['default' => ['font-size' => '2rem']]]);
    $band_two->save();
    $errors = $validator->validate([
      $block_key => ['variants' => [$ref, ['variant' => 'band_two', 'fragment' => 'block(testimonial,1)']]],
    ]);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_AXIS_CONFLICT', $errors[0]->getCode());
    $this->assertSame("$block_key.variants.1", $errors[0]->getPath());
    $this->assertSame('style', $errors[0]->getContext()['axis']);
    $this->assertSame('hero_band', $errors[0]->getContext()['conflicting_reference']);

    // An element wears at most one fragment of a variant: the duplicate
    // rule keys on the variant regardless of fragment.
    $errors = $validator->validate([
      $section_key => [
        'variants' => [
          ['variant' => 'hero_band', 'fragment' => ''],
          ['variant' => 'hero_band', 'fragment' => 'block(testimonial,1)'],
        ],
      ],
    ]);
    $this->assertSame('INVALID_STYLE_ENTRY', $errors[0]->getCode());
    $this->assertSame("$section_key.variants.1", $errors[0]->getPath());

    // entryRefs() is the one structured read semantics; malformed items,
    // the retired bare-string form included, are ignored, and a
    // reference with no "via" reads a NULL origin.
    $this->assertSame([
      ['variant' => 'a', 'fragment' => '', 'via' => NULL],
      ['variant' => 'b', 'fragment' => 'container(1)', 'via' => NULL],
    ], StyleMapValidator::entryRefs([
      'variants' => [['variant' => 'a', 'fragment' => ''], ['variant' => 'b', 'fragment' => 'container(1)']],
    ]));
    $this->assertSame([], StyleMapValidator::entryRefs([
      'variants' => ['a', 5, ['variant' => 'b'], ['variant' => '', 'fragment' => '']],
    ]));
    // entryVariants() projects the references: a fragment reference counts
    // as wearing its variant.
    $this->assertSame([
      'a',
      'b',
    ], StyleMapValidator::entryVariants([
      'variants' => [['variant' => 'a', 'fragment' => ''], ['variant' => 'b', 'fragment' => 'container(1)']],
    ]));

    // Fragment references ride axis-declaration delivery order as today.
    $this->createVariant([
      'machine_name' => 'quote_pop',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
    ]);
    $this->assertSame(['cta_bold', 'quote_pop'], $validator->orderedEntryVariants([
      'variants' => [['variant' => 'quote_pop', 'fragment' => ''], ['variant' => 'cta_bold', 'fragment' => '']],
    ]));
  }

  /**
   * The three permissions map onto entity access; view is open.
   */
  public function testAccess(): void {
    // Burn uid 1, the superuser bypass.
    $this->createUser();
    $variant = $this->createVariant();

    $nobody = $this->createUser();
    $this->assertFalse($variant->access('update', $nobody));
    $this->assertFalse($variant->access('delete', $nobody));
    $this->assertFalse($this->container->get('entity_type.manager')->getAccessControlHandler('daedalus_variant')->createAccess(NULL, $nobody));
    // Applying a variant is permissionless (an ordinary host-entity edit),
    // so anyone may see published variants — their CSS is public anyway.
    $this->assertTrue($variant->access('view', $nobody));

    $creator = $this->createUser(['create variants']);
    $this->assertTrue($this->container->get('entity_type.manager')->getAccessControlHandler('daedalus_variant')->createAccess(NULL, $creator));
    $this->assertFalse($variant->access('update', $creator));

    $updater = $this->createUser(['update variants']);
    $this->assertTrue($variant->access('update', $updater));
    $this->assertFalse($variant->access('delete', $updater));

    $admin = $this->createUser(['administer variants']);
    $this->assertTrue($variant->access('update', $admin));
    $this->assertTrue($variant->access('delete', $admin));

    // Unpublished variants (workspace drafts, retired sets) hide from
    // everyone but administrators. The access handler statically caches per
    // (entity, operation, account); saving does not clear it.
    $variant->setUnpublished()->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('daedalus_variant')->resetCache();
    $this->assertFalse($variant->access('view', $nobody));
    $this->assertTrue($variant->access('view', $admin));
  }

  /**
   * Builds an entity_test host with a testimonial inline block placement.
   *
   * @return string
   *   The placement's component uuid; $host receives the saved entity.
   */
  protected function setUpInlineBlockHost(?EntityTest &$host): string {
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
    $host = EntityTest::create(['name' => 'host', 'layout_builder__layout' => [$section]]);
    $host->save();
    return $component_uuid;
  }

  /**
   * Appends a plugin block placement to the host's first section.
   *
   * The component locator reads the placement's configured plugin id
   * without instantiating the plugin, so any id works.
   *
   * @return string
   *   The placement's component uuid.
   */
  protected function addPluginBlock(EntityTest $host, string $plugin_id): string {
    $component_uuid = $this->container->get('uuid')->generate();
    $sections = $host->get('layout_builder__layout')->getSections();
    $sections[0]->appendComponent(new SectionComponent($component_uuid, 'content', [
      'id' => $plugin_id,
      'provider' => 'system',
      'label' => 'Plugin block',
      'label_display' => 'visible',
    ]));
    $host->save();
    return $component_uuid;
  }

}
