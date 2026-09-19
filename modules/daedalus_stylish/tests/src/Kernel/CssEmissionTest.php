<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_stylish\CssPrinter;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests inline <style> delivery inside the entity's rendered markup.
 *
 * The placement is load-bearing: the tag is part of the entity's own
 * build, so it rides the entity's render cache and travels with subtree
 * swaps — no CSS field in any response contract.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class CssEmissionTest extends KernelTestBase {

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
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser([], ['view test entity']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * Renders an entity_test entity to HTML.
   */
  protected function renderEntity(EntityTest $entity): string {
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
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
    $map = ["block:66666666-6666-6666-6666-666666666666" => ['narrow' => ['padding' => '1rem']]];
    $css = (string) $backend->build($entity, $map)['#value'];
    $this->assertStringContainsString(CssPrinter::PAGE_CONTAINER_RULE, $css);
    // ONE constant rule: no per-element containment anywhere.
    $this->assertSame(1, substr_count($css, 'container-type'));
    $this->assertStringNotContainsString("entity:{$entity->uuid()}\"] {", $css);
    $this->assertStringNotContainsString("container:$section_uuid", $css);
    $this->assertStringNotContainsString('region:', $css);

    // No container conditions in the map: no containment, exact theme
    // layout preserved (the probed hazards, fixed-position
    // capture by the canvas container, stay untouched).
    $css = (string) $backend->build($entity, [
      "block:66666666-6666-6666-6666-666666666666" => [
        'default' => ['padding' => '1rem'],
        'dark' => ['padding' => '0.5rem'],
      ],
    ])['#value'];
    $this->assertStringNotContainsString('container-type', $css);

    // The kill switch for themes that manage their own containment.
    $this->config('daedalus_stylish.settings')->set('container_roots', FALSE)->save();
    $css = (string) $backend->build($entity, $map)['#value'];
    $this->assertStringNotContainsString('container-type', $css);
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
