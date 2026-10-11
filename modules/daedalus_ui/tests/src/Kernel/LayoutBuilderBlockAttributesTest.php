<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_ui\Presentation\Presentation;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the placement attributes a Layout Builder component render carries.
 *
 * The field-block half of the element model's one answer: a field block
 * placement renders one root field inside itself (the placement wrapper,
 * block:{uuid}, around the field's own render, field:{root}:{name}, the
 * same leaf the display's own render of the field carries), so for an
 * editor it carries the MERGED presentation the model decides for it and
 * names the field it presents (data-presented-field, the field's
 * selectable key). The canvas resolver promotes the inner leaf's edit to
 * the placement and the panel folds the two rows as one, exactly as the
 * delivered annotation says
 * (BlueprintElementAnnotatorTest::testFieldBlockAnnotation, the other
 * carrier). A plain plugin block carries neither; a visitor's render
 * carries neither.
 *
 * The portal half: a linked placement shows a blueprint by reference, and
 * its root wears the one projection of the spine's showing
 * (LayoutBuilderBlockAttributes::stampShowing): the blueprint's editing
 * url, its label and its kind word ("Blueprint", DaedalusUi::hostKind, the
 * word the chip reads into "Card · blueprint"), and no claim (a linked
 * placement's Detach is a verb of its own). The frame's and the
 * template's words and the door's claim are pinned where they render,
 * daedalus_layout's PageVariantTest.
 *
 * @group daedalus_ui
 */
#[RunTestsInSeparateProcesses]
class LayoutBuilderBlockAttributesTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The field block placement's component uuid.
   */
  private const FIELD_BLOCK = 'aaaaaaaa-1111-2222-3333-444444444444';

  /**
   * The plugin block placement's component uuid.
   */
  private const PLUGIN_BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';

  /**
   * The linked blueprint placement's component uuid.
   */
  private const LINKED_BLOCK = 'cccccccc-1111-2222-3333-444444444444';

  /**
   * The component uuid of the block inside the linked blueprint's document.
   */
  private const INNER_BLOCK = 'dddddddd-1111-2222-3333-444444444444';

  /**
   * {@inheritdoc}
   *
   * The module's hard dependencies (daedalus_blueprint +
   * daedalus_blueprint_api) are invisible to kernel environments and must
   * be listed explicitly or the container fails to compile.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'entity_test',
    // The block template the placement attributes ride (block.html.twig).
    'block',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
  ];

  /**
   * The rendered entity: a name field block and a plugin block in one section.
   */
  private EntityTest $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['system']);

    // An overridable Layout Builder display: the overrides field is what
    // the per-entity layout below is stored in.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();

    $section = new Section('layout_onecol');
    $section->appendComponent(new SectionComponent(self::FIELD_BLOCK, 'content', [
      'id' => 'field_block:entity_test:entity_test:name',
      'label' => 'Name',
      'label_display' => '0',
      'context_mapping' => ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
    ]));
    $section->appendComponent(new SectionComponent(self::PLUGIN_BLOCK, 'content', [
      'id' => 'system_powered_by_block',
      'label' => 'Powered by',
      'label_display' => '0',
    ]));
    $this->entity = EntityTest::create([
      'name' => 'Placed name',
      OverridesSectionStorage::FIELD_NAME => [$section],
    ]);
    $this->entity->save();
  }

  /**
   * Renders the entity's default view and returns an XPath over it.
   */
  private function renderEntity(): \DOMXPath {
    $build = $this->container->get('entity_type.manager')
      ->getViewBuilder('entity_test')
      ->view($this->entity, 'default');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new \DOMXPath($dom);
  }

  /**
   * The one element carrying a selectable key.
   */
  private function selectable(\DOMXPath $xpath, string $key): \DOMElement {
    $nodes = $xpath->query(sprintf('//*[@data-selectable="%s"]', $key));
    $this->assertSame(1, $nodes->length, "Exactly one element answers to $key in: " . $xpath->document->saveHTML());
    return $nodes->item(0);
  }

  /**
   * Puts the render on the entity's canonical route, in Edit Mode.
   *
   * The presented field and the portal are the ROUTE host's facts: the
   * render runs on the entity's canonical route, the canvas.
   */
  private function onCanonicalRoute(): void {
    $request_stack = $this->container->get('request_stack');
    $request = Request::create('/entity_test/' . $this->entity->id(), 'GET', [], ['navigationMode' => 'edit']);
    $request->setSession($request_stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.entity_test.canonical');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/entity_test/{entity_test}'));
    $request->attributes->set('entity_test', $this->entity);
    $request_stack->push($request);
    $this->container->get('daedalus_ui.ui')->clearDerivedHost();
  }

  /**
   * An editor's field block placement presents merged into its field.
   */
  public function testFieldBlockPlacementPresentsItsField(): void {
    $this->setUpCurrentUser([], ['use daedalus ui edit mode', 'view test entity']);
    $this->onCanonicalRoute();
    $xpath = $this->renderEntity();

    $field_key = 'field:' . $this->entity->uuid() . ':name';
    $placement = $this->selectable($xpath, 'block:' . self::FIELD_BLOCK);
    $this->assertSame(Presentation::MERGED, $placement->getAttribute(Presentation::ATTRIBUTE), 'The placement presents merged into the field it renders.');
    $this->assertSame($field_key, $placement->getAttribute(Presentation::PRESENTED_FIELD_ATTRIBUTE), 'The placement names the root field it presents by its selectable key.');

    // The field's own render sits inside the placement, keyed exactly as the
    // display's own render of it: the leaf the placement's merged fold
    // routes to.
    $this->assertSame(1, $xpath->query(sprintf('.//*[@data-selectable="%s"]', $field_key), $placement)->length, 'The field render is inside the placement, under the field\'s own key.');
    // Core's own Layout Builder display (no daedalus_layout here) also
    // renders the name field beside the layout: the duplicate-key case, one
    // key on two elements (core's own display rendering a field beside a
    // field block placing it).
    // The canvas resolves it to the first element in document order; the
    // stamp is the same on each.
    $this->assertSame(2, $xpath->query(sprintf('//*[@data-selectable="%s"]', $field_key))->length, 'The display render and the placed render both answer to the field key.');

    // A plain plugin block carries the placement key only.
    $plugin = $this->selectable($xpath, 'block:' . self::PLUGIN_BLOCK);
    $this->assertFalse($plugin->hasAttribute(Presentation::ATTRIBUTE), 'A plugin block presents open: nothing stamped.');
    $this->assertFalse($plugin->hasAttribute(Presentation::PRESENTED_FIELD_ATTRIBUTE), 'A plugin block presents no field.');
  }

  /**
   * A linked placement wears the shown blueprint's url, label and kind.
   */
  public function testLinkedPlacementWearsThePortalAndItsKind(): void {
    // The library for the blueprint entity a linked placement shows.
    $this->enableModules(['options', 'image', 'media', 'daedalus_blueprint_library']);
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['image', 'file', 'media', 'daedalus_blueprint_library']);
    // The admin may open the blueprint: the portal rides.
    $this->setUpCurrentUser([], [], TRUE);
    $blueprint = Blueprint::create([
      'type' => 'section',
      'label' => 'Card',
      'machine_name' => 'card',
      'description' => 'Shown by reference.',
      'scope' => 'block',
      'subject_type' => 'entity_test',
      'subject_bundle' => 'entity_test',
      'status' => 1,
      ContentHost::LAYOUT_FIELD => [
        (new Section('layout_onecol'))->appendComponent(new SectionComponent(self::INNER_BLOCK, 'content', [
          'id' => 'system_powered_by_block',
          'label' => 'Inside the card',
          'label_display' => '0',
        ])),
      ],
    ]);
    $blueprint->save();
    // The linked derivative exists once the saved blueprint is published.
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();
    $section = new Section('layout_onecol');
    $section->appendComponent(new SectionComponent(self::PLUGIN_BLOCK, 'content', [
      'id' => 'system_powered_by_block',
      'label' => 'Powered by',
      'label_display' => '0',
    ]));
    $section->appendComponent(new SectionComponent(self::LINKED_BLOCK, 'content', [
      'id' => LinkedBlueprintBlock::pluginId('card'),
      'label_display' => '0',
      'context_mapping' => LinkedBlueprintBlock::CONTEXT_MAPPING,
    ]));
    $this->entity->set(OverridesSectionStorage::FIELD_NAME, [$section])->save();
    $this->onCanonicalRoute();
    $xpath = $this->renderEntity();

    $linked = $this->selectable($xpath, 'block:' . self::LINKED_BLOCK);
    $this->assertSame($blueprint->toUrl()->toString(), $linked->getAttribute('data-daedalus-ui-portal-url'), 'The linked placement is a portal to the blueprint\'s canvas: ' . $xpath->document->saveHTML());
    $this->assertSame('Card', $linked->getAttribute('data-daedalus-ui-portal-label'), 'Named by the blueprint.');
    $this->assertSame('Blueprint', $linked->getAttribute('data-daedalus-ui-portal-type'), 'Typed by what it shows: the word the chip reads into "Card · blueprint".');
    $this->assertFalse($linked->hasAttribute(SlotBlock::CLAIM_ATTRIBUTE), 'A linked placement is never claimed: its Detach is a verb.');
    // The node inside the shown document is inside the placement's own
    // portal and wears none of its own.
    $inner = $xpath->query('//*[@data-layout-builder-block-uuid="' . self::INNER_BLOCK . '"]');
    $this->assertSame(1, $inner->length, 'The blueprint\'s block renders inside the placement.');
    $this->assertFalse($inner->item(0)->hasAttribute('data-daedalus-ui-portal-url'));
    $this->assertFalse($inner->item(0)->hasAttribute('data-daedalus-ui-portal-type'));
    // The page's own placement is no portal: the kind rides the portal alone.
    $this->assertFalse($this->selectable($xpath, 'block:' . self::PLUGIN_BLOCK)->hasAttribute('data-daedalus-ui-portal-type'));
    $this->assertSame(1, $xpath->query('//*[@data-daedalus-ui-portal-type]')->length, 'One portal on the page, one kind word.');
  }

  /**
   * A visitor's render carries no presentation and no presented field.
   */
  public function testVisitorRenderCarriesNoStamp(): void {
    $this->setUpCurrentUser([], ['view test entity']);
    $xpath = $this->renderEntity();

    $this->assertSame(0, $xpath->query('//*[@' . Presentation::PRESENTED_FIELD_ATTRIBUTE . ']')->length, 'The presented field is an editor\'s fact.');
    $this->assertSame(0, $xpath->query('//*[@' . Presentation::ATTRIBUTE . ']')->length, 'No presentation rides a visitor\'s render.');
    $this->assertSame(0, $xpath->query('//*[@data-selectable="block:' . self::FIELD_BLOCK . '"]')->length, 'No selection identity rides a visitor\'s render without demand.');
  }

}
