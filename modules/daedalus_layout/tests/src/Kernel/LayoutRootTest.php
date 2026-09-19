<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\SectionComponent;
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
 * has one, matching the model's unconditional presence answer.
 *
 * @see \Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay::buildSections()
 * @see \Drupal\daedalus_layout\Element\LayoutBuilderPlus::layout()
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class LayoutRootTest extends NestedStorageKernelTestBase {

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
   * display: grid rides the declared-defaults tier (declared defaults
   * follow markup ownership: the entity kind may default the anatomy it
   * declares).
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
      ['default' => ['display' => 'grid']],
      $entity['defaults']['::' . $selector] ?? NULL,
      'The layout root defaults to a grid: the arranging box fact ships with the markup, not the skin.',
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
   * Renders the edit canvas (the layout_builder_plus element) for an entity.
   */
  protected function renderEditCanvas(EntityTest $entity): string {
    $build = [
      '#type' => 'layout_builder_plus',
      '#section_storage' => $this->getNestedStorage($entity),
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
