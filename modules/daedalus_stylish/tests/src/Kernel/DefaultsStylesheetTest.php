<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Layout\LayoutDefinition;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_stylish\ContainerDefaultsStamp;
use Drupal\daedalus_stylish\CssPrinter;
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

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
 * uninstall among them). The stamping seams read the union of both
 * tiers' scopes, so a declared default lands on its element with no
 * skin at all.
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
    // Skins are a swatch-usage source: publish resyncs their rows; the
    // module install hook's shipped sync reads every usage table.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
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
    $skin->setTokens($tokens)->save();
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
   * The @scope root selector of a declared or skin element-scope block.
   */
  protected function scopeRoot(string $scope): string {
    return '@scope (.' . DefaultsStylesheet::SCOPE_CLASS . ' .' . DefaultsStylesheet::scopeClass($scope) . ')';
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

    // Tokens deliver on the token scope element, never :root.
    $this->assertStringContainsString('@scope (.' . DefaultsStylesheet::SCOPE_CLASS . ')', $css);
    $this->assertStringNotContainsString(':root', $css);
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
    $skin->setTokens(['space' => ['4' => '2rem']]);
    $skin->save();
    $new_uri = $this->stylesheetUri();
    $this->assertNotSame($uri, $new_uri);
    $this->assertFileExists($new_uri);
    $this->assertFileDoesNotExist($uri);
    $css = file_get_contents($new_uri);
    $this->assertStringContainsString('--space-4: 2rem;', $css);
    // No width conditions left: the containment rule is demand-driven.
    $this->assertStringNotContainsString('container-type', $css);

    // An unpublished active skin compiles nothing (the variants posture).
    $skin->setUnpublished();
    $skin->save();
    $this->assertNull($this->stylesheetUri());
    $this->assertFileDoesNotExist($new_uri);
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

    // Every declaring refinement prints as an @scope block at its stamped
    // scope class, token-scope-prefixed, through the skin defaults' own
    // printer path: root rules at :scope, part rules with the root-hoist
    // alternative, the container family with the class-extended donut.
    $this->assertStringContainsString($this->scopeRoot('block_plugin:fancy_menu') . " {\n  :scope {\n    display: flex;\n  }", $css);
    $this->assertStringContainsString($this->scopeRoot('block_type:image') . " {\n  :scope {\n    display: block;\n    width: fit-content;\n  }", $css);
    $this->assertStringContainsString(':scope.image-block-image, .image-block-image', $css);
    $this->assertStringContainsString($this->scopeRoot('layout:layout_onecol') . ' to (' . DefaultsStylesheet::DEFAULTS_SCOPE_LIMIT . ')', $css);
    $this->assertStringContainsString(':scope.band-inner, .band-inner', $css);
    // Refinement rank order, stored order within: block plugins, block
    // types, layouts.
    $plugin_at = strpos($css, $this->scopeRoot('block_plugin:fancy_menu'));
    $type_at = strpos($css, $this->scopeRoot('block_type:image'));
    $layout_at = strpos($css, $this->scopeRoot('layout:layout_onecol'));
    $this->assertLessThan($type_at, $plugin_at);
    $this->assertLessThan($layout_at, $type_at);
    // No skin: no token block. The layout's width-conditioned default
    // demands the live width container exactly like a skin's would.
    $this->assertStringNotContainsString('@scope (.' . DefaultsStylesheet::SCOPE_CLASS . ')', $css);
    $this->assertStringContainsString('@container (max-width: 767px)', $css);
    $this->assertStringContainsString(CssPrinter::PAGE_CONTAINER_RULE, $css);

    // The stamping demand is the declared scopes, with no skin to add to
    // them; the library serves the file.
    $this->assertSame(
      ['block_plugin:fancy_menu', 'block_type:image', 'layout:layout_onecol'],
      \Drupal::service('daedalus_stylish.defaults_stylesheet')->scopes(),
    );
    $library = \Drupal::service('library.discovery')->getLibraryByName('daedalus_stylish', 'defaults');
    $this->assertStringContainsString(basename($uri), $library['css'][0]['data']);

    // Uninstalling the declaring module recompiles: nothing left to print.
    \Drupal::service('module_installer')->uninstall([self::FIXTURE]);
    $this->assertNull($this->stylesheetUri(), 'Uninstalling the declaring module recompiles the declared tier away.');
    $this->assertFileDoesNotExist($uri);
    $this->assertSame([], \Drupal::service('daedalus_stylish.defaults_stylesheet')->scopes());
  }

  /**
   * A composed kind's root default compiles into the file, kind first.
   *
   * The ownership law's delivery half: the container kind declared
   * composed defaults its own root, the compiled declared tier carries
   * the kind scope as an @scope block at its family class (with the
   * container donut, like every container-family block), ranked before
   * every refinement so a layout's default wins by document order, and
   * the stamping demand lists the kind scope, so ContainerDefaultsStamp
   * lands the family class on every section with no skin at all.
   */
  public function testComposedKindRootDefaultCompiles(): void {
    $this->assertNull($this->container->get('daedalus_stylish.skin_manager')->activeSkinName());
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['composed' => TRUE, 'defaults' => ['' => ['default' => ['display' => 'grid']]]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->container->get('daedalus_stylish.defaults_stylesheet')->compile();
    $uri = $this->stylesheetUri();
    $this->assertIsString($uri, 'A kind\'s declared default compiles the declared tier with no skin.');
    $css = file_get_contents($uri);
    $this->assertStringContainsString($this->scopeRoot('kind:container') . ' to (' . DefaultsStylesheet::DEFAULTS_SCOPE_LIMIT . ") {\n  :scope {\n    display: grid;\n  }", $css);
    $this->assertSame(['kind:container'], $this->container->get('daedalus_stylish.defaults_stylesheet')->scopes());
    $this->assertSame(['daedalus-stylish-defaults--kind-container'], ContainerDefaultsStamp::classes(['kind:container'], 'layout_onecol'));

    // Beside the refinements the kind block leads the file: kind before
    // refinement is the delivery rank order both tiers share.
    $this->enableFixture();
    $this->container->get('daedalus_stylish.defaults_stylesheet')->compile();
    $css = file_get_contents($this->stylesheetUri());
    $kind_at = strpos($css, $this->scopeRoot('kind:container'));
    $this->assertIsInt($kind_at);
    $this->assertLessThan(strpos($css, $this->scopeRoot('block_plugin:fancy_menu')), $kind_at);
    $this->assertLessThan(strpos($css, $this->scopeRoot('layout:layout_onecol')), $kind_at);
    $scopes = $this->container->get('daedalus_stylish.defaults_stylesheet')->scopes();
    $this->assertSame(['kind:container', 'block_plugin:fancy_menu', 'block_type:image', 'layout:layout_onecol'], $scopes);
    $this->assertSame(
      ['daedalus-stylish-defaults--kind-container', 'daedalus-stylish-defaults--layout-layout_onecol'],
      ContainerDefaultsStamp::classes($scopes, 'layout_onecol'),
    );
  }

  /**
   * The declared tier prints beneath the skin's tiers for the same scope.
   *
   * Equal :scope specificity, so document order decides: the declaration
   * ships the floor, the skin is the site's look. The declared tier leads
   * the whole file (before the token block too), and the stamping demand
   * is the union of both tiers' scopes.
   */
  public function testDeclaredTierPrintsBeneathSkin(): void {
    $this->enableFixture();
    $skin = $this->createSkin('aurora', ['space' => ['4' => '1rem']]);
    $skin->setDefaults([
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
    $kind_at = strpos($css, $this->scopeRoot('kind:block'));
    $tokens_at = strpos($css, '--space-4: 1rem;');
    $this->assertIsInt($declared_at);
    $this->assertIsInt($skin_at);
    $this->assertLessThan($declared_at, strpos($css, $this->scopeRoot('block_type:image')));
    $this->assertLessThan($tokens_at, $declared_at);
    $this->assertLessThan($kind_at, $tokens_at);
    $this->assertLessThan(strrpos($css, $this->scopeRoot('block_type:image')), $kind_at);
    $this->assertLessThan($skin_at, strrpos($css, $this->scopeRoot('block_type:image')));

    $this->assertSame(
      ['block_plugin:fancy_menu', 'block_type:image', 'layout:layout_onecol', 'kind:block'],
      $this->container->get('daedalus_stylish.defaults_stylesheet')->scopes(),
    );
  }

  /**
   * Declared defaults stamp their scope classes with no skin at all.
   *
   * The preprocess seams (plugin blocks, layouts) read the union of both
   * tiers' scopes: a declaring plugin block wears the family class (the
   * donut boundary) and its own refinement class; a declaring layout the
   * container family's; an undeclared plugin the family class alone, since
   * the tier holds element defaults.
   */
  public function testDeclaredDefaultsStampInPreprocess(): void {
    $variables = ['plugin_id' => 'fancy_menu:main', 'attributes' => []];
    daedalus_stylish_preprocess_block($variables);
    $this->assertArrayNotHasKey('class', $variables['attributes'], 'Nothing declares or defaults: nothing stamps.');

    $this->enableFixture();
    $variables = ['plugin_id' => 'fancy_menu:main', 'attributes' => []];
    daedalus_stylish_preprocess_block($variables);
    $this->assertSame(
      ['daedalus-stylish-defaults--kind-block', 'daedalus-stylish-defaults--block_plugin-fancy_menu'],
      $variables['attributes']['class'],
    );
    $variables = ['plugin_id' => 'system_menu_block:main', 'attributes' => []];
    daedalus_stylish_preprocess_block($variables);
    $this->assertSame(['daedalus-stylish-defaults--kind-block'], $variables['attributes']['class']);

    $variables = ['layout' => new LayoutDefinition(['id' => 'layout_onecol']), 'attributes' => []];
    daedalus_stylish_preprocess_layout($variables);
    $this->assertSame(
      ['daedalus-stylish-defaults--kind-container', 'daedalus-stylish-defaults--layout-layout_onecol'],
      $variables['attributes']['class'],
    );
    $variables = ['layout' => new LayoutDefinition(['id' => 'layout_twocol']), 'attributes' => []];
    daedalus_stylish_preprocess_layout($variables);
    $this->assertSame(['daedalus-stylish-defaults--kind-container'], $variables['attributes']['class']);
  }

  /**
   * A composed block's root wears its declared scope classes, no skin.
   *
   * The entity-view stamp lands on the block content's build; block
   * content builds eagerly so its #attributes hoist onto the block build
   * (core's BlockComponentRenderArray), and ComposedBlockRender hands
   * that bag to the composed element as the root's hoisted attributes.
   * Pinned here: no composed-specific stamping exists, and a declared
   * default's class must reach the composed root of a live render on a
   * site with no design system.
   */
  public function testComposedRootStampsWithoutSkin(): void {
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
    // Live: no editing attributes demanded; the stamps are delivery.
    $this->setUpCurrentUser();
    $build = $component->toRenderArray();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringNotContainsString('<div', $html, 'No sandwich renders at all.');
    $this->assertMatchesRegularExpression(
      '/<p[^>]*class="[^"]*daedalus-stylish-defaults--kind-block[^"]*daedalus-stylish-defaults--block_type-text/',
      $html,
      'The composed root wears the family class and the declaring bundle class.',
    );
    // The fragment carries every tag that can change the stamp decision.
    $tags = $build['content']['#cache']['tags'] ?? [];
    $this->assertContains(DefaultsStylesheet::CACHE_TAG, $tags);
    $this->assertContains('daedalus_ui_page_builder', $tags);
    $this->assertContains('config:daedalus_blueprint.settings', $tags);
  }

  /**
   * A composed section's root wears the container-family stamp, no skin.
   *
   * A composed layout has no template, so daedalus_stylish_preprocess_layout()
   * never runs for it; the stamp rides the pre_render daedalus_stylish prepends to
   * daedalus_ui's composed_container element and lands in the
   * #attributes bag the element hoists onto the root. Pinned here, on the
   * resolver fixture's composed container layout: nothing stamps while no
   * tier holds element defaults; with the declaring fixture the family
   * class rides the <section> at root depth and the residual <div>
   * nested, beside the section's own stamped bag; and the shared decision
   * adds the layout refinement class exactly when that layout is
   * defaulted (the templated seam's rule, one function).
   */
  public function testComposedSectionRootStampsWithoutSkin(): void {
    $this->enableModules(['daedalus_ui_resolver_test']);
    $this->installConfig(['system']);
    $this->setUpCurrentUser();
    $this->assertNull($this->container->get('daedalus_stylish.skin_manager')->activeSkinName());

    $html = $this->renderComposedSection();
    $this->assertStringContainsString('<section', $html);
    $this->assertStringNotContainsString('daedalus-stylish-defaults--', $html, 'Nothing declares or defaults: nothing stamps.');

    $this->enableFixture();
    $html = $this->renderComposedSection();
    $section = $this->htmlXpath($html)->query('//section');
    $this->assertSame(1, $section->length, 'The composed root is the section element.');
    $this->assertContains('daedalus-stylish-defaults--kind-container', explode(' ', $section->item(0)->getAttribute('class')), 'The composed section root wears the family class.');
    $this->assertSame('container:11111111-2222-3333-4444-555555555555', $section->item(0)->getAttribute('data-selectable'), 'The section bag still hoists beside the stamp.');
    $this->assertSame(1, $this->htmlXpath($html)->query('//section/p')->length, 'Children still mount at the root.');

    $html = $this->renderComposedSection(nested: TRUE);
    $div = $this->htmlXpath($html)->query('//div[@data-selectable]');
    $this->assertSame(1, $div->length);
    $this->assertContains('daedalus-stylish-defaults--kind-container', explode(' ', $div->item(0)->getAttribute('class')), 'The family class is depth-blind.');
    $this->assertStringNotContainsString('daedalus-stylish-defaults--layout-', $html, 'The fixture defaults layout_onecol, not this layout: no refinement class.');

    $scopes = $this->container->get('daedalus_stylish.defaults_stylesheet')->scopes();
    $this->assertSame(['daedalus-stylish-defaults--kind-container'], ContainerDefaultsStamp::classes($scopes, 'resolver_test_container'));
    $this->assertSame(
      ['daedalus-stylish-defaults--kind-container', 'daedalus-stylish-defaults--layout-layout_onecol'],
      ContainerDefaultsStamp::classes($scopes, 'layout_onecol'),
    );
    $this->assertSame([], ContainerDefaultsStamp::classes(['base:p'], 'layout_onecol'), 'Base tag defaults deliver on selectors and stamp nothing.');
  }

  /**
   * The shipped container kind's display: grid stamps every section.
   *
   * daedalus_stylish_layouts declares `display: grid` on the composed container's
   * root (the box fact its gap grant and children pseudo-part need, code
   * rather than skin data). With no skin and no other declaring module
   * the declared tier holds exactly that kind scope, the file prints it
   * inside the container donut at the family class, and both render
   * seams stamp the family class on a daedalus_stylish_container section: a page
   * band's <section> and a nested container's <div> alike, with no
   * refinement class since the shipped layout declares no defaults of
   * its own.
   */
  public function testShippedContainerKindDefaultStampsEverySection(): void {
    $this->enableModules(['daedalus_stylish_layouts']);
    $this->installConfig(['system']);
    $this->setUpCurrentUser();
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->assertNull($this->container->get('daedalus_stylish.skin_manager')->activeSkinName());
    $this->assertSame(
      ['' => ['default' => ['display' => 'grid']]],
      $this->container->get('daedalus_stylish.style_guide')->getKind('container')['defaults'],
      'The shipped container kind declares its root display.',
    );

    $this->container->get('daedalus_stylish.defaults_stylesheet')->compile();
    $uri = $this->stylesheetUri();
    $this->assertIsString($uri, 'The shipped declaration alone compiles the declared tier.');
    $css = file_get_contents($uri);
    $this->assertStringContainsString($this->scopeRoot('kind:container') . ' to (' . DefaultsStylesheet::DEFAULTS_SCOPE_LIMIT . ") {\n  :scope {\n    display: grid;\n  }", $css);
    $scopes = $this->container->get('daedalus_stylish.defaults_stylesheet')->scopes();
    $this->assertSame(['kind:container'], $scopes);
    $this->assertSame(['daedalus-stylish-defaults--kind-container'], ContainerDefaultsStamp::classes($scopes, 'daedalus_stylish_container'));

    $html = $this->renderComposedSection(layout_id: 'daedalus_stylish_container');
    $section = $this->htmlXpath($html)->query('//section');
    $this->assertSame(1, $section->length, 'A page band renders as the section element.');
    $this->assertContains('daedalus-stylish-defaults--kind-container', explode(' ', $section->item(0)->getAttribute('class')), 'The page band wears the family class.');
    $this->assertStringNotContainsString('daedalus-stylish-defaults--layout-', $html, 'The shipped layout declares no defaults: no refinement class.');

    $html = $this->renderComposedSection(nested: TRUE, layout_id: 'daedalus_stylish_container');
    $div = $this->htmlXpath($html)->query('//div[@data-selectable]');
    $this->assertSame(1, $div->length, 'A nested container renders as a plain div.');
    $this->assertContains('daedalus-stylish-defaults--kind-container', explode(' ', $div->item(0)->getAttribute('class')), 'The nested container wears the family class too.');
  }

  /**
   * Renders a composed container layout (the resolver fixture's by default).
   *
   * The stamps daedalus_layout lands on the section build after the plugin
   * returns (the attribute bag and, embedded only, the nested marker) are
   * stamped by hand at the seam they ride.
   */
  protected function renderComposedSection(bool $nested = FALSE, string $layout_id = 'resolver_test_container'): string {
    $layout = $this->container->get('plugin.manager.core.layout')->createInstance($layout_id);
    $build = $layout->build(['content' => ['aaaa' => ['#markup' => '<p>Mounted child</p>']]]);
    $build['#attributes'] = ['data-selectable' => 'container:11111111-2222-3333-4444-555555555555'];
    if ($nested) {
      $build['#daedalus_layout_nested'] = TRUE;
    }
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Parses rendered HTML for querying.
   */
  protected function htmlXpath(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new \DOMXPath($dom);
  }

  /**
   * Skin scoped defaults co-target; base tag defaults never do.
   *
   * Kind:* and block_type:* defaults flow through the shared printEntry
   * seam (printFragmentStyles at their stamped scope class), so a skin
   * default setting a block's color reaches text-claiming interior
   * elements exactly like a per-entity root style, without the companion
   * it would lose to base:a the same way. base:{tag} defaults print
   * through the bare lane (printBareEntry) and MUST NOT co-target: they
   * ARE the interposer the co-target exists to beat, and co-targeting
   * them would re-land the tag default on the claiming element and defeat
   * the fix.
   */
  public function testScopedDefaultsCoTargetBaseDefaultsDoNot(): void {
    $skin = $this->createSkin('site_skin', ['space' => ['4' => '1rem']]);
    $skin->setDefaults([
      'base:a' => ['' => ['default' => ['color' => '#111111']]],
      'kind:block' => ['' => ['default' => ['color' => '#222222', 'padding' => '1rem']]],
      'block_type:text' => ['' => ['default' => ['color' => '#333333']]],
    ])->save();
    $this->activateSkin($skin);
    $css = file_get_contents($this->stylesheetUri());

    // Element scopes carry the companion with the inherited text subset.
    $companion = ':scope ' . CssPrinter::CLAIMS_CONTENT_SELECTOR . " {\n    color: ";
    $this->assertStringContainsString($companion . "#222222;\n  }", $css);
    $this->assertStringContainsString($companion . "#333333;\n  }", $css);
    // Box properties never ride the companion.
    $this->assertSame(1, substr_count($css, 'padding: 1rem;'));
    // The base:a color prints once (the bare rule), and the whole file
    // holds exactly the two element-scope companions: none for base:a,
    // none for the token block (custom properties are not in the set).
    $this->assertSame(1, substr_count($css, '#111111'));
    $this->assertSame(2, substr_count($css, CssPrinter::CLAIMS_CONTENT_SELECTOR));
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
    // pages attach it unconditionally of compile state (daedalus_stylish_page_top()).
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
   * the active skin's staged revision prints via workspaceInlineCss() into
   * the page-level tag (document order beats the compiled <link>).
   * Publishing recompiles the file: per-entity update hooks do not fire on
   * publish, so that exercises SkinWorkspaceSubscriber.
   */
  public function testWorkspacePreviewInlineFallback(): void {
    $skin = $this->createSkin('aurora', ['space' => ['4' => '1rem']]);
    $this->activateSkin($skin);

    $this->initializeWorkspacesModule();
    $this->setUpCurrentUser();
    $live_uri = $this->stylesheetUri();
    $stylesheet = $this->container->get('daedalus_stylish.defaults_stylesheet');

    // Outside a workspace there is no preview to inline.
    $tags = [];
    $this->assertSame('', $stylesheet->workspaceInlineCss($tags));

    // Stage a token edit: the shared public file keeps the live CSS.
    $this->switchToWorkspace('stage');
    $staged = $this->entityTypeManager->getStorage('daedalus_skin')->load($skin->id());
    $staged->setTokens(['space' => ['4' => '9rem']]);
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

    // Publishing lands the staged tokens in the shared file.
    $this->switchToLive();
    $this->workspaces['stage']->publish();
    $published_uri = $this->stylesheetUri();
    $this->assertNotSame($live_uri, $published_uri);
    $this->assertStringContainsString('--space-4: 9rem;', file_get_contents($published_uri));
    $this->assertFileDoesNotExist($live_uri);
  }

  /**
   * The admin theme gates the file's reach at the token scope element.
   *
   * The scope element is the single boundary all defaults output prints
   * inside, so not stamping it (plus skipping the page-top attach, an
   * optimization) is what keeps user-authored styling data off
   * admin-theme pages.
   */
  public function testAdminThemeReachGate(): void {
    $wrap = function (): string {
      $variables = ['children' => 'X'];
      daedalus_stylish_preprocess_off_canvas_page_wrapper($variables);
      return (string) $variables['children'];
    };
    $page_top_keys = function (): array {
      $page_top = [];
      daedalus_stylish_page_top($page_top);
      return array_keys($page_top);
    };

    // No admin theme configured: the site chose one look everywhere, so
    // nothing gates: scope element stamped, libraries attached (the swatch
    // and animations elements ride the same seam and the same gate).
    $this->assertStringContainsString(DefaultsStylesheet::SCOPE_CLASS, $wrap());
    $this->assertSame(['daedalus_stylish_defaults', 'daedalus_stylish_swatches', 'daedalus_stylish_animations'], $page_top_keys());

    // The active theme IS the configured admin theme: no scope element,
    // no attach; the defaults do not exist on admin-theme pages.
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('admin', 'stark')->save();
    $this->container->get('theme.manager')->setActiveTheme(
      $this->container->get('theme.initialization')->initTheme('stark'));
    $this->assertSame('X', $wrap());
    $this->assertSame([], $page_top_keys());

    // A frontend theme on a site WITH an admin theme configured still gets
    // the defaults: the gate compares the active theme, not the route.
    $this->config('system.theme')->set('admin', 'claro')->save();
    $this->assertStringContainsString(DefaultsStylesheet::SCOPE_CLASS, $wrap());
    $this->assertSame(['daedalus_stylish_defaults', 'daedalus_stylish_swatches', 'daedalus_stylish_animations'], $page_top_keys());
  }

}
