<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_stylish\CssPrinter;
use Drupal\daedalus_stylish\AnimationStylesheet;
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Drupal\daedalus_stylish\FontStylesheet;
use Drupal\daedalus_stylish\SwatchStylesheet;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_ui\Chromeless;
use Drupal\daedalus_ui\Render\ElementAddress;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests defaults delivery: the compiled defaults stylesheet and its stamps.
 *
 * One shared, hash-busted file carries two tiers: the DECLARED tier (every
 * refinement declaration's own `defaults`, code, printed first and with
 * no skin gate) and, only with an active published skin, that skin's
 * tokens (custom-property declarations on the token scope element,
 * .daedalus-stylish-token-scope: width-conditioned values as @container blocks,
 * never @media, since the edit-mode canvas is a constrained div;
 * scheme/motion-conditioned values as @media blocks; compounds composed
 * by the same wrapper machinery style maps ride) and its site defaults.
 * The "daedalus_stylish/defaults" library resolves the current file at discovery
 * time; recompiles run on skin saves, on a design-system switch (the
 * active skin is the active system's default_skin), on daedalus_stylish.settings
 * saves (breakpoint thresholds, container_roots), on workspace publish
 * (the staged revision previewing via page-level inline CSS in the
 * meantime), and on every declaration change (module install and
 * uninstall among them). The whole tier prints bare at zero specificity,
 * element scopes selecting by the element address daedalus_ui stamps
 * unconditionally (data-element), so a declared default lands on its
 * element with no skin at all and every authored rule beats it.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DefaultsStylesheetTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;
  use WorkspaceTestTrait;

  /**
   * The entity type manager, re-fetched by WorkspaceTestTrait.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The declaring fixture: a block type, a block plugin and a layout.
   *
   * Block type image, block plugin fancy_menu, layout layout_onecol, each
   * shipping its own defaults.
   */
  protected const FIXTURE = 'daedalus_stylish_declared_defaults_test';

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
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    // Skins are a swatch-usage source: publish resyncs their rows; the
    // module install hook's shipped sync reads every usage table.
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
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
        'transition' => ['label' => 'Transition', 'shape' => 'text', 'prefix' => 'trans'],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * Creates and saves a skin (which compiles via hook_entity_insert()).
   */
  protected function createSkin(string $machine_name, array $tokens, bool $published = TRUE): DaedalusStylishSkin {
    $skin = DaedalusStylishSkin::create([
      'label' => $machine_name,
      'machine_name' => $machine_name,
      'status' => $published,
    ]);
    $skin->setPackageTokens($tokens)->save();
    return $skin;
  }

  /**
   * The current compiled stylesheet URI from state.
   *
   * Read through the current container: the module installer rebuilds it,
   * and a state instance from before the rebuild would answer from its
   * own cache.
   */
  protected function stylesheetUri(): ?string {
    return \Drupal::state()->get(DefaultsStylesheet::STATE_KEY);
  }

  /**
   * Enables the declaring fixture without install hooks and rebuilds the guide.
   */
  protected function enableFixture(): void {
    $this->enableModules([self::FIXTURE]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * The bare selector a declared or skin element-scope default prints at.
   */
  protected function elementSelector(string $scope): string {
    return DefaultsStylesheet::elementSelector(Address::fromString($scope));
  }

  /**
   * The exact-address member of an element selector, token-scope prefixed.
   */
  protected function addressMember(string $scope): string {
    return '.' . DefaultsStylesheet::SCOPE_CLASS . ' [' . ElementAddress::ATTRIBUTE . '="' . $scope . '"]';
  }

  /**
   * The active skin's tokens compile on activation and on every save.
   */
  public function testCompileOnSave(): void {
    $skin = $this->createSkin('aurora', [
      'space' => [
        '4' => '1rem',
        // Tokens may reference tokens: var() passes the emit gate verbatim
        // and the browser resolves it on the scope element.
        '8' => 'calc(var(--space-4) * 2)',
      ],
      'color' => [
        // Deliberately unordered condition spellings: 'dark+narrow' is the
        // non-canonical spelling of 'narrow+dark'.
        'accent' => [
          'dark+narrow' => '#333333',
          'dark' => '#eeeeee',
          'default' => '#111111',
          'narrow' => '#222222',
        ],
      ],
      'transition' => ['fast' => '150ms ease'],
    ]);

    // An INACTIVE skin compiles nothing: the file holds the site's worn
    // look, not every held one (and no declaration here ships defaults).
    $this->assertNull($this->stylesheetUri());

    // Activating recompiles via the daedalus_blueprint.settings save
    // (DesignSystemSwitchSubscriber): no direct compile call anywhere.
    $this->activateSkin($skin);
    $uri = $this->stylesheetUri();
    $this->assertIsString($uri);
    $this->assertStringStartsWith(DefaultsStylesheet::DIRECTORY . '/defaults-', $uri);
    $this->assertFileExists($uri);
    $css = file_get_contents($uri);

    // Tokens deliver on the token scope element, never :root. The file's
    // ONE :root rule is the scheme declaration (the skin's dark values ride
    // prefers-color-scheme, so the UA's own chrome must follow it too), and
    // no custom property prints on it.
    $this->assertStringContainsString('@scope (.' . DefaultsStylesheet::SCOPE_CLASS . ')', $css);
    $this->assertStringContainsString(CssPrinter::COLOR_SCHEME_RULE, $css);
    $this->assertSame(1, substr_count($css, ':root'));
    $root_at = strpos($css, ':root {');
    $root_rule = substr($css, $root_at, strpos($css, '}', $root_at) - $root_at);
    $this->assertStringNotContainsString('--', $root_rule);
    $this->assertStringContainsString('--space-4: 1rem;', $css);
    $this->assertStringContainsString('--space-8: calc(var(--space-4) * 2);', $css);
    // The declared prefix rides the ONE derivation rule.
    $this->assertStringContainsString('--trans-fast: 150ms ease;', $css);

    // Width conditions are @container (the edit-mode canvas is a
    // constrained div; @media would not react), scheme conditions @media.
    $this->assertStringContainsString('@container (max-width: 767px)', $css);
    $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
    // The compound composes BOTH wrappers, canonicalized to axis order.
    $this->assertSame(2, substr_count($css, '@container (max-width: 767px)'));
    $compound = strpos($css, '#333333');
    $this->assertIsInt($compound);
    // Block order is the cascade: default, then singles in vocabulary
    // order, then compounds, so the more specific compound value wins by
    // document order where it co-applies with its parts.
    $this->assertLessThan(strpos($css, '#222222'), strpos($css, '#111111'));
    $this->assertLessThan(strpos($css, '#eeeeee'), strpos($css, '#222222'));
    $this->assertLessThan($compound, strpos($css, '#eeeeee'));

    // Width-conditioned tokens demand the live page-level width container.
    $this->assertStringContainsString(CssPrinter::PAGE_CONTAINER_RULE, $css);

    // A token edit recompiles to a NEW filename and cleans up the old file.
    $skin->setPackageTokens(['space' => ['4' => '2rem']]);
    $skin->save();
    $new_uri = $this->stylesheetUri();
    $this->assertNotSame($uri, $new_uri);
    $this->assertFileExists($new_uri);
    $this->assertFileDoesNotExist($uri);
    $css = file_get_contents($new_uri);
    $this->assertStringContainsString('--space-4: 2rem;', $css);
    // No width conditions left: the containment rule is demand-driven.
    $this->assertStringNotContainsString('container-type', $css);
    // No scheme conditions left either: a light-only skin declares no
    // scheme (the used scheme is already light).
    $this->assertStringNotContainsString(CssPrinter::COLOR_SCHEME_RULE, $css);
    $this->assertStringNotContainsString(':root', $css);

    // An unpublished active skin compiles nothing (the variants posture).
    $skin->setUnpublished();
    $skin->save();
    $this->assertNull($this->stylesheetUri());
    $this->assertFileDoesNotExist($new_uri);
  }

  /**
   * A skin choice recompiles the file to the chosen member skin.
   *
   * The choice is a daedalus_stylish.settings save, so the recompile is
   * DefaultsSettingsSubscriber's (no direct compile call anywhere), and
   * the read falls back to the default_skin the moment the system may
   * no longer wear the choice (here: the chosen skin unpublished, whose
   * save recompiles through the skin change hook).
   */
  public function testChoiceRecompiles(): void {
    $this->activateSkin('aurora');
    $skin = DaedalusStylishSkin::create(['label' => 'aurora', 'machine_name' => 'aurora', 'design_system' => 'aurora']);
    $skin->setPackageTokens(['space' => ['4' => '1rem']])->save();
    $dusk = DaedalusStylishSkin::create(['label' => 'dusk', 'machine_name' => 'dusk', 'design_system' => 'aurora']);
    $dusk->setPackageTokens(['space' => ['4' => '2rem']])->save();
    $uri = $this->stylesheetUri();
    $this->assertIsString($uri);
    $this->assertStringContainsString('--space-4: 1rem;', file_get_contents($uri));

    $this->container->get('daedalus_stylish.skin_manager')->choose('dusk');
    $chosen_uri = $this->stylesheetUri();
    $this->assertIsString($chosen_uri);
    $this->assertNotSame($uri, $chosen_uri);
    $this->assertFileDoesNotExist($uri);
    $this->assertStringContainsString('--space-4: 2rem;', file_get_contents($chosen_uri));

    $dusk->setUnpublished()->save();
    $fallback_uri = $this->stylesheetUri();
    $this->assertIsString($fallback_uri);
    $this->assertStringContainsString('--space-4: 1rem;', file_get_contents($fallback_uri));
  }

  /**
   * The declared tier compiles with no active system, on module install.
   *
   * A block's box is the block's fact, not the site's look: the file
   * exists and stamps demand its scopes on a site that has activated no
   * design system at all, and the install and uninstall of the declaring
   * module are recompile triggers of their own (hook_modules_installed()
   * and hook_modules_uninstalled(), through the real installer).
   */
  public function testDeclaredTierPrintsWithoutSkin(): void {
    $this->assertNull($this->container->get('daedalus_stylish.skin_manager')->activeSkinName());
    $this->assertNull($this->stylesheetUri());
    // The real installer's uninstall runs user's hook, which sweeps the
    // module's user data.
    $this->installSchema('user', ['users_data']);

    \Drupal::service('module_installer')->install([self::FIXTURE]);
    $uri = $this->stylesheetUri();
    $this->assertIsString($uri, 'Installing a declaring module compiles the declared tier with no skin.');
    $this->assertStringStartsWith(DefaultsStylesheet::DIRECTORY . '/defaults-', $uri);
    $css = file_get_contents($uri);

    // Every declaring refinement prints BARE at its element address,
    // token-scope-prefixed and fully :where()-wrapped (zero specificity, no
    // @scope, no donut), through the skin defaults' own printer path: root
    // rules at the address selector, part rules as :where() descendants.
    $this->assertSame(':where(' . $this->addressMember('block_plugin:fancy_menu') . ')', $this->elementSelector('block_plugin:fancy_menu'));
    $this->assertStringContainsString($this->elementSelector('block_plugin:fancy_menu') . " {\n  display: flex;\n}", $css);
    $this->assertStringContainsString($this->elementSelector('block_type:image') . " {\n  display: block;\n  width: fit-content;\n}", $css);
    $this->assertStringContainsString($this->elementSelector('block_type:image') . " :where(.image-block-image) {\n  display: block;\n}", $css);
    $this->assertStringContainsString($this->elementSelector('layout:layout_onecol') . " :where(.band-inner) {", $css);
    $this->assertStringNotContainsString('@scope', $css, 'No default prints inside an @scope block.');
    $this->assertStringNotContainsString(':scope', $css);
    // Refinement rank order, stored order within: block plugins, block
    // types, layouts.
    $plugin_at = strpos($css, $this->elementSelector('block_plugin:fancy_menu'));
    $type_at = strpos($css, $this->elementSelector('block_type:image'));
    $layout_at = strpos($css, $this->elementSelector('layout:layout_onecol'));
    $this->assertLessThan($type_at, $plugin_at);
    $this->assertLessThan($layout_at, $type_at);
    // No skin: no token block. The layout's width-conditioned default
    // demands the live width container exactly like a skin's would.
    $this->assertStringContainsString('@container (max-width: 767px)', $css);
    $this->assertStringContainsString(CssPrinter::PAGE_CONTAINER_RULE, $css);
    // The declared tier never feeds the scheme declaration: a module's
    // dark-conditioned box fact says nothing about the site's scheme, and
    // with no skin the page is the theme's light fallbacks.
    $this->assertStringNotContainsString('color-scheme', $css);

    // The library serves the file.
    $library = \Drupal::service('library.discovery')->getLibraryByName('daedalus_stylish', 'defaults');
    $this->assertStringContainsString(basename($uri), $library['css'][0]['data']);

    // Uninstalling the declaring module recompiles: nothing left to print.
    \Drupal::service('module_installer')->uninstall([self::FIXTURE]);
    $this->assertNull($this->stylesheetUri(), 'Uninstalling the declaring module recompiles the declared tier away.');
    $this->assertFileDoesNotExist($uri);
  }

  /**
   * A composed kind's root default compiles into the file, kind first.
   *
   * The ownership law's delivery half: the container kind declared
   * composed defaults its own root, the compiled declared tier carries
   * the kind scope as a bare rule selecting every section by its layout
   * address (the kind is the whole layout: family), ranked before every
   * refinement so a layout's default wins by document order, with no skin
   * at all.
   */
  public function testComposedKindRootDefaultCompiles(): void {
    $this->assertNull($this->container->get('daedalus_stylish.skin_manager')->activeSkinName());
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['composed' => TRUE, 'defaults' => ['' => ['default' => ['display' => 'flex', 'flex-direction' => 'column', 'gap' => '0px']]]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->container->get('daedalus_stylish.defaults_stylesheet')->compile();
    $uri = $this->stylesheetUri();
    $this->assertIsString($uri, 'A kind\'s declared default compiles the declared tier with no skin.');
    $css = file_get_contents($uri);
    $this->assertSame(':where(.' . DefaultsStylesheet::SCOPE_CLASS . ' [' . ElementAddress::ATTRIBUTE . '^="layout:"])', $this->elementSelector('kind:container'));
    $this->assertStringContainsString($this->elementSelector('kind:container') . " {\n  display: flex;\n  flex-direction: column;\n  gap: 0px;\n}", $css);

    // Beside the refinements the kind block leads the file: kind before
    // refinement is the delivery rank order both tiers share.
    $this->enableFixture();
    $this->container->get('daedalus_stylish.defaults_stylesheet')->compile();
    $css = file_get_contents($this->stylesheetUri());
    $kind_at = strpos($css, $this->elementSelector('kind:container'));
    $this->assertIsInt($kind_at);
    $this->assertLessThan(strpos($css, $this->elementSelector('block_plugin:fancy_menu')), $kind_at);
    $this->assertLessThan(strpos($css, $this->elementSelector('layout:layout_onecol')), $kind_at);
  }

  /**
   * The declared tier prints beneath the skin's tiers for the same scope.
   *
   * Equal (zero) specificity, so document order decides: the declaration
   * ships the floor, the skin is the site's look. The declared tier leads
   * the whole file (before the token block too).
   */
  public function testDeclaredTierPrintsBeneathSkin(): void {
    $this->enableFixture();
    $skin = $this->createSkin('aurora', ['space' => ['4' => '1rem']]);
    $skin->setPackageDefaults([
      'block_type:image' => ['' => ['default' => ['display' => 'inline-block']]],
      'kind:block' => ['' => ['default' => ['padding' => '1rem']]],
    ])->save();
    $this->activateSkin($skin);
    $css = file_get_contents($this->stylesheetUri());

    // The same scope root prints for both tiers (one @scope block per
    // relative key: the declared root and part, then the skin's root);
    // the declared blocks lead, the skin's kind block sits between them
    // and the skin's refinement block (its rank), and the token block
    // follows the whole declared tier.
    $declared_at = strpos($css, 'width: fit-content;');
    $skin_at = strpos($css, 'display: inline-block;');
    $kind_at = strpos($css, $this->elementSelector('kind:block'));
    $tokens_at = strpos($css, '--space-4: 1rem;');
    $this->assertIsInt($declared_at);
    $this->assertIsInt($skin_at);
    $this->assertLessThan($declared_at, strpos($css, $this->elementSelector('block_type:image')));
    $this->assertLessThan($tokens_at, $declared_at);
    $this->assertLessThan($kind_at, $tokens_at);
    $this->assertLessThan(strrpos($css, $this->elementSelector('block_type:image')), $kind_at);
    $this->assertLessThan($skin_at, strrpos($css, $this->elementSelector('block_type:image')));
  }

  /**
   * A composed block's root is selected by the compiled declared default.
   *
   * The two halves of the one derivation, on one live render with no
   * design system: daedalus_ui stamps the placement's element address on
   * the block build (LayoutBuilderBlockAttributes), ComposedBlockRender
   * hands that bag to the composed element as the root's hoisted
   * attributes, and the compiled file's bare selector names exactly that
   * attribute value. Pinned here: no stylish stamping exists, and the
   * declared default's selector must match the composed root of a live
   * render byte for byte.
   */
  public function testComposedRootIsSelectedByDeclaredDefault(): void {
    $this->enableModules(['block', 'block_content', 'link', 'daedalus_ui_resolver_test']);
    $this->installEntitySchema('block_content');
    $this->installConfig(['system']);
    BlockContentType::create(['id' => 'text', 'label' => 'Text'])->save();
    foreach ([
      'field_text_text' => ['type' => 'string_long'],
      'field_text_tag' => ['type' => 'string'],
      'field_text_url' => ['type' => 'link'],
    ] as $field_name => $storage) {
      FieldStorageConfig::create($storage + ['field_name' => $field_name, 'entity_type' => 'block_content'])->save();
      FieldConfig::create(['field_name' => $field_name, 'entity_type' => 'block_content', 'bundle' => 'text'])->save();
    }
    // The resolver fixture declares the text block composed; the declared
    // default rides the same declaration through the state layer.
    $this->container->get('state')->set('daedalus_stylish_control_test.block_types', [
      'text' => ['defaults' => ['' => ['default' => ['display' => 'block']]]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->assertNull($this->container->get('daedalus_stylish.skin_manager')->activeSkinName());

    $block = BlockContent::create([
      'type' => 'text',
      'info' => 'Text',
      'field_text_text' => 'Composed content',
      'field_text_tag' => 'p',
    ]);
    $block->save();
    $component = new SectionComponent('20000000-0000-0000-0000-000000000000', 'content', [
      'id' => 'inline_block:text',
      'block_revision_id' => $block->getRevisionId(),
    ]);
    // Live: no editing attributes demanded; the address is delivery.
    $this->setUpCurrentUser();
    $build = $component->toRenderArray();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringNotContainsString('<div', $html, 'No sandwich renders at all.');
    $this->assertMatchesRegularExpression(
      '/<p[^>]*\s' . ElementAddress::ATTRIBUTE . '="block_type:text"/',
      $html,
      'The composed root wears the bundle address, with no skin and no demand.',
    );
    $this->assertStringNotContainsString('daedalus-stylish-defaults--', $html, 'No scope class stamps anywhere.');
    $this->container->get('daedalus_stylish.defaults_stylesheet')->compile();
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringContainsString($this->elementSelector('block_type:text') . " {\n  display: block;\n}", $css);
    $this->assertStringContainsString('[' . ElementAddress::ATTRIBUTE . '="block_type:text"]', $this->elementSelector('block_type:text'), 'The selector names the stamped value verbatim.');
  }

  /**
   * The shipped container kind's flex column selects every section.
   *
   * daedalus_stylish_layouts declares `display: flex; flex-direction: column`
   * on the composed container's root (the stack: the box fact its gap
   * grant and children pseudo-part need, code rather than skin data, and
   * the direction the row variant flips) and `gap: 0px` beside it (a
   * plain stack's zero gap as a declared layer of the style stack, so
   * the Gap row's floor is declared, never a widget literal). With no skin and no
   * other declaring module the declared tier holds exactly that kind
   * scope, and the file prints it bare at the kind's selector: every
   * section's layout address, a page band's <section> and a nested
   * container's <div> alike, at zero specificity and with no donut (a
   * default is the same value on every instance of its scope). The
   * address itself is daedalus_ui's stamp (ElementAddressStampTest).
   */
  public function testShippedContainerKindDefaultSelectsEverySection(): void {
    $this->enableModules(['daedalus_stylish_layouts']);
    $this->installConfig(['system']);
    $this->setUpCurrentUser();
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->assertNull($this->container->get('daedalus_stylish.skin_manager')->activeSkinName());
    $this->assertSame(
      ['' => ['default' => ['display' => 'flex', 'flex-direction' => 'column', 'gap' => '0px']]],
      $this->container->get('daedalus_stylish.style_guide')->getKind('container')['defaults'],
      'The shipped container kind declares its root as a flex column with a zero gap.',
    );

    $this->container->get('daedalus_stylish.defaults_stylesheet')->compile();
    $uri = $this->stylesheetUri();
    $this->assertIsString($uri, 'The shipped declaration alone compiles the declared tier.');
    $css = file_get_contents($uri);
    $this->assertStringContainsString($this->elementSelector('kind:container') . " {\n  display: flex;\n  flex-direction: column;\n  gap: 0px;\n}", $css);
    $this->assertStringNotContainsString('@scope', $css);
    $this->assertStringNotContainsString(' to (', $css, 'No donut bounds a default.');
    // The shipped layout declares no defaults of its own: no refinement
    // rule, and the kind's selector names no layout in particular.
    $this->assertStringNotContainsString('layout:daedalus_stylish_container', $css);
  }

  /**
   * The element selector derivation: one rule per address family.
   *
   * A refinement selects its exact address; a kind selects every address
   * of its kind (the two block placement families, the layout family, the
   * bare entity and field kinds); base tags and non-root addresses derive
   * nothing; every member is token-scope prefixed inside one :where().
   */
  public function testElementSelectorDerivation(): void {
    $scope = '.' . DefaultsStylesheet::SCOPE_CLASS . ' ';
    $attribute = ElementAddress::ATTRIBUTE;
    $this->assertSame(":where({$scope}[$attribute=\"block_type:media\"])", $this->elementSelector('block_type:media'));
    $this->assertSame(":where({$scope}[$attribute=\"block_plugin:system_menu_block\"])", $this->elementSelector('block_plugin:system_menu_block'));
    $this->assertSame(":where({$scope}[$attribute=\"layout:layout_onecol\"])", $this->elementSelector('layout:layout_onecol'));
    $this->assertSame(":where({$scope}[$attribute^=\"block_type:\"], {$scope}[$attribute^=\"block_plugin:\"])", $this->elementSelector('kind:block'));
    $this->assertSame(":where({$scope}[$attribute^=\"layout:\"])", $this->elementSelector('kind:container'));
    $this->assertSame(":where({$scope}[$attribute=\"kind:entity\"])", $this->elementSelector('kind:entity'));
    $this->assertSame(":where({$scope}[$attribute=\"kind:field\"])", $this->elementSelector('kind:field'));
    $this->assertNull(DefaultsStylesheet::elementSelector(Address::fromString('base:h2')), 'A base tag is its own selector.');
    $this->assertNull(DefaultsStylesheet::elementSelector(Address::fromString('kind:region')), 'No root resolves through the region kind.');
    $this->assertNull(DefaultsStylesheet::elementSelector(Address::fromString('kind:container::.x')), 'Only a root address selects.');
  }

  /**
   * Skin scoped defaults co-target; base tag defaults never do.
   *
   * Kind:* and block_type:* root defaults ride the bare lane with the
   * co-target companion (printBareEntry, the same INHERITED_TEXT_PROPERTIES
   * subset printEntry co-targets), at the same zero specificity under the
   * same selector: a skin default setting a block's color reaches
   * text-claiming interior elements past a base:a default, which prints
   * earlier in the file and would otherwise win on the claiming element
   * by matching it directly. base:{tag} defaults MUST NOT co-target: they
   * ARE the interposer the co-target exists to beat, and co-targeting
   * them would re-land the tag default on the claiming element and defeat
   * the fix. Part and children entries never co-target either.
   */
  public function testScopedDefaultsCoTargetBaseDefaultsDoNot(): void {
    $skin = $this->createSkin('site_skin', ['space' => ['4' => '1rem']]);
    $skin->setPackageDefaults([
      'base:a' => ['' => ['default' => ['color' => '#111111']]],
      'kind:block' => ['' => ['default' => ['color' => '#222222', 'padding' => '1rem']]],
      'block_type:text' => ['' => ['default' => ['color' => '#333333']]],
    ])->save();
    $this->activateSkin($skin);
    $css = file_get_contents($this->stylesheetUri());

    // Element scopes carry the companion with the inherited text subset,
    // bare and under their own selector.
    $this->assertStringContainsString($this->elementSelector('kind:block') . ' ' . CssPrinter::CLAIMS_CONTENT_SELECTOR . " {\n  color: #222222;\n}", $css);
    $this->assertStringContainsString($this->elementSelector('block_type:text') . ' ' . CssPrinter::CLAIMS_CONTENT_SELECTOR . " {\n  color: #333333;\n}", $css);
    // Box properties never ride the companion.
    $this->assertSame(1, substr_count($css, 'padding: 1rem;'));
    // The base:a color prints once (the bare rule), and the whole file
    // holds exactly the two element-scope companions: none for base:a,
    // none for the token block (custom properties are not in the set).
    $this->assertSame(1, substr_count($css, '#111111'));
    $this->assertSame(2, substr_count($css, CssPrinter::CLAIMS_CONTENT_SELECTOR));
    // The base rule and the companions all sit at zero specificity, in
    // document order base first: the companion wins on the claiming
    // element by order alone.
    $this->assertLessThan(strpos($css, '#222222'), strpos($css, '#111111'));
  }

  /**
   * The kill switch also gates the page-level width container.
   */
  public function testContainerRootsKillSwitch(): void {
    $skin = $this->createSkin('aurora', [
      'space' => ['4' => ['default' => '1rem', 'narrow' => '0.5rem']],
    ]);
    $this->activateSkin($skin);
    $this->assertStringContainsString('container-type: inline-size', file_get_contents($this->stylesheetUri()));

    // Flipping the switch recompiles (daedalus_stylish.settings save) without it:
    // the theme owns containment, the @container blocks stay.
    $this->config('daedalus_stylish.settings')->set('container_roots', FALSE)->save();
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringNotContainsString('container-type', $css);
    $this->assertStringContainsString('@container (max-width: 767px)', $css);
  }

  /**
   * Tokens on a scale the site no longer declares degrade to inert data.
   */
  public function testUndeclaredScaleIsInert(): void {
    $skin = $this->createSkin('aurora', [
      'space' => ['4' => '1rem'],
      'retired_scale' => ['x' => '1rem'],
    ]);
    $this->activateSkin($skin);
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringContainsString('--space-4: 1rem;', $css);
    $this->assertStringNotContainsString('retired', $css);
  }

  /**
   * The daedalus_stylish/defaults library resolves the current compiled file.
   */
  public function testLibraryResolvesCurrentFile(): void {
    // Before anything compiles the library is asset-less but defined:
    // pages attach it unconditionally of compile state
    // (daedalus_stylish_page_attachments()).
    $discovery = $this->container->get('library.discovery');
    $library = $discovery->getLibraryByName('daedalus_stylish', 'defaults');
    $this->assertNotFalse($library);
    $this->assertSame([], $library['css'] ?? []);

    $skin = $this->createSkin('aurora', ['space' => ['4' => '1rem']]);
    $this->activateSkin($skin);
    $library = $discovery->getLibraryByName('daedalus_stylish', 'defaults');
    $this->assertCount(1, $library['css']);
    $this->assertStringContainsString(basename($this->stylesheetUri()), $library['css'][0]['data']);
    // Token definitions compete unlayered, like every daedalus-stylish-owned file.
    $this->assertTrue($library['css'][0]['daedalus_stylish_layers_exempt'] ?? FALSE);

    // The library key is the file's DRUPAL_ROOT-relative path, not a browser
    // URL: core reads a leading-slash key as DRUPAL_ROOT-relative (strips
    // the slash into `data`) and prepends base_path() once at render. A
    // generated URL already carries base_path() and doubled it on every
    // subdirectory install (d.o CI serves the site at /web: the four
    // compiled files 404'd as /web/web/sites/...).
    $path = $this->container->get('daedalus_stylish.defaults_stylesheet')->libraryPath();
    $this->assertMatchesRegularExpression(
      '#^/' . preg_quote(PublicStream::basePath(), '#') . '/daedalus_stylish/defaults-[0-9a-f]{16}\.css$#',
      $path,
    );
    $this->assertSame(substr($path, 1), $library['css'][0]['data']);
    $this->assertSame('file', $library['css'][0]['type']);
  }

  /**
   * Deleting the active skin removes the compiled file.
   *
   * The active design system keeps naming the skin (the derivation is the
   * system's default_skin, not a pointer to clear); it now loads nothing,
   * and the delete hook's recompile prints nothing (no declaration here
   * ships defaults).
   */
  public function testDeleteCleansUp(): void {
    $skin = $this->createSkin('aurora', ['space' => ['4' => '1rem']]);
    $this->activateSkin($skin);
    $uri = $this->stylesheetUri();
    $this->assertFileExists($uri);

    $skin->delete();
    $skin_manager = $this->container->get('daedalus_stylish.skin_manager');
    $this->assertSame('aurora', $skin_manager->activeSkinName());
    $this->assertNull($skin_manager->loadActiveSkin());
    $this->assertNull($this->stylesheetUri());
    $this->assertFileDoesNotExist($uri);
  }

  /**
   * Workspace preview: staged skin CSS inlines; the file stays live.
   *
   * The edge at page level: library assets cannot vary by workspace, so
   * the compiled file always holds the LIVE revision; inside a workspace
   * the active skin's staged revision prints via workspaceInlineCss() as a
   * <style> tag inside the token scope element
   * (daedalus_stylish_preprocess_off_canvas_page_wrapper(); document order
   * beats the compiled <link> in <head>), and its cache tags ride the
   * attachments (daedalus_stylish_page_attachments()). Publishing
   * recompiles the file: per-entity update hooks do not fire on publish,
   * so that exercises SkinWorkspaceSubscriber.
   */
  public function testWorkspacePreviewInlineFallback(): void {
    $skin = $this->createSkin('aurora', ['space' => ['4' => '1rem']]);
    $this->activateSkin($skin);

    $this->initializeWorkspacesModule();
    $this->setUpCurrentUser();
    $live_uri = $this->stylesheetUri();
    $stylesheet = $this->container->get('daedalus_stylish.defaults_stylesheet');

    // Outside a workspace there is no preview to inline: the scope element
    // wraps the children alone.
    $tags = [];
    $this->assertSame('', $stylesheet->workspaceInlineCss($tags));
    $this->assertSame('<div class="' . DefaultsStylesheet::SCOPE_CLASS . '">X</div>', $this->wrap());

    // Stage a token edit: the shared public file keeps the live CSS.
    $this->switchToWorkspace('stage');
    $staged = $this->entityTypeManager->getStorage('daedalus_skin')->load($skin->id());
    $staged->setPackageTokens(['space' => ['4' => '9rem']]);
    $staged->save();
    $this->assertSame($live_uri, $this->stylesheetUri());
    $this->assertStringContainsString('--space-4: 1rem;', file_get_contents($live_uri));

    // Inside the workspace the staged revision inlines at the same scope
    // class, and its tags ride along so staged edits invalidate the page.
    $tags = [];
    $css = $stylesheet->workspaceInlineCss($tags);
    $this->assertStringContainsString('@scope (.' . DefaultsStylesheet::SCOPE_CLASS . ')', $css);
    $this->assertStringContainsString('--space-4: 9rem;', $css);
    $this->assertContains("daedalus_skin:{$skin->id()}", $tags);

    // The print lands INSIDE the scope element, before the children (the
    // one place every defaults output lives), and the page varies by
    // workspace and the staged skin's tags through the attachments.
    $wrapped = $this->wrap();
    $this->assertMatchesRegularExpression(
      '#^<div class="' . DefaultsStylesheet::SCOPE_CLASS . '"><style data-daedalus-stylish-defaults-preview>\n.*--space-4: 9rem;.*\n</style>X</div>$#s',
      $wrapped,
    );
    $this->assertStringNotContainsString('data-daedalus-stylish-swatch-preview', $wrapped);
    $attachments = $this->pageAttachments();
    $this->assertContains('workspace', $attachments['#cache']['contexts']);
    $this->assertContains("daedalus_skin:{$skin->id()}", $attachments['#cache']['tags']);

    // Publishing lands the staged tokens in the shared file.
    $this->switchToLive();
    $this->workspaces['stage']->publish();
    $published_uri = $this->stylesheetUri();
    $this->assertNotSame($live_uri, $published_uri);
    $this->assertStringContainsString('--space-4: 9rem;', file_get_contents($published_uri));
    $this->assertFileDoesNotExist($live_uri);
  }

  /**
   * The skin's reach is gated at the token scope element.
   *
   * The scope element is the single boundary all defaults output prints
   * inside, so not stamping it (plus skipping the attachments, an
   * optimization) is what keeps user-authored styling data off
   * admin-theme pages and out of pop-out documents
   * (_daedalus_stylish_skinless_document()).
   */
  public function testAdminThemeReachGate(): void {
    $libraries = [
      'daedalus_stylish/defaults',
      'daedalus_stylish/swatches',
      'daedalus_stylish/animations',
      'daedalus_stylish/fonts',
    ];
    $tags = [
      DefaultsStylesheet::CACHE_TAG,
      'config:daedalus_stylish.settings',
      SwatchStylesheet::CACHE_TAG,
      AnimationStylesheet::CACHE_TAG,
      FontStylesheet::CACHE_TAG,
    ];

    // No admin theme configured: the site chose one look everywhere, so
    // nothing gates: scope element stamped, the four libraries attached
    // with their recompile tags (the swatch, animations and fonts files
    // ride the same seam and the same gate).
    $this->assertStringContainsString(DefaultsStylesheet::SCOPE_CLASS, $this->wrap());
    $attachments = $this->pageAttachments();
    $this->assertSame($libraries, $attachments['#attached']['library']);
    $this->assertEqualsCanonicalizing($tags, $attachments['#cache']['tags']);

    // The active theme IS the configured admin theme: no scope element,
    // no attach; the defaults do not exist on admin-theme pages.
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('admin', 'stark')->save();
    $this->container->get('theme.manager')->setActiveTheme(
      $this->container->get('theme.initialization')->initTheme('stark'));
    $this->assertSame('X', $this->wrap());
    $this->assertSame([], $this->pageAttachments());

    // A frontend theme on a site WITH an admin theme configured still gets
    // the defaults: the gate compares the active theme, not the route.
    $this->config('system.theme')->set('admin', 'claro')->save();
    $this->assertStringContainsString(DefaultsStylesheet::SCOPE_CLASS, $this->wrap());
    $this->assertSame($libraries, $this->pageAttachments()['#attached']['library']);

    // A pop-out route (the _daedalus_ui.popout option, daedalus_ui.chromeless)
    // is a tool window, not a site page: no scope element, no attach, in
    // the frontend theme too.
    $request = Request::create('/popout');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'popout');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/popout', [], [], [Chromeless::ROUTE_OPTION => TRUE]));
    $this->container->get('request_stack')->push($request);
    $this->assertSame('X', $this->wrap());
    $this->assertSame([], $this->pageAttachments());
    $this->container->get('request_stack')->pop();
    $this->assertStringContainsString(DefaultsStylesheet::SCOPE_CLASS, $this->wrap());
    $this->assertSame($libraries, $this->pageAttachments()['#attached']['library']);
  }

  /**
   * Runs the scope-element preprocess over one child and returns the markup.
   */
  private function wrap(): string {
    $variables = ['children' => 'X'];
    daedalus_stylish_preprocess_off_canvas_page_wrapper($variables);
    return (string) $variables['children'];
  }

  /**
   * Runs hook_page_attachments() and returns the attachments array.
   */
  private function pageAttachments(): array {
    $attachments = [];
    $this->container->get('module_handler')
      ->invoke('daedalus_stylish', 'page_attachments', [&$attachments]);
    return $attachments;
  }

}
