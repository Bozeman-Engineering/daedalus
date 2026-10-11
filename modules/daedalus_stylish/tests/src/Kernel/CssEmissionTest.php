<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_stylish\CssPrinter;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus_stylish\Traits\HostTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests inline <style> delivery inside the entity's rendered markup.
 *
 * The placement is load-bearing: the tag is part of the entity's own
 * build, so it rides the entity's render cache and travels with subtree
 * swaps — no CSS field in any response contract. Styling follows the host:
 * an entity without an override renders its display's template, so the
 * template's map styles it (its element entries once per display per
 * page, its document-level entries re-keyed onto the entity's root), and
 * an overridden entity renders its own.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class CssEmissionTest extends KernelTestBase {

  use HostTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'daedalus_blueprint',
    // daedalus_stylish_entity_view() consults daedalus_ui's mode state for the
    // entity-root live-attribution stamp.
    'block',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    // The section stamp (data-selectable on a rendered section, answered
    // by live attribution demand) is daedalus_layout's display class's.
    'daedalus_layout',
    'daedalus_stylish',
    // The unbound site: this class proves the delivery lanes with literal
    // paddings and gaps, and a never-edited page's render seeds a clone
    // from its template through the style ops (the whole-document copy),
    // which refuse a literal under the suite's scale bindings.
    'daedalus_stylish_unbound_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    // The seed's style ops validate through the materials roster, so the
    // fixture carries the material entity schemas the roster reads.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser([], ['view test entity']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * Renders an entity_test entity to HTML.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   The entity.
   * @param array $build
   *   The rendered build, by reference: its bubbled attachments and cache
   *   metadata after the render.
   */
  protected function renderEntity(EntityTest $entity, array &$build = []): string {
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * The CSS inside an entity's own authoritative style tag.
   */
  protected function inlineStyle(string $html, string $uuid): string {
    preg_match('#<style data-daedalus-stylish-entity="' . preg_quote($uuid, '#') . '">(.*?)</style>#s', $html, $matches);
    return $matches[1] ?? '';
  }

  /**
   * The CSS of the page-head style tag a template render attaches.
   *
   * @return string[]
   *   CSS text keyed by html_head key, for the daedalus_stylish entries.
   */
  protected function headStyles(array $build): array {
    $styles = [];
    foreach ($build['#attached']['html_head'] ?? [] as [$element, $key]) {
      if (str_starts_with((string) $key, 'daedalus_stylish:')) {
        $styles[$key] = (string) $element['#value'];
      }
    }
    return $styles;
  }

  /**
   * A styled entity's markup carries its authoritative <style> tag.
   */
  public function testStyledEntityEmitsStyleTag(): void {
    $entity = EntityTest::create(['name' => 'styled']);
    $entity->save();
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "entity:{$entity->uuid()}" => ['default' => ['padding' => '2rem']],
    ]));
    $entity->save();

    $html = $this->renderEntity($entity);
    $this->assertStringContainsString('<style data-daedalus-stylish-entity="' . $entity->uuid() . '">', $html);
    $this->assertStringContainsString('@scope ([data-selectable="entity:' . $entity->uuid() . '"])', $html);
    $this->assertStringContainsString('padding: 2rem;', $html);
  }

  /**
   * The inline tag co-targets root text styling onto claiming elements.
   *
   * Delivery-level pin of the printer's companion rule: a root entry's
   * inherited text properties re-target declared text-claiming interior
   * elements (the element model's data-claims-content stamp) so root color
   * survives the skin's base:a on a claimed link — while box properties
   * stay on the root only (co-targeted they would double-apply).
   */
  public function testInlineStyleTagCoTargets(): void {
    $entity = EntityTest::create(['name' => 'linked-text']);
    $entity->save();
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "entity:{$entity->uuid()}" => ['default' => ['color' => '#123456', 'padding' => '2rem']],
    ]));
    $entity->save();

    $html = $this->renderEntity($entity);
    $this->assertStringContainsString(':scope ' . CssPrinter::CLAIMS_CONTENT_SELECTOR . ' {', $html);
    $this->assertSame(2, substr_count($html, 'color: #123456;'));
    $this->assertSame(1, substr_count($html, 'padding: 2rem;'));
  }

  /**
   * Unstyled entities render byte-identical to a world without Daedalus Stylish.
   */
  public function testUnstyledEntityEmitsNothing(): void {
    $entity = EntityTest::create(['name' => 'plain']);
    $entity->save();
    $this->assertStringNotContainsString('<style', $this->renderEntity($entity));
  }

  /**
   * Container conditions ship the ONE page-level width-band query root.
   *
   * Container queries never apply without an ancestor query container, so
   * when (and only when) the map styles under a container condition, the
   * backend emits the constant page-container rule
   * (CssPrinter::PAGE_CONTAINER_RULE). Never per-element roots: width
   * bands measure the page everywhere (edit mode's posed stage wins by
   * nearest-ancestor resolution), so no entity or container selector may
   * appear in the containment rule.
   */
  public function testContainerRoots(): void {
    // A Layout Builder override so the entity carries sections of its own,
    // proving they are NOT enumerated as query containers.
    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')->create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->setOverridable()->save();

    $section_uuid = '77777777-7777-7777-7777-777777777777';
    $section = new Section('layout_onecol', [], [
      new SectionComponent('66666666-6666-6666-6666-666666666666', 'content', ['id' => 'system_powered_by_block']),
    ], ['daedalus_layout' => ['uuid' => $section_uuid]]);
    $entity = EntityTest::create(['name' => 'container-styled']);
    $entity->set('layout_builder__layout', [['section' => $section]]);
    $entity->save();

    $backend = $this->container->get('daedalus_stylish.style_backend');
    $host = $this->host($entity);
    $map = ["block:66666666-6666-6666-6666-666666666666" => ['narrow' => ['padding' => '1rem']]];
    $css = (string) $backend->build($host, $map)['#value'];
    $this->assertStringContainsString(CssPrinter::PAGE_CONTAINER_RULE, $css);
    // ONE constant rule: no per-element containment anywhere.
    $this->assertSame(1, substr_count($css, 'container-type'));
    $this->assertStringNotContainsString("entity:{$entity->uuid()}\"] {", $css);
    $this->assertStringNotContainsString("container:$section_uuid", $css);
    $this->assertStringNotContainsString('region:', $css);

    // No container conditions in the map: no containment, exact theme
    // layout preserved (the probed hazards, fixed-position
    // capture by the canvas container, stay untouched).
    $css = (string) $backend->build($host, [
      "block:66666666-6666-6666-6666-666666666666" => [
        'default' => ['padding' => '1rem'],
        'dark' => ['padding' => '0.5rem'],
      ],
    ])['#value'];
    $this->assertStringNotContainsString('container-type', $css);

    // The kill switch for themes that manage their own containment.
    $this->config('daedalus_stylish.settings')->set('container_roots', FALSE)->save();
    $css = (string) $backend->build($host, $map)['#value'];
    $this->assertStringNotContainsString('container-type', $css);
  }

  /**
   * An entity without an override renders its display's map.
   *
   * The template's element entries (the container, the block) ride the
   * page head once per display, tagged with the display's config so a
   * template style save rebuilds every un-overridden page; its
   * document-level entry re-keys onto the rendered entity's own root,
   * inline, where the entity's own root styles layer over it. The section
   * is stamped for a visitor because the template demands it.
   *
   * The inline tag also prints the SEEDED keys: a never-edited page's
   * host seeds its empty override from the template on the first read of
   * either document door (derived identity, styles re-keyed), which is
   * what the edit canvas renders the page under on arrival, so delivery
   * reads the page through a host over a disposable clone and prints the
   * template's element entries under the derived keys too. The request's
   * entity stays as loaded: a later save persists no document.
   */
  public function testUnoverriddenEntityRendersTheDisplayMap(): void {
    $section_uuid = '55555555-5555-5555-5555-555555555555';
    $block_uuid = '44444444-4444-4444-4444-444444444444';
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->setOverridable();
    $display->appendSection(new Section('layout_onecol', [], [
      new SectionComponent($block_uuid, 'content', ['id' => 'system_powered_by_block']),
    ], ['daedalus_layout' => ['uuid' => $section_uuid]]));
    // Through the host: the display keeps its map as config rows.
    $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display)->setStyleMap([
      "entity:{$display->uuid()}" => ['default' => ['padding' => '2rem', 'color' => '#123456']],
      "entity:{$display->uuid()}::.daedalus-layout-layout" => ['default' => ['gap' => '1rem']],
      "container:$section_uuid" => ['default' => ['margin' => '1rem']],
      "block:$block_uuid" => ['default' => ['color' => 'teal']],
    ]);
    $display->save();

    $entity = EntityTest::create(['name' => 'templated']);
    $entity->save();
    $build = [];
    $html = $this->renderEntity($entity, $build);

    // The shared lane: once per display per page, the display's tag.
    $head = $this->headStyles($build);
    $this->assertSame(['daedalus_stylish:entity_view_display:entity_test.entity_test.default'], array_keys($head));
    $shared = reset($head);
    $this->assertStringContainsString("@scope ([data-selectable=\"container:$section_uuid\"])", $shared);
    $this->assertStringContainsString('margin: 1rem;', $shared);
    $this->assertStringContainsString("@scope ([data-selectable=\"block:$block_uuid\"])", $shared);
    $this->assertStringContainsString('color: teal;', $shared);
    $this->assertStringNotContainsString('entity:', $shared);
    $this->assertContains('config:core.entity_view_display.entity_test.entity_test.default', $build['#cache']['tags']);

    // The seeded keys, inline: the template's element entries under the
    // identity the seed derives for this page, never under the template's
    // own keys (those are the shared lane's), and nothing landed on the
    // request's entity.
    $seeded_section = DocumentInstantiator::derivedUuid($entity->uuid(), $section_uuid);
    $seeded_block = DocumentInstantiator::derivedUuid($entity->uuid(), $block_uuid);
    $inline = $this->inlineStyle($html, $entity->uuid());
    $this->assertStringContainsString("@scope ([data-selectable=\"container:$seeded_section\"])", $inline);
    $this->assertStringContainsString('margin: 1rem;', $inline);
    $this->assertStringContainsString("@scope ([data-selectable=\"block:$seeded_block\"])", $inline);
    $this->assertStringContainsString('color: teal;', $inline);
    $this->assertStringNotContainsString("container:$section_uuid", $inline, "The template's own keys print in the shared lane alone.");
    $this->assertTrue($entity->get(OverridesSectionStorage::FIELD_NAME)->isEmpty(), 'The render seeded nothing onto the request entity.');
    $this->assertTrue($entity->get(DaedalusStylishFieldManager::FIELD_NAME)->isEmpty(), 'The render wrote no styles onto the request entity.');

    // The inline lane: the template's document-level entries on THIS
    // entity's root, under its own key (never the display's).
    $this->assertStringContainsString('<style data-daedalus-stylish-entity="' . $entity->uuid() . '">', $html);
    $this->assertStringContainsString('@scope ([data-selectable="entity:' . $entity->uuid() . '"])', $html);
    $this->assertStringContainsString('padding: 2rem;', $html);
    $this->assertStringContainsString('gap: 1rem;', $html);
    $this->assertStringNotContainsString($display->uuid(), $html);
    $this->assertStringContainsString('data-selectable="entity:' . $entity->uuid() . '"', $html);

    // The visitor's markup carries the stamped section the shared CSS
    // targets: demand resolved through the render host.
    $this->assertStringContainsString("data-selectable=\"container:$section_uuid\"", $html);

    // The entity's own root entry layers over the template's: its color
    // wins, the template's padding stays.
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "entity:{$entity->uuid()}" => ['default' => ['color' => '#654321']],
    ]));
    $entity->save();
    $html = $this->renderEntity($entity, $build);
    $this->assertStringContainsString('padding: 2rem;', $html);
    $this->assertStringNotContainsString('#123456', $html);
    $this->assertSame(2, substr_count($html, 'color: #654321;'), 'The layered root color co-targets like any root color.');
    // Still a template render: the saved page comes back un-overridden,
    // so the first render seeded nothing it could persist.
    $this->assertSame(array_keys($head), array_keys($this->headStyles($build)), 'The saved page still renders its template.');
    $this->assertStringContainsString("@scope ([data-selectable=\"container:$seeded_section\"])", $this->inlineStyle($html, $entity->uuid()), 'The seeded keys print on the second render too.');
    $this->container->get('entity_type.manager')->getStorage('entity_test')->resetCache([$entity->id()]);
    $this->assertTrue(EntityTest::load($entity->id())->get(OverridesSectionStorage::FIELD_NAME)->isEmpty(), 'The entity comes back NOT overridden.');

    // A second un-overridden entity on the same page attaches the same
    // head key: the page deduplicates it to one tag.
    $other = EntityTest::create(['name' => 'templated too']);
    $other->save();
    $other_build = [];
    $this->renderEntity($other, $other_build);
    $this->assertSame(array_keys($head), array_keys($this->headStyles($other_build)));
  }

  /**
   * An overridden entity renders its own map, never the template's.
   */
  public function testOverriddenEntityRendersItsOwnMap(): void {
    $template_section = '55555555-5555-5555-5555-555555555555';
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->setOverridable();
    $display->appendSection(new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $template_section]]));
    $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display)->setStyleMap([
      "entity:{$display->uuid()}" => ['default' => ['padding' => '2rem']],
      "container:$template_section" => ['default' => ['margin' => '1rem']],
    ]);
    $display->save();

    $own_section = '77777777-7777-7777-7777-777777777777';
    $entity = EntityTest::create(['name' => 'overridden']);
    $entity->set('layout_builder__layout', [
      ['section' => new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $own_section]])],
    ]);
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "container:$own_section" => ['default' => ['margin' => '3rem']],
    ]));
    $entity->save();

    $build = [];
    $html = $this->renderEntity($entity, $build);
    $this->assertSame([], $this->headStyles($build), 'An overridden entity attaches no template tag.');
    $this->assertStringContainsString("@scope ([data-selectable=\"container:$own_section\"])", $html);
    $this->assertStringContainsString('margin: 3rem;', $html);
    $this->assertStringNotContainsString('margin: 1rem;', $html);
    $this->assertStringNotContainsString('padding: 2rem;', $html);
  }

  /**
   * A page holding core's blank marker wears none of the template's styles.
   *
   * Core's layout_builder_blank section marks a layout purposefully
   * emptied (a blank start, or an editor removing the last section), not
   * one never instantiated: core counts it as an override, so the page
   * renders its own empty document, and its styles are its own alone:
   * no shared lane (no template tag in the head), no template root entry
   * re-keyed onto it, and no seed on the request entity or on a clone of
   * it. The marker is what the field holds after the render, and after a
   * save and a reload.
   */
  public function testMarkerPageWearsNoneOfTheTemplatesStyles(): void {
    $template_section = '55555555-5555-5555-5555-555555555555';
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->setOverridable();
    $display->appendSection(new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $template_section]]));
    $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display)->setStyleMap([
      "entity:{$display->uuid()}" => ['default' => ['padding' => '2rem']],
      "container:$template_section" => ['default' => ['margin' => '1rem']],
    ]);
    $display->save();

    $entity = EntityTest::create(['name' => 'started, and empty']);
    $entity->set(OverridesSectionStorage::FIELD_NAME, [new Section('layout_builder_blank')]);
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "entity:{$entity->uuid()}" => ['default' => ['color' => '#654321']],
    ]));
    $entity->save();

    $build = [];
    $html = $this->renderEntity($entity, $build);
    $this->assertSame([], $this->headStyles($build), 'A marker page attaches no template tag: its document is its own.');
    $this->assertStringContainsString('color: #654321;', $html, 'Its own root entry styles it.');
    $this->assertStringNotContainsString('padding: 2rem;', $html, "The template's root entry does not re-key onto it.");
    $this->assertStringNotContainsString('margin: 1rem;', $html, "The template's element entries do not reach it.");
    $this->assertStringNotContainsString("container:$template_section", $html);
    $this->assertStringNotContainsString($display->uuid(), $html);

    $sections = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $this->assertCount(1, $sections, 'The render seeded nothing onto the request entity.');
    $this->assertSame('layout_builder_blank', reset($sections)->getLayoutId(), 'The marker is what the field holds.');
    $this->container->get('entity_type.manager')->getStorage('entity_test')->resetCache([$entity->id()]);
    $reloaded = EntityTest::load($entity->id())->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $this->assertCount(1, $reloaded);
    $this->assertSame('layout_builder_blank', reset($reloaded)->getLayoutId(), 'The marker survives the save: the page stays empty.');
  }

  /**
   * The tag prints the map in document order, whatever order it was written.
   *
   * The map's one canonical order (NodeTreeBuilder::styleMapOf()): the
   * node-less keys first (the root entity and its fields), then the
   * document's nodes depth-first, a container before its children, a
   * node's own keys in their stored order. Storage keeps write order;
   * delivery never echoes it. The client derives the same order from the
   * mirror's tree (PrinterEquivalenceTest locks the bytes), so the inline
   * tag and the live preview agree for a map written backwards.
   */
  public function testStyleTagPrintsDocumentOrder(): void {
    $first_section = '11111111-1111-1111-1111-111111111111';
    $second_section = '22222222-2222-2222-2222-222222222222';
    $block_uuid = '44444444-4444-4444-4444-444444444444';
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->setOverridable()->save();

    $entity = EntityTest::create(['name' => 'scrambled']);
    $entity->set('layout_builder__layout', [
      [
        'section' => new Section('layout_onecol', [], [
          new SectionComponent($block_uuid, 'content', ['id' => 'system_powered_by_block']),
        ], ['daedalus_layout' => ['uuid' => $first_section]]),
      ],
      ['section' => new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $second_section]])],
    ]);
    // Written backwards: the last node first, the root last, a part
    // before its root.
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "container:$second_section" => ['default' => ['margin' => '3rem']],
      "block:$block_uuid" => ['default' => ['color' => 'teal']],
      "container:$first_section::.daedalus-stylish-layout-container" => ['default' => ['max-width' => '60rem']],
      "container:$first_section" => ['default' => ['margin' => '1rem']],
      "entity:{$entity->uuid()}" => ['default' => ['padding' => '2rem']],
    ]));
    $entity->save();

    $html = $this->renderEntity($entity);
    $roots = [];
    preg_match_all('/@scope \(\[data-selectable="([^"]+)"\]\)/', $html, $roots);
    $this->assertSame([
      "entity:{$entity->uuid()}",
      "container:$first_section",
      "container:$first_section",
      "block:$block_uuid",
      "container:$second_section",
    ], $roots[1]);
    // The first section's own keys keep their stored order: the part
    // before the root entry.
    $this->assertLessThan(strpos($html, 'margin: 1rem;'), strpos($html, 'max-width: 60rem;'));
    // Storage is untouched: the field still holds the write order.
    $this->assertSame(
      [
        "container:$second_section",
        "block:$block_uuid",
        "container:$first_section::.daedalus-stylish-layout-container",
        "container:$first_section",
        "entity:{$entity->uuid()}",
      ],
      array_keys($this->host(EntityTest::load($entity->id()))->styleMap()),
    );
  }

  /**
   * Live mode: variant-reference-only maps emit no tag.
   *
   * That CSS ships in the compiled variant stylesheet instead. (Edit mode
   * resolves references inline at their key roots; VariantStylesheetTest covers
   * it.)
   */
  public function testVariantOnlyMapEmitsNothing(): void {
    $entity = EntityTest::create(['name' => 'on-variant']);
    $entity->save();
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "block:{$entity->uuid()}" => ['variants' => [['variant' => 'cta_bold', 'fragment' => '']]],
    ]));
    $entity->save();
    $this->assertStringNotContainsString('<style', $this->renderEntity($entity));
  }

}
