<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\ClientStyleGuide;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the style guide payload the inspector client renders from.
 *
 * Every served node is the vocabulary roster's answers at its address
 * (grants, value spaces, axes; VocabularyRosterBuilder); the client
 * twin's reads of those nodes are locked against the roster by
 * VocabularyRosterTest::testClientTwinEquivalence.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ClientStyleGuideTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  protected const THEME = 'daedalus_stylish_guide_test_theme';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'daedalus_blueprint',
    'user',
    'field',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
    'daedalus_stylish_guide_test_extra',
    'daedalus_stylish_control_test',
  ];

  /**
   * The client style guide under test.
   */
  protected ClientStyleGuide $clientStyleGuide;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The payload serves the published swatches; the user schema backs the
    // swatch entity's owner field for the fixtures testSwatches() mints.
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->clientStyleGuide = $this->container->get('daedalus_stylish.client_style_guide');
  }

  /**
   * The payload serves the base section as rosters, the tag tier's twin.
   */
  public function testBaseElementsServed(): void {
    $payload = $this->clientStyleGuide->build(self::THEME);
    $this->assertSame($this->container->get('daedalus_stylish.style_guide')->baseTags(self::THEME), array_keys($payload['base']));
    $this->assertSame(['typography', 'spacing', 'background', 'border'], array_keys($payload['base']['p']['grants']));
    $this->assertArrayNotHasKey('property_groups', $payload['base']['p'], 'The roster is served, never the authored declaration.');
    // Links carry motion beside typography: the one prose tag with a
    // state worth transitioning.
    $this->assertSame(['typography', 'motion'], array_keys($payload['base']['a']['grants']));
    // The node IS the roster at "base:{tag}".
    $roster = $this->container->get('daedalus_stylish.vocabulary_roster')->build(Address::base('p'), self::THEME);
    $this->assertSame($roster->grants(), $payload['base']['p']['grants']);
    $this->assertSame($roster->valueSpaces(), $payload['base']['p']['valueSpaces']);
  }

  /**
   * The payload carries groups, conditions, kinds, and block types.
   */
  public function testPayload(): void {
    $payload = $this->clientStyleGuide->build(self::THEME);

    $this->assertSame('guarded', $payload['enforcement']);

    // Groups arrive in inspector panel order with property definitions the
    // guide's group ids refer to; labels are plain strings (the payload is
    // JSON-encoded into drupalSettings).
    $this->assertSame([
      'spacing',
      'typography',
      'background',
      'border',
      'shadow',
      'sizing',
      'layout',
      'effects',
      'motion',
    ], array_keys($payload['groups']));
    $spacing = $payload['groups']['spacing'];
    $this->assertSame('Spacing', $spacing['label']);
    $this->assertSame('Padding', $spacing['properties']['padding']['label']);
    $this->assertSame('dimension', $spacing['properties']['padding']['type']);
    $this->assertSame(4, $spacing['properties']['padding']['repeat']);

    // The condition vocabulary, in declaration order, labels as strings and
    // wrappers carrying live thresholds (the client printer's dispatch
    // table). "default" is not listed — the client knows it comes first.
    $this->assertArrayNotHasKey('default', $payload['conditions']);
    $this->assertSame(':hover', $payload['conditions']['hover']['wrapper']);
    $this->assertIsString($payload['conditions']['hover']['label']);
    $this->assertSame('@container (max-width: 767px)', $payload['conditions']['narrow']['wrapper']);
    // The structured facts ride beside the wrapper as the condition types
    // declare them: the canvas-posable width bands' px edges and the
    // emulated preferences' feature + value. The client's ruler, the
    // editing-at derivation and the pose read these, never the wrapper
    // text; a state condition carries neither.
    $this->assertSame(['label', 'wrapper', 'axis'], array_keys($payload['conditions']['hover']));
    $this->assertSame(['max' => 767], $payload['conditions']['narrow']['band']);
    $this->assertSame(['min' => 768, 'max' => 1023], $payload['conditions']['medium']['band']);
    $this->assertArrayNotHasKey('preference', $payload['conditions']['narrow']);
    $this->assertSame(['feature' => 'prefers-color-scheme', 'value' => 'dark'], $payload['conditions']['dark']['preference']);
    $this->assertSame(['feature' => 'prefers-reduced-motion', 'value' => 'reduce'], $payload['conditions']['reduced-motion']['preference']);
    $this->assertArrayNotHasKey('band', $payload['conditions']['dark']);

    // The container-roots switch rides along so the client preview emits
    // the page-container rule exactly when the backend would.
    $this->assertTrue($payload['containerRoots']);

    // Kind nodes carry the merged declaration's riding sections (the
    // entity kind carries daedalus_stylish's own spine declaration, the style map
    // field's existence fact, even though no fixture declares styling for
    // it) and the ROSTER's answers in place of the authored styling: the
    // node's `grants` (group id => granted names) and `valueSpaces`
    // (property => the effective block), never the authored
    // property_groups wire.
    $section = $payload['kinds']['container'];
    $this->assertArrayHasKey('spacing', $section['grants']);
    $this->assertContains('padding', $section['grants']['spacing']);
    $this->assertSame('6rem', $section['valueSpaces']['padding']['max']);
    $this->assertFalse($payload['kinds']['entity']['fields']['daedalus_stylish_styles']['element']);

    // Block type nodes are EFFECTIVE (universal block kind layered
    // underneath by the guide), so the client never re-implements the
    // merge; a part is served as the roster at its part address.
    $testimonial = $payload['blockTypes']['testimonial'];
    $this->assertArrayHasKey('spacing', $testimonial['grants']);
    $this->assertSame('2rem', $testimonial['valueSpaces']['font-size']['max']);
    $this->assertSame('Quote', $testimonial['elements']['.testimonial__quote']['label']);
    $this->assertArrayHasKey('typography', $testimonial['elements']['.testimonial__quote']['grants']);
    $this->assertSame('1.75rem', $testimonial['elements']['.testimonial__quote']['valueSpaces']['font-size']['max']);

    // Layout nodes are EFFECTIVE too (universal container kind layered
    // underneath), keyed by layout plugin id.
    $twocol = $payload['layouts']['layout_twocol_section'];
    $this->assertArrayHasKey('spacing', $twocol['grants']);
    $this->assertSame('6rem', $twocol['valueSpaces']['padding']['max']);
    $this->assertSame('Content container', $twocol['elements']['.band-content']['label']);

    // The served-shape invariants, payload-wide: the authored styling
    // wire is gone everywhere (elements trees and children pseudo-parts
    // included), every node carries grants and value spaces, and every
    // curated grant resolves in the typed groups catalog; a group id
    // outside it is by construction the generated wide tier (the
    // client's csstree lane), its granted names riding like any other.
    $encoded = json_encode($payload);
    $this->assertStringNotContainsString('"property_groups"', $encoded);
    $this->assertStringNotContainsString('"variant_axes"', $encoded);
    $this->assertStringNotContainsString('"sets"', $encoded);
    $this->assertStringNotContainsString('"constraints"', $encoded);
    foreach (['kinds', 'blockTypes', 'blockPlugins', 'layouts', 'instances', 'base'] as $dimension) {
      foreach ($payload[$dimension] as $node) {
        $this->assertServedNode($node, $payload['groups']);
      }
    }

    // A root node carries the roster's axes: the EFFECTIVE vocabulary
    // (the built-in "style" axis first, then declared axes in declaration
    // order), each with its label and depth stamp. The client never
    // re-implements the implicit-axis rule.
    $this->assertSame(['style', 'proportions', 'width'], array_keys($twocol['axes']));
    $this->assertSame(['label' => NULL, 'root_only' => FALSE, 'description' => NULL, 'owns' => []], $twocol['axes']['proportions']);
    $this->assertSame(['style', 'emphasis'], array_keys($testimonial['axes']));
    $this->assertSame(['style'], array_keys($section['axes']));
    $this->assertArrayNotHasKey('axes', $testimonial['elements']['.testimonial__quote'], 'A part carries no axes of its own: variants are worn by elements.');
  }

  /**
   * Every served node is the roster's answers at its address.
   *
   * The payload never re-derives a grant: a kind, a refinement, a part,
   * a children pseudo-part and a base tag each serve exactly what
   * VocabularyRosterBuilder answers for the same address, a narrowed
   * grant already resolved to names, so the client twin reads answers
   * and never rules.
   */
  public function testNodesAreRosters(): void {
    // A narrowed grant on the container kind: the served node lists the
    // granted names, and no allowlist rides for the client to apply.
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => [
        'property_groups' => [
          'spacing' => ['properties' => ['padding-top', 'padding-bottom', 'row-gap', 'column-gap']],
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $builder = $this->container->get('daedalus_stylish.vocabulary_roster');
    $payload = $this->clientStyleGuide->build(self::THEME);

    // The served grant is the roster's answer: the gap shorthand derived
    // from its two longhands rides the names, so the client reads it and
    // never derives a shorthand from the sides.
    $container = $payload['kinds']['container'];
    $this->assertSame(['padding-top', 'padding-bottom', 'gap', 'row-gap', 'column-gap'], $container['grants']['spacing']);
    $this->assertArrayNotHasKey('properties', $container);
    $this->assertStringNotContainsString('"properties":{"spacing"', json_encode($payload));

    foreach ([
      'kind:container' => $container,
      'kind:container::>' => $container['children'],
      'kind:block' => $payload['kinds']['block'],
      'block_type:testimonial' => $payload['blockTypes']['testimonial'],
      'block_type:testimonial::.testimonial__quote' => $payload['blockTypes']['testimonial']['elements']['.testimonial__quote'],
      'block_plugin:fancy_menu' => $payload['blockPlugins']['fancy_menu'],
      'layout:layout_twocol_section' => $payload['layouts']['layout_twocol_section'],
      'layout:layout_twocol_section::.band-content' => $payload['layouts']['layout_twocol_section']['elements']['.band-content'],
      'layout:layout_twocol_section::.band-content::>' => $payload['layouts']['layout_twocol_section']['elements']['.band-content']['children'],
      'base:p' => $payload['base']['p'],
    ] as $address => $node) {
      $roster = $builder->build(Address::fromString($address), self::THEME);
      $this->assertSame($roster->grants(), $node['grants'], $address);
      $this->assertSame($roster->valueSpaces(), $node['valueSpaces'], $address);
      if ($roster->address()->isRoot() && !$roster->address()->isBase()) {
        $this->assertSame($roster->axes(), $node['axes'], $address);
      }
      else {
        $this->assertArrayNotHasKey('axes', $node, $address);
      }
    }

    // The layout's part grants sizing and its children grant layout: the
    // roster's part rule, served at the part and children addresses.
    $band = $payload['layouts']['layout_twocol_section']['elements']['.band-content'];
    $this->assertSame(['sizing'], array_keys($band['grants']));
    $this->assertSame(['layout'], array_keys($band['children']['grants']));

    // The narrowed kind rides into the layout's roster: the layout's node
    // is the resolved answer, not the kind's declaration plus a rule; an
    // undeclared layout has no node at all (the client degrades it to
    // the kind's node, the roster's own degradation).
    $this->assertSame(['padding-top', 'padding-bottom', 'gap', 'row-gap', 'column-gap'], $payload['layouts']['layout_twocol_section']['grants']['spacing']);
    $this->assertArrayNotHasKey('layout_onecol', $payload['layouts']);
  }

  /**
   * Pattern nodes serve what the roster of any class they cover answers.
   *
   * An instance namespace and an owner's pattern part are not addresses
   * ("{prefix}*"), so the payload serves them through the builder's node
   * projection; a concrete class in the namespace resolves, on the
   * server, to that very node, so the served grants equal its roster's.
   */
  public function testPatternNodesAreRosters(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.instances', [
      '.s-*' => [
        'label' => 'Text element',
        'tags' => ['p' => 'Paragraph'],
        'property_groups' => ['typography' => ['properties' => ['font-size', 'color']]],
      ],
    ]);
    $this->container->get('state')->set('daedalus_stylish_control_test.block_types', [
      'testimonial' => [
        'elements' => [
          '.t-*' => ['label' => 'Owner namespace', 'property_groups' => ['background' => []]],
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $builder = $this->container->get('daedalus_stylish.vocabulary_roster');
    $payload = $this->clientStyleGuide->build(self::THEME);

    $namespace = $payload['instances']['.s-*'];
    $this->assertSame('Text element', $namespace['label']);
    $this->assertSame(['p' => 'Paragraph'], $namespace['tags']);
    $this->assertSame(['typography' => ['font-size', 'color']], $namespace['grants']);
    $this->assertArrayNotHasKey('axes', $namespace);
    $covered = $builder->build(Address::fromString('kind:block::.s-a3'), self::THEME);
    $this->assertSame($covered->grants(), $namespace['grants']);
    $this->assertSame($covered->valueSpaces(), $namespace['valueSpaces']);

    $owner = $payload['blockTypes']['testimonial']['elements']['.t-*'];
    $this->assertSame('Owner namespace', $owner['label']);
    $this->assertSame(['background'], array_keys($owner['grants']));
    $covered = $builder->build(Address::fromString('block_type:testimonial::.t-quote'), self::THEME);
    $this->assertSame($covered->grants(), $owner['grants']);
    $this->assertSame($covered->valueSpaces(), $owner['valueSpaces']);
  }

  /**
   * Control widget definitions and controls: bindings ride the payload.
   */
  public function testControls(): void {
    $payload = $this->clientStyleGuide->build(self::THEME);

    // Widget definitions project per plugin id — the label the client shows
    // when a controls: entry sets none (cast to a plain string, the payload
    // is JSON-encoded), the basic-mode section the widget files under and
    // the CSS properties the widget owns. No arrange widget exists: the
    // container-arranges-contents story is the layout widget's dedicated
    // Layout section, not a ControlWidget.
    $this->assertArrayNotHasKey('arrange', $payload['controlWidgets']);
    $this->assertSame('Background', (string) $payload['controlWidgets']['background']['label']);
    $this->assertSame('Shadow', (string) $payload['controlWidgets']['elevation']['label']);

    // The section roster: the four headers in weight order, and every
    // shipped widget files under one of them (the decided roster; the
    // control test module's own widgets ride along and are left out).
    $this->assertSame(['text', 'surface', 'layout', 'motion'], array_keys($payload['controlSections']));
    $this->assertSame(['label' => 'Text', 'weight' => 0], $payload['controlSections']['text']);
    $this->assertSame('Motion', $payload['controlSections']['motion']['label']);
    $sections = array_map(static fn (array $widget) => $widget['section'], $payload['controlWidgets']);
    unset($sections['test_alignment'], $sections['test_orphan']);
    $this->assertSame([
      'background' => 'surface',
      'corners_border' => 'surface',
      'elevation' => 'surface',
      'gap' => 'layout',
      'motion_trigger' => 'motion',
      'position' => 'layout',
      'spacing_scale' => 'layout',
      'text_alignment' => 'text',
      'text_color' => 'text',
      'typography' => 'text',
      'width' => 'layout',
    ], $sections);
    $this->assertSame([
      'background-color',
      'background-image',
      'background-size',
      'background-repeat',
      'background-position',
    ], $payload['controlWidgets']['background']['properties']);
    // corners_border owns the full stroke families: the radius, the three
    // border shorthands with their twelve side longhands (a per-side edit
    // expands every family in one op) and the four outline properties.
    $this->assertSame('Corners, border and outline', (string) $payload['controlWidgets']['corners_border']['label']);
    $this->assertSame([
      'border-radius',
      'border-width',
      'border-top-width',
      'border-right-width',
      'border-bottom-width',
      'border-left-width',
      'border-style',
      'border-top-style',
      'border-right-style',
      'border-bottom-style',
      'border-left-style',
      'border-color',
      'border-top-color',
      'border-right-color',
      'border-bottom-color',
      'border-left-color',
      'outline-width',
      'outline-style',
      'outline-color',
      'outline-offset',
    ], $payload['controlWidgets']['corners_border']['properties']);
    $this->assertSame(['box-shadow'], $payload['controlWidgets']['elevation']['properties']);
    $this->assertSame('Motion trigger', (string) $payload['controlWidgets']['motion_trigger']['label']);
    $this->assertSame(['timeline-trigger', 'animation-trigger'], $payload['controlWidgets']['motion_trigger']['properties']);
    $this->assertSame([
      'padding',
      'padding-top',
      'padding-right',
      'padding-bottom',
      'padding-left',
      'gap',
    ], $payload['controlWidgets']['spacing_scale']['properties']);
    $this->assertSame(['align-self', 'justify-self'], $payload['controlWidgets']['position']['properties']);
    $this->assertSame(['text-align'], $payload['controlWidgets']['text_alignment']['properties']);
    $this->assertSame(['color', 'background-image', 'background-clip'], $payload['controlWidgets']['text_color']['properties']);
    $this->assertSame(['font-family', 'font-size', 'font-weight', 'line-height'], $payload['controlWidgets']['typography']['properties']);
    $this->assertSame(['width'], $payload['controlWidgets']['width']['properties']);

    // Widget ids arrive sorted — deterministic regardless of discovery
    // order across providing modules.
    $ids = array_keys($payload['controlWidgets']);
    $sorted = $ids;
    sort($sorted);
    $this->assertSame($sorted, $ids);

    // The controls: bindings themselves ride inside the effective block
    // type declaration as authored — no separate projection to drift.
    $this->assertSame([
      'label' => 'Alignment',
      'widget' => 'test_alignment',
      'element' => '.media__frame',
    ], $payload['blockTypes']['media']['controls']['alignment']);
  }

  /**
   * The payload backs every padding longhand spacing_scale owns.
   *
   * The box-model diagram edits per side, so the widget owns the four
   * padding longhands alongside the shorthand. The client control model
   * resolves a definition and constraints per owned property (from the
   * groups table and the bound target's declaration respectively), so each
   * longhand must arrive with a dimension definition, and a constraint
   * authored on a longhand must survive layering into the served
   * declaration next to the shorthand's.
   */
  public function testSpacingScalePaddingLonghands(): void {
    $payload = $this->clientStyleGuide->build(self::THEME);

    // Every owned property resolves to a definition in the groups payload,
    // the same lookup the client control model performs per property.
    $spacing = $payload['groups']['spacing']['properties'];
    foreach ($payload['controlWidgets']['spacing_scale']['properties'] as $property) {
      $this->assertSame('dimension', $spacing[$property]['type'], $property);
    }

    // Longhands are single-value and non-negative: no repeat count, and no
    // negative flag (the client clamps padding scrubs at zero from the
    // definition, unlike margins).
    foreach (['padding-top', 'padding-right', 'padding-bottom', 'padding-left'] as $side) {
      $this->assertArrayNotHasKey('repeat', $spacing[$side], $side);
      $this->assertArrayNotHasKey('negative', $spacing[$side], $side);
    }

    // A constraint authored on a longhand (daedalus_stylish_guide_test_extra caps
    // padding-left) rides the served container node's value spaces
    // beside the shorthand's cap, where the client resolves per-property
    // constraints.
    $spaces = $payload['kinds']['container']['valueSpaces'];
    $this->assertSame('6rem', $spaces['padding']['max']);
    $this->assertSame('3rem', $spaces['padding-left']['max']);
  }

  /**
   * Asserts a served node carries the roster's shape, recursively.
   *
   * Every node (root, elements parts at any depth, children pseudo-part
   * sections) carries `grants` (group id => a list of names, every
   * curated id in the groups catalog and every granted name owned by
   * that group) and `valueSpaces` (property => a block, only for granted
   * properties); the authored styling wire never rides.
   */
  protected function assertServedNode(array $node, array $groups): void {
    $this->assertArrayHasKey('grants', $node);
    $this->assertArrayHasKey('valueSpaces', $node);
    $this->assertArrayNotHasKey('property_groups', $node);
    $this->assertArrayNotHasKey('variant_axes', $node);
    $granted = [];
    foreach ($node['grants'] as $id => $names) {
      $this->assertIsString($id);
      $this->assertIsList($names);
      $this->assertNotEmpty($names, "$id grants at least one name.");
      if (!str_starts_with($id, 'wide:')) {
        $this->assertArrayHasKey($id, $groups, $id);
        foreach ($names as $name) {
          $this->assertArrayHasKey($name, $groups[$id]['properties'], "$id owns $name");
        }
      }
      array_push($granted, ...$names);
    }
    foreach ($node['valueSpaces'] as $property => $block) {
      $this->assertContains($property, $granted, "$property is granted where it has a value space.");
      $this->assertIsArray($block);
      $this->assertNotEmpty($block);
    }
    if (isset($node['children']) && is_array($node['children'])) {
      $this->assertServedNode($node['children'], $groups);
    }
    foreach ($node['elements'] ?? [] as $part) {
      if (is_array($part)) {
        $this->assertServedNode($part, $groups);
      }
    }
  }

  /**
   * Materials never ride the guide; their group vocabularies do.
   *
   * The active skin's tokens and the published swatches are materials:
   * they ride the roster's delivery payload beside the blueprint mirror
   * (daedalus_blueprint's MaterialsDelivery) and the `materials` delta on
   * every mutation response, never the guide. The guide keeps the
   * DECLARATIONS pickers group them under: token scales and swatch kinds,
   * whose labels come from the declarations and plugin definitions, never
   * client hardcoding.
   */
  public function testMaterialsRideTheRosterNotTheGuide(): void {
    $red = DaedalusStylishSwatch::create(['label' => 'Brand red', 'kind' => 'color']);
    $red->setSwatchValue('#b91c1c');
    $red->save();

    $payload = $this->clientStyleGuide->build(self::THEME);
    $this->assertArrayNotHasKey('swatches', $payload);
    $this->assertArrayNotHasKey('tokens', $payload);
    $this->assertStringNotContainsString('brand_red', json_encode($payload));
    $this->assertSame('Color', $payload['swatchKinds']['color']['label']);
    $this->assertSame('Gradient', $payload['swatchKinds']['gradient']['label']);
    $this->assertArrayHasKey('tokenScales', $payload);

    // The roster carries the swatch instead, in the delivery record shape.
    $records = $this->container->get('daedalus_blueprint.materials_delivery')->payload()['records'];
    $this->assertSame(['swatch', 'brand_red'], [$records[0]['kind'], $records[0]['id']]);
    $this->assertSame('var(--swatch-brand_red)', $records[0]['spellings']['style']);

    // The swatch list tag is the roster's to carry (its provider names
    // it); the guide payload no longer depends on the palette.
    $this->assertNotContains('daedalus_swatch_list', ClientStyleGuide::CACHE_TAGS);
    $this->assertContains('daedalus_swatch_list', $this->container->get('daedalus_blueprint.materials_delivery')->cacheMetadata()->getCacheTags());
  }

  /**
   * The active published skin's edit URL rides the payload, else NULL.
   *
   * The inspector's "Edit skin…" doorway for a skin-supplied value: served
   * under the same gate as skinDefaults (active AND published), so the
   * doorway exists exactly when a skin value can show.
   */
  public function testSkinEditUrl(): void {
    $this->assertNull($this->clientStyleGuide->build(self::THEME)['skinEditUrl'], 'No active skin: no edit URL.');

    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora']);
    $skin->save();
    $this->assertNull($this->clientStyleGuide->build(self::THEME)['skinEditUrl'], 'A saved but inactive skin: no edit URL.');

    $this->activateSkin($skin);
    $this->assertSame('/admin/appearance/skins/' . $skin->id(), $this->clientStyleGuide->build(self::THEME)['skinEditUrl'], 'The active published skin serves its edit-form URL.');

    $skin->setUnpublished()->save();
    $this->assertNull($this->clientStyleGuide->build(self::THEME)['skinEditUrl'], 'An unpublished active skin serves no edit URL, matching the skinDefaults gate.');
  }

  /**
   * The declared defaults tier rides the payload always, beside the skin's.
   *
   * A declaration's own defaults are code, so they serve with no skin at
   * all and unchanged beside an active published skin's defaults: the
   * inspector resolves the two tiers apart (the skin's over the
   * declaration's) and names the supplier in the origin.
   */
  public function testDeclaredDefaults(): void {
    $payload = $this->clientStyleGuide->build(self::THEME);
    $this->assertSame([], $payload['declaredDefaults'], 'No declaring refinement: the key is present and empty.');
    $this->assertSame([], $payload['skinDefaults']);

    // The declaring fixture is enabled inside the test so the class's
    // other payloads stay declaration-free; the guide re-reads the
    // module list after the container rebuild.
    $this->enableModules(['daedalus_stylish_declared_defaults_test']);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->clientStyleGuide = $this->container->get('daedalus_stylish.client_style_guide');
    $expected = $this->container->get('daedalus_stylish.style_guide')->declaredDefaults(self::THEME);
    $this->assertSame([
      'block_plugin:fancy_menu' => ['' => ['default' => ['display' => 'flex']]],
      'block_type:image' => [
        '' => ['default' => ['display' => 'block', 'width' => 'fit-content']],
        '::.image-block-image' => ['default' => ['display' => 'block']],
      ],
      'layout:layout_onecol' => [
        '' => ['default' => ['display' => 'grid'], 'narrow' => ['display' => 'block']],
        '::.band-inner' => ['default' => ['max-width' => '80rem']],
      ],
    ], $expected);
    $payload = $this->clientStyleGuide->build(self::THEME);
    $this->assertSame($expected, $payload['declaredDefaults'], 'No skin: the declared tier serves alone.');
    $this->assertSame([], $payload['skinDefaults']);

    // An active published skin adds its own tier and leaves the declared
    // one exactly as it was: two keys, two suppliers.
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setDefaults(['block_type:image' => ['' => ['default' => ['display' => 'inline-block']]]])->save();
    $this->activateSkin($skin);
    $payload = $this->clientStyleGuide->build(self::THEME);
    $this->assertSame($expected, $payload['declaredDefaults'], 'With a skin: the declared tier is unchanged.');
    $this->assertSame(['block_type:image' => ['' => ['default' => ['display' => 'inline-block']]]], $payload['skinDefaults']);
  }

  /**
   * The site's own constraint defaults ride the payload, and only those.
   *
   * The authored constraint_defaults section serves as the property-keyed
   * map beneath the rosters' value spaces (which already fold the
   * authored half in, the law), so the client guide model can resolve an
   * offer (the node's value space, else this map) for widget stop rows.
   * A node's block is that node's value space and never a site-wide
   * entry, however many nodes agree on it.
   */
  public function testConstraintDefaults(): void {
    // The fixture guide authors no site section of its own: the map is
    // exactly daedalus_stylish's shipped bindings (the site lane every fixture
    // layers over: font-family to font, the type trio, and the dimension
    // shorthands to space and radius, served expanded over their
    // families), even though every fixture node constraining padding
    // agrees on 6rem (the theme layer); that cap rides each node's value
    // space.
    $payload = $this->clientStyleGuide->build(self::THEME);
    $space = ['scale' => 'space', 'allowed' => ['0']];
    $radius = ['scale' => 'radius', 'allowed' => ['0']];
    $this->assertSame([
      'font-family' => ['scale' => 'font'],
      'font-size' => ['scale' => 'text'],
      'font-weight' => ['scale' => 'weight'],
      'line-height' => ['scale' => 'leading'],
      'padding' => $space,
      'padding-top' => $space,
      'padding-right' => $space,
      'padding-bottom' => $space,
      'padding-left' => $space,
      'gap' => $space,
      'row-gap' => $space,
      'column-gap' => $space,
      'border-radius' => $radius,
      'border-top-left-radius' => $radius,
      'border-top-right-radius' => $radius,
      'border-bottom-right-radius' => $radius,
      'border-bottom-left-radius' => $radius,
    ], $payload['constraintDefaults']);

    // An authored section is the map (the state-injected site REPLACES
    // the layered lane, shipped binding included), served expanded over
    // the shorthand family by the guide, so the client never expands for
    // itself: the gap block is row-gap's and column-gap's too.
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => ['space' => ['label' => 'Space', 'shape' => 'dimension']],
      'constraint_defaults' => ['gap' => ['scale' => 'space', 'stops' => ['2', '4']]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $payload = $this->clientStyleGuide->build(self::THEME);
    $gap = ['scale' => 'space', 'stops' => ['2', '4']];
    $this->assertSame(['gap' => $gap, 'row-gap' => $gap, 'column-gap' => $gap], $payload['constraintDefaults'], 'the payload carries the site map the guide serves, the gap family expanded at the source');
  }

  /**
   * The withheld verdict rides the payload: the stale row's WHY.
   *
   * A published variant the current law refuses is off the roster (the
   * materials delivery drops it, so its wearers show a stale row); the
   * guide names it under `withheld` with the same {path, code, message}
   * triples the STYLE_VARIANT_NONCONFORMING refusal carries, so the row
   * can say "withheld by the style guide" with the refused values. The
   * verdict follows the law: under open enforcement nothing binds and
   * the map is empty. The variant list tag is the payload's, so a
   * variant save re-serves the reasons.
   */
  public function testWithheld(): void {
    // The verdict judges under the site default theme, like every write.
    $this->config('system.theme')->set('default', self::THEME)->save();
    $this->assertSame([], $this->clientStyleGuide->build(self::THEME)['withheld'], 'No variants: nothing withheld.');

    // The fixture theme caps a container's padding at 6rem, a constraint
    // that is law under guarded (the default); the published variant
    // exceeds it and a conforming sibling does not.
    $loud = DaedalusStylishVariant::create(['label' => 'Loud', 'machine_name' => 'loud', 'scope' => 'kind:container']);
    $loud->setStyles(['' => ['default' => ['padding' => '9rem']]])->save();
    $tidy = DaedalusStylishVariant::create(['label' => 'Tidy', 'machine_name' => 'tidy', 'scope' => 'kind:container']);
    $tidy->setStyles(['' => ['default' => ['padding' => '1rem']]])->save();

    $payload = $this->clientStyleGuide->build(self::THEME);
    $this->assertSame(['loud'], array_keys($payload['withheld']));
    $this->assertCount(1, $payload['withheld']['loud']);
    $refusal = $payload['withheld']['loud'][0];
    $this->assertSame(['path', 'code', 'message'], array_keys($refusal));
    $this->assertSame('default.padding', $refusal['path'], 'The path is relative to the variant\'s styles.');
    $this->assertSame('STYLE_VALUE_ABOVE_MAX', $refusal['code']);
    $this->assertStringContainsString('9rem', $refusal['message']);
    // The same triples the reference refusal's `refused` context carries.
    $this->assertSame($this->container->get('daedalus_stylish.variant_conformance')->refusals(), $payload['withheld']);
    $this->assertFalse($this->container->get('daedalus_blueprint.materials_roster_builder')->build()->has(MaterialKind::Variant, 'loud'), 'The withheld variant is off the roster: its wearers show a stale row.');

    // Open law binds nothing: the map empties with no write in between.
    $this->enforce('open');
    $this->assertSame([], $this->clientStyleGuide->build(self::THEME)['withheld']);

    // A variant save re-judges: the list tag is the payload's to carry.
    $this->assertContains('daedalus_variant_list', ClientStyleGuide::CACHE_TAGS);
  }

  /**
   * The entity's layout root serves as a part node of the entity kind.
   *
   * daedalus_layout declares the anatomy (label, tag) and its display: grid;
   * daedalus_stylish layers spacing and layout over the part. The payload serves
   * the merged part as a roster node, the twin of the roster at
   * kind:entity::.daedalus-layout-layout, so the inspector's Spacing and Layout
   * sections on the part, the code view's completions and the page-level
   * Gap control read one truth. daedalus_layout is enabled inside the case so the
   * class's other payloads stay as the module list declares them.
   */
  public function testEntityLayoutRootPart(): void {
    $this->enableModules(['block', 'daedalus_layout']);
    $this->container->get('daedalus_ui.page_builder_declarations')->resetCache();
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->clientStyleGuide = $this->container->get('daedalus_stylish.client_style_guide');
    $payload = $this->clientStyleGuide->build(self::THEME);

    $entity = $payload['kinds']['entity'];
    $this->assertSame(['.daedalus-layout-layout'], array_keys($entity['elements']));
    $part = $entity['elements']['.daedalus-layout-layout'];
    $this->assertSame('Layout', $part['label']);
    $this->assertSame(['spacing', 'layout'], array_keys($part['grants']));
    $this->assertContains('gap', $part['grants']['spacing']);
    $this->assertContains('display', $part['grants']['layout']);
    $this->assertArrayNotHasKey('axes', $part, 'A part carries no axes of its own.');
    $this->assertArrayNotHasKey('children', $part, 'The sections are containers of their own, not a children pseudo-part.');

    $roster = $this->container->get('daedalus_stylish.vocabulary_roster')->build(Address::fromString('kind:entity::.daedalus-layout-layout'), self::THEME);
    $this->assertSame($roster->grants(), $part['grants']);
    $this->assertSame($roster->valueSpaces(), $part['valueSpaces']);

    // The declared tier carries daedalus_layout's box fact for the part: code, no
    // skin, the mechanic a gap needs.
    $this->assertSame(['default' => ['display' => 'grid']], $payload['declaredDefaults']['kind:entity']['::.daedalus-layout-layout']);
  }

  /**
   * The enforcement mode is the active design system's.
   */
  public function testEnforcement(): void {
    $this->enforce('strict');
    $this->assertSame('strict', $this->clientStyleGuide->build(self::THEME)['enforcement']);
  }

  /**
   * Without an explicit theme the payload resolves the site default theme.
   */
  public function testDefaultTheme(): void {
    $this->config('system.theme')->set('default', self::THEME)->save();
    $payload = $this->clientStyleGuide->build();
    $this->assertArrayHasKey('container', $payload['kinds']);
  }

}
