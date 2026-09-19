<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\daedalus_ui\ElementModel\HtmlProjector;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the read print's mounting of the root sections.
 *
 * The print is a drawing of the resolved declaration: the root sections
 * are content of the entity box under the entity kind's `sections` slot
 * token and mount wherever that token's claim lands, exactly as the root
 * composition sketches them. With daedalus_layout installed its layout root
 * (`div.daedalus-layout-layout`, `contains: sections`) holds them; without a
 * claimant they print directly inside the entity box. The two cases run
 * over the real serializer and annotator (an LB-enabled entity_test host
 * with two empty sections), because the mount is a print fact of the
 * whole read path, not of the resolver alone.
 *
 * @group daedalus_ui
 */
#[RunTestsInSeparateProcesses]
class HtmlProjectorTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. Navigation_plus's hard
   * dependencies are invisible to kernel environments and must be listed
   * explicitly; daedalus_layout is enabled per case, since the two cases differ
   * exactly in its presence.
   */
  protected static $modules = [
    'layout_discovery',
    'block',
    'block_content',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
  ];

  /**
   * The section UUIDs, in blueprint order.
   *
   * @var string[]
   */
  protected array $sectionUuids = [
    'aaaaaaaa-1111-2222-3333-444444444444',
    'bbbbbbbb-1111-2222-3333-444444444444',
  ];

  /**
   * The host entity holding the two root sections.
   */
  protected object $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableLayoutBuilder();

    $sections = [];
    foreach ($this->sectionUuids as $uuid) {
      $section = $this->createSection('layout_onecol');
      $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
      $sections[] = $section;
    }
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'layout_builder__layout' => $sections,
    ]);
    $this->entity->save();
  }

  /**
   * With daedalus_layout, the sections print inside the layout root, and only they.
   */
  public function testSectionsMountInsideTheLayoutRoot(): void {
    $this->enableModules(['daedalus_layout']);
    $declarations = $this->container->get('daedalus_ui.page_builder_declarations');
    $declarations->resetCache();
    $this->assertSame(['sections' => '.daedalus-layout-layout'], $this->container->get('daedalus_ui.element_resolver')->resolve($declarations->getKind('entity'))['slots']);

    $lines = $this->printedLines();
    $open = array_search('  <div class="daedalus-layout-layout">', $lines, TRUE);
    $this->assertNotFalse($open, 'The layout root prints as a part of the entity box.');
    $close = NULL;
    foreach ($lines as $index => $line) {
      if ($index > $open && $line === '  </div>') {
        $close = $index;
        break;
      }
    }
    $this->assertNotNull($close, 'The layout root closes at the part indent.');

    $inside = array_slice($lines, $open + 1, $close - $open - 1);
    $this->assertSame(
      $this->sectionUuids,
      $this->sectionKeysAtIndent($inside, 4),
      'The layout root holds the sections, in blueprint order, at the part\'s child indent.'
    );
    $this->assertSame(
      [],
      array_values(array_filter($inside, static fn(string $line): bool => preg_match('/^ {4}<!--/', $line) === 1)),
      'The layout root holds only the sections: the root field comments stay at the entity box.'
    );
    $this->assertSame([], $this->sectionKeysAtIndent($lines, 2), 'No section prints directly inside the entity box while a part claims the section list.');
  }

  /**
   * Without daedalus_layout, no part claims the sections and they print at the root.
   */
  public function testSectionsMountAtTheEntityBoxUnclaimed(): void {
    $declarations = $this->container->get('daedalus_ui.page_builder_declarations');
    $this->assertArrayNotHasKey('elements', $declarations->getKind('entity'), 'daedalus_ui itself declares no entity parts, so nothing claims the section list.');

    $lines = $this->printedLines();
    $this->assertStringStartsWith('<div data-key="entity:' . $this->entity->uuid() . '" data-type="entity_test/entity_test">', $lines[0]);
    $this->assertSame('</div>', end($lines));
    $this->assertStringNotContainsString('daedalus-layout-layout', implode("\n", $lines));
    // A section's key is daedalus_layout's uuid (its third-party setting on the
    // section); without daedalus_layout the serialized section carries none and
    // the box prints `container:` with a blank key, so this case counts
    // the section boxes at the entity box's child indent and pins no key.
    $this->assertCount(2, $this->sectionKeysAtIndent($lines, 2), 'Both sections print directly inside the entity box.');
    $this->assertSame([], $this->sectionKeysAtIndent($lines, 4), 'Nothing nests the sections deeper than the entity box.');
  }

  /**
   * Prints the host through the projector service of the current container.
   *
   * @return string[]
   *   The printed lines.
   */
  protected function printedLines(): array {
    $projector = $this->container->get('daedalus_ui.html_projector');
    $this->assertInstanceOf(HtmlProjector::class, $projector);
    $html = $projector->projectEntity($this->entity);
    $this->assertIsString($html);
    return explode("\n", $html);
  }

  /**
   * The section keys opened at exactly the given indent, in print order.
   *
   * @param string[] $lines
   *   The lines to scan.
   * @param int $indent
   *   The exact indent (spaces) a section box opens at.
   *
   * @return string[]
   *   The section UUIDs whose box opens at that indent.
   */
  protected function sectionKeysAtIndent(array $lines, int $indent): array {
    $keys = [];
    foreach ($lines as $line) {
      if (preg_match('/^ {' . $indent . '}<[a-z][a-z0-9-]* data-key="container:([^"]*)" data-type="layout_onecol"/', $line, $match)) {
        $keys[] = $match[1];
      }
    }
    return $keys;
  }

}
