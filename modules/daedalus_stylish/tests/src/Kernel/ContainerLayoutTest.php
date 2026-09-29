<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the one-box container's composed shapes and style delivery.
 *
 * A container is ONE element at every depth: the declaration's root forms
 * swap a real <section> (page band) for a plain <div> (nested, the depth
 * fact traveling from daedalus_layout's NestedSectionRenderArray into the
 * composed_container element), and the section's identity attributes ride
 * that same root — there is no inner part element and never was a second
 * box. The single "content" region is a storage vestige and renders no
 * wrapper at either depth.
 *
 * Styling addresses the root key ('') and the '::> *' children pseudo-part;
 * the printer's donut rules scope-limit at nested section and block
 * roots, so an outer band's styles never restyle a nested container's
 * identical markup. This locks the delivery half the printer round-trip
 * test cannot see: the emitted CSS and the composed markup on one page.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ContainerLayoutTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    'daedalus_stylish_layouts',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    // Live renders only: no editing permission, demand-driven stamping.
    $this->setUpCurrentUser([], ['view test entity']);
  }

  /**
   * Builds an entity: a container root section embedding a nested container.
   *
   * Both sections use the daedalus_stylish_container layout; the nested one holds a
   * field block so its element has real children. Key placeholders in the
   * map: {root} and {nested}.
   *
   * @return array{0: \Drupal\entity_test\Entity\EntityTest, 1: string, 2: string}
   *   The entity, the root section uuid, the nested section uuid.
   */
  protected function containerEntity(array $map): array {
    $nested = $this->createSection('daedalus_stylish_container', [
      new SectionComponent($this->uuid->generate(), 'content', [
        'id' => 'field_block:entity_test:entity_test:name',
        'label' => 'Name',
        'label_display' => '0',
        'context_mapping' => ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
      ]),
    ]);
    $root = $this->createSection('daedalus_stylish_container', [
      SectionBlock::createComponent($nested, 'content'),
    ]);
    $entity = $this->createTestEntity([$root]);
    $root_uuid = $root->getThirdPartySetting('daedalus_layout', 'uuid');
    $nested_uuid = $nested->getThirdPartySetting('daedalus_layout', 'uuid');

    $keyed = [];
    foreach ($map as $key => $entry) {
      $keyed[str_replace(['{root}', '{nested}'], [$root_uuid, $nested_uuid], $key)] = $entry;
    }
    $entity->set('name', 'Container sentinel');
    if ($keyed) {
      $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($keyed));
    }
    $entity->save();
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $storage->resetCache([$entity->id()]);
    return [$storage->load($entity->id()), $root_uuid, $nested_uuid];
  }

  /**
   * One element per depth: <section> page band, <div> nested, no part.
   */
  public function testShapePerDepth(): void {
    // Root-key styles at both keys stamp both section roots (demand).
    [$entity, $root_uuid, $nested_uuid] = $this->containerEntity([
      'container:{root}' => ['default' => ['padding-inline' => 'max(24px, calc((100% - 60rem) / 2))']],
      'container:{nested}' => ['default' => ['display' => 'grid']],
    ]);
    $html = $this->renderEntity($entity);
    $xpath = $this->htmlXpath($html);

    // ROOT depth: the key rides a real <section> — the root form's tag —
    // and nothing sits between it and its children.
    $root = $xpath->query(sprintf('//*[@data-selectable="container:%s"]', $root_uuid));
    $this->assertSame(1, $root->length);
    $this->assertSame('section', $root->item(0)->nodeName);

    // NESTED depth: the residual <div> form, the same ONE element.
    $nested = $xpath->query(sprintf('//*[@data-selectable="container:%s"]', $nested_uuid));
    $this->assertSame(1, $nested->length);
    $this->assertSame('div', $nested->item(0)->nodeName);
    // The nested container's children render directly inside it.
    $this->assertStringContainsString('Container sentinel', $xpath->document->saveHTML($nested->item(0)));

    // The two-box era is over: no part element, no part class, anywhere.
    $this->assertStringNotContainsString('daedalus-stylish-layout-container', $html);

    // The vestigial "content" region renders no wrapper at either depth.
    $this->assertStringNotContainsString('layout__region', $html);
    $this->assertStringNotContainsString(sprintf('region:%s:content', $root_uuid), $html);

    // Both containers hold components: no empty form matched, no marker.
    $this->assertStringNotContainsString('data-container-empty', $html);
  }

  /**
   * Root-key styles print as donuts and land on the composed roots.
   */
  public function testRootStylesLandAtBothDepths(): void {
    [$entity, $root_uuid, $nested_uuid] = $this->containerEntity([
      'container:{root}' => ['default' => ['padding-inline' => 'max(24px, calc((100% - 60rem) / 2))']],
      'container:{root}::> *' => ['default' => ['min-width' => '0']],
      'container:{nested}' => ['default' => ['display' => 'grid']],
    ]);
    $html = $this->renderEntity($entity);

    // Both root entries print scope-limited at nested section and block
    // roots — the outer band's styles stop at the nested container's own
    // root, which carries its own entry.
    foreach ([$root_uuid, $nested_uuid] as $uuid) {
      $this->assertStringContainsString(
        sprintf('@scope ([data-selectable="container:%s"]) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {', $uuid),
        $html,
      );
    }
    $this->assertStringContainsString('padding-inline: max(24px, calc((100% - 60rem) / 2));', $html);
    $this->assertStringContainsString('display: grid;', $html);
    // The children selector rides the root one level down, with no
    // donut: the child combinator self-limits.
    $this->assertStringContainsString(
      sprintf("@scope ([data-selectable=\"container:%s\"]) {\n  :scope > * {", $root_uuid),
      $html,
    );

    // And the elements the ':scope' selectors match exist: the section
    // and div roots ARE the addressed elements (CSS + markup on one page).
    $xpath = $this->htmlXpath($html);
    $this->assertSame('section', $xpath->query(sprintf('//*[@data-selectable="container:%s"]', $root_uuid))->item(0)->nodeName);
    $this->assertSame('div', $xpath->query(sprintf('//*[@data-selectable="container:%s"]', $nested_uuid))->item(0)->nodeName);
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
