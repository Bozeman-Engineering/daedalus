<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\CssPrinter;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_ui\Render\ElementAddress;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the site defaults tier: skin defaults grammar and delivery.
 *
 * A skin's defaults field holds {scope: element-relative styles} with LIVE
 * semantics — validated through the defaults seam
 * (StyleMapValidator::validateDefaults(), riding the scope-only seam),
 * delivered through the compiled defaults stylesheet as bare
 * zero-specificity rules: element scopes at their element address
 * selector (the data-element attribute daedalus_ui stamps), the closed
 * base:{tag} family at :where(tag), every one overridden by every
 * authored style by specificity.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SkinDefaultsTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * Any theme name works: an unknown theme simply has no theme layers.
   */
  protected const THEME = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
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
    'daedalus_stylish_control_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The client payload serves the published swatches, so every build()
    // consumer needs the swatch entity schema.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
      ],
      'base' => [
        'h2' => ['property_groups' => ['typography' => []]],
        'a' => ['property_groups' => ['typography' => []]],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * Validates defaults through the defaults seam.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The validation errors; empty when valid.
   */
  protected function validateDefaults(array $defaults): array {
    return $this->container->get('daedalus_stylish.style_map_validator')->validateDefaults($defaults, ['theme' => self::THEME]);
  }

  /**
   * The error codes keyed by path.
   */
  protected function codes(array $errors): array {
    $codes = [];
    foreach ($errors as $error) {
      $codes[$error->getPath()][] = $error->getCode();
    }
    return $codes;
  }

  /**
   * Strict defaults validate var() references against the OWNING skin.
   *
   * The self-containment rule: a skin's defaults deliver only while that
   * skin is active, so their token references resolve against ITS tokens
   * (the skin_tokens context), never whatever skin is active at write
   * time. Without the context, the active-skin universe applies (here:
   * nothing minted, so the reference errors).
   */
  public function testStrictDefaultsValidateAgainstOwnSkin(): void {
    $this->enforce('strict');
    $defaults = [
      'base:h2' => ['' => ['default' => ['font-size' => 'var(--space-4)']]],
    ];

    $codes = $this->codes($this->validateDefaults($defaults));
    $this->assertSame(['STYLE_TOKEN_NOT_MINTED'], $codes['base:h2.default.font-size'] ?? [], var_export($codes, TRUE));

    $errors = $this->container->get('daedalus_stylish.style_map_validator')->validateDefaults($defaults, [
      'theme' => self::THEME,
      'skin_tokens' => ['space' => ['4' => '1rem']],
    ]);
    $this->assertSame([], $errors);
  }

  /**
   * Valid defaults across every scope family pass the seam.
   */
  public function testValidDefaults(): void {
    $this->assertSame([], $this->validateDefaults([
      'base:h2' => ['' => ['default' => ['font-size' => '2rem'], 'narrow' => ['font-size' => '1.5rem']]],
      'base:a' => [
        '' => ['default' => ['color' => 'var(--color-accent)'], 'hover' => ['text-decoration' => 'underline']],
      ],
      'kind:container' => [
        // The one-box container's shipped shape: the width fact is a
        // padding-inline stop on the root itself.
        '' => ['default' => ['padding-inline' => 'max(var(--space-gutter), calc((100% - 60rem) / 2))']],
        // An undeclared part on a permissive site: grammar-checked only.
        '::.band-inner' => ['default' => ['max-width' => '80rem']],
      ],
      'layout:layout_onecol' => ['' => ['default' => ['padding' => '3rem']]],
      'block_type:media' => ['' => ['default' => ['margin' => '1rem']]],
      'block_plugin:system_menu_block' => ['' => ['default' => ['margin' => '1rem']]],
      'kind:entity' => ['' => ['default' => ['background' => '#fff']]],
      'kind:field' => ['' => ['default' => ['margin-block' => '0.5rem']]],
      'kind:block' => ['' => ['default' => ['padding' => '1rem']]],
    ]));
  }

  /**
   * The defaults scope vocabulary is gated: base tags closed, region out.
   */
  public function testScopeGate(): void {
    // base:div is outside the closed tag vocabulary — not a scope at all.
    $codes = $this->codes($this->validateDefaults(['base:div' => ['' => ['default' => ['color' => 'red']]]]));
    $this->assertSame(['SKIN_DEFAULT_SCOPE_INVALID'], $codes['base:div']);

    // Regions are not addressable elements: nothing could carry the class.
    $codes = $this->codes($this->validateDefaults(['kind:region' => ['' => ['default' => ['padding' => '1rem']]]]));
    $this->assertSame(['SKIN_DEFAULT_SCOPE_INVALID'], $codes['kind:region']);

    // Not a scope shape at all.
    $codes = $this->codes($this->validateDefaults(['sections' => ['' => ['default' => ['padding' => '1rem']]]]));
    $this->assertSame(['SKIN_DEFAULT_SCOPE_INVALID'], $codes['sections']);

    // A base tag NOT declared by the site is still a legal scope: it
    // degrades to an empty declaration (checkable on permissive sites).
    $this->assertSame([], $this->validateDefaults(['base:p' => ['' => ['default' => ['margin-block' => '1rem']]]]));

    // ... but in strict mode an undeclared tag has nothing stylable.
    $this->enforce('strict');
    $this->assertNotSame([], $this->validateDefaults(['base:p' => ['' => ['default' => ['margin-block' => '1rem']]]]));
    // A declared tag with a declared property group passes strict.
    $this->assertSame([], $this->validateDefaults(['base:h2' => ['' => ['default' => ['font-size' => '2rem']]]]));
  }

  /**
   * Defaults keys are element-only, and entries carry styles directly.
   */
  public function testKeyAndEntryNarrowings(): void {
    // Slot paths cannot deliver (no placement to distribute through).
    $codes = $this->codes($this->validateDefaults([
      'kind:container' => ['container(1)' => ['default' => ['padding' => '1rem']]],
    ]));
    $this->assertSame(['SKIN_DEFAULT_KEY_NOT_ELEMENT'], $codes['kind:container.container(1)']);

    // Variant references are per-element choices, never global defaults.
    $codes = $this->codes($this->validateDefaults([
      'kind:container' => ['' => ['variants' => [['variant' => 'hero', 'fragment' => '']]]],
    ]));
    $this->assertSame(['SKIN_DEFAULT_VARIANT_REF'], $codes['kind:container.']);

    // A non-map scope value is a shape error.
    $codes = $this->codes($this->validateDefaults(['kind:container' => 'nope']));
    $this->assertSame(['INVALID_SKIN_DEFAULTS_ENTRY'], $codes['kind:container']);

    // Style errors re-root under the scope.
    $codes = $this->codes($this->validateDefaults([
      'base:h2' => ['' => ['bogus-condition' => ['font-size' => '2rem']]],
    ]));
    $this->assertSame(['INVALID_STYLE_CONDITION'], $codes['base:h2.bogus-condition']);
  }

  /**
   * Base:{tag} is a variant scope too: tag treatments share the family.
   *
   * The axes resolve through the site declaration's base section exactly
   * like any scope's declaration (the built-in "style" axis when the tag
   * declares no variant_axes); a tag outside the closed vocabulary is
   * still no scope at all.
   */
  public function testBaseScopeIsVariantScope(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->assertSame(['style'], $validator->scopeAxes('base:h2', self::THEME));
    $this->assertNull($validator->scopeAxes('base:div', self::THEME));
  }

  /**
   * The guide's base grammar: closed vocabulary, site-only placement.
   */
  public function testBaseGuideGrammar(): void {
    $state = $this->container->get('state');
    $guide = $this->container->get('daedalus_stylish.style_guide');

    // A tag outside the closed vocabulary fails the build loudly.
    $state->set('daedalus_stylish_control_test.site', [
      'base' => ['div' => ['property_groups' => ['layout' => []]]],
    ]);
    $guide->resetCache();
    try {
      $guide->getBaseElements(self::THEME);
      $this->fail('An out-of-vocabulary base tag must fail the guide build.');
    }
    catch (StyleGuideException $exception) {
      $this->assertStringContainsString('"div" is not in the base tag vocabulary', $exception->getMessage());
    }

    // Base declared on a kind is a misplacement error.
    $state->set('daedalus_stylish_control_test.site', []);
    $state->set('daedalus_stylish_control_test.kinds', [
      'container' => ['base' => ['h2' => []]],
    ]);
    $guide->resetCache();
    try {
      $guide->getKind('container', self::THEME);
      $this->fail('A base section on a kind must fail the guide build.');
    }
    catch (StyleGuideException $exception) {
      $this->assertStringContainsString('base elements are site vocabulary', $exception->getMessage());
    }
  }

  /**
   * Defaults compile into the defaults stylesheet in delivery rank order.
   */
  public function testDefaultsDelivery(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setDefaults([
      // Stored deliberately OUT of rank order: refinement first.
      'layout:layout_onecol' => ['' => ['default' => ['padding' => '3rem']]],
      'kind:container' => [
        '' => ['default' => ['padding' => '2rem']],
        '::.band-inner' => ['default' => ['max-width' => '80rem']],
      ],
      'kind:block' => ['' => ['default' => ['padding' => '1rem'], 'narrow' => ['padding' => '0.5rem']]],
      'block_plugin:system_menu_block' => ['' => ['default' => ['margin' => '1rem']]],
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
    ]);
    $skin->save();
    $this->activateSkin($skin);

    $uri = $this->container->get('state')->get(DefaultsStylesheet::STATE_KEY);
    $this->assertIsString($uri);
    $css = file_get_contents($uri);

    // The whole tier is BARE rules inside the token scope element: no
    // @scope anywhere (a default in its own @scope at the element would
    // beat an ancestor's equal-weight rule on proximity) and FULLY
    // :where()-wrapped, the scope class included, so every default sits
    // at zero specificity: the element tag tier's bare in-scope tag
    // (0-0-1) beats a base default outright, and every authored rule
    // beats every default by specificity alone.
    $this->assertStringContainsString(':where(.' . DefaultsStylesheet::SCOPE_CLASS . ') :where(h2) {', $css);
    $this->assertStringContainsString('font-size: 2rem;', $css);
    $this->assertStringNotContainsString('@scope', $css);
    $this->assertStringNotContainsString(':scope', $css);
    $this->assertStringNotContainsString('daedalus-stylish-defaults--', $css, 'No scope class exists any more.');

    // Element defaults select by the element address daedalus_ui stamps,
    // PREFIXED by the token scope element inside the :where(): the one
    // boundary both tiers honor, so an address outside it (admin theme,
    // dialogs) is inert. A kind selects its whole family.
    $selector = static fn (string $scope): string => DefaultsStylesheet::elementSelector(Address::fromString($scope));
    $prefix = '.' . DefaultsStylesheet::SCOPE_CLASS . ' [' . ElementAddress::ATTRIBUTE;
    $this->assertSame(':where(' . $prefix . '^="layout:"])', $selector('kind:container'));
    $this->assertStringContainsString($selector('kind:container') . " {\n  padding: 2rem;\n}", $css);
    $this->assertStringContainsString($selector('layout:layout_onecol') . " {\n  padding: 3rem;\n}", $css);
    // Part entries print as :where() descendants of the address selector.
    $this->assertStringContainsString($selector('kind:container') . " :where(.band-inner) {\n  max-width: 80rem;\n}", $css);
    // The block kind is the two placement families; its plugin
    // refinement compiles the same way at its own exact address.
    $this->assertSame(':where(' . $prefix . '^="block_type:"], ' . $prefix . '^="block_plugin:"])', $selector('kind:block'));
    $this->assertStringContainsString($selector('kind:block') . " {\n  padding: 1rem;\n}", $css);
    $this->assertStringContainsString($selector('block_plugin:system_menu_block') . " {\n  margin: 1rem;\n}", $css);

    // Delivery rank order: base, then kinds, then refinements — the
    // refinement must beat its kind by document order at equal
    // specificity.
    $base_at = strpos($css, ':where(h2)');
    $kind_at = strpos($css, $selector('kind:container'));
    $refinement_at = strpos($css, $selector('layout:layout_onecol'));
    $this->assertLessThan($kind_at, $base_at);
    $this->assertLessThan($refinement_at, $kind_at);
    $block_kind_at = strpos($css, $selector('kind:block'));
    $plugin_at = strpos($css, $selector('block_plugin:system_menu_block'));
    $this->assertLessThan($plugin_at, $block_kind_at);

    // A width-conditioned default demands the live width container exactly
    // like a width-conditioned token.
    $this->assertStringContainsString('@container (max-width: 767px)', $css);
    $this->assertStringContainsString(CssPrinter::PAGE_CONTAINER_RULE, $css);

    // Unpublishing retires defaults from delivery.
    $skin->setUnpublished()->save();
    $this->assertNull($this->container->get('state')->get(DefaultsStylesheet::STATE_KEY));
  }

  /**
   * A children entry prints as the address selector's direct children.
   *
   * The bare twin of the children pseudo-part: ' > *' on the element's
   * selector (and on a part's), still at zero specificity, no donut.
   */
  public function testChildrenDefaultsDelivery(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setDefaults([
      'kind:container' => [
        '::> *' => ['default' => ['min-width' => '0']],
        '::.band-inner > *' => ['default' => ['flex' => '1']],
      ],
    ]);
    $skin->save();
    $this->activateSkin($skin);
    $css = file_get_contents($this->container->get('state')->get(DefaultsStylesheet::STATE_KEY));
    $selector = DefaultsStylesheet::elementSelector(Address::fromString('kind:container'));
    $this->assertStringContainsString($selector . " > * {\n  min-width: 0;\n}", $css);
    $this->assertStringContainsString($selector . " :where(.band-inner) > * {\n  flex: 1;\n}", $css);
    $this->assertStringNotContainsString('@scope', $css);
  }

  /**
   * The blueprint lane: the defaults field handler rides the seam.
   */
  public function testFieldHandler(): void {
    $handler = $this->container->get('daedalus_stylish.field_handler.skin_defaults');
    $definition = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('daedalus_skin', 'daedalus_skin')['defaults'];
    $this->assertTrue($handler->applies($definition));

    // A valid write stores canonical JSON with normalized compound
    // spellings ('dark+narrow' canonicalizes to 'narrow+dark').
    $result = $handler->deserialize([
      'base:h2' => ['' => ['dark+narrow' => ['font-size' => '1.5rem'], 'default' => ['font-size' => '2rem']]],
    ], $definition);
    $this->assertFalse($result->hasErrors());
    $this->assertStringContainsString('narrow+dark', $result->getValue());

    // Errors re-root under the field.
    $errors = $handler->deserialize(['kind:region' => ['' => ['default' => ['padding' => '1rem']]]], $definition)->getErrors();
    $this->assertSame('SKIN_DEFAULT_SCOPE_INVALID', $errors[0]->getCode());
    $this->assertSame('fields.defaults.kind:region', $errors[0]->getPath());

    // Not an object of scopes at all.
    $this->assertSame('INVALID_SKIN_DEFAULTS', $handler->deserialize('nope', $definition)->getErrors()[0]->getCode());

    // The schema catalog: the bare key list in every posture (no
    // kind:region, base tags listed), and what each scope's element may
    // style as the stylable entries, by property name at the scope-key
    // grammar, in every posture too.
    $additions = $handler->getSchemaAdditions($definition);
    $this->assertIsList($additions['style_guide']['scopes']);
    $this->assertNotContains('kind:region', $additions['style_guide']['scopes']);
    $this->assertContains('h2', $additions['style_guide']['base_tags']);
    $this->assertContains('base:h2', $additions['style_guide']['scopes']);
    $entries = $additions['style_guide']['stylable']['entries'];
    $this->assertArrayNotHasKey('kind:region', $entries);
    $this->assertStringContainsString('font-size', $entries['base:h2']);

    $this->enforce('strict');
    $additions = $handler->getSchemaAdditions($definition);
    $this->assertSame($entries, $additions['style_guide']['stylable']['entries']);
    $this->assertIsList($additions['style_guide']['scopes']);
    $this->assertContains('base:h2', $additions['style_guide']['scopes']);
  }

  /**
   * The client payload serves the active skin's defaults as provenance.
   */
  public function testClientPayload(): void {
    $payload = $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME);
    $this->assertSame([], $payload['skinDefaults']);

    $skin = DaedalusStylishSkin::create(['label' => 'aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $defaults = ['kind:container' => ['' => ['default' => ['padding' => '2rem']]]];
    $skin->setDefaults($defaults)->save();
    $this->activateSkin($skin);
    $payload = $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME);
    $this->assertSame($defaults, $payload['skinDefaults']);
  }

  /**
   * The carbon skin's defaults conform to the suite's bindings under law.
   *
   * A skin's defaults go through the same validateAt() as any style map,
   * so on a law site every value on a property the suite binds must be a
   * token the skin itself mints (the bindings define the minimum skin, no
   * fallback). Carbon ships global (no design system of its own), so the
   * test meets it the way a strict site would: the suite's own vocabulary
   * (not the controlled one setUp injects), a code-built system wearing
   * the carbon skin in strict enforcement, then the module's sync, which
   * validates the shipped defaults under that law as it lands them.
   */
  public function testCarbonConformsUnderStrict(): void {
    $this->container->get('state')->delete('daedalus_stylish_control_test.site');
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    // The importers switch to an administrative account.
    $this->setUpCurrentUser([], [], TRUE);
    // daedalus_stylish_layouts grants the container root its spacing (the gap the
    // skin's kind:container default sets), as on every site.
    $this->enableModules(['daedalus_stylish_carbon', 'daedalus_stylish_layouts']);
    $this->activateSkin('carbon');
    $this->enforce('strict');
    $this->assertSame('strict', $this->container->get('daedalus_stylish.style_guide')->enforcement(), 'the carbon system is in strict enforcement');

    $report = $this->container->get('daedalus_stylish.shipped_sync')->sync(['daedalus_stylish_carbon']);
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));

    $skin = $this->container->get('daedalus_stylish.skin_manager')->loadActiveSkin();
    $this->assertNotNull($skin, 'the carbon skin landed and is the active skin');
    $this->assertSame('carbon', $skin->getMachineName());
    $tokens = $skin->getTokens();
    $defaults = $skin->getDefaults();

    $errors = $this->container->get('daedalus_stylish.style_map_validator')->validateDefaults($defaults, ['theme' => self::THEME, 'skin_tokens' => $tokens]);
    $this->assertSame([], array_map(static fn (BlueprintError $error): string => $error->getPath() . ' [' . $error->getCode() . ']: ' . $error->getMessage(), $errors), 'the carbon skin\'s defaults validate empty under strict law');

    // The bound line-height speaks the skin's leading scale: every value
    // a default carries is a minted leading token, never a literal.
    $line_heights = [];
    foreach ($defaults as $scope => $styles) {
      foreach ($styles as $relative => $entry) {
        foreach ($entry as $condition => $properties) {
          if (isset($properties['line-height'])) {
            $line_heights["$scope$relative.$condition"] = $properties['line-height'];
          }
        }
      }
    }
    $this->assertNotEmpty($line_heights, 'the carbon defaults set line-height');
    foreach ($line_heights as $at => $value) {
      $this->assertMatchesRegularExpression('/^var\\(--leading-([a-z0-9-]+)\\)$/', $value, "$at line-height is a leading token");
      $name = substr($value, strlen('var(--leading-'), -1);
      $this->assertArrayHasKey($name, $tokens['leading'], "$at line-height names a minted leading token");
    }
  }

}
