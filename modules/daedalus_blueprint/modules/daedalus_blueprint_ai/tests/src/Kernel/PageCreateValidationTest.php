<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
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
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();

    $this->createBlockContentType('basic', 'Basic');
    $this->createBlockField('field_heading', 'string', 'basic', ['max_length' => 255]);

    // The closed world (undeclared properties rejected) is strict mode;
    // guarded, the default, only makes authored constraints law.
    $this->enforce('strict');
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

    // The style landed on the container the alias names.
    $pages = $this->loadPages();
    $entity = reset($pages);
    $section = $entity->get('layout_builder__layout')->getSections()[0];
    $container_uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $style_item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    $map = $style_item ? $style_item->getStyleMap() : [];
    $this->assertSame(['margin' => '2rem'], $map["container:$container_uuid"]['default'] ?? NULL);
  }

}
