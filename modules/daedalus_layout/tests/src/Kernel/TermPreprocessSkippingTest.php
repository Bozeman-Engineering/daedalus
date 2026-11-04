<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * The edit-mode canvas keeps the host's field keys for core's preprocess.
 *
 * In edit mode the canvas (the layout_builder_plus element) takes the place
 * of the route entity's fields in its own view build
 * (EditableUiBuilder::onUiBuild()). Core's entity preprocess hooks read
 * base-field elements by key and some read them unguarded:
 * TaxonomyThemeHooks::preprocessTaxonomyTerm() assigns
 * $variables['elements']['name'] at TaxonomyThemeHooks.php:83 (core issue
 * 3015623), so a build the canvas had emptied of its field KEYS warned
 * "Undefined array key" on every edit-mode render of a term. The canvas
 * therefore sets every present field key to an empty render array rather
 * than dropping it: the preprocess finds what the contract promises and the
 * empty child renders nothing.
 *
 * The render here goes through the real edit-mode path, not the plain view
 * builder: a term on its canonical route with the navigationMode cookie,
 * the page builder on for its vocabulary, an editor with edit-mode access.
 * Core's phpunit.xml.dist runs with failOnWarning, so a green run of the
 * render IS the assertion that no preprocess warning fired; swapping the
 * canvas back to dropping the keys turns this class red (Warnings: 1).
 *
 * @see \Drupal\daedalus_layout\EventSubscriber\EditableUiBuilder::onUiBuild()
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class TermPreprocessSkippingTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'taxonomy',
  ];

  /**
   * The term rendered on its canonical route.
   */
  private Term $term;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['taxonomy']);

    // The page builder is a bundle switch (ShouldNotEditMode reads the
    // bundle entity's daedalus_ui `enabled` setting); off, the canvas is
    // appended beside the fields and nothing is emptied.
    Vocabulary::create([
      'vid' => 'tags',
      'name' => 'Tags',
    ])
      ->setThirdPartySetting('daedalus_ui', 'enabled', TRUE)
      ->save();

    // A Layout Builder display with one section; overridable, so the term
    // carries the override field the content host's document lives in
    // (ContentHost::hasDocument()): without one the host has no section
    // storage and the canvas never builds.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'taxonomy_term',
      'bundle' => 'tags',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->setThirdPartySetting('daedalus_layout', 'default_section', [
        'layout_plugin' => 'layout_onecol',
        'label' => '',
      ])
      ->appendSection($this->createSection('layout_onecol'))
      ->save();

    $this->term = Term::create([
      'vid' => 'tags',
      'name' => 'News',
    ]);
    $this->term->save();

    // An editor: edit-mode access, and update access on the term (the
    // capability spine's answer to whether Edit Mode applies to the host).
    $this->setUpCurrentUser([], [
      'use daedalus ui edit mode',
      'configure any layout',
      'access content',
      'edit terms in tags',
    ]);
  }

  /**
   * The canvas takes the term's fields' place; core's preprocess finds them.
   */
  public function testEditModeCanvasKeepsTheFieldKeys(): void {
    $this->onCanonicalRoute();

    // The view builder's #pre_render callback, run by hand so the altered
    // build can be read before it renders: hook_entity_view stamps the
    // main-entity root, hook_entity_view_alter dispatches the
    // EditableUiBuilder event and the canvas takes the fields' place.
    $view_builder = $this->entityTypeManager->getViewBuilder('taxonomy_term');
    assert($view_builder instanceof EntityViewBuilder);
    $build = $view_builder->build($view_builder->view($this->term, 'full'));

    // The render first: core's taxonomy_term preprocess runs inside it, and
    // with failOnWarning its unguarded read of elements['name'] fails the
    // test (PHPUnit "Warnings: 1") the moment the key is gone. A copy
    // renders, so the altered build is still readable below; it is already
    // built, and rendering it through #pre_render again would rebuild the
    // fields and alter them away a second time.
    $render = $build;
    unset($render['#pre_render']);
    $html = (string) $this->container->get('renderer')->renderRoot($render);
    $xpath = $this->htmlXpath($html);

    $this->assertSame(1, $xpath->query('//*[@data-main-entity="true"]')->length, 'The term renders as the one main-entity root.');
    $this->assertSame(1, $xpath->query('//*[@id="layout-builder"]')->length, 'The canvas element renders.');
    $this->assertSame(1, $xpath->query('//*[@data-main-entity="true"]//*[@id="layout-builder"]')->length, 'The canvas is inside the entity root, where the fields were.');
    $this->assertStringNotContainsString('News', $html, 'The kept name key renders nothing (the full view mode prints no heading either).');

    // The build the canvas left behind: the fields' keys stay, empty.
    $this->assertSame('true', $build['#attributes']['data-main-entity'] ?? NULL, 'The route entity\'s build wears the main-entity root the canvas builds on.');
    $this->assertSame('layout_builder_plus', $build['layout']['#type'] ?? NULL, 'The canvas took the fields\' place in the entity build.');
    $this->assertArrayNotHasKey('_layout_builder', $build, 'The view render\'s section list left with the fields.');
    $this->assertArrayHasKey('name', $build, 'The name key stays: core\'s term preprocess reads elements[name] by key.');
    $this->assertSame([], $build['name'], 'The kept field is an empty render array: it renders nothing.');
  }

  /**
   * Puts the render on the term's canonical route, in Edit Mode.
   *
   * The main-entity stamp (DaedalusUiHooks::entityView()) and the edit
   * alter (EntityViewAlter::alter()) are the ROUTE host's facts: both ask
   * DaedalusUi::deriveHostFromRoute(), and the mode is the navigationMode
   * cookie (ModeState::getMode()).
   */
  private function onCanonicalRoute(): void {
    $request_stack = $this->container->get('request_stack');
    $request = Request::create('/taxonomy/term/' . $this->term->id(), 'GET', [], ['navigationMode' => 'edit']);
    $request->setSession($request_stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.taxonomy_term.canonical');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/taxonomy/term/{taxonomy_term}'));
    $request->attributes->set('taxonomy_term', $this->term);
    $request_stack->push($request);
    $this->container->get('daedalus_ui.ui')->clearDerivedHost();
  }

}
