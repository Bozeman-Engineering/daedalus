<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the layout root: the one element the root sections render inside.
 *
 * daedalus_layout wraps the section list buildSections() returns in one
 * div.daedalus-layout-layout in every view mode and for every host shape, gives
 * the edit canvas's layout_builder_plus container the same class, and
 * declares that element as entity anatomy in daedalus_layout.page_builder.yml
 * (the arranging box a page-level gap acts on, the same element live and
 * edit). The wrapper holds exactly the sections; in the view render an
 * entity with no sections has none (the display still renders its fields,
 * as core's empty-list semantics require), while the edit canvas always
 * has one, matching the model's unconditional presence answer. On an empty
 * document the canvas holds the blank-page affordance, whose hint names
 * its host: the canvas serves a page, a template and a blueprint.
 *
 * @see \Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay::buildSections()
 * @see \Drupal\daedalus_layout\Element\LayoutBuilderPlus::layout()
 * @see \Drupal\daedalus_layout\Element\LayoutBuilderPlus::buildBlankPage()
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class LayoutRootTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The blueprint host's canvas needs the library's `blueprint` entity,
   * whose icon field references media and whose scope is a list_string.
   */
  protected static $modules = [
    'file',
    'image',
    'media',
    'options',
    'daedalus_blueprint_library',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['image', 'file', 'media', 'daedalus_blueprint_library']);
  }

  /**
   * The rendered layout root contains exactly the root sections.
   */
  public function testLayoutRootWrapsExactlyTheSections(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity']);
    $entity = $this->createTestEntity([
      $this->createSection('layout_onecol', [$this->nameFieldComponent()]),
      $this->createSection('layout_onecol', [$this->nameFieldComponent()]),
    ]);
    $entity->set('name', 'Layout root sentinel')->save();

    $xpath = $this->htmlXpath($this->renderEntity($entity));

    $roots = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " daedalus-layout-layout ")]');
    $this->assertSame(1, $roots->length, 'The sections render inside exactly one layout root.');
    $root = $roots->item(0);

    $children = [];
    foreach ($root->childNodes as $child) {
      if ($child instanceof \DOMElement) {
        $children[] = $child;
      }
      elseif ($child instanceof \DOMText) {
        $this->assertSame('', trim($child->wholeText), 'The layout root holds no stray text between its sections.');
      }
    }
    $this->assertCount(2, $children, 'The layout root holds the two root sections and nothing else.');
    $this->assertSame(['0', '1'], array_map(
      static fn(\DOMElement $child): string => $child->getAttribute('data-layout-builder-section-delta'),
      $children,
    ), 'Each direct child of the layout root is a root section, in delta order.');

    $sections = $xpath->query('//*[@data-layout-builder-section-delta]');
    $this->assertSame(2, $sections->length, 'No root section renders outside the layout root.');
    foreach ($sections as $section) {
      $this->assertSame($root, $section->parentNode, 'Every root section is a direct child of the layout root.');
    }
  }

  /**
   * An entity with no sections renders no layout root and keeps its fields.
   *
   * The empty section list stays core's bare array, so buildMultiple()'s
   * Element::isEmpty() check still renders the display's fields; the
   * declared part is presence-gated exactly as its anatomy says.
   */
  public function testNoSectionsNoLayoutRoot(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity']);
    $entity = EntityTest::create([
      'name' => 'Sectionless sentinel',
      OverridesSectionStorage::FIELD_NAME => [],
    ]);
    $entity->save();
    $entity = EntityTest::load($entity->id());

    $html = $this->renderEntity($entity);
    $this->assertStringNotContainsString(LayoutBuilderEntityViewDisplay::LAYOUT_ROOT_CLASS, $html, 'A section-less entity has no layout root.');
    $this->assertStringNotContainsString('data-layout-builder-section-delta', $html);
    $this->assertStringContainsString('Sectionless sentinel', $html, 'The display keeps rendering its fields when the section list is empty.');
  }

  /**
   * daedalus_layout declares the layout root as entity anatomy, with its box fact.
   *
   * The rendered class and the declared part key are one fact; the part's
   * flex column (display: flex; flex-direction: column, the container
   * kind's own stacking model) rides the declared-defaults tier (declared
   * defaults follow markup ownership: the entity kind may default the
   * anatomy it declares).
   */
  public function testLayoutRootIsDeclaredEntityAnatomy(): void {
    $declarations = $this->container->get('daedalus_ui.page_builder_declarations');
    $entity = $declarations->getKind('entity');
    $selector = '.' . LayoutBuilderEntityViewDisplay::LAYOUT_ROOT_CLASS;

    $this->assertSame(
      ['label' => 'Layout', 'tag' => 'div', 'contains' => 'sections'],
      $entity['elements'][$selector] ?? NULL,
      'The layout root is a declared div part of the entity kind, labeled Layout, claiming the section list.',
    );
    $this->assertSame(
      ['default' => ['display' => 'flex', 'flex-direction' => 'column']],
      $entity['defaults']['::' . $selector] ?? NULL,
      'The layout root defaults to a flex column: the arranging box fact ships with the markup, not the skin, and packs the bands instead of stretching them.',
    );

    $resolved = $this->container->get('daedalus_ui.element_resolver')->resolve($entity);
    $this->assertSame('div', $resolved['parts'][$selector]['tag'] ?? NULL, 'The part resolves as its own element under the entity root.');
    $this->assertSame([], $resolved['hoisted'], 'The layout root never hoists onto the entity root: the theme owns that element.');
    $this->assertSame(['sections' => $selector], $resolved['slots'], 'The layout root claims the section list: every sketch of the entity mounts the sections inside it.');
  }

  /**
   * The edit canvas's container is the layout root too: same class, same
   * exactly-the-sections contract, so a page gap paints alike live and edit.
   *
   * The route entity's sections render through the layout_builder_plus
   * element in edit mode (daedalus_ui's EditableUiBuilder event, daedalus_layout's
   * subscriber swapping buildSections()'s list out), so its container wears
   * LAYOUT_ROOT_CLASS beside its own id and class, which the AJAX rebuild
   * target and daedalus_ui's edit-mode plugin still read.
   */
  public function testEditCanvasContainerIsTheLayoutRoot(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity']);
    $entity = $this->createTestEntity([
      $this->createSection('layout_onecol', [$this->nameFieldComponent()]),
      $this->createSection('layout_onecol', [$this->nameFieldComponent()]),
    ]);
    $entity->set('name', 'Edit canvas sentinel')->save();

    $xpath = $this->htmlXpath($this->renderEditCanvas($entity));

    $roots = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " daedalus-layout-layout ")]');
    $this->assertSame(1, $roots->length, 'The edit canvas renders exactly one layout root.');
    $root = $roots->item(0);
    $this->assertSame('layout-builder', $root->getAttribute('id'), 'The layout root is the layout_builder_plus container (the AJAX rebuild target keeps its id).');
    $classes = explode(' ', $root->getAttribute('class'));
    $this->assertContains('layout-builder', $classes, 'The container keeps its layout-builder class beside the layout root class.');
    $this->assertContains(LayoutBuilderEntityViewDisplay::LAYOUT_ROOT_CLASS, $classes);

    $children = [];
    foreach ($root->childNodes as $child) {
      if ($child instanceof \DOMElement) {
        $children[] = $child;
      }
      elseif ($child instanceof \DOMText) {
        $this->assertSame('', trim($child->wholeText), 'The layout root holds no stray text between its sections.');
      }
    }
    $this->assertCount(2, $children, 'The edit canvas layout root holds the two root sections and nothing else.');
    $this->assertSame(['0', '1'], array_map(
      static fn(\DOMElement $child): string => $child->getAttribute('data-layout-builder-section-delta'),
      $children,
    ), 'Each direct child of the layout root is a root section, in delta order.');

    $sections = $xpath->query('//*[@data-layout-builder-section-delta]');
    $this->assertSame(2, $sections->length, 'No root section renders outside the layout root.');
    foreach ($sections as $section) {
      $this->assertSame($root, $section->parentNode, 'Every root section is a direct child of the layout root.');
    }
  }

  /**
   * A blank page's edit canvas still wears the layout root.
   *
   * The declared part carries no present: condition, so the blueprint's
   * markup annotation says the layout root is present on every Layout
   * Builder entity in edit mode; the canvas agrees by always rendering the
   * container, the blank-page affordance its only child (one child box, so
   * the page's gap gate hides exactly as on a one-section page). The empty
   * storage is seeded into tempstore first, the live shape of a page whose
   * sections were all removed: core's PrepareLayout copies the default
   * display's sections into an override it does not find there.
   */
  public function testEmptyEditCanvasStillWearsTheLayoutRoot(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity']);
    $entity = EntityTest::create([
      'name' => 'Blank canvas sentinel',
      OverridesSectionStorage::FIELD_NAME => [],
    ]);
    $entity->save();
    $entity = EntityTest::load($entity->id());
    $storage = $this->getNestedStorage($entity);
    $this->assertSame(0, $storage->count());
    $this->container->get('layout_builder.tempstore_repository')->set($storage);

    $xpath = $this->htmlXpath($this->renderEditCanvas($entity));

    $roots = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " daedalus-layout-layout ")]');
    $this->assertSame(1, $roots->length, 'A blank page still renders the edit canvas layout root.');
    $root = $roots->item(0);
    $this->assertSame('layout-builder', $root->getAttribute('id'));

    $children = [];
    foreach ($root->childNodes as $child) {
      if ($child instanceof \DOMElement) {
        $children[] = $child;
      }
    }
    $this->assertCount(1, $children, 'The blank-page affordance is the layout root\'s only child.');
    $this->assertSame('daedalus-layout-blank-page', $children[0]->getAttribute('id'));
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-section-delta]')->length, 'A blank page renders no section.');
  }

  /**
   * A defaults storage's canvas wears the root wrapper naming the display.
   *
   * On core's manage-display layout route the layout_builder_plus element
   * is built with the display's defaults storage alone (no entity view
   * around it), so it is the canvas root itself: its host is the display,
   * and the output's outermost element carries the main-entity wrapper
   * with the display's identity (what the client reads root identity
   * from), the layout root inside it holding the template's sections.
   */
  public function testDefaultsStorageCanvasWearsTheRootWrapper(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity']);
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $display->removeAllSections();
    $display->appendSection($this->createSection('layout_onecol', [$this->nameFieldComponent()]));
    $display->save();
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $storage = $this->sectionStorageManager->load('defaults', [
      'display' => EntityContext::fromEntity($display),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);

    $build = [
      '#type' => 'layout_builder_plus',
      '#section_storage' => $storage,
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $xpath = $this->htmlXpath($html);

    $wrappers = $xpath->query('//*[@data-main-entity]');
    $this->assertSame(1, $wrappers->length, 'The canvas carries one main-entity wrapper.');
    $wrapper = $wrappers->item(0);
    $this->assertSame('entity_view_display::entity_test.entity_test.default::entity_view_display', $wrapper->getAttribute('data-daedalus-ui-entity-wrapper'), 'The root wrapper names the display.');
    $this->assertSame('entity:' . $display->uuid(), $wrapper->getAttribute('data-selectable'), 'The root wrapper carries the display\'s selectable key.');
    $this->assertSame('entity_view_display', $wrapper->getAttribute('data-entity-type'));
    $this->assertSame('entity_test.entity_test.default', $wrapper->getAttribute('data-entity-id'));
    $this->assertSame($display->uuid(), $wrapper->getAttribute('data-entity-uuid'));
    $this->assertSame('default', $wrapper->getAttribute('data-daedalus-ui-view-mode'));
    $this->assertSame('kind:entity', $wrapper->getAttribute('data-element'));
    $this->assertContains('daedalus-ui-entity-wrapper', explode(' ', $wrapper->getAttribute('class')));
    $this->assertSame('body', $wrapper->parentNode->nodeName, 'The wrapper is the outermost element of the canvas (a direct child of the parsed body).');

    $roots = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " daedalus-layout-layout ")]');
    $this->assertSame(1, $roots->length, 'The template renders inside exactly one layout root.');
    $root = $roots->item(0);
    $this->assertSame('layout-builder', $root->getAttribute('id'));
    $this->assertSame($wrapper, $root->parentNode, 'The layout root sits directly inside the root wrapper.');
    $this->assertSame(1, $xpath->query('//*[@data-layout-builder-section-delta]')->length, 'The template\'s one section renders.');
  }

  /**
   * A page's blank canvas names the page in its hint.
   *
   * The empty override is seeded into tempstore first, as in
   * testEmptyEditCanvasStillWearsTheLayoutRoot: a page whose sections were
   * all removed, not one core's PrepareLayout would fill from the template.
   */
  public function testBlankCanvasHintNamesThePage(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity']);
    $entity = EntityTest::create([
      'name' => 'Blank page sentinel',
      OverridesSectionStorage::FIELD_NAME => [],
    ]);
    $entity->save();
    $entity = EntityTest::load($entity->id());
    $storage = $this->getNestedStorage($entity);
    $this->assertSame(0, $storage->count());
    $this->container->get('layout_builder.tempstore_repository')->set($storage);

    $this->assertBlankCanvasHint($this->renderEditCanvas($entity), 'Empty page.');
  }

  /**
   * A template's blank canvas names the template in its hint.
   *
   * The display's defaults storage with every section removed, rendered
   * as the canvas root the manage-display layout route builds (the host is
   * the display itself).
   */
  public function testBlankCanvasHintNamesTheTemplate(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity']);
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $display->removeAllSections();
    $display->save();
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $storage = $this->sectionStorageManager->load('defaults', [
      'display' => EntityContext::fromEntity($display),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);
    $this->assertSame(0, $storage->count());

    $html = $this->renderCanvasRoot($storage);
    $this->assertBlankCanvasHint($html, 'Empty template.');
    $this->assertSame('entity_view_display', $this->htmlXpath($html)->query('//*[@data-main-entity]')->item(0)->getAttribute('data-entity-type'), 'The canvas root is the display host.');
  }

  /**
   * A blueprint's blank canvas names the blueprint in its hint.
   *
   * A blueprint composed from scratch ("Add blueprint") has no document;
   * its storage is always its own (never the subject bundle's template,
   * which the display above still carries at this point), so its canvas
   * opens empty and the affordance names the host it serves.
   */
  public function testBlankCanvasHintNamesTheBlueprint(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity']);
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $display->appendSection($this->createSection('layout_onecol', [$this->nameFieldComponent()]));
    $display->save();
    $blueprint = Blueprint::create([
      'type' => 'section',
      'label' => 'Blank',
      'machine_name' => 'uncomposed',
      'scope' => 'block',
      'subject_type' => 'entity_test',
      'subject_bundle' => 'entity_test',
      'status' => 1,
    ]);
    $blueprint->save();
    $storage = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($blueprint)->sectionStorage();
    $this->assertSame(0, $storage->count());
    $this->assertNotEmpty($storage->getRootStorage()->getDefaultSectionStorage()->getSections(), 'The subject bundle\'s display carries a template the canvas must not copy in.');

    $html = $this->renderCanvasRoot($storage);
    $this->assertBlankCanvasHint($html, 'Empty blueprint.');
    $xpath = $this->htmlXpath($html);
    $this->assertSame('blueprint', $xpath->query('//*[@data-main-entity]')->item(0)->getAttribute('data-entity-type'), 'The canvas root is the blueprint host.');
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-section-delta]')->length, 'The blank blueprint canvas renders no section: the template stayed on the display.');
  }

  /**
   * Asserts the blank-page affordance and the host-naming sentence of its hint.
   *
   * The hint's second sentence is the instruction every host shares
   * (daedalus_ai is not installed here, so it is the shorter one), with
   * the Blocks panel's declared hot key in its key cap.
   */
  protected function assertBlankCanvasHint(string $html, string $name): void {
    $xpath = $this->htmlXpath($html);
    $hints = $xpath->query('//button[@id="daedalus-layout-blank-page"]/span[contains(concat(" ", normalize-space(@class), " "), " daedalus-layout-blank-page__hint ")]');
    $this->assertSame(1, $hints->length, 'The blank canvas renders the affordance with one hint.');
    $text = preg_replace('/\s+/', ' ', trim($hints->item(0)->textContent));
    $this->assertSame("$name Drag a block here, or press i for Blocks.", $text, 'The hint names its host and then the shared instruction.');
    $this->assertSame(1, $xpath->query('//span[contains(@class, "daedalus-layout-blank-page__hint")]/kbd[normalize-space(.)="i"]')->length, 'The key cap prints the Blocks panel\'s hot key.');
  }

  /**
   * Renders the edit canvas (the layout_builder_plus element) for an entity.
   */
  protected function renderEditCanvas(EntityTest $entity): string {
    return $this->renderCanvasRoot($this->getNestedStorage($entity));
  }

  /**
   * Renders the layout_builder_plus element over a storage, as the canvas root.
   */
  protected function renderCanvasRoot(SectionStorageInterface $storage): string {
    $build = [
      '#type' => 'layout_builder_plus',
      '#section_storage' => $storage,
    ];
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * A name field block: a root section needs content for onecol to print.
   */
  protected function nameFieldComponent(): SectionComponent {
    return new SectionComponent($this->uuid->generate(), 'content', [
      'id' => 'field_block:entity_test:entity_test:name',
      'label' => 'Name',
      'label_display' => '0',
      'context_mapping' => ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
    ]);
  }

}
