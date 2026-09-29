<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\daedalus_ui\Presentation\Presentation;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the placement attributes a Layout Builder component render carries.
 *
 * The field-block half of the element model's one answer: a field block
 * placement renders one root field inside itself (the placement wrapper,
 * block:{uuid}, around the field's own render, field:{root}:{name}, the
 * same leaf a preserved field renders), so for an editor it carries the
 * MERGED presentation the model decides for it and names the field it
 * presents (data-presented-field, the field's selectable key). The canvas
 * resolver promotes the inner leaf's edit to the placement and the panel
 * folds the two rows as one, exactly as the delivered annotation says
 * (BlueprintElementAnnotatorTest::testFieldBlockAnnotation, the other
 * carrier). A plain plugin block carries neither; a visitor's render
 * carries neither.
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
   * An editor's field block placement presents merged into its field.
   */
  public function testFieldBlockPlacementPresentsItsField(): void {
    $this->setUpCurrentUser([], ['use daedalus ui edit mode', 'view test entity']);
    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    $xpath = $this->renderEntity();

    $field_key = 'field:' . $this->entity->uuid() . ':name';
    $placement = $this->selectable($xpath, 'block:' . self::FIELD_BLOCK);
    $this->assertSame(Presentation::MERGED, $placement->getAttribute(Presentation::ATTRIBUTE), 'The placement presents merged into the field it renders.');
    $this->assertSame($field_key, $placement->getAttribute(Presentation::PRESENTED_FIELD_ATTRIBUTE), 'The placement names the root field it presents by its selectable key.');

    // The field's own render sits inside the placement, keyed exactly as a
    // preserved field's: the leaf the placement's merged fold routes to.
    $this->assertSame(1, $xpath->query(sprintf('.//*[@data-selectable="%s"]', $field_key), $placement)->length, 'The field render is inside the placement, under the field\'s own key.');
    // Core's own Layout Builder display (no daedalus_layout here) also
    // renders the name field beside the layout: the duplicate-key case, one
    // key on two elements (a preserved field also placed as a field block).
    // The canvas resolves it to the first element in document order; the
    // stamp is the same on each.
    $this->assertSame(2, $xpath->query(sprintf('//*[@data-selectable="%s"]', $field_key))->length, 'The display render and the placed render both answer to the field key.');

    // A plain plugin block carries the placement key only.
    $plugin = $this->selectable($xpath, 'block:' . self::PLUGIN_BLOCK);
    $this->assertFalse($plugin->hasAttribute(Presentation::ATTRIBUTE), 'A plugin block presents open: nothing stamped.');
    $this->assertFalse($plugin->hasAttribute(Presentation::PRESENTED_FIELD_ATTRIBUTE), 'A plugin block presents no field.');
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
