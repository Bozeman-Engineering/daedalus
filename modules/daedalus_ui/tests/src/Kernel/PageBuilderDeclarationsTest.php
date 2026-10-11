<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_ui\ElementModel\DeclarationException;
use Drupal\daedalus_ui\ElementModel\DeclarationOrigin;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests page builder declaration discovery, layering, and validation.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class PageBuilderDeclarationsTest extends KernelTestBase {

  /**
   * The sub theme; its base chain is the base theme below.
   */
  protected const THEME = 'daedalus_ui_declarations_test_theme';

  protected const BASE_THEME = 'daedalus_ui_declarations_test_base_theme';

  /**
   * {@inheritdoc}
   *
   * Navigation_plus's hard dependencies are invisible to kernel
   * environments and must be listed explicitly or the container fails to
   * compile.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'options',
    'file',
    'image',
    'media',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_ui_declarations_test',
    'daedalus_ui_declarations_test_extra',
  ];

  /**
   * The service under test.
   */
  protected PageBuilderDeclarations $declarations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->declarations = $this->container->get('daedalus_ui.page_builder_declarations');
  }

  /**
   * Navigation_plus's own shipped file is the layer everything merges over.
   */
  public function testShippedDeclarations(): void {
    // The universal field shell: core's markup chain with the value slot on
    // the item. The base theme relabeled the label part over the shipped
    // declaration; the rest of the chain survives untouched.
    $field = $this->declarations->getKind('field', self::BASE_THEME);
    $this->assertSame('Base label', $field['elements']['.field__label']['label']);
    $this->assertSame('value', $field['elements']['.field__items']['elements']['.field__item']['contains']);

    // The shell's markup model: every part declares the div core's field
    // template renders it as.
    $this->assertSame('div', $field['elements']['.field__label']['tag']);
    $this->assertSame('div', $field['elements']['.field__items']['elements']['.field__item']['tag']);

    // The info existence declaration: the block_content admin label never
    // renders, so nothing answers to its key.
    $block = $this->declarations->getKind('block', self::BASE_THEME);
    $this->assertFalse($block['fields']['info']['element']);
  }

  /**
   * Kind declarations layer module -> base theme -> sub theme.
   */
  public function testKindLayering(): void {
    // The sub theme's NULL withdrew the shipped items part, nested item
    // included, and its relabel beat the base theme's.
    $field = $this->declarations->getKind('field', self::THEME);
    $this->assertSame('Theme label', $field['elements']['.field__label']['label']);
    $this->assertArrayNotHasKey('.field__items', $field['elements']);

    // A foreign key inside a part rides through every layer untouched.
    $this->assertSame('module', $field['elements']['.field__label']['annotations']['source']);

    // Scalars and booleans replace across module layers.
    $this->assertSame('unit', $this->declarations->getKind('block', self::THEME)['presentation']);
    $entity = $this->declarations->getKind('entity', self::THEME);
    $this->assertTrue($entity['fields']['field_internal']['element']);
    $this->assertSame('unit', $entity['fields']['field_gallery']['presentation']);
  }

  /**
   * The alter hook is the highest-precedence layer.
   */
  public function testAlter(): void {
    $region = $this->declarations->getKind('region', self::THEME);
    $this->assertSame('Region inner', $region['elements']['.region__inner']['label']);
  }

  /**
   * Block type declarations layer and sit on the universal block kind.
   */
  public function testBlockType(): void {
    $card = $this->declarations->getBlockType('card', self::THEME);

    // The universal block kind is the base layer under the declaration:
    // its parts and the shipped info existence declaration flow in.
    $this->assertSame('Inner', $card['elements']['.block__inner']['label']);
    $this->assertFalse($card['fields']['info']['element']);

    // The card's own presentation map replaced the kind's scalar.
    $this->assertSame('merged', $card['presentation']['mode']);
    $this->assertSame('field_title', $card['presentation']['edit_target']);

    // The extra module's boolean replaced the base module's.
    $this->assertTrue($card['composed']);

    // Parts merge across layers: the theme relabeled the root part, the
    // extra module relabeled the nested part, the base module's contains
    // slots survive.
    $this->assertSame('Theme card', $card['elements']['.card']['label']);
    $this->assertSame(['field:field_title'], $card['elements']['.card']['contains']);
    $body = $card['elements']['.card']['elements']['.card__body'];
    $this->assertSame('Body copy', $body['label']);
    $this->assertSame('field:field_body', $body['contains']);

    // The existence axis and foreign sections ride the same declaration.
    $this->assertFalse($card['fields']['field_admin']['element']);
    $this->assertSame(['style' => 'card_default'], $card['default_variants']);
    $this->assertArrayHasKey('spacing', $card['property_groups']);

    // An undeclared bundle resolves to the universal block declaration.
    $undeclared = $this->declarations->getBlockType('undeclared', self::THEME);
    $this->assertSame('unit', $undeclared['presentation']);
    $this->assertArrayNotHasKey('composed', $undeclared);

    $this->assertSame(['banner', 'card'], $this->declarations->getBlockTypes(self::THEME));
  }

  /**
   * Block plugin declarations layer and sit on the universal block kind.
   */
  public function testBlockPlugin(): void {
    $menu = $this->declarations->getBlockPlugin('system_menu_block', self::THEME);

    // The universal block kind is the base layer under the declaration:
    // its parts and the shipped info existence declaration flow in.
    $this->assertSame('Inner', $menu['elements']['.block__inner']['label']);
    $this->assertFalse($menu['fields']['info']['element']);

    // Parts merge across module layers: the extra module relabeled the
    // nested part, the base module's tree survives.
    $this->assertSame('Menu', $menu['elements']['.menu']['label']);
    $this->assertSame('Menu link', $menu['elements']['.menu']['elements']['.menu-item']['label']);

    // Foreign sections ride the same declaration.
    $this->assertArrayHasKey('typography', $menu['property_groups']);

    // A derivative id resolves to its base plugin's declaration: all
    // derivatives share one template, so one declaration covers them.
    $this->assertSame($menu, $this->declarations->getBlockPlugin('system_menu_block:main', self::THEME));

    // An undeclared plugin resolves to the universal block declaration.
    $undeclared = $this->declarations->getBlockPlugin('undeclared_plugin', self::THEME);
    $this->assertSame('unit', $undeclared['presentation']);
    $this->assertArrayNotHasKey('.menu', $undeclared['elements']);

    $this->assertSame(['system_menu_block'], $this->declarations->getBlockPlugins(self::THEME));
  }

  /**
   * A block_plugins bucket keyed by a derivative id fails the build.
   *
   * The file grammar keys on the base plugin id (filenames cannot carry
   * colons), so only the alter hook could smuggle a derivative key in; it
   * is rejected rather than sitting unreachable beside the base id's
   * declaration.
   */
  public function testBlockPluginDerivativeKey(): void {
    $reflection = new \ReflectionMethod($this->declarations, 'validate');
    $errors = [];
    $reflection->invokeArgs($this->declarations, [
      [
        'kinds' => [],
        'block_types' => [],
        'block_plugins' => ['system_menu_block:main' => ['elements' => []]],
        'layouts' => [],
      ],
      &$errors,
    ]);
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('The declaration of block plugin "system_menu_block:main" keys on a derivative id', $errors[0]);
  }

  /**
   * Layout declarations layer and sit on the universal container kind.
   */
  public function testLayout(): void {
    $layout = $this->declarations->getLayout('daedalus_stylish_twocol', self::THEME);
    $this->assertSame('Aside', $layout['elements']['.layout__aside']['label']);
    $this->assertSame('region:aside', $layout['elements']['.layout__aside']['contains']);

    // The layout's multi-form root (the depth-conditioned section/div
    // swap) rides the effective declaration as authored.
    $this->assertSame([
      ['tag' => 'section', 'present' => ['depth' => 'root']],
      ['tag' => 'div'],
    ], $layout['root']['forms']);

    // The universal container kind is the base layer under the declaration.
    $this->assertSame('Section inner', $layout['elements']['.section__inner']['label']);

    // An undeclared layout resolves to the universal container declaration.
    $undeclared = $this->declarations->getLayout('daedalus_stylish_onecol', self::THEME);
    $this->assertSame('Section inner', $undeclared['elements']['.section__inner']['label']);
    $this->assertArrayNotHasKey('.layout__aside', $undeclared['elements']);

    $this->assertSame(['daedalus_stylish_twocol'], $this->declarations->getLayouts(self::THEME));
  }

  /**
   * The site lane: site-scope foreign sections layer like kind declarations.
   */
  public function testSite(): void {
    $site = $this->declarations->getSite(self::THEME);

    // Maps merge across module layers (the extra module's slogan replaced
    // the base module's) and theme layers (the base theme's logo won).
    $this->assertSame('Extra slogan', $site['branding']['slogan']);
    $this->assertSame('base.svg', $site['branding']['logo']);

    // The sub theme's NULL withdrew a whole site section.
    $this->assertArrayNotHasKey('analytics', $site);
    $this->assertSame('acme', $this->declarations->getSite(self::BASE_THEME)['analytics']['vendor']);

    // A foreign section whose owner lives elsewhere (daedalus_layout's
    // groups, the site's group vocabulary) is answered as the merged map
    // of both module layers, in layer order; the lane validates nothing
    // inside it, its owner does.
    $this->assertSame([
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers.'],
      'bands' => ['label' => 'Bands'],
    ], $site['groups']);

    // The site bucket rides the full definition set beside the others.
    $this->assertSame($site, $this->declarations->getDefinitions(self::THEME)['site']);

    // "site" is a reserved lane, never an element kind.
    $this->expectException(\InvalidArgumentException::class);
    $this->declarations->getKind('site', self::THEME);
  }

  /**
   * The instances lane: namespaces layer, withdraw, and match by prefix.
   */
  public function testInstances(): void {
    $instances = $this->declarations->getInstances(self::THEME);

    // Maps merge across module layers: the extra module relabeled the p
    // tag and added h2; the base label and the foreign section survive.
    $namespace = $instances['.in-*'];
    $this->assertSame('Module instance', $namespace['label']);
    $this->assertSame(['p' => 'Extra paragraph', 'h2' => 'Heading 2'], $namespace['tags']);
    $this->assertSame('module', $namespace['annotations']['source']);

    // The sub theme's NULL withdrew a whole namespace; lower layers keep
    // it.
    $this->assertArrayNotHasKey('.tx-*', $instances);
    $this->assertSame('Theme withdraws this', $this->declarations->getInstances(self::BASE_THEME)['.tx-*']['label']);

    // The instances bucket rides the full definition set beside the
    // others.
    $this->assertSame($instances, $this->declarations->getDefinitions(self::THEME)['instances']);

    // The prefix match: a concrete single-class selector extending a
    // namespace's prefix resolves to its declaration; the bare prefix, a
    // non-class selector, and a foreign prefix do not.
    $this->assertSame('Module instance', PageBuilderDeclarations::findInstanceDeclaration($instances, '.in-a3')['label']);
    $this->assertNull(PageBuilderDeclarations::findInstanceDeclaration($instances, '.in-'));
    $this->assertNull(PageBuilderDeclarations::findInstanceDeclaration($instances, 'div > .in-a3'));
    $this->assertNull(PageBuilderDeclarations::findInstanceDeclaration($instances, '.zz-a3'));
  }

  /**
   * Only the defined element kinds may be queried.
   */
  public function testInvalidKind(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->declarations->getKind('paragraph', self::THEME);
  }

  /**
   * The first build with problems throws once, listing all of them.
   */
  public function testValidation(): void {
    $this->enableModules(['daedalus_ui_declarations_test_invalid']);
    $declarations = $this->container->get('daedalus_ui.page_builder_declarations');
    $declarations->resetCache();
    try {
      $declarations->getDefinitions(self::THEME);
      $this->fail('The invalid declarations should have failed the build.');
    }
    catch (DeclarationException $e) {
      $message = $e->getMessage();
      // The universal file: an unknown kind, owned sections in contexts
      // that cannot honor them.
      $this->assertStringContainsString('"widget" is not a page builder element kind', $message);
      $this->assertStringContainsString('The fields declaration of kind "container" is not valid there', $message);
      $this->assertStringContainsString('The presentation declaration of kind "container" is not valid there', $message);
      $this->assertStringContainsString('The presentation declaration of kind "field" is not valid there', $message);
      // The block type file: out-of-vocabulary values and the retired
      // collapse spelling's corrective.
      $this->assertStringContainsString('The collapsed declaration of block type "broken" is retired', $message);
      $this->assertStringContainsString('The composed declaration of block type "broken" must be a boolean', $message);
      $this->assertStringContainsString('The presentation declaration of block type "broken" must be one of: open, unit, merged', $message);
      $this->assertStringContainsString('The field "field_a" of block type "broken" must declare element as a boolean', $message);
      $this->assertStringContainsString('The field "field_b" of block type "broken" must declare presentation as one of: open, unit', $message);
      // The elements tree: duplicate classes and kind-impossible tokens.
      $this->assertStringContainsString('The part ".dup" of block type "broken" is declared more than once', $message);
      $this->assertStringContainsString('The contains slot of part ".dup" in block type "broken" uses "regions", which is impossible in a block tree', $message);
      $this->assertStringContainsString('The contains slot of part ".value-part" in block type "broken" uses "value", which is impossible in a block tree', $message);
      // The markup vocabulary: tag values, attribute names and values, the
      // one-root rule.
      $this->assertStringContainsString('The tag declaration of block type "broken" is \'DIV>\', which is not a tag name', $message);
      $this->assertStringContainsString('The declaration of block type "broken" carries both tag and root', $message);
      $this->assertStringContainsString('The tag of part ".attrs" in block type "broken" must be a literal tag name ("h2") or {field: NAME}', $message);
      $this->assertStringContainsString('The attributes of part ".attrs" in block type "broken" names an attribute \'BAD NAME\'', $message);
      $this->assertStringContainsString('The attributes of part ".attrs" in block type "broken" gives attribute "href" an impossible value', $message);
      // root.forms: closed form keys, order-totality both ways.
      $this->assertStringContainsString('The root form 0 of block type "broken" has unknown key(s) \'href\'', $message);
      $this->assertStringContainsString('The root form 0 of block type "broken" is unconditional but not last', $message);
      $this->assertStringContainsString('The present condition of root form 1 of block type "broken" conditions on media \'print\'', $message);
      $this->assertStringContainsString('The present condition of root form 2 of block type "broken" conditions on "depth", which is impossible in a block tree', $message);
      $this->assertStringContainsString('The root form 3 of block type "broken" is the last form but conditional', $message);
      // Part forms: the same forms grammar on a part - tag xor forms,
      // closed form keys, first-match-wins order, per-form label - minus
      // the exhaustiveness rule (a part whose forms all fail is absent).
      $this->assertStringContainsString('The part ".formed" of block type "broken" carries both tag and forms', $message);
      $this->assertStringContainsString('The part ".formed" form 0 of block type "broken" has unknown key(s) \'href\'', $message);
      $this->assertStringContainsString('The part ".formed" form 0 of block type "broken" is unconditional but not last', $message);
      $this->assertStringContainsString('The label of part ".formed" form 1 of block type "broken" must be a non-empty string', $message);
      $this->assertStringNotContainsString('The part ".formed" form 1 of block type "broken" is the last form but conditional', $message);
      // The block plugin file: compose rendering keys off block types, so a
      // block plugin declaration cannot honor composed or root; the block
      // tree vocabulary applies; the retired spelling correctives fire in
      // every context.
      $this->assertStringContainsString('The composed declaration of block plugin "broken_plugin" is not valid there', $message);
      $this->assertStringContainsString('The root declaration of block plugin "broken_plugin" is not valid there', $message);
      $this->assertStringContainsString('The collapsed declaration of block plugin "broken_plugin" is retired', $message);
      $this->assertStringContainsString('The contains slot of part ".oops" in block plugin "broken_plugin" uses "regions", which is impossible in a block tree', $message);
      // The layout file: owned sections a container declaration cannot
      // honor (fields), out-of-vocabulary values on the ones it can
      // (composed), and the retired collapse corrective.
      $this->assertStringContainsString('The composed declaration of layout "broken_layout" must be a boolean', $message);
      $this->assertStringContainsString('The collapsed declaration of layout "broken_layout" is retired', $message);
      $this->assertStringContainsString('The fields declaration of layout "broken_layout" is not valid there', $message);
      // The present condition vocabulary: closed, kind-possible, depth on
      // root forms only, AND composition on root forms only, retired
      // part-depth corrective.
      $this->assertStringContainsString('The part ".value-part" of block type "broken" declares depth, which is retired; depth is a root-form condition', $message);
      $this->assertStringContainsString('The present condition of part ".value-part" in block type "broken" uses "hovered", which is not a condition', $message);
      $this->assertStringContainsString('The present condition of part ".outer" in layout "broken_layout" conditions on depth, but depth is a root-form condition', $message);
      $this->assertStringContainsString('The present condition of part ".deep" in layout "broken_layout" composes 2 conditions, but conditions compose (AND) only on root forms', $message);
      $this->assertStringContainsString('The present condition of part ".band" in kind "container" conditions on depth \'sideways\'; the depths are root and nested', $message);
      $this->assertStringContainsString('The present condition of part ".flourish" in kind "field" conditions on "empty", which is impossible in a field tree (a field has nothing to vary on, so its parts are unconditional)', $message);
      // The instances lane: pattern-shaped keys, a required tags
      // vocabulary with lowercase tag names and non-empty labels, no
      // shell vocabulary, non-empty label.
      $this->assertStringContainsString('The instance namespace ".bad" is not a valid pattern key', $message);
      $this->assertStringContainsString('The label of instance namespace ".n-*" must be a non-empty string', $message);
      $this->assertStringContainsString('The instance namespace ".n-*" declares tag \'P!\', which is not a tag name', $message);
      $this->assertStringContainsString('The tag "h2" of instance namespace ".n-*" must carry a non-empty string label', $message);
      $this->assertStringContainsString('The instance namespace ".n-*" declares contains, but a namespace\'s instances are CONTENT', $message);
      $this->assertStringContainsString('The instance namespace ".no-tags-*" must declare tags', $message);
    }
  }

  /**
   * Built declaration sets are cached per theme and reset invalidates them.
   */
  public function testCaching(): void {
    $this->declarations->getDefinitions(self::THEME);
    $cache = $this->container->get('cache.discovery');
    $this->assertNotFalse($cache->get('daedalus_ui_page_builder:' . self::THEME));

    $this->declarations->resetCache();
    $this->assertFalse($cache->get('daedalus_ui_page_builder:' . self::THEME));
  }

  /**
   * Every merged value knows the last layer to write it.
   *
   * The origin tree mirrors the layered set leaf for leaf; a file origin
   * names the provider and the file's basename, the alter hook's values
   * carry the alter origin, and the tree composes through merge() as the
   * declarations do.
   */
  public function testOrigins(): void {
    $origins = $this->declarations->getOrigins(self::THEME);
    $set = $this->declarations->getDefinitions(self::THEME);
    $this->assertSame(array_keys($set), array_keys($origins), 'The origin tree is keyed as the set is.');
    $this->assertMirrors($set, $origins, '');

    // Kind layering: the sub theme's relabel is the last write, the base
    // theme's when the base theme is the site theme, and a foreign key no
    // theme touched still names the module that authored it.
    $label = $origins['kinds']['field']['elements']['.field__label'];
    $this->assertOrigin(self::THEME, self::THEME . '.page_builder.yml', $label['label']);
    $this->assertOrigin('daedalus_ui_declarations_test', 'daedalus_ui_declarations_test.page_builder.yml', $label['annotations']['source']);
    $base = $this->declarations->getOrigins(self::BASE_THEME);
    $this->assertOrigin(self::BASE_THEME, self::BASE_THEME . '.page_builder.yml', $base['kinds']['field']['elements']['.field__label']['label']);
    $this->assertMirrors($this->declarations->getDefinitions(self::BASE_THEME), $base, '');

    // A withdrawn key leaves the tree with its value; a scalar or boolean
    // the later module replaced names that module.
    $this->assertArrayNotHasKey('.field__items', $origins['kinds']['field']['elements']);
    $this->assertOrigin('daedalus_ui_declarations_test_extra', 'daedalus_ui_declarations_test_extra.page_builder.yml', $origins['kinds']['block']['presentation']);
    $this->assertOrigin('daedalus_ui_declarations_test_extra', 'daedalus_ui_declarations_test_extra.page_builder.yml', $origins['kinds']['entity']['fields']['field_internal']['element']);
    $this->assertOrigin('daedalus_ui_declarations_test', 'daedalus_ui_declarations_test.page_builder.yml', $origins['kinds']['entity']['fields']['field_gallery']['presentation']);

    // The reserved lanes: the site lane layers module, module, base theme
    // and loses the sub theme's withdrawal; the instances lane the same.
    $this->assertOrigin('daedalus_ui_declarations_test_extra', 'daedalus_ui_declarations_test_extra.page_builder.yml', $origins['site']['branding']['slogan']);
    $this->assertOrigin(self::BASE_THEME, self::BASE_THEME . '.page_builder.yml', $origins['site']['branding']['logo']);
    $this->assertArrayNotHasKey('analytics', $origins['site']);
    $this->assertOrigin('daedalus_ui_declarations_test', 'daedalus_ui_declarations_test.page_builder.yml', $origins['instances']['.in-*']['label']);
    $this->assertOrigin('daedalus_ui_declarations_test_extra', 'daedalus_ui_declarations_test_extra.page_builder.yml', $origins['instances']['.in-*']['tags']['p']);
    $this->assertOrigin('daedalus_ui_declarations_test_extra', 'daedalus_ui_declarations_test_extra.page_builder.yml', $origins['instances']['.in-*']['tags']['h2']);
    $this->assertArrayNotHasKey('.tx-*', $origins['instances']);

    // Per-type files: each of the card's three layers is the origin of
    // what it wrote, a list is one leaf, and an empty map is a leaf with
    // an origin of its own.
    $card = $origins['block_types']['card'];
    $this->assertOrigin(self::THEME, self::THEME . '.page_builder.block_type.card.yml', $card['elements']['.card']['label']);
    $this->assertOrigin('daedalus_ui_declarations_test', 'daedalus_ui_declarations_test.page_builder.block_type.card.yml', $card['elements']['.card']['contains']);
    $this->assertOrigin('daedalus_ui_declarations_test_extra', 'daedalus_ui_declarations_test_extra.page_builder.block_type.card.yml', $card['elements']['.card']['elements']['.card__body']['label']);
    $this->assertOrigin('daedalus_ui_declarations_test', 'daedalus_ui_declarations_test.page_builder.block_type.card.yml', $card['elements']['.card']['elements']['.card__body']['contains']);
    $this->assertOrigin('daedalus_ui_declarations_test_extra', 'daedalus_ui_declarations_test_extra.page_builder.block_type.card.yml', $card['composed']);
    $this->assertOrigin('daedalus_ui_declarations_test', 'daedalus_ui_declarations_test.page_builder.block_type.card.yml', $card['property_groups']['spacing']);
    $this->assertOrigin('daedalus_ui_declarations_test', 'daedalus_ui_declarations_test.page_builder.layout.daedalus_stylish_twocol.yml', $origins['layouts']['daedalus_stylish_twocol']['root']['forms']);

    // The alter hook: what it wrote carries the alter origin, what it left
    // alone keeps its file's.
    $inner = $origins['kinds']['region']['elements']['.region__inner']['label'];
    $this->assertInstanceOf(DeclarationOrigin::class, $inner);
    $this->assertTrue($inner->isAlter());
    $this->assertSame(['provider' => NULL, 'file' => NULL], $inner->toArray());
    $this->assertFalse($label['label']->isAlter());

    // Effective origins compose as effective declarations do: the kind's
    // leaves under the bundle's, one merge() for both.
    $effective = PageBuilderDeclarations::merge($origins['kinds']['block'], $card);
    $this->assertMirrors($this->declarations->getBlockType('card', self::THEME), $effective, '');
    $this->assertOrigin('daedalus_ui_declarations_test', 'daedalus_ui_declarations_test.page_builder.yml', $effective['elements']['.block__inner']['label']);
    $this->assertOrigin(self::THEME, self::THEME . '.page_builder.block_type.card.yml', $effective['elements']['.card']['label']);
    $this->assertOrigin('daedalus_ui_declarations_test', 'daedalus_ui_declarations_test.page_builder.block_type.card.yml', $effective['presentation']['mode']);

    // Cached with the set: one entry carries both, and a reset drops both.
    $cache = $this->container->get('cache.discovery');
    $entry = $cache->get('daedalus_ui_page_builder:' . self::THEME);
    $this->assertNotFalse($entry);
    $this->assertSame(['declarations', 'origins'], array_keys($entry->data));
    $this->assertEquals($origins, $entry->data['origins']);
    $this->declarations->resetCache();
    $this->assertFalse($cache->get('daedalus_ui_page_builder:' . self::THEME));
    $this->assertEquals($origins, $this->declarations->getOrigins(self::THEME));

    // An origin names both its provider and its file, or neither.
    $this->expectException(\InvalidArgumentException::class);
    new DeclarationOrigin('daedalus_ui', NULL);
  }

  /**
   * Asserts an origin tree mirrors a declaration leaf for leaf.
   *
   * A populated map in the declaration is a node with exactly its keys in
   * the tree; anything else (a scalar, a list, an empty map) is a leaf
   * carrying one origin.
   */
  protected function assertMirrors(array $declaration, array $origins, string $path): void {
    $this->assertSame(array_keys($declaration), array_keys($origins), "The origin tree at '$path' has the declaration's keys.");
    foreach ($declaration as $key => $value) {
      if (is_array($value) && $value !== [] && !array_is_list($value)) {
        $this->assertIsArray($origins[$key], "'$path/$key' is a node.");
        $this->assertMirrors($value, $origins[$key], "$path/$key");
      }
      else {
        $this->assertInstanceOf(DeclarationOrigin::class, $origins[$key], "'$path/$key' is a leaf with an origin.");
      }
    }
  }

  /**
   * Asserts a leaf's origin names the provider and file.
   */
  protected function assertOrigin(string $provider, string $file, mixed $origin): void {
    $this->assertInstanceOf(DeclarationOrigin::class, $origin);
    $this->assertSame(['provider' => $provider, 'file' => $file], $origin->toArray());
  }

  /**
   * A package's law layers only while its design system is active.
   *
   * The design-system stratum: modules packaging no system (list order),
   * then the ACTIVE system's package, then the themes; an inactive
   * package layers nothing, and a module that ships a system is a package
   * from install, before its system lands and after it has left.
   */
  public function testDesignSystemStratum(): void {
    // Before its system lands (a recipe installs the module and imports
    // its config after) the module is already a dormant package: its
    // files do not layer, least of all as an ordinary module's BELOW the
    // suite, where its wholesale list replacements would break the guide.
    $this->enableModules(['daedalus_ui_design_system_test']);
    $declarations = $this->container->get('daedalus_ui.page_builder_declarations');
    $declarations->resetCache();
    $this->assertSame(['aurora' => 'daedalus_ui_design_system_test'], $this->container->get(ActiveDesignSystem::SERVICE_ID)->shippedPackages());
    $this->assertSame([], $this->container->get(ActiveDesignSystem::SERVICE_ID)->packages());
    $site = $declarations->getSite(self::THEME);
    $this->assertArrayNotHasKey('token_scales', $site);
    $this->assertSame('Extra slogan', $site['branding']['slogan']);
    $this->assertNotContains('daedalus_ui_design_system_test', $this->providers($declarations));

    // The system lands: the module is now a landed, still INACTIVE
    // package and its files stay out of the layered set.
    $this->installConfig(['daedalus_blueprint', 'daedalus_ui_design_system_test']);
    $site = $declarations->getSite(self::THEME);
    $this->assertArrayNotHasKey('token_scales', $site);
    $this->assertSame('Extra slogan', $site['branding']['slogan']);
    $this->assertNotContains('daedalus_ui_design_system_test', $this->providers($declarations));

    // A different system active (site-built, no package): still absent.
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $active = $this->container->get(ActiveDesignSystem::SERVICE_ID);
    $active->activate('borealis');
    $this->assertArrayNotHasKey('token_scales', $declarations->getSite(self::THEME));

    // Aurora active: its law is present; its slogan beat the extra
    // module's (the package layers above every module) and the base
    // theme's logo beat its own (the themes stay on top).
    $active->activate('aurora');
    $site = $declarations->getSite(self::THEME);
    $this->assertSame(['label' => 'Glow', 'shape' => 'dimension'], $site['token_scales']['glow']);
    $this->assertSame('Aurora slogan', $site['branding']['slogan']);
    $this->assertSame('base.svg', $site['branding']['logo']);
    $origins = $declarations->getOrigins(self::THEME)['site'];
    $package = [
      'provider' => 'daedalus_ui_design_system_test',
      'file' => 'daedalus_ui_design_system_test.page_builder.yml',
    ];
    $this->assertSame($package, $origins['token_scales']['glow']['label']->toArray());
    $this->assertSame($package, $origins['branding']['slogan']->toArray());
    $this->assertSame(self::BASE_THEME, $origins['branding']['logo']->provider);

    // The provider order itself: the package is the last module provider,
    // after modules that sort later than it in list order, directly
    // under the theme chain.
    $providers = $this->providers($declarations);
    $package_at = array_search('daedalus_ui_design_system_test', $providers, TRUE);
    $this->assertGreaterThan(array_search('user', $providers, TRUE), $package_at);
    $this->assertGreaterThan(array_search('daedalus_ui_declarations_test_extra', $providers, TRUE), $package_at);
    $this->assertSame([self::BASE_THEME, self::THEME], array_slice($providers, $package_at + 1));

    // Switching back leaves the site whole: the law leaves with the unit.
    $active->activate('borealis');
    $site = $declarations->getSite(self::THEME);
    $this->assertArrayNotHasKey('token_scales', $site);
    $this->assertSame('Extra slogan', $site['branding']['slogan']);

    // The system leaving does not make the module an ordinary provider:
    // its shipped file still says it is a package, so its law stays out
    // until the system is back and active (layout_builder's entity delete
    // hook wants its usage table).
    $this->installSchema('layout_builder', ['inline_block_usage']);
    DesignSystem::load('aurora')->delete();
    $site = $declarations->getSite(self::THEME);
    $this->assertArrayNotHasKey('token_scales', $site);
    $this->assertSame('Extra slogan', $site['branding']['slogan']);
    $this->assertNotContains('daedalus_ui_design_system_test', $this->providers($declarations));
  }

  /**
   * A switch drops the cached set; the entry names what it depends on.
   *
   * Any writer of the pointer counts (the activate op, drush config-set,
   * config sync): the reset rides the config save event.
   */
  public function testDesignSystemSwitchInvalidatesCache(): void {
    $this->enableModules(['daedalus_ui_design_system_test']);
    $this->installConfig(['daedalus_blueprint', 'daedalus_ui_design_system_test']);
    $declarations = $this->container->get('daedalus_ui.page_builder_declarations');
    $cache = $this->container->get('cache.discovery');
    $cid = 'daedalus_ui_page_builder:' . self::THEME;

    $declarations->getDefinitions(self::THEME);
    $entry = $cache->get($cid);
    $this->assertNotFalse($entry);
    $this->assertContains('daedalus_ui_page_builder', $entry->tags);
    $this->assertContains('config:daedalus_blueprint.settings', $entry->tags);
    $this->assertContains('config:design_system_list', $entry->tags);

    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('aurora');
    $this->assertFalse($cache->get($cid));
    $this->assertArrayHasKey('glow', $declarations->getSite(self::THEME)['token_scales']);
    $this->assertNotFalse($cache->get($cid));

    $this->config('daedalus_blueprint.settings')->set('active_design_system', NULL)->save();
    $this->assertFalse($cache->get($cid));
    $this->assertArrayNotHasKey('token_scales', $declarations->getSite(self::THEME));
  }

  /**
   * The provider names in layering order for the sub theme.
   *
   * @return string[]
   *   Module and theme names as getProviderDirectories() orders them.
   */
  protected function providers(PageBuilderDeclarations $declarations): array {
    $method = new \ReflectionMethod($declarations, 'getProviderDirectories');
    return array_keys($method->invoke($declarations, self::THEME));
  }

  /**
   * Merge semantics: maps merge, lists and scalars replace, NULL removes.
   */
  public function testMerge(): void {
    $low = [
      'map' => ['a' => 1, 'b' => ['deep' => TRUE]],
      'list' => ['x', 'y'],
      'scalar' => 'low',
      'removed' => 'anything',
      'kept' => 'stays',
    ];
    $high = [
      'map' => ['b' => ['deep' => FALSE], 'c' => 3],
      'list' => ['z'],
      'scalar' => 'high',
      'removed' => NULL,
      'empty_over_map' => [],
    ];
    $this->assertSame([
      'map' => ['a' => 1, 'b' => ['deep' => FALSE], 'c' => 3],
      'list' => ['z'],
      'scalar' => 'high',
      'kept' => 'stays',
      'empty_over_map' => [],
    ], PageBuilderDeclarations::merge($low, $high));

    // An empty map in a higher layer ("just declare the section") does not
    // wipe a populated lower declaration.
    $this->assertSame(
      ['part' => ['label' => 'Kept']],
      PageBuilderDeclarations::merge(['part' => ['label' => 'Kept']], ['part' => []])
    );
  }

  /**
   * Part lookups search the whole nested tree.
   */
  public function testElementHelpers(): void {
    $card = $this->declarations->getBlockType('card', self::THEME);
    $this->assertSame('Body copy', PageBuilderDeclarations::findElement($card, '.card__body')['label']);
    $this->assertNull(PageBuilderDeclarations::findElement($card, '.missing'));

    $flat = PageBuilderDeclarations::flattenElements($card);
    $this->assertSame(['.block__inner', '.block__content', '.card', '.card__body'], array_keys($flat));
  }

  /**
   * A pattern part covers its namespace; exact declarations stay exact.
   *
   * The '.s-*' shape: findElement() never matches a pattern (exact lookups
   * stay exact), findPatternElement() resolves any concrete single-class
   * selector extending the pattern's prefix, and the prefix alone or a
   * non-class selector is no instance of the namespace.
   */
  public function testPatternElementLookup(): void {
    $declaration = [
      'elements' => [
        '.wrap' => [
          'label' => 'Wrap',
          'elements' => [
            '.s-*' => ['label' => 'Text element'],
          ],
        ],
        '.s-exact' => ['label' => 'Exact wins'],
      ],
    ];
    // Exact lookups: the pattern key is not a concrete part.
    $this->assertNull(PageBuilderDeclarations::findElement($declaration, '.s-a3'));
    $this->assertSame('Exact wins', PageBuilderDeclarations::findElement($declaration, '.s-exact')['label']);
    // The namespace: any depth, prefix must be extended, charset locked.
    $this->assertSame('Text element', PageBuilderDeclarations::findPatternElement($declaration, '.s-a3')['label']);
    $this->assertNull(PageBuilderDeclarations::findPatternElement($declaration, '.s-'));
    $this->assertNull(PageBuilderDeclarations::findPatternElement($declaration, '.x-a3'));
    $this->assertNull(PageBuilderDeclarations::findPatternElement($declaration, 'div > .s-a3'));
    $this->assertNull(PageBuilderDeclarations::findPatternElement(['elements' => []], '.s-a3'));
  }

  /**
   * Pattern part grammar: trailing star only, no shell vocabulary.
   */
  public function testPatternPartGrammar(): void {
    $reflection = new \ReflectionMethod($this->declarations, 'validate');
    $base = ['kinds' => [], 'block_types' => [], 'block_plugins' => [], 'layouts' => []];

    // A misplaced star is a typo, never silently a pattern.
    $errors = [];
    $reflection->invokeArgs($this->declarations, [
      ['block_types' => ['broken' => ['elements' => ['.s*x' => ['label' => 'Broken']]]]] + $base,
      &$errors,
    ]);
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('The part ".s*x" of block type "broken" is not a valid pattern part key', $errors[0]);

    // A pattern names a class namespace, not a concrete wrapper: contains
    // slots and nested parts cannot apply to it.
    $errors = [];
    $reflection->invokeArgs($this->declarations, [
      [
        'block_types' => [
          'broken' => [
            'elements' => ['.s-*' => ['contains' => 'fields', 'elements' => ['.inner' => ['label' => 'Inner']]]],
          ],
        ],
      ] + $base,
      &$errors,
    ]);
    $this->assertCount(2, $errors);
    $this->assertStringContainsString('The pattern part ".s-*" of block type "broken" declares a contains slot', $errors[0]);
    $this->assertStringContainsString('The pattern part ".s-*" of block type "broken" declares nested elements', $errors[1]);

    // A clean pattern part validates: label plus foreign sections only.
    $errors = [];
    $reflection->invokeArgs($this->declarations, [
      [
        'block_types' => [
          'fine' => ['elements' => ['.s-*' => ['label' => 'Text element', 'property_groups' => ['typography' => []]]]],
        ],
      ] + $base,
      &$errors,
    ]);
    $this->assertSame([], $errors);

    // A pattern names a class namespace whose instances are content, so no
    // one tag/present/attributes declaration can describe them.
    $errors = [];
    $reflection->invokeArgs($this->declarations, [
      [
        'block_types' => [
          'broken' => [
            'elements' => [
              '.s-*' => ['tag' => 'p', 'present' => ['field' => 'field_body'], 'attributes' => ['href' => 'x']],
            ],
          ],
        ],
      ] + $base,
      &$errors,
    ]);
    $this->assertCount(3, $errors);
    $this->assertStringContainsString('The pattern part ".s-*" of block type "broken" declares tag', $errors[0]);
    $this->assertStringContainsString('declares present', $errors[1]);
    $this->assertStringContainsString('declares attributes', $errors[2]);

    // The forms grammar is markup too: no one ordered list can describe a
    // namespace of content-borne instances.
    $errors = [];
    $reflection->invokeArgs($this->declarations, [
      ['block_types' => ['broken' => ['elements' => ['.s-*' => ['forms' => [['tag' => 'p']]]]]]] + $base,
      &$errors,
    ]);
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('The pattern part ".s-*" of block type "broken" declares forms', $errors[0]);
  }

  /**
   * The markup grammar's valid shapes pass whole.
   *
   * The composed text and image blocks' declarations, distilled: a
   * field-decided tag with a conditional attributed wrapper part, a
   * first-match-wins root.forms list ending in the {empty: true} residual
   * (an unconditional last form is the other legal ending), and a part
   * declaring the same forms grammar with per-form labels, its last form
   * legally conditional (a part whose forms all fail is absent) — plus
   * the one-box container: the container KIND declaring composed and a
   * multi-form root whose empty-at-root form composes depth AND empty in
   * one present map, layered under a layout refining it with a
   * named-slot part. None of it may raise an error, or the shipped
   * declarations could not build.
   */
  public function testMarkupGrammar(): void {
    $reflection = new \ReflectionMethod($this->declarations, 'validate');
    $base = ['kinds' => [], 'block_types' => [], 'block_plugins' => [], 'layouts' => []];
    $errors = [];
    $reflection->invokeArgs($this->declarations, [
      [
        'block_types' => [
          'text' => [
            'composed' => TRUE,
            'tag' => ['field' => 'field_text_tag'],
            'elements' => [
              '.text-block-link' => [
                'label' => 'Link',
                'tag' => 'a',
                'present' => ['field' => 'field_text_url'],
                'attributes' => ['href' => ['field' => 'field_text_url'], 'rel' => 'noopener'],
                'contains' => 'field:field_text_text',
              ],
            ],
          ],
          'image' => [
            'composed' => TRUE,
            'root' => [
              'forms' => [
                [
                  'tag' => 'a',
                  'present' => ['field' => 'field_image_url'],
                  'attributes' => ['href' => ['field' => 'field_image_url']],
                ],
                ['tag' => 'img', 'present' => ['media' => 'raster']],
                ['tag' => 'svg', 'present' => ['media' => 'vector']],
                ['tag' => 'div', 'present' => ['empty' => TRUE]],
              ],
            ],
            'elements' => [
              '.image-block-image' => [
                'label' => 'Image',
                'contains' => 'field:field_image',
                'forms' => [
                  ['tag' => 'img', 'present' => ['media' => 'raster'], 'label' => 'Raster'],
                  ['tag' => 'svg', 'present' => ['media' => 'vector'], 'label' => 'Vector'],
                ],
              ],
            ],
          ],
          'fixed' => [
            'root' => [
              'forms' => [
                ['tag' => 'a', 'present' => ['field' => 'field_url']],
                ['tag' => 'div'],
              ],
            ],
          ],
        ],
        'kinds' => [
          'container' => [
            'composed' => TRUE,
            'root' => [
              'forms' => [
                ['tag' => 'section', 'present' => ['depth' => 'root', 'empty' => TRUE]],
                ['tag' => 'div', 'present' => ['empty' => TRUE]],
                ['tag' => 'section', 'present' => ['depth' => 'root']],
                ['tag' => 'div'],
              ],
            ],
          ],
        ],
        'layouts' => [
          'daedalus_stylish_hero' => [
            'elements' => [
              '.hero__media' => [
                'label' => 'Media',
                'tag' => 'div',
                'contains' => 'region:media',
              ],
            ],
          ],
        ],
      ] + $base,
      &$errors,
    ]);
    $this->assertSame([], $errors);
  }

  /**
   * The `sections` slot token: an entity bucket, impossible elsewhere.
   *
   * An entity's model children are its fields AND its layout field's
   * section list, so an entity part may claim `sections` (daedalus_layout's
   * layout root does) beside `fields` / `field:NAME`; the generic bucket
   * rule applies to it like any other (one bucket per tree). A block, a
   * container and a field have no section list, so the token is
   * kind-impossible there and the refusal names the kind's vocabulary.
   */
  public function testSectionsSlotToken(): void {
    $reflection = new \ReflectionMethod($this->declarations, 'validate');
    $base = ['kinds' => [], 'block_types' => [], 'block_plugins' => [], 'layouts' => []];

    // Accepted on an entity part, beside the fields bucket.
    $errors = [];
    $reflection->invokeArgs($this->declarations, [
      [
        'kinds' => [
          'entity' => [
            'elements' => [
              '.daedalus-layout-layout' => ['label' => 'Layout', 'tag' => 'div', 'contains' => 'sections'],
              '.entity__content' => ['label' => 'Content', 'tag' => 'div', 'contains' => ['fields']],
            ],
          ],
        ],
      ] + $base,
      &$errors,
    ]);
    $this->assertSame([], $errors);

    // One generic bucket per child kind: a second sections claim is the
    // same error a second fields claim is.
    $errors = [];
    $reflection->invokeArgs($this->declarations, [
      [
        'kinds' => [
          'entity' => [
            'elements' => [
              '.first' => ['label' => 'First', 'contains' => 'sections'],
              '.second' => ['label' => 'Second', 'contains' => 'sections'],
            ],
          ],
        ],
      ] + $base,
      &$errors,
    ]);
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('The contains slot of part ".second" in kind "entity" declares a second generic "sections" bucket, but the part ".first" already declares one', $errors[0]);

    // Refused on a block, a container and a field part, each refusal
    // naming that kind's own vocabulary.
    $errors = [];
    $reflection->invokeArgs($this->declarations, [
      [
        'kinds' => [
          'container' => ['elements' => ['.band' => ['label' => 'Band', 'contains' => 'sections']]],
          'field' => ['elements' => ['.wrap' => ['label' => 'Wrap', 'contains' => 'sections']]],
        ],
        'block_types' => [
          'broken' => ['elements' => ['.shell' => ['label' => 'Shell', 'contains' => 'sections']]],
        ],
      ] + $base,
      &$errors,
    ]);
    $this->assertCount(3, $errors);
    $message = implode("\n", $errors);
    $this->assertStringContainsString('The contains slot of part ".band" in kind "container" uses "sections", which is impossible in a container tree (a container may contain: regions, region:NAME).', $message);
    $this->assertStringContainsString('The contains slot of part ".wrap" in kind "field" uses "sections", which is impossible in a field tree (a field may contain: value).', $message);
    $this->assertStringContainsString('The contains slot of part ".shell" in block type "broken" uses "sections", which is impossible in a block tree (a block may contain: fields, field:NAME).', $message);

    // A malformed token's corrective lists the whole vocabulary, sections
    // included.
    $errors = [];
    $reflection->invokeArgs($this->declarations, [
      ['kinds' => ['entity' => ['elements' => ['.odd' => ['label' => 'Odd', 'contains' => 'section:main']]]]] + $base,
      &$errors,
    ]);
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('uses "section:main", which is not a slot token; tokens are regions, fields, sections, value, field:NAME, or region:NAME.', $errors[0]);
  }

}
