<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\PageHosts;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\CreateEntity;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\Edit;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\PageCreate;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\PageEdit;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\PageRead;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\ReadElement;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\ReadEntity;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\ValidateBlueprint;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The page-language doors: the JSON entity tools refuse pages.
 *
 * On a page-language site (the catalog declares a container layout) every
 * AI tool that takes an entity agrees on one meaning of "page"
 * (PageHosts::isPage()): an entity whose render is a page
 * document, its own or its template's. Three host states are pages: a
 * host that owns a document, a host carrying the empty override field
 * (never edited, renders its template until it is), and the plain host
 * (its bundle's display is Layout Builder enabled without overrides, so
 * it renders the template's document and owns only its fields). An
 * entity whose display has no Layout Builder is not a page and keeps the
 * JSON door, and on a site without a container layout nothing is a page:
 * every door stays open there. Each refusal names the page tool for the
 * gesture, and the plain host's names the template its layout is edited
 * on.
 *
 * The matrix is host state by tool; this class carries the eb_edit legs,
 * the two JSON read doors' legs (eb_read_entity in every mode, the markup
 * projection included, and eb_read_element) and the two JSON create
 * doors' legs (eb_create_entity and eb_validate), which ask the same
 * question of a bundle before any entity exists
 * (PageHosts::isPageBundle()): the override field is a
 * bundle fact and the render display resolves for an unsaved entity, so
 * the owning and the plain bundle are both refused toward page_create
 * before the deserializer runs.
 *
 * The last column is the page tools on a page the page language cannot
 * spell whole: a page on core layouts (a layout_onecol section and a
 * two-column one, built on the storage since the site refuses to create
 * one). The JSON doors are closed on it like any page, so the page tools
 * must be total there: page_read answers its fields and a print headed by
 * its reading notes, page_edit reports success and echoes the print, and
 * page_create lands a plain bundle's page whose template holds such a
 * section and echoes it. Before the print was total, each of these
 * answered an Error: for the whole page.
 */
