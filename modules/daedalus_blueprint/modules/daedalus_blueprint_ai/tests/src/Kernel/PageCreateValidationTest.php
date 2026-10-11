<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_test\TestFileDeclarationHandler;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that page_create validates the whole document before persisting.
 *
 * The one-gesture create must be atomic against the WHOLE validation
 * stack, not just the document grammar: the layout applies to the
 * in-memory entity first, so a semantic refusal (an undeclared style
 * property judged by daedalus_stylish's validation engine) costs one corrective
 * and leaves no bare entity behind, no page_edit repair detour, and no
 * open-link to a page that never formed. The valid case still lands
 * entity and layout in ONE save (a bare save first would turn the
 * layout save into a defaults-to-overrides transition that duplicates
 * every inline block), with exactly one inline-block usage row recorded
 * against the real entity id via daedalus_layout's deferred usage tracking.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class PageCreateValidationTest extends BlueprintKernelTestBase {

  use DesignSystemActivationTrait;

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'file',
      'daedalus_ui',
      // Section identity (the uuid the tree addresses containers by) is
      // daedalus_layout's third-party setting.
      'daedalus_layout',
      'daedalus_stylish',
      'daedalus_stylish_guide_test',
      // The eb_test_container layout: the container layout the real
      // catalog builder derives containerLayoutId from.
      'daedalus_blueprint_test',
    ]);
    $this->installEntitySchema('block_content');
    // The catalog build reads the materials roster, whose daedalus_stylish provider
    // reads all three material tables.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();

    $this->createBlockContentType('basic', 'Basic');
    $this->createBlockField('field_heading', 'string', 'basic', ['max_length' => 255]);

    // The closed world (undeclared properties rejected) is strict mode;
    // guarded, the default, only makes authored constraints law.
    $this->enforce('strict');
    TestFileDeclarationHandler::$declared = [];
    TestFileDeclarationHandler::$uuids = [];
  }

  /**
   * Runs an AI tool by function name and returns its readable output.
   *
   * @param string $function_name
   *   The tool's function name (e.g. page_create).
   * @param array $contexts
   *   Tool parameters.
   *
   * @return string
   *   The tool's readable output.
   */
  protected function runTool(string $function_name, array $contexts): string {
    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->getFunctionCallFromFunctionName($function_name);
    $this->assertNotNull($tool, "The $function_name tool is available.");
    foreach ($contexts as $name => $value) {
      $tool->setContextValue($name, $value);
    }
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * All entity_test entities, keyed by id.
   */
  protected function loadPages(): array {
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $storage->resetCache();
    return $storage->loadMultiple();
  }

  /**
   * An invalid style property creates NOTHING and returns its errors.
   *
   * The document errors come back from the one call and no entity, block,
   * or usage row exists. Persisting the entity first and failing the
   * layout second would route repair through page_edit on a bare node (a
   * second ~5k output turn per retry, one debris node per attempt).
   */
  public function testInvalidStylePropertyCreatesNothing(): void {
    // The grant is the validator's (judged against the catalog's roster
    // when the parsed styles land as ops); the output shape and the total
    // refusal are the same whichever layer refuses.
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Broken page'],
      // text-decoration is typography; the guide declares only spacing on
      // containers, so strict enforcement refuses it.
      'layout' => '<pb-container id="hero" style="text-decoration: none"></pb-container>',
    ]);

    $this->assertStringContainsString('STYLE_PROPERTY_NOT_DECLARED', $output);
    $this->assertStringContainsString('Nothing was created', $output);
    $this->assertStringNotContainsString('page_edit', $output);

    // No entity, no inline block, no usage row: the refusal was total.
    $this->assertSame([], $this->loadPages());
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $block_storage->resetCache();
    $this->assertSame([], $block_storage->loadMultiple());
    $usage = \Drupal::database()->select('inline_block_usage', 'u')
      ->fields('u')->execute()->fetchAll();
    $this->assertSame([], $usage);
  }

  /**
   * A field block lands by the field it names; a field the page lacks refuses.
   *
   * The document spells <pb-block field="name"/>: no per-bundle plugin id
   * anywhere, the bundle being created supplies its own derivative, and
   * the read prints the field back. A field this bundle cannot place is
   * refused FIELD_NOT_ON_HOST in the same round as any other grammar
   * fact, with the fields it can, and nothing is created.
   */
  public function testFieldBlockLandsByNameAndIsRefusedOffHost(): void {
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Fielded page'],
      'layout' => '<pb-container id="band"><pb-block id="title" field="name" /></pb-container>',
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertMatchesRegularExpression('/<pb-block id="[0-9a-f-]{36}" data-alias="title" field="name" \/>/', $output, 'The created page reads the field back by name.');
    $this->assertStringNotContainsString('field_block:', $output, 'No derivative is ever printed.');

    $pages = $this->loadPages();
    $this->assertCount(1, $pages);
    $entity = reset($pages);
    $components = $entity->get('layout_builder__layout')->getSections()[0]->getComponents();
    $this->assertCount(1, $components);
    $this->assertSame('field_block:entity_test:entity_test:name', reset($components)->getPluginId(), 'The bundle being created supplied its own derivative.');

    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Fieldless page'],
      'layout' => '<pb-container id="band"><pb-block id="nope" field="field_nope" /></pb-container>',
    ]);
    $this->assertStringContainsString('FIELD_NOT_ON_HOST', $output);
    $this->assertStringContainsString('has no placeable field "field_nope"', $output);
    $this->assertStringContainsString('field_subtitle', $output, 'The corrective names the fields the page has.');
    $this->assertStringContainsString('Nothing was created', $output);
    $this->assertCount(1, $this->loadPages(), 'The refusal was total.');
  }

  /**
   * A grant miss and a grammar miss come back in ONE round.
   *
   * The parser judges grammar only (the grant is the validator's, one
   * door), and the document path judges a document that failed the
   * grammar WHOLE (a judged batch over the best-effort tree), so both
   * facts ride one result: the grammar miss addressed by line, the grant
   * miss addressed to the author's element in the style="" grammar with
   * the roster's own corrective (the property's family, never the roster
   * itself). Aborting on the grammar error and leaving the semantic pass
   * to a second parse would cost a document with a clamp() and an
   * undeclared property two full re-emits.
   */
  public function testGrantAndGrammarMissesShareOneRound(): void {
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Broken page'],
      // margin-inline is outside the guide's spacing grant; calc() is
      // refused by the value grammar. Two declarations, two facts.
      'layout' => "<pb-container id=\"hero\" style=\"margin-inline: auto; padding: calc(1rem + 2px)\">\n<pb-basic id=\"intro\" heading=\"Hi\" style=\"font-size: 2rem\"></pb-basic></pb-container>",
    ]);

    $this->assertStringContainsString('STYLE_PROPERTY_NOT_DECLARED', $output);
    $this->assertStringContainsString('UNSUPPORTED_VALUE', $output);
    $this->assertStringContainsString('Nothing was created', $output);
    $this->assertStringContainsString('calc() in padding: use a token or a single length; clamp()/calc() are not supported (line 1)', $output);
    $this->assertStringContainsString('The style guide does not declare "margin-inline" as stylable on this element.', $output);
    $this->assertStringContainsString('Did you mean margin, margin-top, margin-right, margin-bottom or margin-left?', $output);
    $this->assertStringContainsString('The style guide does not declare "font-size" as stylable on this element.', $output);
    // The grant misses name the author's elements in the style=""
    // grammar, never a translated op index.
    $this->assertStringContainsString("element: hero\n", $output);
    $this->assertStringContainsString("path: style.margin-inline\n", $output);
    $this->assertStringContainsString("element: intro\n", $output);
    $this->assertStringContainsString("path: style.font-size\n", $output);
    $this->assertStringNotContainsString('operation_index', $output);
    $this->assertSame([], $this->loadPages());

    // The two doors refuse against ONE roster per kind: what the parser
    // read from the catalog is what the validator's semantic branch
    // lists for the same kind.
    $catalog = $this->container->get('daedalus_blueprint.dialect_catalog_builder')->build();
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    foreach (['container', 'block'] as $kind) {
      $errors = $validator->validate(["$kind:" . $this->container->get('uuid')->generate() => ['default' => ['font-size' => '2rem']]]);
      $this->assertSame('STYLE_PROPERTY_NOT_DECLARED', $errors[0]->getCode());
      $this->assertSame($catalog->vocabularies()[$kind]->granted(), $errors[0]->getContext()['declared_properties'], "The $kind roster is one derivation on both doors.");
    }
  }

  /**
   * A valid document still lands entity + layout in ONE call.
   */
  public function testValidDocumentLandsEntityAndLayoutInOneCall(): void {
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Launch page'],
      'layout' => '<pb-container id="hero" style="margin: 3rem"><pb-basic id="intro" heading="Hello"></pb-basic></pb-container>',
    ]);

    $this->assertStringNotContainsString('Nothing was created', $output);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertStringContainsString('Created page:', $output);

    $pages = $this->loadPages();
    $this->assertCount(1, $pages);
    $entity = reset($pages);
    $this->assertSame('Launch page', $entity->label());

    // The layout landed on the saved entity: one container section holding
    // the block, styles on the container.
    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $section = $sections[0];
    $this->assertSame('eb_test_container', $section->getLayoutId());
    $container_uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $this->assertNotEmpty($container_uuid);
    $components = $section->getComponents();
    $this->assertCount(1, $components);
    $component = reset($components);
    $this->assertSame('inline_block:basic', $component->getPluginId());

    $style_item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    $map = $style_item ? $style_item->getStyleMap() : [];
    $this->assertSame(['margin' => '3rem'], $map["container:$container_uuid"]['default'] ?? NULL);

    // Exactly ONE usage row, against the real entity id. Two rows would
    // mean the create saved twice and the defaults-to-overrides transition
    // duplicated the inline block; a NULL id would mean usage was recorded
    // at presave time (garbage collection would reap the block). daedalus_layout
    // records the row post-insert by host uuid, so a one-save create is
    // whole.
    $usage = \Drupal::database()->select('inline_block_usage', 'u')
      ->fields('u')->execute()->fetchAll();
    $this->assertCount(1, $usage, print_r($usage, TRUE));
    $this->assertSame((string) $entity->id(), (string) $usage[0]->layout_entity_id);
    $this->assertSame('entity_test', $usage[0]->layout_entity_type);

    // The id echo maps the author-chosen aliases to minted uuids.
    $this->assertStringContainsString('hero:', $output);
    $this->assertStringContainsString('intro:', $output);
  }

  /**
   * A refused <pb-file> body creates nothing and names the declaration.
   *
   * The declaration's refusal is a fact of the same round as the grammar
   * (the document is judged whole), addressed by line, and the total
   * refusal holds: no entity, no block.
   */
  public function testRefusedFileDeclarationCreatesNothing(): void {
    $this->container->get('daedalus_blueprint_ai.conversation_context')->set('thread-create');
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Broken page'],
      'layout' => "<pb-container id=\"hero\"></pb-container>\n<pb-file name=\"bad.svg\" type=\"image/svg+xml\"><svg>" . TestFileDeclarationHandler::REFUSE . "</svg></pb-file>",
    ]);

    $this->assertStringContainsString('FILE_REJECTED', $output);
    $this->assertStringContainsString('The file "bad.svg" was refused: The body was refused by the test seam. The declared type image/svg+xml was noted. (line 2)', $output);
    $this->assertStringContainsString('Nothing was created', $output);
    $this->assertSame([], $this->loadPages());
    $this->assertNull($this->container->get('daedalus_blueprint.dialect_alias_map')->resolveFile('thread-create', 'bad.svg'));
  }

  /**
   * A <pb-file> declaration lands with the page and its name settles.
   */
  public function testDeclaredFileLandsWithThePage(): void {
    $this->container->get('daedalus_blueprint_ai.conversation_context')->set('thread-create');
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Iconed page'],
      'layout' => "<pb-file name=\"logo.svg\" type=\"image/svg+xml\"><svg xmlns=\"http://www.w3.org/2000/svg\"/></pb-file>\n<pb-container id=\"hero\"><pb-basic id=\"intro\" heading=\"Hello\"></pb-basic></pb-container>",
    ]);

    $this->assertStringContainsString('Created page:', $output);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertCount(1, $this->loadPages());
    $this->assertSame([['thread-create', 'logo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>']], TestFileDeclarationHandler::$declared);
    // The next request resolves the declared name: it settled with the
    // document's batch, beside the element aliases.
    $this->assertSame(
      TestFileDeclarationHandler::$uuids['logo.svg'],
      (new DialectAliasMap($this->container->get('tempstore.shared')))->resolveFile('thread-create', 'logo.svg'),
    );
  }

  /**
   * A create's authored ids keep resolving on the next turn.
   *
   * Page_create's document path runs the handler's translated batch as
   * the OUTERMOST batch, so alias settlement fires inside applyDocument;
   * the minted pairings must ride that settlement into tempstore, not a
   * post-batch overlay the settlement already flushed. The pin replays
   * the live failure shape: create a page, drop the overlay the way the
   * next request would, then style `#hero` through page_edit. An alias
   * that missed tempstore dies there with UNKNOWN_ID_SELECTOR.
   */
  public function testCreateAliasesResolveInFollowingPageEdit(): void {
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Alias page'],
      'layout' => '<pb-container id="hero"><pb-basic id="intro" heading="Hello"></pb-basic></pb-container>',
    ]);
    $this->assertStringContainsString('Created page:', $output);
    $pages = $this->loadPages();
    $this->assertCount(1, $pages);
    $entity = reset($pages);

    // The next request starts with an empty overlay; discard() is that
    // request's view of the alias map.
    $this->container->get('daedalus_blueprint.dialect_alias_map')->discard();

    $edit = $this->runTool('page_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
      'ops' => [['op' => 'style', 'css' => '#hero { margin: 2rem }']],
      'save' => TRUE,
    ]);
    $this->assertStringNotContainsString('UNKNOWN_ID_SELECTOR', $edit);
    $this->assertStringNotContainsString('Error', $edit);
    $this->assertStringContainsString('persisted: database', $edit);
    // A style write lands in the page's style store, which is the
    // document's, not a field of the page: the echo is the document alone.
    $this->assertStringContainsString("\n\nUpdated page:\n<pb-container", $edit);
    $this->assertStringNotContainsString('fields:', $edit);

    // The style landed on the container the alias names.
    $pages = $this->loadPages();
    $entity = reset($pages);
    $section = $entity->get('layout_builder__layout')->getSections()[0];
    $container_uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $style_item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    $map = $style_item ? $style_item->getStyleMap() : [];
    $this->assertSame(['margin' => '2rem'], $map["container:$container_uuid"]['default'] ?? NULL);
  }

  /**
   * A created page holds the document only: no seed from the template.
   *
   * With a section on the bundle's display (a non-blank template), a page
   * made by page_create reads exactly its document, nothing of the
   * template under or beside it; a page created without a document (the
   * node form's road) is still seeded from the template.
   */
  public function testDocumentIsThePagesOnlyStartingLayout(): void {
    $this->templateWithPoweredBy();

    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Authored page'],
      'layout' => '<pb-container id="hero"><pb-basic id="intro" heading="Hello"></pb-basic></pb-container>',
    ]);
    $this->assertStringNotContainsString('Nothing was created', $output);
    $this->assertStringContainsString('persisted: database', $output);

    $pages = $this->loadPages();
    $this->assertCount(1, $pages);
    $entity = reset($pages);
    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections, 'The document is the whole layout: no template section beside it.');
    $this->assertSame('eb_test_container', $sections[0]->getLayoutId());
    $components = $sections[0]->getComponents();
    $this->assertCount(1, $components);
    $this->assertSame('inline_block:basic', reset($components)->getPluginId());
    $this->assertStringNotContainsString('system_powered_by_block', json_encode($sections[0]->toArray()));

    // A page created without a document is still seeded from the template.
    $bare = $this->entityTypeManager->getStorage('entity_test')->create(['name' => 'Bare']);
    $bare->save();
    $seeded = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($bare)->sections();
    $this->assertCount(1, $seeded);
    $components = $seeded[0]->getComponents();
    $this->assertSame('system_powered_by_block', reset($components)->getPluginId());
  }

  /**
   * The bundle decides the parameters: an owning bundle takes exactly one source.
   *
   * The bundle's pages own their layout (the override field is on the
   * bundle), so the new page's first document comes from exactly one of
   * start and layout: neither, or both, is refused before anything is
   * built, no entity, and the corrective names the three starts and the
   * authored document rather than the spine's late NO_LAYOUT with its
   * hint to enable Layout Builder.
   */
  public function testOwningBundleTakesExactlyOneSource(): void {
    $neither = 'Error: pass start or layout: this bundle\'s pages own their layout, so the new page needs its first document. Start it from template (the bundle\'s template), blank (an empty page) or a page blueprint offered for this bundle (start: its id, listed under starts in the bundle\'s schema read), or author the whole page as ONE page-language document in layout.';
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Bare page'],
    ]);
    $this->assertSame($neither, $output);
    $this->assertStringNotContainsString('NO_LAYOUT', $output);
    $this->assertSame([], $this->loadPages());

    // A blank document is no document, and a blank start is no start.
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Bare page'],
      'layout' => "  \n",
      'start' => ' ',
    ]);
    $this->assertSame($neither, $output);
    $this->assertSame([], $this->loadPages());

    // Both: a page's first document has one source.
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Torn page'],
      'start' => 'template',
      'layout' => '<pb-container id="hero"><pb-basic id="intro" heading="Hello"></pb-basic></pb-container>',
    ]);
    $this->assertSame('Error: pass exactly one of start or layout, not both: a page\'s first document has one source. Start it from template (the bundle\'s template), blank (an empty page) or a page blueprint offered for this bundle (start: its id, listed under starts in the bundle\'s schema read), or author the whole page as ONE page-language document in layout.', $output);
    $this->assertSame([], $this->loadPages());
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $block_storage->resetCache();
    $this->assertSame([], $block_storage->loadMultiple(), 'Nothing of the document was built.');
  }

  /**
   * The template start: an empty field that seeds on its first read.
   *
   * The page lands with its fields and an empty override field, the
   * default a page made in the UI gets: it follows its template, and the
   * first read of its document seeds it from the template.
   */
  public function testStartFromTemplate(): void {
    $this->templateWithPoweredBy();
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Templated page'],
      'start' => 'template',
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringNotContainsString('Nothing was created', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertStringContainsString('Created page:', $output);
    $this->assertStringContainsString('plugin="system_powered_by_block"', $output, 'The echo prints the page as a read would: its template\'s document.');

    $entity = $this->reloadOnlyPage();
    $this->assertSame('Templated page', $entity->label());
    $this->assertSame([], $entity->get('layout_builder__layout')->getSections(), 'The override field was saved empty: the page follows its template.');
    $seeded = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($entity)->ownSections();
    $this->assertCount(1, $seeded, 'The first read of the document seeds it from the template.');
    $components = $seeded[0]->getComponents();
    $this->assertSame('system_powered_by_block', reset($components)->getPluginId());
  }

  /**
   * The blank start: core's marker, persisted, and read as no sections.
   */
  public function testStartFromBlank(): void {
    $this->templateWithPoweredBy();
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Blank page'],
      'start' => 'blank',
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertStringContainsString('Created page:', $output);
    $this->assertStringNotContainsString('system_powered_by_block', $output, 'Nothing of the template is on a blank page.');

    $entity = $this->reloadOnlyPage();
    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $this->assertSame('layout_builder_blank', $sections[0]->getLayoutId(), 'Core\'s own marker persisted with the one save: started, and empty.');
    $host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($entity);
    $this->assertSame([], $host->ownSections(), 'A blank page reads as no sections, and the template never seeds under the marker.');
    $this->assertSame([], $host->sections());
  }

  /**
   * A page blueprint start: its whole document, fresh identity, one save.
   *
   * The page blueprint is authored through page_create itself (scope
   * page, two roots), then named as a new page's start: the page lands
   * with the blueprint's document copied with fresh identity and no
   * provenance stamp (a started page is not a placement), the field block
   * resolved against the new page's bundle, the container's styles
   * re-keyed under the page's own container, each inline block saved once
   * and recorded as the page's usage, and the echo is the page's print.
   */
  public function testStartFromPageBlueprint(): void {
    $this->enableLibrary();
    $this->templateWithPoweredBy();
    $output = $this->runTool('page_create', [
      'entity_type' => 'blueprint',
      'bundle' => 'section',
      'fields' => [
        'label' => 'Landing',
        'description' => 'A landing page for test entities.',
        'scope' => 'page',
        'subject_type' => 'entity_test',
        'subject_bundle' => 'entity_test',
      ],
      'layout' => '<pb-container id="hero" style="margin: 3rem"><pb-basic id="intro" heading="Hello"></pb-basic></pb-container><pb-container id="band"><pb-block id="title" field="name" /></pb-container>',
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $blueprint_storage = $this->entityTypeManager->getStorage('blueprint');
    $blueprint_storage->resetCache();
    $blueprints = $blueprint_storage->loadMultiple();
    $this->assertCount(1, $blueprints);
    $blueprint = reset($blueprints);
    $this->assertSame('landing', $blueprint->getMachineName());
    $this->assertSame('page', $blueprint->getScope());
    $blueprint_uuids = array_map(static fn ($section) => $section->getThirdPartySetting('daedalus_layout', 'uuid'), $blueprint->get('layout_builder__layout')->getSections());
    $this->assertCount(2, $blueprint_uuids);
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $block_storage->resetCache();
    $this->assertCount(1, $block_storage->loadMultiple(), 'The blueprint saved its own inline block.');

    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Started page'],
      'start' => 'landing',
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringNotContainsString('Nothing was created', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertStringContainsString('Created page:', $output);
    $this->assertMatchesRegularExpression('/<pb-block id="[0-9a-f-]{36}" field="name" \/>/', $output, 'The echo prints the started page\'s own document.');
    $this->assertStringContainsString('heading="Hello"', $output);
    $this->assertStringNotContainsString('system_powered_by_block', $output, 'The template never seeded under the start.');

    $entity = $this->reloadOnlyPage();
    $this->assertSame('Started page', $entity->label());
    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(2, $sections, 'Both roots landed, and nothing of the template beside them.');
    $plugins = [];
    foreach ($sections as $section) {
      $this->assertNull($section->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint'), 'A started page is not a placement: no root carries a provenance stamp.');
      $this->assertNotContains($section->getThirdPartySetting('daedalus_layout', 'uuid'), $blueprint_uuids, 'Fresh identity: no root keeps the blueprint\'s uuid.');
      foreach ($section->getComponents() as $component) {
        $plugins[] = $component->getPluginId();
      }
    }
    $this->assertSame(['inline_block:basic', 'field_block:entity_test:entity_test:name'], $plugins, 'The blueprint\'s document, the field block resolved against the page\'s bundle.');
    $hero_uuid = $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid');
    $style_item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    $map = $style_item ? $style_item->getStyleMap() : [];
    $this->assertSame(['margin' => '3rem'], $map["container:$hero_uuid"]['default'] ?? NULL, 'The container\'s styles re-keyed under the page\'s own container.');
    foreach (array_keys($map) as $key) {
      foreach ($blueprint_uuids as $uuid) {
        $this->assertStringNotContainsString($uuid, $key);
      }
    }

    // ONE save: the page's inline block was saved exactly once, beside the
    // blueprint's own, and the page's usage names it alone.
    $block_storage->resetCache();
    $this->assertCount(2, $block_storage->loadMultiple(), 'One new inline block: the page\'s copy, saved once.');
    $usage = \Drupal::database()->select('inline_block_usage', 'u')
      ->fields('u')
      ->condition('layout_entity_type', 'entity_test')
      ->condition('layout_entity_id', $entity->id())
      ->execute()->fetchAll();
    $this->assertCount(1, $usage, print_r($usage, TRUE));
  }

  /**
   * A refused start creates nothing and answers in the spine's shape.
   *
   * An unknown id (BLUEPRINT_NOT_FOUND, listing what is available) and a
   * block blueprint (BLUEPRINT_WRONG_SCOPE, the corrective naming
   * place_blueprint) come back as the start verb spells them, with the
   * closing line, and no entity or block exists.
   */
  public function testRefusedStartCreatesNothing(): void {
    $this->enableLibrary();
    $output = $this->runTool('page_create', [
      'entity_type' => 'blueprint',
      'bundle' => 'section',
      'fields' => [
        'label' => 'Band',
        'description' => 'One band.',
        'scope' => 'block',
        'subject_type' => 'entity_test',
        'subject_bundle' => 'entity_test',
      ],
      'layout' => '<pb-container id="band"><pb-basic id="intro" heading="Hello"></pb-basic></pb-container>',
    ]);
    $this->assertStringNotContainsString('Error', $output);

    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Lost page'],
      'start' => 'does_not_exist',
    ]);
    $this->assertStringContainsString('BLUEPRINT_NOT_FOUND', $output);
    $this->assertStringContainsString("path: start\n", $output);
    $this->assertStringContainsString('- blank', $output);
    $this->assertStringContainsString('- template', $output);
    $this->assertStringContainsString('Nothing was created. Fix start and call page_create again.', $output);
    $this->assertSame([], $this->loadPages());

    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Banded page'],
      'start' => 'band',
    ]);
    $this->assertStringContainsString('BLUEPRINT_WRONG_SCOPE', $output);
    $this->assertStringContainsString('place_blueprint', $output);
    $this->assertStringContainsString('Nothing was created', $output);
    $this->assertSame([], $this->loadPages());
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $block_storage->resetCache();
    $this->assertCount(1, $block_storage->loadMultiple(), 'No block beside the blueprint\'s own: the refusal was total.');
  }

  /**
   * A locked bundle's page starts as fills of the template's slots, or empty.
   *
   * The bundle's display is not overridable and its template places one
   * slot (hero): template and blank both land the page with its fields
   * alone and nothing filled (no marker: a locked page's field is its
   * fills or nothing), the echo's notice names the slot the page may
   * fill, a page blueprint of slot fills (authored through page_create as
   * a blueprint whose document is <pb-slot> sections) lands its fill as
   * the page's own section under the lock, printed writable after the
   * read-only template, a whole page document is refused toward the
   * slots, and a start the bundle is not offered is refused by the verb.
   */
  public function testLockedBundleTakesStartOfSlotFills(): void {
    $this->enableLibrary();
    $this->config(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();
    $template = $this->createSection('eb_test_container', [
      [
        'uuid' => 'aaaaaaaa-1111-4444-8888-000000000001',
        'configuration' => [
          'id' => 'system_powered_by_block',
          'provider' => 'system',
          'label' => 'Template',
          'label_display' => '0',
        ],
      ],
      [
        'uuid' => 'aaaaaaaa-1111-4444-8888-000000000002',
        'configuration' => [
          'id' => SlotBlock::PLUGIN_ID . ':hero',
          'label' => 'Hero',
          'label_display' => '0',
          'context_mapping' => SlotBlock::CONTEXT_MAPPING,
          SlotBlock::DEFAULT_KEY => NULL,
        ],
      ],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', 'aaaaaaaa-1111-4444-8888-000000000000');
    LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default')
      ->setOverridable(FALSE)
      ->appendSection($template)
      ->save();
    $notice = "The layout of this entity_test belongs to the template entity_view_display entity_test.entity_test.default, which it renders but does not own. Edit this entity_test's own fields here and fill its slots (hero) by inserting inside their slot blocks; edit its layout on the template: run the operation against entity_view_display entity_test.entity_test.default.";

    // The two reserved starts: the page lands with its fields, nothing
    // filled, and the notice says which slot it may fill.
    foreach (['template', 'blank'] as $start) {
      $output = $this->runTool('page_create', [
        'entity_type' => 'entity_test',
        'bundle' => 'entity_test',
        'fields' => ['name' => 'From ' . $start],
        'start' => $start,
      ]);
      $this->assertStringNotContainsString('Error', $output, "start $start lands.");
      $this->assertStringContainsString("\n\nCreated page:\n$notice\n", $output, 'The notice names the slot the page fills.');
      $this->assertStringContainsString('<pb-block id="aaaaaaaa-1111-4444-8888-000000000002" data-can="insert" plugin="daedalus_slot:hero" />', $output, 'The slot block is the door: insert is the page\'s own.');
      $this->assertStringNotContainsString('<pb-slot', $output, 'Nothing filled.');
    }
    $pages = $this->loadPages();
    $this->assertCount(2, $pages);
    foreach ($pages as $page) {
      $this->assertSame([], $page->get('layout_builder__layout')->getSections(), 'Neither start wrote anything: no fill, no marker.');
    }

    // A page blueprint of slot fills, authored as any page blueprint is.
    $output = $this->runTool('page_create', [
      'entity_type' => 'blueprint',
      'bundle' => 'section',
      'fields' => [
        'label' => 'Hero fill',
        'description' => 'A hero for the locked template.',
        'scope' => 'page',
        'subject_type' => 'entity_test',
        'subject_bundle' => 'entity_test',
      ],
      'layout' => '<pb-slot name="hero"><pb-basic id="intro" heading="Hello"></pb-basic></pb-slot>',
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('<pb-slot id="', $output, 'The blueprint\'s document is one slot section.');
    $schema = $this->runTool('eb_read_schema', ['entity_type' => 'entity_test', 'bundle' => 'entity_test']);
    $this->assertStringContainsString('hero_fill', $schema, 'The locked bundle\'s schema read lists the fill under starts.');

    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Filled'],
      'start' => 'hero_fill',
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertStringContainsString("\n\nCreated page:\n$notice\n", $output);
    $this->assertStringContainsString('<pb-container id="aaaaaaaa-1111-4444-8888-000000000000" data-can="">', $output, 'The template prints read-only.');
    $this->assertMatchesRegularExpression('/\n<pb-slot id="[0-9a-f-]{36}" name="hero">\n  <pb-basic id="[0-9a-f-]{36}" heading="Hello" \/>\n<\/pb-slot>\n/', $output, 'The fill prints after the template as the page\'s own slot section, writable, with fresh identity.');
    $pages = $this->loadPages();
    $this->assertCount(3, $pages);
    $filled = end($pages);
    $this->assertSame('Filled', $filled->label());
    $host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($filled);
    $own = $host->ownSections();
    $this->assertCount(1, $own, 'The page owns its fill.');
    $this->assertSame('hero', LayoutSerializer::slotOf($own[0]));
    $this->assertNull($own[0]->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint'), 'A started page is not a placement.');

    // A whole page document is no start of a locked page, and neither is
    // an authored one.
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Refused'],
      'start' => 'does_not_exist',
    ]);
    $this->assertStringContainsString('BLUEPRINT_NOT_FOUND', $output);
    $this->assertStringContainsString('Nothing was created. Fix start', $output);
    $omit = 'Error: omit layout: this bundle\'s pages render the template and own only the slots it places. Pass fields, and start to fill the slots at birth (template, the default, fills none; a page blueprint of slot fills is listed under starts in the bundle\'s schema read); fill a slot on the new page with page_edit by inserting inside its slot block. The layout is the template\'s: edit it with page_edit against entity_view_display entity_test.entity_test.default.';
    $this->assertSame($omit, $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Refused'],
      'layout' => '<pb-container id="hero"></pb-container>',
    ]));
    $this->assertSame($omit, $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Refused'],
      'start' => 'template',
      'layout' => '<pb-container id="hero"></pb-container>',
    ]));
    $this->assertCount(3, $this->loadPages(), 'No refusal created a page.');
  }

  /**
   * The bundle's template: one section holding the powered-by block.
   */
  protected function templateWithPoweredBy(): void {
    $template = $this->createSection('layout_onecol', [
      [
        'uuid' => 'aaaaaaaa-1111-4444-8888-000000000001',
        'region' => 'content',
        'configuration' => [
          'id' => 'system_powered_by_block',
          'provider' => 'system',
          'label' => 'Template',
          'label_display' => '0',
        ],
      ],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', 'aaaaaaaa-1111-4444-8888-000000000000');
    LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default')->appendSection($template)->save();
  }

  /**
   * The one entity_test page, reloaded from storage.
   */
  protected function reloadOnlyPage() {
    $pages = $this->loadPages();
    $this->assertCount(1, $pages);
    return $this->entityTypeManager->getStorage('entity_test')->loadUnchanged(reset($pages)->id());
  }

  /**
   * The blueprint library joins the site: the saved blueprint type exists.
   */
  protected function enableLibrary(): void {
    $this->enableModules(['options', 'image', 'media', 'daedalus_blueprint_library']);
    // setUp warmed the entity type definitions before the library joined.
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['image', 'file', 'media', 'daedalus_blueprint_library']);
  }

  /**
   * A plain bundle's page lands with its fields alone: no document, no seed.
   *
   * The bundle's display is not overridable, so its pages render the
   * template's document and own none; page_create creates the entity from
   * its fields, the entity carries no override field (so nothing could be
   * seeded under it) and the answer is what page_read gives the page: the
   * notice naming the template its layout is edited on, then the
   * template's print with its elements marked.
   */
  public function testPlainBundleCreatesFieldsOnly(): void {
    $template = $this->createSection('eb_test_container', [
      [
        'uuid' => 'aaaaaaaa-1111-4444-8888-000000000001',
        'configuration' => [
          'id' => 'system_powered_by_block',
          'provider' => 'system',
          'label' => 'Template',
          'label_display' => '0',
        ],
      ],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', 'aaaaaaaa-1111-4444-8888-000000000000');
    LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default')
      ->setOverridable(FALSE)
      ->appendSection($template)
      ->save();

    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Plain page'],
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertStringContainsString("\n\nCreated page:\nThe layout of this entity_test belongs to the template entity_view_display entity_test.entity_test.default, which it renders but does not own. Edit this entity_test's own fields here; edit its layout on the template: run the operation against entity_view_display entity_test.entity_test.default.\n", $output, 'The create answers as page_read would: the notice names where the layout is edited.');
    $this->assertStringContainsString('<pb-container id="aaaaaaaa-1111-4444-8888-000000000000" data-can="">', $output, 'The print is the template\'s, its elements marked as not this page\'s to edit.');
    $this->assertStringContainsString('<pb-block id="aaaaaaaa-1111-4444-8888-000000000001" data-can="" plugin="system_powered_by_block" />', $output);

    $pages = $this->loadPages();
    $this->assertCount(1, $pages);
    $entity = reset($pages);
    $this->assertSame('Plain page', $entity->label());
    $this->assertTrue($entity->hasField('layout_builder__layout'), 'The locked bundle keeps the override field (daedalus_layout keeps it on every enabled bundle, through the lock).');
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    $host = $resolver->fromEntity($entity);
    $this->assertTrue($host->hasDocument(), 'The page owns a document: what it holds inside the template, nothing here.');
    $this->assertNotNull($resolver->foreignOwner($host), 'And it renders its template.');
    $this->assertSame([], $host->ownSections(), 'A locked page is never seeded.');
    // Nothing was seeded or built: the template is still the display's
    // alone, and no block or usage row exists for the page.
    $sections = $host->sections();
    $this->assertCount(1, $sections);
    $this->assertSame('aaaaaaaa-1111-4444-8888-000000000000', $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $block_storage->resetCache();
    $this->assertSame([], $block_storage->loadMultiple());
    $usage = \Drupal::database()->select('inline_block_usage', 'u')
      ->fields('u')->execute()->fetchAll();
    $this->assertSame([], $usage);
  }

  /**
   * A locked bundle with an authored document is refused, nothing built.
   *
   * The bundle's pages own no whole document, so one cannot be stated for
   * them: the refusal is the whole answer (no result, no print), names
   * the template the layout is edited on and the slot road, and no entity
   * or block exists.
   */
  public function testPlainBundleWithDocumentIsRefused(): void {
    LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default')
      ->setOverridable(FALSE)
      ->save();

    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Plain page'],
      'layout' => '<pb-container id="hero"><pb-basic id="intro" heading="Hello"></pb-basic></pb-container>',
    ]);
    $this->assertSame('Error: omit layout: this bundle\'s pages render the template and own only the slots it places. Pass fields, and start to fill the slots at birth (template, the default, fills none; a page blueprint of slot fills is listed under starts in the bundle\'s schema read); fill a slot on the new page with page_edit by inserting inside its slot block. The layout is the template\'s: edit it with page_edit against entity_view_display entity_test.entity_test.default.', $output);
    $this->assertSame([], $this->loadPages());
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $block_storage->resetCache();
    $this->assertSame([], $block_storage->loadMultiple());
  }

  /**
   * A bundle no page document renders is not page_create's.
   *
   * With or without a document, a bundle whose display has no Layout
   * Builder (entity_test_rev) is refused toward eb_create_entity before
   * anything is built; the spine's late NO_LAYOUT is never reached.
   */
  public function testNonPageBundleIsRefusedTowardJsonCreate(): void {
    foreach ([[], ['layout' => '<pb-container id="hero"></pb-container>']] as $layout) {
      $output = $this->runTool('page_create', [
        'entity_type' => 'entity_test_rev',
        'bundle' => 'entity_test_rev',
        'fields' => ['name' => 'Not a page'],
      ] + $layout);
      $this->assertSame('Error: entity_test_rev/entity_test_rev is not a page bundle (no page document renders its entities), so page_create is not its door: create it with eb_create_entity.', $output);
    }
    $storage = $this->entityTypeManager->getStorage('entity_test_rev');
    $storage->resetCache();
    $this->assertSame([], $storage->loadMultiple());
  }

  /**
   * A saved blueprint is authored from scratch the way a page is.
   *
   * page_create with entity_type blueprint: the face in fields (label,
   * description, scope, subject), the composition in layout, judged by the
   * same spine. A field block names the SUBJECT's field (the blueprint host
   * expands it against the subject bundle, not the blueprint's own fields),
   * the blueprint lands published with its document in one save, the
   * roster offers it with the page id the AI reads it back by, and
   * page_read answers that id with the face and the body. A second root on
   * a block-scope blueprint is refused by BlueprintScopePolicy in the same
   * round, and nothing is created.
   */
  public function testBlueprintIsAuthoredFromScratch(): void {
    $this->enableLibrary();

    $output = $this->runTool('page_create', [
      'entity_type' => 'blueprint',
      'bundle' => 'section',
      'fields' => [
        'label' => 'Teaser',
        'description' => 'A titled teaser for test entities.',
        'scope' => 'block',
        'subject_type' => 'entity_test',
        'subject_bundle' => 'entity_test',
      ],
      'layout' => '<pb-container id="band"><pb-block id="title" field="name" /><pb-basic id="intro" heading="Hello"></pb-basic></pb-container>',
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringNotContainsString('Nothing was created', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertStringContainsString('status: published', $output);
    $this->assertMatchesRegularExpression('/<pb-block id="[0-9a-f-]{36}" data-alias="title" field="name" \/>/', $output, 'The field block reads back by the subject\'s field name.');

    $storage = $this->entityTypeManager->getStorage('blueprint');
    $storage->resetCache();
    $blueprints = $storage->loadMultiple();
    $this->assertCount(1, $blueprints);
    $blueprint = reset($blueprints);
    $this->assertSame('Teaser', $blueprint->label());
    $this->assertSame('teaser', $blueprint->getMachineName());
    $this->assertSame('block', $blueprint->getScope());
    $this->assertSame('entity_test', $blueprint->getSubjectType());
    $this->assertSame('entity_test', $blueprint->getSubjectBundle());
    $this->assertTrue($blueprint->isPublished());
    $sections = $blueprint->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $plugins = array_map(static fn ($component) => $component->getPluginId(), array_values($sections[0]->getComponents()));
    $this->assertSame(['field_block:entity_test:entity_test:name', 'inline_block:basic'], $plugins, 'The field block expanded against the subject bundle, never the blueprint\'s own fields.');

    // The roster offers the new blueprint as a page of its own.
    $record = $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->get(MaterialKind::Blueprint, 'teaser');
    $this->assertNotNull($record, 'The saved blueprint is on the roster.');
    $this->assertSame((string) $blueprint->id(), $record->entityId);
    $this->assertSame('block', $record->blueprintScope);

    // page_read by that id answers the face and the body.
    $read = $this->runTool('page_read', ['entity_type' => 'blueprint', 'entity_id' => (string) $blueprint->id()]);
    $this->assertStringStartsWith('The block blueprint "teaser" holds one root container; it already has one.', $read, 'The root verdict heads the read, as on any page.');
    $this->assertStringContainsString("\nfields:\n  label: Teaser\n", $read);
    $this->assertStringContainsString("\n  scope: block\n", $read);
    $this->assertMatchesRegularExpression('/<pb-block id="[0-9a-f-]{36}"( data-alias="title")? field="name" \/>/', $read, 'The body prints with ids (the conversation\'s alias still echoes).');

    // A block-scope blueprint holds one root: the policy refuses the
    // second in the create's one round, and nothing lands.
    $output = $this->runTool('page_create', [
      'entity_type' => 'blueprint',
      'bundle' => 'section',
      'fields' => ['label' => 'Two roots', 'description' => 'Two bands in one block blueprint.', 'scope' => 'block', 'subject_type' => 'entity_test', 'subject_bundle' => 'entity_test'],
      'layout' => '<pb-container id="one"></pb-container><pb-container id="two"></pb-container>',
    ]);
    $this->assertStringContainsString('BLUEPRINT_ONE_ROOT', $output);
    $this->assertStringContainsString('Nothing was created', $output);
    $storage->resetCache();
    $this->assertCount(1, $storage->loadMultiple(), 'The refusal was total.');
  }

}
