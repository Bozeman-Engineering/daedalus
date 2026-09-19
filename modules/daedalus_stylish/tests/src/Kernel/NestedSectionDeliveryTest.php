<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\CssPrinter;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests live style delivery for nested sections (embedded containers).
 *
 * Under sections-all-the-way-down a nested section stamps its OWN key at its
 * own root at every depth — no collapse, no scope-root translation. That
 * makes three things regressions rather than features:
 * - a section-keyed style entry at a nested key must reach live markup (the
 *   stamped element IS the @scope root);
 * - a variant reference at a nested section key must deliver its class on
 *   the live render (the old collapsed shape silently delivered NOTHING
 *   live — the stamping walk never saw a merged key; this is that
 *   regression test in new-shape form);
 * - an UNSTYLED nested section must still stamp once the map styles any
 *   container: it is the donut boundary that stops ancestor section CSS from
 *   leaking into its identical layout markup.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class NestedSectionDeliveryTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    // Live renders only: no editing permission, demand-driven stamping.
    $this->setUpCurrentUser([], ['view test entity']);
  }

  /**
   * Builds an entity with a nested section and the given style map.
   *
   * Key placeholders: {root} and {nested} are replaced with the root and
   * nested section uuids.
   *
   * @return array{0: \Drupal\entity_test\Entity\EntityTest, 1: string, 2: string}
   *   The entity, the root section uuid, the nested section uuid.
   */
  protected function styledEntityWithNestedSection(array $map): array {
    $nested = $this->createSection('layout_twocol_section', [
      new SectionComponent($this->uuid->generate(), 'first', [
        'id' => 'field_block:entity_test:entity_test:name',
        'label' => 'Name',
        'label_display' => '0',
        'context_mapping' => ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
      ]),
    ]);
    $root = $this->createSection('layout_onecol', [
      SectionBlock::createComponent($nested, 'content'),
    ]);
    $entity = $this->createTestEntity([$root]);
    $root_uuid = $root->getThirdPartySetting('daedalus_layout', 'uuid');
    $nested_uuid = $nested->getThirdPartySetting('daedalus_layout', 'uuid');

    $keyed = [];
    foreach ($map as $key => $entry) {
      $keyed[str_replace(['{root}', '{nested}'], [$root_uuid, $nested_uuid], $key)] = $entry;
    }
    // The nested field block needs real content: an all-empty section would
    // fail its layout template's {% if content %} guard and render nothing.
    $entity->set('name', 'Nested sentinel');
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($keyed));
    $entity->save();
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $storage->resetCache([$entity->id()]);
    return [$storage->load($entity->id()), $root_uuid, $nested_uuid];
  }

  /**
   * A styled nested section's key and CSS both reach the live render.
   */
  public function testNestedSectionStylesDeliverLive(): void {
    [$entity, , $nested_uuid] = $this->styledEntityWithNestedSection([
      'container:{nested}' => ['default' => ['padding' => '4rem']],
    ]);

    $html = $this->renderEntity($entity);
    // The @scope root exists on the page….
    $this->assertStringContainsString(sprintf('data-selectable="container:%s"', $nested_uuid), $html);
    // …and the CSS scopes at the nested key itself, donut intact — no
    // translation to any other root.
    $this->assertStringContainsString(
      sprintf('@scope ([data-selectable="container:%s"]) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"])', $nested_uuid),
      $html,
    );
  }

  /**
   * A variant reference at a nested section key delivers its class live.
   */
  public function testNestedSectionVariantClassStampsLive(): void {
    [$entity, , $nested_uuid] = $this->styledEntityWithNestedSection([
      'container:{nested}' => ['variants' => [['variant' => 'test_variant', 'fragment' => '']]],
    ]);

    $html = $this->renderEntity($entity);
    $xpath = $this->htmlXpath($html);
    $section = $xpath->query(sprintf('//*[@data-selectable="container:%s"]', $nested_uuid));
    $this->assertSame(1, $section->length, 'The referencing nested section key is stamped on the live render.');
    $this->assertStringContainsString('daedalus-stylish-variant--test_variant', $section->item(0)->getAttribute('class'), 'The variant class rides the nested section element.');
  }

  /**
   * An unstyled nested section stamps as the donut boundary.
   *
   * Root-section CSS is scope-limited at the next container: stamp; without
   * the nested stamp the boundary is missing and the root's styles leak
   * into the nested section's identical layout markup.
   */
  public function testUnstyledNestedSectionStampsAsBoundary(): void {
    [$entity, $root_uuid, $nested_uuid] = $this->styledEntityWithNestedSection([
      'container:{root}::.daedalus-stylish-layout-container' => ['default' => ['background-color' => '#e60505']],
    ]);

    $html = $this->renderEntity($entity);
    $this->assertStringContainsString(sprintf('data-selectable="container:%s"', $root_uuid), $html);
    $this->assertStringContainsString(sprintf('data-selectable="container:%s"', $nested_uuid), $html, 'The unstyled nested section is stamped: it is the boundary that stops the root section\'s CSS.');
  }

  /**
   * Container-roots CSS is the single page rule, embedded tree or not.
   *
   * A map styling under a container-query condition ships the page-level
   * width-band query root (CssPrinter::PAGE_CONTAINER_RULE); nested
   * containers are deliberately NOT query containers: width bands measure
   * the page everywhere, never the styled element's enclosing column, so
   * no container at any depth may appear in the containment rule.
   */
  public function testContainerRootsAreThePageRule(): void {
    $map = ['block:11111111-1111-1111-1111-111111111111' => ['narrow' => ['padding' => '1rem']]];
    [$entity, $root_uuid, $nested_uuid] = $this->styledEntityWithNestedSection($map);

    $build = $this->container->get('daedalus_stylish.style_backend')->build($entity, $map);
    $css = (string) $build['#value'];

    $this->assertStringContainsString(CssPrinter::PAGE_CONTAINER_RULE, $css);
    $this->assertSame(1, substr_count($css, 'container-type'));
    $this->assertStringNotContainsString(sprintf('container:%s', $root_uuid), $css);
    $this->assertStringNotContainsString(sprintf('container:%s', $nested_uuid), $css);
    $this->assertStringNotContainsString('region:', $css);
  }

  /**
   * Without section-keyed styles nothing stamps: unstyled pages stay clean.
   */
  public function testNoStampsWithoutSectionDemand(): void {
    [$entity] = $this->styledEntityWithNestedSection([]);

    $html = $this->renderEntity($entity);
    $this->assertStringNotContainsString('data-selectable="container:', $html);
  }

  /**
   * Renders an entity's default view to HTML.
   */
  protected function renderEntity(EntityTest $entity): string {
    $build = $this->entityTypeManager->getViewBuilder('entity_test')->view($entity, 'default');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Parses rendered HTML for querying.
   */
  protected function htmlXpath(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new \DOMXPath($dom);
  }

}