#[CoversClass(DaedalusBlueprintToolBase::class)]
#[CoversClass(PageHosts::class)]
#[CoversClass(Edit::class)]
#[CoversClass(ReadEntity::class)]
#[CoversClass(ReadElement::class)]
#[CoversClass(CreateEntity::class)]
#[CoversClass(ValidateBlueprint::class)]
#[CoversClass(PageRead::class)]
#[CoversClass(PageEdit::class)]
#[CoversClass(PageCreate::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class PageLanguageDoorsTest extends BlueprintKernelTestBase {

  /**
   * The template of the page bundle: the entity_test bundle's default display.
   */
  protected const DISPLAY_ID = 'entity_test.entity_test.default';

  /**
   * The UUID of the block an owning page's document places.
   */
  protected const BLOCK_UUID = '3b0a4c7e-9f21-4d6a-8e5b-1c2d3e4f5a6b';

  /**
   * The UUID of the block a two-column section places in its first region.
   */
  protected const COLUMN_BLOCK_UUID = '3b0a4c7e-9f21-4d6a-8e5b-1c2d3e4f5a6c';

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency;
   * daedalus_blueprint_test ships the eb_test_container layout the real
   * catalog builder derives containerLayoutId from, so the site is a
   * page-language site until a leg disables it.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
    'daedalus_blueprint_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Layout Builder overridable on entity_test.entity_test: the bundle
    // grows the override field and its display is the bundle's template.
    $this->enableLayoutBuilder();
  }

  /**
   * Runs an AI tool by function name and returns its readable output.
   *
   * @param string $function_name
   *   The tool's function name.
   * @param array $contexts
   *   The context values, keyed by name.
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
   * Runs eb_edit's legal root-field rename against an entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The tool's readable output.
   */
  protected function runEdit(FieldableEntityInterface $entity): string {
    return $this->runTool('eb_edit', [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (string) $entity->id(),
      'save' => TRUE,
      'operations' => [
        ['op' => 'update_element', 'properties' => ['name' => 'Renamed']],
      ],
    ]);
  }

  /**
   * A page that owns a document: the override field holds a section.
   *
   * The section places one block (BLOCK_UUID), so the element door has a
   * real element to refuse, and to answer where it stays open.
   *
   * @param string $layout_id
   *   The section's layout: the test container by default; a leg that
   *   disables daedalus_blueprint_test (the container's provider) builds
   *   on a core layout instead so the document still serializes.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved page.
   */
  protected function createOwningPage(string $layout_id = 'eb_test_container'): FieldableEntityInterface {
    $page = $this->createTestEntity([
      'name' => 'Host',
      'layout_builder__layout' => [$this->poweredBySection($layout_id, self::BLOCK_UUID, 'content')],
    ]);
    $this->assertTrue($this->host($page)->hasDocument());
    $this->assertFalse($page->get('layout_builder__layout')->isEmpty(), 'The page owns a document.');
    return $page;
  }

  /**
   * A section placing one "Powered by" block, with its section identity.
   *
   * @param string $layout_id
   *   The section's layout.
   * @param string $block_uuid
   *   The block's uuid.
   * @param string $region
   *   The region the block sits in.
   *
   * @return \Drupal\layout_builder\Section
   *   The section.
   */
  protected function poweredBySection(string $layout_id, string $block_uuid, string $region): Section {
    $section = $this->createSection($layout_id, [
      [
        'uuid' => $block_uuid,
        'region' => $region,
        'configuration' => [
          'id' => 'system_powered_by_block',
          'provider' => 'system',
          'label' => 'Powered by',
          'label_display' => '0',
        ],
      ],
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', (new PhpUuid())->generate());
    return $section;
  }

  /**
   * A page the page language cannot spell whole: two sections on core layouts.
   *
   * The owning page on layout_onecol (BLOCK_UUID in its one region), then
   * a two-column section appended on the storage (the site refuses to
   * create one: RESTRICTED_LAYOUT) holding COLUMN_BLOCK_UUID in its first
   * region and nothing in its second.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved page.
   */
  protected function createCoreLayoutPage(): FieldableEntityInterface {
    $page = $this->createOwningPage('layout_onecol');
    $page->get('layout_builder__layout')->appendSection($this->poweredBySection('layout_twocol_section', self::COLUMN_BLOCK_UUID, 'first'));
    $page->save();
    return $page;
  }

  /**
   * A page carrying the empty override field: never edited, renders its template.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved page.
   */
  protected function createEmptyFieldPage(): FieldableEntityInterface {
    $page = $this->createTestEntity(['name' => 'Host']);
    $this->assertTrue($this->host($page)->hasDocument());
    $this->assertTrue($page->get('layout_builder__layout')->isEmpty(), 'The page carries the field, empty.');
    return $page;
  }

  /**
   * Makes the page bundle plain: Layout Builder enabled without overrides.
   *
   * Disabling overrides removes the override field from the bundle (core's
   * display presave), so an entity of the bundle owns no document and
   * renders the template's.
   */
  protected function makeBundlePlain(): void {
    LayoutBuilderEntityViewDisplay::load(self::DISPLAY_ID)
      ->setOverridable(FALSE)
      ->save();
  }

  /**
   * A plain page: its display is Layout Builder enabled without overrides.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved page.
   */
  protected function createPlainPage(): FieldableEntityInterface {
    $this->makeBundlePlain();
    $page = $this->createTestEntity(['name' => 'Host']);
    $host = $this->host($page);
    $this->assertFalse($host->hasDocument(), 'The plain page owns no document.');
    $this->assertNotSame($host, $this->container->get('daedalus_blueprint.host_resolver')->renderHost($host), 'The plain page renders its template.');
    return $page;
  }

  /**
   * An entity that is not a page: its display has no Layout Builder.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved entity.
   */
  protected function createNonPage(): FieldableEntityInterface {
    return $this->createRevEntity(['name' => 'Host']);
  }

  /**
   * Asserts the rename did not land on a refused entity.
   */
  protected function assertUntouched(FieldableEntityInterface $entity): void {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $storage->resetCache();
    $this->assertSame('Host', $storage->load($entity->id())->get('name')->value, 'Nothing ran: the rename did not land.');
  }

  /**
   * Asserts the rename landed on a served entity.
   */
  protected function assertRenamed(FieldableEntityInterface $entity): void {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $storage->resetCache();
    $this->assertSame('Renamed', $storage->load($entity->id())->get('name')->value, 'The rename landed.');
  }

  /**
   * Eb_edit refuses a page that owns a document toward page_edit.
   */
  public function testEditRefusesOwningPage(): void {
    $page = $this->createOwningPage();
    $output = $this->runEdit($page);
    $this->assertStringContainsString('is a page, and pages speak the page language', $output);
    $this->assertStringContainsString('page_edit', $output);
    $this->assertStringNotContainsString('success: true', $output);
    $this->assertUntouched($page);
  }

  /**
   * Eb_edit refuses a page carrying the empty field: an empty page is a page.
   */
  public function testEditRefusesEmptyFieldPage(): void {
    $page = $this->createEmptyFieldPage();
    $output = $this->runEdit($page);
    $this->assertStringContainsString('is a page, and pages speak the page language', $output);
    $this->assertStringContainsString('page_edit', $output);
    $this->assertStringNotContainsString('success: true', $output);
    $this->assertUntouched($page);
  }

  /**
   * Eb_edit refuses the plain page toward page_edit and names its template.
   *
   * The plain host's layout is its template's, so the corrective routes
   * its fields to page_edit's root set and its layout to the template.
   */
  public function testEditRefusesPlainPageNamingTemplate(): void {
    $page = $this->createPlainPage();
    $output = $this->runEdit($page);
    $this->assertSame(
      'Error: entity_test ' . $page->id() . ' is a page whose layout is its template\'s: edit its fields with page_edit (set with ref "root"); edit its layout on the template: run the operation against entity_view_display ' . self::DISPLAY_ID . '.',
      $output,
    );
    $this->assertUntouched($page);
  }

  /**
   * An entity whose display has no Layout Builder keeps the JSON door.
   */
  public function testEditServesNonPage(): void {
    $entity = $this->createNonPage();
    $output = $this->runEdit($entity);
    $this->assertStringContainsString('success: true', $output);
    $this->assertStringNotContainsString('page_edit', $output);
    $this->assertRenamed($entity);
  }

  /**
   * On a site without a container layout nothing is a page: the door is open.
   *
   * The container layout is the page language's site predicate; without
   * one (daedalus_blueprint_test gone, so eb_test_container with it) the
   * same entity that was refused above edits through eb_edit.
   */
  public function testEditServesPageOnSiteWithoutContainerLayout(): void {
    $page = $this->createEmptyFieldPage();
    $this->disableModules(['daedalus_blueprint_test']);
    $this->assertSame('', $this->container->get('daedalus_blueprint.dialect_catalog_builder')->build()->containerLayoutId(), 'The site declares no container layout.');

    $output = $this->runEdit($page);
    $this->assertStringContainsString('success: true', $output);
    $this->assertStringNotContainsString('page_edit', $output);
    $this->assertRenamed($page);
  }

  /**
   * Runs eb_read_entity against an entity in a mode.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $mode
   *   The reading mode: summary, full or markup.
   *
   * @return string
   *   The tool's readable output.
   */
  protected function runReadEntity(FieldableEntityInterface $entity, string $mode = 'summary'): string {
    return $this->runTool('eb_read_entity', [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (string) $entity->id(),
      'mode' => $mode,
    ]);
  }

  /**
   * Runs eb_read_element against an entity's block in a mode.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $mode
   *   The reading mode: full or markup.
   *
   * @return string
   *   The tool's readable output.
   */
  protected function runReadElement(FieldableEntityInterface $entity, string $mode = 'full'): string {
    return $this->runTool('eb_read_element', [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (int) $entity->id(),
      'element' => self::BLOCK_UUID,
      'mode' => $mode,
    ]);
  }

  /**
   * The corrective both read doors answer for a content page.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $page
   *   The page.
   *
   * @return string
   *   The exact refusal.
   */
  protected function readCorrective(FieldableEntityInterface $page): string {
    return 'Error: ' . $page->getEntityTypeId() . ' ' . $page->id() . ' is a page, and pages speak the page language: read it with page_read (its fields ride before the print, and the print carries every element by id).';
  }

  /**
   * Eb_read_entity refuses a page that owns a document, in every mode.
   *
   * The markup projection of a page goes with the JSON road: the refusal
   * answers markup mode too, not the empty-socket corrective.
   */
  public function testReadEntityRefusesOwningPageInEveryMode(): void {
    $page = $this->createOwningPage();
    foreach (['summary', 'full', 'markup'] as $mode) {
      $output = $this->runReadEntity($page, $mode);
      $this->assertSame($this->readCorrective($page), $output, "Mode $mode is refused.");
      $this->assertStringNotContainsString('eb_test_container', $output, "Mode $mode prints nothing of the document.");
    }
  }

  /**
   * Eb_read_entity refuses a page carrying the empty field; nothing is read.
   *
   * The gate sits before any read of the document, so the full read that
   * used to fire a never-edited page's template seed reads nothing: the
   * override field stays empty on the loaded entity.
   */
  public function testReadEntityRefusesEmptyFieldPageWithoutReading(): void {
    $page = $this->createEmptyFieldPage();
    $output = $this->runReadEntity($page, 'full');
    $this->assertSame($this->readCorrective($page), $output);
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $this->assertTrue($storage->load($page->id())->get('layout_builder__layout')->isEmpty(), 'The refused read seeded nothing.');
  }

  /**
   * Eb_read_entity refuses the plain page toward page_read.
   *
   * The plain host's fields are a page's fields: page_read prints them
   * before the template's document, so the JSON read has nothing to add.
   */
  public function testReadEntityRefusesPlainPage(): void {
    $page = $this->createPlainPage();
    $this->assertSame($this->readCorrective($page), $this->runReadEntity($page, 'full'));
  }

  /**
   * Eb_read_element refuses a page's element, in both modes.
   */
  public function testReadElementRefusesOwningPageInBothModes(): void {
    $page = $this->createOwningPage();
    foreach (['full', 'markup'] as $mode) {
      $output = $this->runReadElement($page, $mode);
      $this->assertSame($this->readCorrective($page), $output, "Mode $mode is refused.");
      $this->assertStringNotContainsString('system_powered_by_block', $output, "Mode $mode prints nothing of the element.");
    }
  }

  /**
   * Eb_read_element refuses the plain page: its elements are its template's.
   */
  public function testReadElementRefusesPlainPage(): void {
    $page = $this->createPlainPage();
    $this->assertSame($this->readCorrective($page), $this->runReadElement($page));
  }

  /**
   * An entity whose display has no Layout Builder keeps both read doors.
   *
   * The element door answers its own not-found for a UUID the entity does
   * not place: the door was passed, and the tool, not the gate, answered.
   */
  public function testReadServesNonPage(): void {
    $entity = $this->createNonPage();
    $output = $this->runReadEntity($entity, 'full');
    $this->assertStringContainsString('entity_type: entity_test_rev', $output);
    $this->assertStringContainsString('Host', $output);
    $this->assertStringNotContainsString('page_read', $output);

    $output = $this->runReadElement($entity);
    $this->assertSame("Element with UUID '" . self::BLOCK_UUID . "' not found in entity_test_rev:" . $entity->id() . '.', $output);
  }

  /**
   * On a site without a container layout nothing is a page: both doors open.
   *
   * The same owning page refused above reads as JSON, whole and by element.
   */
  public function testReadServesPageOnSiteWithoutContainerLayout(): void {
    $page = $this->createOwningPage('layout_onecol');
    $this->disableModules(['daedalus_blueprint_test']);
    $this->assertSame('', $this->container->get('daedalus_blueprint.dialect_catalog_builder')->build()->containerLayoutId(), 'The site declares no container layout.');

    $output = $this->runReadEntity($page, 'full');
    $this->assertStringNotContainsString('page_read', $output);
    $this->assertStringContainsString('entity_type: entity_test', $output);
    $this->assertStringContainsString(self::BLOCK_UUID, $output);

    $output = $this->runReadElement($page);
    $this->assertStringNotContainsString('page_read', $output);
    $this->assertStringContainsString(self::BLOCK_UUID, $output);
    $this->assertStringContainsString('system_powered_by_block', $output);
  }

  /**
   * The two JSON create doors, by function name.
   */
  protected const CREATE_DOORS = ['eb_create_entity', 'eb_validate'];

  /**
   * Runs a JSON create door against a bundle's blueprint.
   *
   * @param string $function_name
   *   The door: eb_create_entity or eb_validate.
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param array $fields
   *   The blueprint's fields.
   *
   * @return string
   *   The tool's readable output.
   */
  protected function runCreateDoor(string $function_name, string $entity_type, string $bundle, array $fields = ['name' => 'Born']): string {
    return $this->runTool($function_name, [
      'blueprint' => ['entity_type' => $entity_type, 'bundle' => $bundle, 'fields' => $fields],
    ]);
  }

  /**
   * The corrective both create doors answer for a page bundle.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   *
   * @return string
   *   The exact refusal.
   */
  protected function createCorrective(string $entity_type, string $bundle): string {
    return 'Error: ' . $entity_type . '/' . $bundle . ' is a page bundle (its entities render a page document), and pages speak the page language: create it with page_create, which judges the whole page before anything is built.';
  }

  /**
   * Asserts no entity of a type exists.
   */
  protected function assertNothingBorn(string $entity_type): void {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $storage->resetCache();
    $this->assertSame([], $storage->loadMultiple(), "No $entity_type entity was created.");
  }

  /**
   * Both create doors refuse the owning bundle before the deserializer runs.
   *
   * The bundle whose display enables overrides is a page bundle before any
   * entity of it exists (the override field is a bundle fact). The
   * blueprint names a field the bundle lacks, which the deserializer would
   * refuse as UNKNOWN_FIELD: the page corrective answering instead pins the
   * gate ahead of the deserializer.
   */
  public function testCreateDoorsRefuseOwningBundleBeforeTheDeserializer(): void {
    foreach (self::CREATE_DOORS as $door) {
      $output = $this->runCreateDoor($door, 'entity_test', 'entity_test', ['name' => 'Born', 'no_such_field' => 'x']);
      $this->assertSame($this->createCorrective('entity_test', 'entity_test'), $output, "$door refuses the owning bundle.");
    }
    $this->assertNothingBorn('entity_test');
  }

  /**
   * Both create doors refuse the plain bundle: its pages render the template.
   *
   * The plain bundle's entities own no document, but their render is the
   * template's page document, so they are pages and page_create (fields
   * only) is their birth.
   */
  public function testCreateDoorsRefusePlainBundle(): void {
    $this->makeBundlePlain();
    foreach (self::CREATE_DOORS as $door) {
      $output = $this->runCreateDoor($door, 'entity_test', 'entity_test');
      $this->assertSame($this->createCorrective('entity_test', 'entity_test'), $output, "$door refuses the plain bundle.");
    }
    $this->assertNothingBorn('entity_test');
  }

  /**
   * A bundle whose display has no Layout Builder keeps both create doors.
   *
   * Eb_validate judges the blueprint and creates nothing; eb_create_entity
   * lands the entity.
   */
  public function testCreateDoorsServeNonPageBundle(): void {
    $output = $this->runCreateDoor('eb_validate', 'entity_test_rev', 'entity_test_rev');
    $this->assertStringContainsString('success: true', $output);
    $this->assertStringNotContainsString('page_create', $output);
    $this->assertNothingBorn('entity_test_rev');

    $output = $this->runCreateDoor('eb_create_entity', 'entity_test_rev', 'entity_test_rev');
    $this->assertStringContainsString('success: true', $output);
    $this->assertStringNotContainsString('page_create', $output);
    $storage = $this->entityTypeManager->getStorage('entity_test_rev');
    $storage->resetCache();
    $entities = $storage->loadMultiple();
    $this->assertCount(1, $entities);
    $this->assertSame('Born', reset($entities)->get('name')->value, 'The create landed.');
  }

  /**
   * On a site without a container layout nothing is a page: both doors open.
   *
   * The same owning bundle refused above is judged and created as JSON.
   */
  public function testCreateDoorsServePageBundleOnSiteWithoutContainerLayout(): void {
    $this->disableModules(['daedalus_blueprint_test']);
    $this->assertSame('', $this->container->get('daedalus_blueprint.dialect_catalog_builder')->build()->containerLayoutId(), 'The site declares no container layout.');

    $output = $this->runCreateDoor('eb_validate', 'entity_test', 'entity_test');
    $this->assertStringContainsString('success: true', $output);
    $this->assertStringNotContainsString('page_create', $output);
    $this->assertNothingBorn('entity_test');

    $output = $this->runCreateDoor('eb_create_entity', 'entity_test', 'entity_test');
    $this->assertStringContainsString('success: true', $output);
    $this->assertStringNotContainsString('page_create', $output);
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $storage->resetCache();
    $entities = $storage->loadMultiple();
    $this->assertCount(1, $entities);
    $this->assertSame('Born', reset($entities)->get('name')->value, 'The create landed.');
  }

  /**
   * The bundle listing marks page bundles and says which own their layout.
   *
   * The create skill forks on the mark, so the model picks page_create or
   * eb_create_entity (and a document or fields alone) from the listing
   * instead of from a refusal.
   */
  public function testBundleListMarksPageBundles(): void {
    $builder = $this->container->get('daedalus_blueprint.bundle_list_builder');

    $markdown = $builder->formatAsMarkdown();
    $this->assertMatchesRegularExpression('/^- entity_test: [^\[\n]*\[page\]/m', $markdown, 'A bundle whose pages own their layout is marked a page.');
    $this->assertMatchesRegularExpression('/^- entity_test_rev: [^\[\n]*$/m', $markdown, 'A bundle no page document renders carries no mark.');

    $this->makeBundlePlain();
    $markdown = $builder->formatAsMarkdown();
    $this->assertMatchesRegularExpression('/^- entity_test: [^\[\n]*\[page, renders its template\]/m', $markdown, 'A plain bundle is a page whose layout is its template\'s.');
  }

  /**
   * On a site without a container layout no bundle is marked a page.
   */
  public function testBundleListMarksNothingOnSiteWithoutContainerLayout(): void {
    $this->disableModules(['daedalus_blueprint_test']);
    $this->assertStringNotContainsString('[page', $this->container->get('daedalus_blueprint.bundle_list_builder')->formatAsMarkdown());
  }

  /**
   * The layout sentence a multi-region print's reading notes carry.
   */
  protected const REGION_SENTENCE = '- A <pb-container> carrying data-layout is a section on that layout rather than the container: its children print directly under it, data-regions lists its regions and each child\'s data-region names the one it sits in. An insert or move into such a section names the region as at: {ref, position, region}; afterbegin or beforeend on it without a region is refused (REGION_REQUIRED), a region anywhere else is refused too (REGION_NOT_APPLICABLE), and nothing creates such a section.';

  /**
   * Asserts a print spells the page on core layouts whole, as read-only facts.
   *
   * @param string $output
   *   A tool's output holding the print.
   * @param bool $with_block
   *   Whether the layout_onecol section still holds BLOCK_UUID.
   */
  protected function assertCoreLayoutPrint(string $output, bool $with_block = TRUE): void {
    $this->assertSame(1, substr_count($output, 'Reading notes:'), 'The reading notes head the print once.');
    $this->assertStringContainsString("\n" . self::REGION_SENTENCE . "\n", $output, 'The layout sentence teaches the region address.');
    $this->assertMatchesRegularExpression('/\n<pb-container( id="[^"]*")?( data-can="[^"]*")? data-layout="layout_onecol">\n/', $output, 'The single-region section carries its layout and no regions.');
    $this->assertMatchesRegularExpression('/\n<pb-container( id="[^"]*")?( data-can="[^"]*")? data-layout="layout_twocol_section" data-regions="first second">\n/', $output, 'The two-column section carries its layout and both region names.');
    $this->assertMatchesRegularExpression('/\n  <pb-block id="' . self::COLUMN_BLOCK_UUID . '"( data-can="[^"]*")? data-region="first" plugin="system_powered_by_block" \/>\n/', $output, 'The column\'s block carries its region as a fact.');
    $block = '/\n  <pb-block id="' . self::BLOCK_UUID . '"( data-can="[^"]*")? plugin="system_powered_by_block" \/>\n/';
    if ($with_block) {
      $this->assertMatchesRegularExpression($block, $output, 'The single region\'s block carries no region.');
    }
    else {
      $this->assertDoesNotMatchRegularExpression($block, $output, 'The removed block is gone from the echo.');
    }
    $this->assertStringEndsWith("</pb-container>\n", $output);
  }

  /**
   * Page_read answers a page on core layouts: its fields, then a total print.
   *
   * The JSON read is closed on this page like any page, so what it owns
   * (its fields) and what it renders (two sections the authored grammar
   * cannot spell) must both come through the page read: the fields block
   * first, the reading notes once, then the document with its layouts and
   * regions as data-* facts.
   */
  public function testPageReadServesPageOnCoreLayouts(): void {
    $page = $this->createCoreLayoutPage();
    $output = $this->runTool('page_read', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $page->id(),
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertMatchesRegularExpression('/^fields:\n(  .*\n)+Reading notes:\n- /', $output, 'The page\'s own fields head the read, the reading notes follow.');
    $this->assertStringContainsString("\n  name: Host\n", $output);
    $this->assertCoreLayoutPrint($output);
  }

  /**
   * Page_edit on a page on core layouts reports success and echoes the print.
   *
   * A set on root (the page's own fields) and a remove of the single
   * region's block, saved: the result reports success and the database,
   * the echo heads with the fields (the batch wrote them) and prints the
   * document after the write. A landed write is never answered as an
   * error, so the write is on the page when the tool returns.
   */
  public function testPageEditServesPageOnCoreLayouts(): void {
    $page = $this->createCoreLayoutPage();
    $output = $this->runTool('page_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $page->id(),
      'save' => TRUE,
      'ops' => [
        ['op' => 'set', 'ref' => 'root', 'attrs' => ['name' => 'Renamed']],
        ['op' => 'remove', 'ref' => self::BLOCK_UUID],
      ],
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('success: true', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertMatchesRegularExpression('/\n\nUpdated page:\nfields:\n(  .*\n)+Reading notes:\n- /', $output, 'The echo heads with the fields the batch wrote, then the reading notes.');
    $this->assertStringContainsString("\n  name: Renamed\n", $output);
    $this->assertCoreLayoutPrint($output, with_block: FALSE);
    $this->assertRenamed($page);

    $storage = $this->entityTypeManager->getStorage('entity_test');
    $uuids = [];
    foreach ($storage->load($page->id())->get('layout_builder__layout')->getSections() as $section) {
      foreach ($section->getComponents() as $uuid => $component) {
        $uuids[] = $uuid;
      }
    }
    $this->assertSame([self::COLUMN_BLOCK_UUID], $uuids, 'The remove landed and the column\'s block stands.');
  }

  /**
   * Page_create lands a plain page whose template holds a two-column section.
   *
   * The plain bundle's pages render the template, so the create (fields
   * only) echoes the template's print; a template on a layout the grammar
   * cannot spell used to fail that echo after the page had landed. Now the
   * result reports the landed page and the echo heads with the notice
   * naming the template, then the reading notes and the print.
   */
  public function testPageCreateServesPlainBundleWhoseTemplateHoldsRegions(): void {
    // The display is config, and its schema does not know daedalus_layout's
    // section identity on this site (the module is not installed), so the
    // template's section goes on without it; sections print id-less here
    // either way.
    $section = $this->poweredBySection('layout_twocol_section', self::COLUMN_BLOCK_UUID, 'first');
    $section->unsetThirdPartySetting('daedalus_layout', 'uuid');
    LayoutBuilderEntityViewDisplay::load(self::DISPLAY_ID)
      ->setOverridable(FALSE)
      ->appendSection($section)
      ->save();

    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Born'],
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('success: true', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertStringContainsString("\n\nCreated page:\nThe layout of this entity_test belongs to the template entity_view_display " . self::DISPLAY_ID . ", which it renders but does not own.", $output, 'The echo heads with the notice naming the template.');
    $this->assertMatchesRegularExpression('/does not own\. [^\n]*\nReading notes:\n- /', $output, 'The reading notes follow the notice.');
    $this->assertSame(1, substr_count($output, 'Reading notes:'), 'The reading notes head the print once.');
    $this->assertStringContainsString("\n" . self::REGION_SENTENCE . "\n", $output);
    $this->assertMatchesRegularExpression('/\n<pb-container( id="[^"]*")?( data-can="[^"]*")? data-layout="layout_twocol_section" data-regions="first second">\n/', $output, 'The template\'s section carries its layout and both region names.');
    $this->assertMatchesRegularExpression('/\n  <pb-block id="' . self::COLUMN_BLOCK_UUID . '"( data-can="[^"]*")? data-region="first" plugin="system_powered_by_block" \/>\n/', $output);

    $storage = $this->entityTypeManager->getStorage('entity_test');
    $storage->resetCache();
    $entities = $storage->loadMultiple();
    $this->assertCount(1, $entities);
    $entity = reset($entities);
    $this->assertSame('Born', $entity->get('name')->value, 'The create landed.');
    $this->assertFalse($entity->hasField('layout_builder__layout'), 'The page owns no document.');
    $this->assertStringContainsString("entity_id: '" . $entity->id() . "'", $output, 'The result reports the landed page.');
  }

}
