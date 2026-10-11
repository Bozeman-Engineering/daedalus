<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_stylish\CssPrinter;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\VariantStylesheet;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\daedalus_stylish\Traits\HostTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests variant CSS delivery.
 *
 * Compile-on-save into one shared, hash-busted stylesheet file; the
 * "daedalus_stylish/variants" library resolving the current file at discovery time;
 * variant classes stamped at render on the elements whose map entries
 * reference a variant; and the workspace-preview edge — staged variant
 * revisions inline into the entity's own style tag while the shared file
 * keeps the live CSS until the workspace publishes.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantStylesheetTest extends KernelTestBase {

  use HostTrait;
  use UserCreationTrait;
  use WorkspaceTestTrait;
  use DesignSystemActivationTrait;

  /**
   * The entity type manager, re-fetched by WorkspaceTestTrait.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
    'layout_discovery',
    'layout_builder',
    'entity_test',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_layout',
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
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('daedalus_variant');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    // Variants are a swatch-usage source: publish resyncs their rows.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser([], ['view test entity']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * Creates and saves a variant (which compiles via hook_entity_insert()).
   */
  protected function createVariant(string $machine_name, array $styles, bool $published = TRUE, string $scope = 'kind:block', ?string $axis = NULL): DaedalusStylishVariant {
    $variant = DaedalusStylishVariant::create([
      'label' => $machine_name,
      'machine_name' => $machine_name,
      'scope' => $scope,
      'status' => $published,
    ] + ($axis === NULL ? [] : ['axis' => $axis]));
    $variant->setStyles($styles);
    $variant->save();
    return $variant;
  }

  /**
   * The current compiled stylesheet URI from state.
   */
  protected function stylesheetUri(): ?string {
    return $this->container->get('state')->get(VariantStylesheet::STATE_KEY);
  }

  /**
   * Saving a variant compiles the shared, hash-busted stylesheet file.
   */
  public function testCompileOnSave(): void {
    $variant = $this->createVariant('cta_bold', [
      '' => [
        'default' => ['padding' => '2rem'],
        'hover' => ['background-color' => '#eaeaea'],
      ],
      '::.cta__button' => ['default' => ['font-weight' => '700']],
      // Slot-path keys (kind:block roots open at field slots) compile as
      // their own (variant, fragment) class blocks in the shared file.
      'field(field_x)' => ['default' => ['color' => 'blue']],
    ]);

    $uri = $this->stylesheetUri();
    $this->assertIsString($uri);
    $this->assertStringStartsWith(VariantStylesheet::DIRECTORY . '/variants-', $uri);
    $this->assertFileExists($uri);
    $css = file_get_contents($uri);
    // Each variant scopes to BOTH its machine-name and uuid classes: a map
    // reference may use either form, and stamping derives the class from
    // the reference verbatim.
    $this->assertStringContainsString("@scope (.daedalus-stylish-variant--cta_bold, .daedalus-stylish-variant--{$variant->uuid()})", $css);
    $this->assertStringContainsString('padding: 2rem;', $css);
    $this->assertStringContainsString(':scope:is(:hover, [data-force-state~="hover"])', $css);
    $this->assertStringContainsString('.cta__button', $css);
    // The fragment compiles at its own (variant, fragment) class — both
    // reference forms — with no positional selector anywhere.
    $this->assertStringContainsString("@scope (.daedalus-stylish-variant--cta_bold--field-field_x, .daedalus-stylish-variant--{$variant->uuid()}--field-field_x)", $css);
    $this->assertStringContainsString('color: blue;', $css);
    // No class ever wraps in :where() here (only children entries cap their
    // part class); the co-target companion's attribute :where() is the one
    // legitimate appearance.
    $this->assertStringNotContainsString(':where(.', $css);

    // The emit gate is the print seam's own hygiene: an unprintable
    // relative key and a path the root kind does not contain are dropped,
    // not emitted. A PUBLISHED variant carrying either never reaches the
    // file through the roster (the key grammar is law under any mode, so
    // the conformance judge withholds it: testWithheldVariantLeavesDelivery),
    // so the gate is exercised on the seam itself with an unsaved variant.
    $unsaved = DaedalusStylishVariant::create(['label' => 'Unsaved', 'machine_name' => 'unsaved', 'scope' => 'kind:block']);
    $unsaved->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      '::.bad{selector}' => ['default' => ['color' => 'red']],
      'container(1)' => ['default' => ['color' => 'red']],
    ]);
    $block = $this->container->get('daedalus_stylish.variant_stylesheet')->printVariant($unsaved);
    $this->assertStringContainsString('padding: 2rem;', $block);
    // The needle keeps the leading dot: uuids are hex, so a bare "bad" can
    // legitimately appear inside the variant's uuid class.
    $this->assertStringNotContainsString('.bad', $block);
    $this->assertStringNotContainsString('container-1', $block);
    $this->assertStringNotContainsString('red', $block);

    // Unpublished variants never reach the public file (view-published
    // access is open, view-unpublished is not).
    $this->createVariant('secret_draft', ['' => ['default' => ['color' => 'red']]], FALSE);
    $this->assertStringNotContainsString('secret_draft', file_get_contents($this->stylesheetUri()));

    // A style change recompiles to a NEW filename (content hash — browsers
    // cache the file cross-page, so regeneration must bust) and cleans the
    // old file up. The delete is part of the accepted staleness contract:
    // only HTML served with the old URL AFTER this point can miss it, and
    // internal caches never serve that (CACHE_TAG); see the
    // VariantStylesheet docblock.
    $variant->setStyles(['' => ['default' => ['padding' => '4rem']]]);
    $variant->save();
    $new_uri = $this->stylesheetUri();
    $this->assertNotSame($uri, $new_uri);
    $this->assertFileExists($new_uri);
    $this->assertFileDoesNotExist($uri);
    $this->assertStringContainsString('padding: 4rem;', file_get_contents($new_uri));

    // No published variants: no file, and state knows.
    $variant->setUnpublished();
    $variant->save();
    $this->assertNull($this->stylesheetUri());
    $this->assertFileDoesNotExist($new_uri);
  }

  /**
   * A width-conditioned variant compiles its page-level query root.
   *
   * The compiled file is a delivery tier of its own: a worn variant's
   * width conditions must find their query container on live renders even
   * when the host map has no width conditions of its own (the entity's
   * inline tag only ships the rule for the map's OWN conditions), so the
   * file that carries the width-conditioned CSS carries the constant
   * page-container rule (CssPrinter::PAGE_CONTAINER_RULE), demand-driven
   * and gated by the same container_roots kill switch as every tier.
   */
  public function testWidthConditionedVariantCompilesPageContainerRule(): void {
    $variant = $this->createVariant('no_width', ['' => ['default' => ['padding' => '2rem']]]);
    $this->assertStringNotContainsString('container-type', file_get_contents($this->stylesheetUri()));

    $variant->setStyles(['' => ['default' => ['padding' => '2rem'], 'narrow' => ['padding' => '1rem']]]);
    $variant->save();
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringContainsString(CssPrinter::PAGE_CONTAINER_RULE, $css);
    // ONE constant rule, no per-element containment.
    $this->assertSame(1, substr_count($css, 'container-type'));

    // The kill switch gates the rule out of the next compile (a settings
    // change reaches the file on the next variant save; the accepted
    // staleness class, see DefaultsSettingsSubscriber); the @container blocks
    // themselves stay.
    $this->config('daedalus_stylish.settings')->set('container_roots', FALSE)->save();
    $variant->save();
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringNotContainsString('container-type', $css);
    $this->assertStringContainsString('@container (max-width: 767px)', $css);
  }

  /**
   * Children pseudo-part entries compile into the shared stylesheet.
   *
   * A container-family variant carrying '::> *' (and '::.class > *') styles
   * delivers them through its fragment classes like any part entry: the
   * compiled file gets the ' > *' children rules at the variant-class
   * scope roots, bounded by the child-shifted donut. This is the equal-fill
   * delivery highway (zero per-instance CSS for equal columns).
   */
  public function testChildrenPseudoPartCompilation(): void {
    $this->createVariant('row', [
      '' => ['default' => ['display' => 'flex']],
      '::> *' => [
        'default' => ['flex-grow' => '1', 'min-width' => '0'],
        'narrow' => ['flex-grow' => '0'],
      ],
      '::.daedalus-stylish-layout-container > *' => ['default' => ['min-width' => '0']],
    ], TRUE, 'kind:container');

    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringContainsString(':scope > * {', $css);
    // The part half's class wraps in :where(): a children rule never
    // outweighs a child's own ':scope' entry, which wins on proximity.
    $this->assertStringContainsString(':scope:where(.daedalus-stylish-layout-container) > *, .daedalus-stylish-layout-container > * {', $css);
    // The children rules stay scoped to the variant classes with the
    // CHILD-SHIFTED donut: nested roots (the children) are in scope, their
    // interiors are not.
    $this->assertStringContainsString('to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *)', $css);
    // The conditioned children block rides its condition wrapper.
    $this->assertStringContainsString('flex-grow: 0;', $css);
  }

  /**
   * A child's own grow override beats a worn row's equal-fill rule.
   *
   * Mode + share's cascade seam, both delivery modes. The '::> *' children
   * rules are DEFAULTS under any per-child fact: every children target is
   * capped at ':scope' weight (0-1-0, the part class in :where()), and
   * the child's own entry prints at the child's OWN scope root, so at
   * equal weight it wins on scope proximity in every mode and at every
   * depth. That structural fact is what this locks: the capped selector
   * in both delivery forms, plus edit mode's document-order backup (the
   * '::> *' fragment rules inline at the referencing container key BEFORE
   * the map's own blocks).
   */
  public function testChildGrowOverrideBeatsChildrenRules(): void {
    $this->createVariant('row_mode', [
      '::.daedalus-stylish-layout-container' => [
        'default' => ['display' => 'flex', 'gap' => '16px'],
        'narrow' => ['flex-direction' => 'column'],
      ],
      '::.daedalus-stylish-layout-container > *' => [
        'default' => ['flex-grow' => '1', 'flex-shrink' => '1', 'flex-basis' => '0', 'min-width' => '0'],
        'narrow' => ['flex-grow' => '0', 'flex-shrink' => '0', 'flex-basis' => 'auto'],
      ],
    ], TRUE, 'kind:container');

    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')->create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->setOverridable()->save();

    $section_uuid = '99999999-9999-9999-9999-999999999999';
    $component_uuid = '44444444-4444-4444-4444-444444444444';
    $section = new Section('layout_onecol', [], [
      new SectionComponent($component_uuid, 'content', ['id' => 'system_powered_by_block']),
    ], ['daedalus_layout' => ['uuid' => $section_uuid]]);
    $entity = EntityTest::create(['name' => 'row-with-override']);
    $entity->set('layout_builder__layout', [['section' => $section]]);
    $entity->save();
    $map = [
      "container:$section_uuid" => ['variants' => [['variant' => 'row_mode', 'fragment' => '']]],
      // The share half: one wider column, written on the child itself.
      "block:$component_uuid" => ['default' => ['flex-grow' => '2']],
    ];
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();

    // LIVE: the compiled file carries the equal-fill rule at the capped
    // dual; the child's override prints inline at the child's own key (the
    // map CSS rides the entity markup in every mode). Equal weight, closer
    // scope root: the child wins.
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $file = file_get_contents($this->stylesheetUri());
    $this->assertStringContainsString(':scope:where(.daedalus-stylish-layout-container) > *, .daedalus-stylish-layout-container > * {', $file);
    $this->assertStringNotContainsString(':scope.daedalus-stylish-layout-container > *', $file);
    $this->assertStringContainsString("@scope ([data-selectable=\"block:$component_uuid\"])", $html);
    $this->assertStringContainsString('flex-grow: 2;', $html);

    // EDIT: the '::> *' fragment rules inline at the referencing container
    // key, capped dual and child-shifted donut intact, and the child's
    // override block prints AFTER them (variant CSS first inside the tag).
    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    $css = (string) $this->container->get('daedalus_stylish.style_backend')->build($this->host($entity), $map)['#value'];
    $this->assertStringContainsString("@scope ([data-selectable=\"container:$section_uuid\"]) to (:scope [data-selectable^=\"container:\"] > *, :scope [data-selectable^=\"block:\"] > *)", $css);
    $this->assertStringContainsString(':scope:where(.daedalus-stylish-layout-container) > *, .daedalus-stylish-layout-container > * {', $css);
    $this->assertStringNotContainsString(':scope.daedalus-stylish-layout-container > *', $css);
    $this->assertStringContainsString('flex-basis: auto;', $css);
    $this->assertLessThan(strpos($css, 'flex-grow: 2;'), strpos($css, 'flex-grow: 1;'));
  }

  /**
   * The daedalus_stylish/variants library resolves the current compiled file.
   */
  public function testLibraryResolvesCurrentFile(): void {
    // Before any variant exists the library is asset-less but defined —
    // renders may attach it unconditionally of compile state.
    $discovery = $this->container->get('library.discovery');
    $library = $discovery->getLibraryByName('daedalus_stylish', 'variants');
    $this->assertNotFalse($library);
    $this->assertSame([], $library['css'] ?? []);

    $this->createVariant('cta_bold', ['' => ['default' => ['padding' => '2rem']]]);
    // compile() invalidated library_info; discovery re-resolves.
    $library = $discovery->getLibraryByName('daedalus_stylish', 'variants');
    $this->assertCount(1, $library['css']);
    $this->assertStringContainsString(basename($this->stylesheetUri()), $library['css'][0]['data']);
    // User-authored CSS competes unlayered: the compiled stylesheet must be
    // exempt from the daedalus_stylish_layers wrapper layer or theme specificity
    // beats it again inside the layer.
    $this->assertTrue($library['css'][0]['daedalus_stylish_layers_exempt'] ?? FALSE);
  }

  /**
   * Referencing elements carry the variant class in live rendered markup.
   *
   * The class rides wherever the selectable key was stamped: section,
   * region and block keys are stamped by the daedalus_layout/daedalus_ui seams
   * exactly when demanded (a variant reference is demand), the entity root
   * by daedalus_stylish itself.
   *
   * This is the LIVE half of variant delivery: class + compiled shared
   * stylesheet, and nothing else (no key-rooted inline resolution). The
   * edit-mode half (key-rooted inline, no class dependency) is locked by
   * testEditModeInlineResolution().
   */
  public function testVariantClassStamping(): void {
    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')->create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->setOverridable()->save();

    $section_uuid = '77777777-7777-7777-7777-777777777777';
    $component_uuid = '66666666-6666-6666-6666-666666666666';
    $section = new Section('layout_onecol', [], [
      new SectionComponent($component_uuid, 'content', ['id' => 'system_powered_by_block']),
    ], ['daedalus_layout' => ['uuid' => $section_uuid]]);
    $entity = EntityTest::create(['name' => 'on-variant']);
    $entity->set('layout_builder__layout', [['section' => $section]]);
    $entity->save();
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "entity:{$entity->uuid()}" => ['variants' => [['variant' => 'hero_frame', 'fragment' => '']]],
      "container:$section_uuid" => ['variants' => [['variant' => 'airy_section', 'fragment' => '']]],
      "block:$component_uuid" => ['variants' => [['variant' => 'cta_bold', 'fragment' => '']]],
      // Sub-element keys have no stampable element; skipped, not fatal.
      "block:$component_uuid::.quote" => ['variants' => [['variant' => 'quote_style', 'fragment' => '']]],
    ]));
    $entity->save();

    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $this->assertStringContainsString('daedalus-stylish-variant--airy_section', $html);
    $this->assertStringContainsString('daedalus-stylish-variant--cta_bold', $html);
    $this->assertStringNotContainsString('daedalus-stylish-variant--quote_style', $html);
    // The class and the selectable key ride the same element.
    $this->assertMatchesRegularExpression('/<[^>]*data-selectable="container:' . $section_uuid . '"[^>]*daedalus-stylish-variant--airy_section|<[^>]*daedalus-stylish-variant--airy_section[^>]*data-selectable="container:' . $section_uuid . '"/', $html);

    // The entity root's class rides the entity build's own attributes
    // (entity_test's kernel render does not print template attributes, so
    // assert on the build).
    $this->assertContains('daedalus-stylish-variant--hero_frame', $build['#attributes']['class'] ?? []);

    // Variant references attach the compiled stylesheet's library and the
    // recompile tag.
    $this->assertContains('daedalus_stylish/variants', $build['#attached']['library'] ?? []);
    $this->assertContains(VariantStylesheet::CACHE_TAG, $build['#cache']['tags'] ?? []);

    // Class delivery is the WHOLE live delivery: no key-rooted inline
    // resolution ever prints in live mode (that mechanism is edit mode's,
    // see testEditModeInlineResolution()).
    $this->assertStringNotContainsString('@scope ([data-selectable=', $html);
  }

  /**
   * An entity field referencing a variant carries the class on its markup.
   */
  public function testFieldVariantClass(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_live',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_live',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')->create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->setComponent('field_live', ['type' => 'string'])->save();

    $entity = EntityTest::create(['name' => 'styled', 'field_live' => 'styled text']);
    $entity->save();
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "field:{$entity->uuid()}:field_live" => ['variants' => [['variant' => 'field_frame', 'fragment' => '']]],
    ]));
    $entity->save();

    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('daedalus-stylish-variant--field_frame', $html);
  }

  /**
   * A {variant, fragment} reference delivers exactly its fragment.
   *
   * Composition children are ordinary entries: apply distributed a
   * {variant, fragment} reference onto each resolved element, and delivery
   * treats it like any other reference — the compiled file holds one class
   * per (variant, fragment), live stamping derives that class from the
   * stored reference verbatim, and edit mode prints the fragment's styles
   * (own entry + '::.class' parts, nothing deeper) at the referencing
   * key's selectable root. No positional selector compilation anywhere.
   */
  public function testFragmentReferenceDelivery(): void {
    $variant = $this->createVariant('card_grid', [
      '' => ['default' => ['gap' => '3rem']],
      'block(text,1)' => ['default' => ['margin-top' => '0']],
      'block(text,1)::.quote' => ['default' => ['color' => 'purple']],
    ], TRUE, 'kind:container');

    // The compiled file: one class pair per fragment, the '' fragment on
    // the plain variant classes with the section donut, the block fragment
    // on its encoded classes with its part entry riding along.
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringContainsString("@scope (.daedalus-stylish-variant--card_grid, .daedalus-stylish-variant--{$variant->uuid()}) to (", $css);
    $this->assertStringContainsString("@scope (.daedalus-stylish-variant--card_grid--block-text-1, .daedalus-stylish-variant--{$variant->uuid()}--block-text-1)", $css);
    $this->assertStringContainsString('margin-top: 0;', $css);
    $this->assertStringContainsString('color: purple;', $css);
    // Part classes print unwrapped (see testCompileOnSave); the co-target
    // companion's attribute :where() is the one legitimate appearance.
    $this->assertStringNotContainsString(':where(.', $css);

    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')->create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->setOverridable()->save();

    $section_uuid = '88888888-8888-8888-8888-888888888888';
    $component_uuid = '55555555-5555-5555-5555-555555555555';
    $section = new Section('layout_onecol', [], [
      new SectionComponent($component_uuid, 'content', ['id' => 'system_powered_by_block']),
    ], ['daedalus_layout' => ['uuid' => $section_uuid]]);
    $entity = EntityTest::create(['name' => 'wearing-fragments']);
    $entity->set('layout_builder__layout', [['section' => $section]]);
    $entity->save();
    $map = [
      "container:$section_uuid" => ['variants' => [['variant' => 'card_grid', 'fragment' => '']]],
      "block:$component_uuid" => ['variants' => [['variant' => 'card_grid', 'fragment' => 'block(text,1)']]],
    ];
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();

    // Live: the child stamps its (variant, fragment) class via the same
    // any-entry-with-refs walk a root reference rides.
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertMatchesRegularExpression('/<[^>]*data-selectable="container:' . $section_uuid . '"[^>]*daedalus-stylish-variant--card_grid[ "]|<[^>]*daedalus-stylish-variant--card_grid[ "][^>]*data-selectable="container:' . $section_uuid . '"/', $html);
    $this->assertStringContainsString('daedalus-stylish-variant--card_grid--block-text-1', $html);
    $this->assertStringNotContainsString('@scope ([data-selectable=', $html);

    // Edit mode: the reference resolves inline at the referencing key,
    // fragment styles only — the root's styles print at the SECTION key,
    // never at the child's.
    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    $css = (string) $this->container->get('daedalus_stylish.style_backend')->build($this->host($entity), $map)['#value'];
    $this->assertStringContainsString("@scope ([data-selectable=\"container:$section_uuid\"]) to (", $css);
    $this->assertStringContainsString('gap: 3rem;', $css);
    $this->assertStringContainsString("@scope ([data-selectable=\"block:$component_uuid\"])", $css);
    $this->assertStringContainsString('margin-top: 0;', $css);
    $this->assertStringContainsString('color: purple;', $css);
    // The fragment's styles land ONLY at the child key, the root's ONLY at
    // the section key.
    $this->assertSame(1, substr_count($css, 'gap: 3rem;'));
    $this->assertSame(1, substr_count($css, 'margin-top: 0;'));
    $this->assertStringNotContainsString('.daedalus-stylish-variant--', $css);
  }

  /**
   * Edit mode resolves variant references inline at their key roots.
   *
   * In edit mode a variant is a preset, not a stylesheet: class stamping
   * never reaches the canvas (sections build inside the canvas element's
   * #pre_render, after hook_entity_view), so the CSS backend prints each
   * referenced variant's styles into the entity's authoritative <style>
   * tag, rooted at the referencing key's data-selectable attribute. The
   * compiled <link> may stay attached — the inline tag is later in the
   * document and wins the cascade for identical selectors, the same trick
   * as the workspace fallback.
   */
  public function testEditModeInlineResolution(): void {
    $entity_variant = $this->createVariant('hero_frame', ['' => ['default' => ['padding' => '5rem']]], TRUE, 'kind:entity');
    $section_variant = $this->createVariant('airy_section', ['' => ['default' => ['gap' => '3rem']]], TRUE, 'kind:container');
    $block_variant = $this->createVariant('cta_bold', [
      '' => [
        'default' => ['padding' => '2rem'],
        'hover' => ['background-color' => '#eaeaea'],
      ],
      '::.cta__button' => ['default' => ['font-weight' => '700']],
    ]);
    $draft_variant = $this->createVariant('secret_draft', ['' => ['default' => ['color' => 'rebeccapurple']]], FALSE, 'kind:container');

    $section_uuid = '77777777-7777-7777-7777-777777777777';
    $component_uuid = '66666666-6666-6666-6666-666666666666';
    $entity = EntityTest::create(['name' => 'on-variant']);
    $entity->save();
    $map = [
      "entity:{$entity->uuid()}" => ['variants' => [['variant' => 'hero_frame', 'fragment' => '']]],
      // The second reference is unpublished: it never prints (and never
      // loads: it is not a material), and the roster's list tag bubbles so
      // publishing it invalidates the cached edit-mode render.
      "container:$section_uuid" => [
        'variants' => [
          ['variant' => 'airy_section', 'fragment' => ''],
          ['variant' => 'secret_draft', 'fragment' => ''],
        ],
      ],
      "block:$component_uuid" => ['variants' => [['variant' => 'cta_bold', 'fragment' => '']]],
    ];
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();

    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    $element = $this->container->get('daedalus_stylish.style_backend')->build($this->host($entity), $map);
    $css = (string) $element['#value'];

    // Key-rooted resolution, never class-rooted, for every reference kind.
    $this->assertStringContainsString("@scope ([data-selectable=\"entity:{$entity->uuid()}\"])", $css);
    $this->assertStringContainsString('padding: 5rem;', $css);
    // A section-family variant's path-less entries keep the section donut.
    $this->assertStringContainsString("@scope ([data-selectable=\"container:$section_uuid\"]) to (", $css);
    $this->assertStringContainsString('gap: 3rem;', $css);
    $this->assertStringContainsString("@scope ([data-selectable=\"block:$component_uuid\"])", $css);
    $this->assertStringContainsString('padding: 2rem;', $css);
    $this->assertStringContainsString(':scope:is(:hover, [data-force-state~="hover"])', $css);
    $this->assertStringContainsString('.cta__button', $css);
    $this->assertStringNotContainsString('.daedalus-stylish-variant--', $css);
    $this->assertStringNotContainsString('rebeccapurple', $css);

    // The printed variants' tags bubble; the draft rides the roster's list
    // tag (a draft is not a material, so nothing loads it per entity).
    $tags = $element['#cache']['tags'] ?? [];
    foreach ([$entity_variant, $section_variant, $block_variant] as $variant) {
      $this->assertContains("daedalus_variant:{$variant->id()}", $tags);
    }
    $this->assertNotContains("daedalus_variant:{$draft_variant->id()}", $tags);
    $this->assertContains('daedalus_variant_list', $tags);

    // End to end: the rendered entity carries the resolved CSS in its
    // authoritative style tag, still attaches the compiled stylesheet's
    // library, and varies by the effective mode.
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('<style data-daedalus-stylish-entity="' . $entity->uuid() . '">', $html);
    $this->assertStringContainsString("@scope ([data-selectable=\"block:$component_uuid\"])", $html);
    $this->assertContains('daedalus_stylish/variants', $build['#attached']['library'] ?? []);
    $this->assertContains('daedalus_ui_mode', $build['#cache']['contexts'] ?? []);

    // Live mode: no key-rooted inline resolution (class + compiled file
    // deliver), but the render still varies by mode so each mode gets its
    // own cache entry.
    $this->container->get('request_stack')->getCurrentRequest()->cookies->remove('navigationMode');
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringNotContainsString('@scope ([data-selectable=', $html);
    $this->assertContains('daedalus_ui_mode', $build['#cache']['contexts'] ?? []);
  }

  /**
   * Both variant delivery tiers carry the co-target companion.
   *
   * The compiled variants file and the edit-mode inline resolution both
   * flow through the shared printEntry seam, so a root fragment carrying
   * inherited text properties (a text block styled "big and blue", then
   * linked) emits the ':scope :where([data-claims-content])' companion in
   * both tiers with zero tier-specific code. The kind-scoped variant is
   * the pin that matters most: the marker attribute carries the anatomy
   * fact, so print time needs no bundle knowledge, which is exactly what
   * per-tier derivation could never do (a kind:block variant does not
   * know which bundles claim their text). Box properties never ride
   * along, and a part entry on the claiming link itself stays
   * companion-free: a real part style must WIN over the co-target
   * (button-making on the anchor), never feed it.
   */
  public function testVariantTiersCoTargetInheritedText(): void {
    // The text block's bundle-scoped variant styling its ROOT fragment:
    // color co-targets, padding must not, and the declared link part's own
    // entry emits no companion.
    $variant = $this->createVariant('text_hero', [
      '' => [
        'default' => ['color' => '#123456', 'padding' => '2rem'],
        'hover' => ['color' => '#654321'],
      ],
      '::.text-block-link' => ['default' => ['font-weight' => '700']],
    ], TRUE, 'block_type:text');
    // A KIND-scoped variant co-targets identically with no bundle in sight.
    $this->createVariant('any_block_ink', ['' => ['default' => ['color' => 'teal']]]);

    // TIER 1: the compiled variants file.
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringContainsString("@scope (.daedalus-stylish-variant--text_hero, .daedalus-stylish-variant--{$variant->uuid()})", $css);
    $this->assertStringContainsString(":scope :where([data-claims-content]) {\n    color: #123456;\n  }", $css);
    // Pseudo-class conditions stay on :scope — the condition holds on the
    // styled element, the claiming element merely receives.
    $this->assertStringContainsString(":scope:is(:hover, [data-force-state~=\"hover\"]) :where([data-claims-content]) {\n    color: #654321;\n  }", $css);
    // The kind-scoped companion: same seam, same bytes.
    $this->assertStringContainsString(":scope :where([data-claims-content]) {\n    color: teal;\n  }", $css);
    // Box properties print once (the main rule only), and the part entry
    // prints once (no companion): two root default companions + one hover
    // companion is the whole co-target surface of the file.
    $this->assertSame(1, substr_count($css, 'padding: 2rem;'));
    $this->assertStringContainsString(':scope.text-block-link, .text-block-link {', $css);
    $this->assertSame(1, substr_count($css, 'font-weight: 700;'));
    $this->assertSame(2, substr_count($css, 'color: teal;'));
    $this->assertSame(3, substr_count($css, ':where([data-claims-content])'));

    // TIER 2: edit-mode inline resolution at the wearing block's key.
    $block_uuid = '66666666-6666-6666-6666-666666666666';
    $kind_uuid = '55555555-5555-5555-5555-555555555555';
    $entity = EntityTest::create(['name' => 'linked-text']);
    $entity->save();
    $map = [
      "block:$block_uuid" => ['variants' => [['variant' => 'text_hero', 'fragment' => '']]],
      "block:$kind_uuid" => ['variants' => [['variant' => 'any_block_ink', 'fragment' => '']]],
    ];
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();

    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    $css = (string) $this->container->get('daedalus_stylish.style_backend')->build($this->host($entity), $map)['#value'];
    $this->assertStringContainsString("@scope ([data-selectable=\"block:$block_uuid\"])", $css);
    $this->assertStringContainsString(":scope :where([data-claims-content]) {\n    color: #123456;\n  }", $css);
    $this->assertStringContainsString(":scope:is(:hover, [data-force-state~=\"hover\"]) :where([data-claims-content]) {\n    color: #654321;\n  }", $css);
    $this->assertStringContainsString(":scope :where([data-claims-content]) {\n    color: teal;\n  }", $css);
    $this->assertSame(1, substr_count($css, 'padding: 2rem;'));
    $this->assertSame(1, substr_count($css, 'font-weight: 700;'));
    $this->assertSame(2, substr_count($css, 'color: teal;'));
    $this->assertSame(3, substr_count($css, ':where([data-claims-content])'));
  }

  /**
   * Workspace preview: staged variant CSS inlines; the file stays live.
   *
   * The edge, resolved as the inline fallback: library assets cannot
   * vary by workspace, so the shared file always holds the LIVE revisions;
   * inside a workspace the entity's own style tag inlines the referenced
   * variants' staged CSS at the same class roots (document order beats the
   * compiled <link>). Publishing recompiles the file.
   */
  public function testWorkspacePreviewInlineFallback(): void {
    $variant = $this->createVariant('ws_variant', ['' => ['default' => ['padding' => '2rem']]]);

    // The host is created in Live: entity_test is not workspace-supported,
    // so it cannot be saved inside a workspace — rendering there is fine.
    $entity = EntityTest::create(['name' => 'on-variant']);
    $entity->save();
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      // A layered entry: the workspace-staged variant CSS must print BEFORE
      // the entry's own override block, so the override keeps beating the
      // variant inside the workspace too.
      "block:66666666-6666-6666-6666-666666666666" => [
        'variants' => [['variant' => 'ws_variant', 'fragment' => '']],
        'default' => ['margin' => '1rem'],
      ],
    ]));
    $entity->save();

    $this->initializeWorkspacesModule();
    $this->setUpCurrentUser([], ['view test entity']);
    $live_uri = $this->stylesheetUri();

    // Stage a variant edit, adding a width condition no LIVE variant has.
    $this->switchToWorkspace('stage');
    $staged = $this->entityTypeManager->getStorage('daedalus_variant')->load($variant->id());
    $staged->setStyles(['' => ['default' => ['padding' => '9rem'], 'narrow' => ['padding' => '5rem']]]);
    $staged->save();

    // The staged revision stays out of the shared public file: compile-on-
    // save reads the live default revisions.
    $this->assertSame($live_uri, $this->stylesheetUri());
    $this->assertStringContainsString('padding: 2rem;', file_get_contents($live_uri));

    // Inside the workspace the entity's style tag inlines the staged CSS at
    // the variant-class root, page-container rule included: the staged
    // width condition exists in no live variant, so the compiled file
    // cannot be relied on to establish the query root.
    $build = $this->entityTypeManager->getViewBuilder('entity_test')->view($entity);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('@scope (.daedalus-stylish-variant--ws_variant)', $html);
    $this->assertStringContainsString('padding: 9rem;', $html);
    $this->assertStringContainsString(CssPrinter::PAGE_CONTAINER_RULE, $html);
    $this->assertStringNotContainsString('container-type', file_get_contents($live_uri));
    // Document order inside the tag: staged variant CSS first, the entry's
    // own override block after it, so the override wins identical selectors.
    $this->assertLessThan(strpos($html, 'margin: 1rem;'), strpos($html, 'padding: 9rem;'));

    // Live render: no inline variant CSS (the compiled file delivers).
    $this->switchToLive();
    $build = $this->entityTypeManager->getViewBuilder('entity_test')->view($entity);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringNotContainsString('@scope (.daedalus-stylish-variant--ws_variant)', $html);
    $this->assertStringNotContainsString('padding: 9rem;', $html);

    // Publishing the workspace lands the staged CSS in the shared file —
    // per-entity update hooks do not fire on publish, so this exercises
    // VariantWorkspaceSubscriber.
    $this->workspaces['stage']->publish();
    $published_uri = $this->stylesheetUri();
    $this->assertNotSame($live_uri, $published_uri);
    $this->assertStringContainsString('padding: 9rem;', file_get_contents($published_uri));
    $this->assertFileDoesNotExist($live_uri);
  }

  /**
   * Every delivery path emits variant references in axis-declaration order.
   *
   * The stored list keeps application order, but emission (stamped
   * classes, edit-mode inline blocks, the compiled file's block order) is
   * the axis vocabulary order ("style" first, then declared axes in
   * declaration order), so rendering is deterministic regardless of the
   * sequence variants were applied in. Unplaceable references (a stale name
   * that resolves to no variant) emit after every placed one, in list
   * order.
   */
  public function testAxisDeclarationOrderDelivery(): void {
    // Machine-name order (aa < zz) OPPOSES axis order (style before
    // emphasis), so each assertion below proves the axis sort did the work.
    $this->createVariant('aa_pop', ['' => ['default' => ['font-weight' => '700']]], TRUE, 'block_type:testimonial', 'emphasis');
    $this->createVariant('zz_calm', ['' => ['default' => ['padding' => '3rem']]], TRUE, 'block_type:testimonial');

    // The compiled file: block order is the live cascade among variants
    // co-occurring on one element, so it mirrors the same vocabulary order.
    $css = file_get_contents($this->stylesheetUri());
    $this->assertLessThan(strpos($css, '.daedalus-stylish-variant--aa_pop'), strpos($css, '.daedalus-stylish-variant--zz_calm'));

    $entity = EntityTest::create(['name' => 'on-two-axes']);
    $entity->save();
    $map = [
      // Application order deliberately reversed from the vocabulary order,
      // with a stale reference wedged in between.
      "entity:{$entity->uuid()}" => [
        'variants' => [
          ['variant' => 'aa_pop', 'fragment' => ''],
          ['variant' => 'ghost_ref', 'fragment' => ''],
          ['variant' => 'zz_calm', 'fragment' => ''],
        ],
      ],
    ];
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();

    // Live stamping: classes in axis order, the unplaceable ref last.
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    $this->container->get('renderer')->renderInIsolation($build);
    $classes = array_values(array_filter(
      (array) ($build['#attributes']['class'] ?? []),
      static fn (string $class) => str_starts_with($class, VariantStylesheet::CLASS_PREFIX),
    ));
    $this->assertSame([
      'daedalus-stylish-variant--zz_calm',
      'daedalus-stylish-variant--aa_pop',
      'daedalus-stylish-variant--ghost_ref',
    ], $classes);

    // Edit mode: the inline blocks print in the same order — here document
    // order IS the cascade, so the emphasis-axis block beats the style-axis
    // block on any shared property, never the application sequence.
    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    $css = (string) $this->container->get('daedalus_stylish.style_backend')->build($this->host($entity), $map)['#value'];
    $this->assertLessThan(strpos($css, 'font-weight: 700;'), strpos($css, 'padding: 3rem;'));
  }

  /**
   * A variant the style guide withholds leaves every delivery tier.
   *
   * Legality is a fact about (variant, current law): a published variant
   * born legal under guarded law and refused under strict law is withheld
   * from the roster the moment the site turns strict (VariantConformance),
   * with no write to the variant. Every delivery tier reads the roster,
   * so the compiled file omits its block on the next compile (the
   * enforcement save recompiles the defaults file only; the next variant
   * save carries the verdict, the accepted staleness class), edit mode
   * prints nothing for it, and a host map still wearing it delivers
   * deterministically: the reference survives in the map, its class
   * stamps after every placed one exactly like any stale reference, and
   * nothing in the file answers that class. Whole-map validation names
   * the wearing (STYLE_VARIANT_NONCONFORMING) and still judges the map's
   * other entries on their own.
   */
  public function testWithheldVariantLeavesDelivery(): void {
    // The guide's block kind grants spacing only: the color literal is
    // legal under guarded law (recognition and grammar) and refused under
    // strict law (the closed world).
    $ink = $this->createVariant('raw_ink', ['' => ['default' => ['color' => 'teal']]]);
    $pad = $this->createVariant('tidy_pad', ['' => ['default' => ['padding' => '2rem']]]);
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringContainsString('.daedalus-stylish-variant--raw_ink', $css);
    $this->assertStringContainsString('color: teal;', $css);

    $entity = EntityTest::create(['name' => 'wearing-ink']);
    $entity->save();
    $map = [
      // Application order puts the soon-withheld reference FIRST, so the
      // placed-before-unplaceable sort is what orders the classes below.
      "entity:{$entity->uuid()}" => [
        'variants' => [['variant' => 'raw_ink', 'fragment' => ''], ['variant' => 'tidy_pad', 'fragment' => '']],
      ],
    ];
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();

    // Strict law. The next variant save (any variant) compiles the judged
    // set: its hook resets the repository, the verdict and the roster
    // before the compile reads them.
    $this->enforce('strict');
    $pad->save();
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringNotContainsString('raw_ink', $css);
    $this->assertStringNotContainsString($ink->uuid(), $css);
    $this->assertStringNotContainsString('teal', $css);
    $this->assertStringContainsString('.daedalus-stylish-variant--tidy_pad', $css);
    $this->assertStringContainsString('padding: 2rem;', $css);

    // Live stamping: the placed class first, the withheld reference's
    // class last, in the application order's teeth; the file answers the
    // first and not the second.
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    $this->container->get('renderer')->renderInIsolation($build);
    $classes = array_values(array_filter(
      (array) ($build['#attributes']['class'] ?? []),
      static fn (string $class) => str_starts_with($class, VariantStylesheet::CLASS_PREFIX),
    ));
    $this->assertSame(['daedalus-stylish-variant--tidy_pad', 'daedalus-stylish-variant--raw_ink'], $classes);

    // Edit mode: the inline tier prints the offered variant's styles and
    // nothing for the withheld one.
    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    $inline = (string) $this->container->get('daedalus_stylish.style_backend')->build($this->host($entity), $map)['#value'];
    $this->assertStringContainsString('padding: 2rem;', $inline);
    $this->assertStringNotContainsString('teal', $inline);

    // Whole-map validation: the wearing is refused by name at its own
    // path, and the map's other entries are judged on their own (a legal
    // one passes, an illegal one carries its own error beside it).
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $wearing = 'block:12121212-1212-1212-1212-121212121212';
    $other = 'block:34343434-3434-3434-3434-343434343434';
    $codes = static function (array $errors): array {
      $codes = [];
      foreach ($errors as $error) {
        $codes[$error->getPath()][] = $error->getCode();
      }
      return $codes;
    };
    $worn = [
      $wearing => [
        'variants' => [['variant' => 'raw_ink', 'fragment' => ''], ['variant' => 'tidy_pad', 'fragment' => '']],
      ],
    ];
    $this->assertSame(
      ["$wearing.variants.0" => ['STYLE_VARIANT_NONCONFORMING']],
      $codes($validator->validate($worn + [$other => ['default' => ['padding' => '1rem']]])),
    );
    $this->assertSame(
      [
        "$wearing.variants.0" => ['STYLE_VARIANT_NONCONFORMING'],
        "$other.default.color" => ['STYLE_PROPERTY_NOT_DECLARED'],
      ],
      $codes($validator->validate($worn + [$other => ['default' => ['color' => 'red']]])),
    );
  }

  /**
   * A layered entry's own overrides print after its variant CSS.
   *
   * Inside the entity's authoritative tag both land at the same scope root
   * with equal specificity, so document order alone decides: the resolved
   * variant CSS must come first and the entry's own condition blocks last,
   * or an override of a variant-defined property would silently lose.
   */
  public function testOverridesPrintAfterVariantCss(): void {
    $this->createVariant('hero_frame', ['' => ['default' => ['padding' => '5rem']]], TRUE, 'kind:entity');
    $entity = EntityTest::create(['name' => 'layered']);
    $entity->save();
    $map = [
      "entity:{$entity->uuid()}" => [
        'variants' => [['variant' => 'hero_frame', 'fragment' => '']],
        'default' => ['padding' => '2rem'],
      ],
    ];
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();

    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    $css = (string) $this->container->get('daedalus_stylish.style_backend')->build($this->host($entity), $map)['#value'];
    $this->assertLessThan(strpos($css, 'padding: 2rem;'), strpos($css, 'padding: 5rem;'));
  }


  /**
   * Wear at a rule delivers inside the rule owner's @scope in every mode.
   *
   * The third lane: a reference on a rule entry prints the variant's
   * fragment inside the OWNER's @scope with each suffix continued under
   * the rule by descent (the root entry at the rule's targets, a part at
   * `{rule} .part`, the children at `{rule} > *`), the same text in edit
   * mode and live, never a class; a suffix the rule's grammar refuses (a
   * class under a tag rule) prints nothing; the rule's own properties
   * print after what it wears (own beats worn by order); the workspace
   * fallback skips rule keys (its class-rooted CSS would reach nothing);
   * and the worn variant's tag bubbles.
   */
  public function testRuleWearDelivery(): void {
    $text_variant = $this->createVariant('text_soft', [
      '' => ['default' => ['padding' => '1rem'], 'hover' => ['color' => 'purple']],
      '::.link' => ['default' => ['font-weight' => '700']],
      '::> *' => ['default' => ['margin' => '0']],
    ]);
    $this->createVariant('link_style', [
      '' => ['default' => ['color' => 'red']],
      '::.icon' => ['default' => ['margin' => '0']],
    ], TRUE, 'base:a');
    $section_uuid = '77777777-7777-7777-7777-777777777777';
    $entity = EntityTest::create(['name' => 'rule-wear']);
    $entity->save();
    $map = [
      "container:$section_uuid::> pb-text" => [
        'variants' => [['variant' => 'text_soft', 'fragment' => '']],
        'default' => ['padding' => '2rem'],
      ],
      "container:$section_uuid::a" => ['variants' => [['variant' => 'link_style', 'fragment' => '']]],
    ];
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();

    $rule = "@scope ([data-selectable=\"container:$section_uuid\"]) {\n  :where(:scope) > [data-element=\"block_type:text\"] {\n    padding: 1rem;\n  }\n  :where(:scope) > [data-element=\"block_type:text\"]:is(:hover, [data-force-state~=\"hover\"]) {\n    color: purple;\n  }\n}";
    $expected_rule_css = $rule
      . "\n@scope ([data-selectable=\"container:$section_uuid\"]) {\n  :where(:scope) > :where([data-element=\"block_type:text\"]) .link {\n    font-weight: 700;\n  }\n}"
      . "\n@scope ([data-selectable=\"container:$section_uuid\"]) {\n  :where(:scope) > [data-element=\"block_type:text\"] > * {\n    margin: 0;\n  }\n}"
      . "\n@scope ([data-selectable=\"container:$section_uuid\"]) {\n  a {\n    color: red;\n  }\n}";
    $tags = [];
    $this->assertSame($expected_rule_css, $this->container->get('daedalus_stylish.variant_stylesheet')->ruleWearCss($map, $tags));
    $this->assertContains("daedalus_variant:{$text_variant->id()}", $tags);
    $this->assertContains('daedalus_variant_list', $tags);

    foreach (['edit', NULL] as $mode) {
      $request = $this->container->get('request_stack')->getCurrentRequest();
      $mode === NULL ? $request->cookies->remove('navigationMode') : $request->cookies->set('navigationMode', $mode);
      $element = $this->container->get('daedalus_stylish.style_backend')->build($this->host($entity), $map);
      $css = (string) $element['#value'];
      $this->assertStringContainsString($expected_rule_css, $css, $mode ?? 'live');
      // Own beats worn by order: the rule's own padding prints after the
      // worn one. Never a class root, never the refused part.
      $this->assertLessThan(strpos($css, 'padding: 2rem;'), strpos($css, 'padding: 1rem;'), $mode ?? 'live');
      $this->assertStringNotContainsString('.daedalus-stylish-variant--', $css, $mode ?? 'live');
      $this->assertStringNotContainsString('.icon', $css, $mode ?? 'live');
      $this->assertContains("daedalus_variant:{$text_variant->id()}", $element['#cache']['tags'], $mode ?? 'live');
    }

    // The live render carries the rule's wear in the authoritative tag.
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString($rule, $html);

    // The workspace fallback is the element lane's: rule keys are skipped.
    $this->initializeWorkspacesModule();
    $this->switchToWorkspace('stage');
    $tags = [];
    $this->assertSame('', $this->container->get('daedalus_stylish.variant_stylesheet')->workspaceInlineCss($map, $tags));
  }

  /**
   * A map without references neither depends on nor builds the roster.
   *
   * Cacheability rides the read (VariantStylesheet::resolveWorn()): the
   * roster's metadata attaches at each reference delivery resolves, so a
   * styled entity wearing nothing carries none of the roster's tags (the
   * list tag a publish invalidates included) in any mode, and the lanes
   * build no roster along the way: after the compile's build is dropped,
   * the builder's memo stays empty through both renders.
   */
  public function testReferencelessMapSkipsTheRoster(): void {
    // A published variant exists, so the roster is not trivially empty and
    // the compiled file is current; the map just never wears it.
    $this->createVariant('unworn', ['' => ['default' => ['padding' => '1rem']]]);
    $block_uuid = '66666666-6666-6666-6666-666666666666';
    $section_uuid = '77777777-7777-7777-7777-777777777777';
    $entity = EntityTest::create(['name' => 'no-refs']);
    $entity->save();
    $map = [
      "block:$block_uuid" => ['default' => ['padding' => '2rem']],
      "container:$section_uuid::> pb-text" => ['default' => ['margin' => '0']],
    ];
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();

    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    // The roster's tags that are the roster's alone: the three entity
    // lists its records read (the config tags beside them are owed by the
    // render in its own right, for the condition thresholds and the
    // container-roots switch).
    $list_tags = ['daedalus_variant_list', 'daedalus_swatch_list', 'daedalus_animation_list'];
    $this->assertSame([], array_diff($list_tags, $builder->cacheMetadata()->getCacheTags()));
    $memo = new \ReflectionProperty($builder, 'memo');
    // The variant's save compiled the file over a built roster; drop it so
    // a build by either render below would show.
    $builder->reset();
    $this->assertSame([], $memo->getValue($builder));

    foreach (['edit', NULL] as $mode) {
      $request = $this->container->get('request_stack')->getCurrentRequest();
      $mode === NULL ? $request->cookies->remove('navigationMode') : $request->cookies->set('navigationMode', $mode);
      $element = $this->container->get('daedalus_stylish.style_backend')->build($this->host($entity), $map);
      $this->assertStringContainsString('padding: 2rem;', (string) $element['#value'], $mode ?? 'live');
      $tags = $element['#cache']['tags'];
      $this->assertSame([], array_values(array_intersect($list_tags, $tags)), $mode ?? 'live');
      $this->assertSame([], $memo->getValue($builder), $mode ?? 'live');
    }
  }

}
