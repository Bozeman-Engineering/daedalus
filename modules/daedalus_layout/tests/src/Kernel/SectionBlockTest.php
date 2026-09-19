<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Kernel tests for the nested-section container block plugin.
 *
 * The container is the storage atom of the sections-all-the-way-down model:
 * a SectionComponent whose configuration embeds ONE Section::toArray() value.
 * These tests pin its three contracts: component uuid == section uuid,
 * context forwarding as a hard (throwing) contract, and the embedded value
 * surviving core's config schema on the defaults path.
 *
 * @see \Drupal\daedalus_layout\Plugin\Block\SectionBlock
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class SectionBlockTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // FieldBlock::blockAccess() checks entity view access; the forwarding
    // tests prove context resolution by rendering the root entity's name
    // field inside embedded sections.
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity']);
  }

  /**
   * Component uuid == embedded section uuid, by construction.
   */
  public function testCreateComponentLocksUuids(): void {
    // A section already carrying its daedalus_layout uuid keeps it.
    $section = $this->createSection('layout_onecol');
    $component = SectionBlock::createComponent($section, 'content', 5);
    $this->assertSame($section->getThirdPartySetting('daedalus_layout', 'uuid'), $component->getUuid());
    $this->assertSame('content', $component->getRegion());
    $this->assertSame(5, $component->getWeight());
    $this->assertSame(SectionBlock::PLUGIN_ID, $component->getPluginId());
    $this->assertSame($section->toArray(), $component->get('configuration')[SectionBlock::SECTION_KEY]);
    $this->assertTrue(SectionBlock::isSectionComponent($component));
    $this->assertFalse(SectionBlock::isSectionComponent($this->createBasicBlockComponent()));

    // A bare section gets a uuid minted and stamped, and the component is
    // locked to it.
    $bare = new Section('layout_onecol');
    $bare_component = SectionBlock::createComponent($bare, 'content');
    $minted = $bare->getThirdPartySetting('daedalus_layout', 'uuid');
    $this->assertNotEmpty($minted);
    $this->assertSame($minted, $bare_component->getUuid());
    $this->assertSame($bare->toArray(), $bare_component->get('configuration')[SectionBlock::SECTION_KEY]);
  }

  /**
   * Embedded sections render at depth with forwarded contexts (live path).
   *
   * The root entity's name field block only renders inside an embedded
   * section if the container forwarded the entity and view_mode contexts, so
   * a name sentinel at layout depth 2 and 3 proves forwarding at depth 1 and
   * 2 respectively. One block maps to layout_builder.entity and one to
   * daedalus_layout.root_entity, pinning both forwarded ids.
   */
  public function testRendersEmbeddedSectionsWithForwardedContexts(): void {
    $depth_two = $this->createSection('layout_onecol', [
      $this->createNameFieldBlockComponent(),
    ]);
    $depth_one = $this->createSection('layout_onecol', [
      $this->createNameFieldBlockComponent(),
      $this->createNameFieldBlockComponent(NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID),
      SectionBlock::createComponent($depth_two, 'content', 1),
    ]);
    $entity = $this->createTestEntity([
      $this->createSection('layout_onecol', [
        SectionBlock::createComponent($depth_one, 'content'),
      ]),
    ]);
    $entity->setName('Forwarded context sentinel');

    $html = $this->renderEntity($entity);
    $this->assertRendersAtLayoutDepth($html, 'Forwarded context sentinel', 2, 'The depth-1 embedded field block renders the root entity name.');
    $this->assertRendersAtLayoutDepth($html, 'Forwarded context sentinel', 3, 'The depth-2 embedded field block renders the root entity name.');
  }

  /**
   * The default context mapping resolves preview-shaped contexts.
   *
   * Preview renders provide layout_builder.entity and view_mode but no
   * 'entity' id (OverridesSectionStorage::getContextsDuringPreview() renames
   * it), so the container's default mapping must resolve from those two ids
   * alone.
   */
  public function testPreviewShapedContextsRender(): void {
    $entity = EntityTest::create(['name' => 'Preview shape sentinel']);
    $entity->save();

    $embedded = $this->createSection('layout_onecol', [
      $this->createNameFieldBlockComponent(),
    ]);
    $container = SectionBlock::createComponent($embedded, 'content');

    $build = $container->toRenderArray([
      'layout_builder.entity' => EntityContext::fromEntity($entity),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('Preview shape sentinel', $html);
  }

  /**
   * Missing contexts throw instead of silently rendering a degraded subtree.
   */
  public function testMissingContextsThrow(): void {
    $embedded = $this->createSection('layout_onecol', [
      $this->createNameFieldBlockComponent(),
    ]);
    $container = SectionBlock::createComponent($embedded, 'content');

    $this->expectException(ContextException::class);
    $build = $container->toRenderArray([]);
    $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * An empty container is invalid state and build() throws.
   */
  public function testEmptyContainerBuildThrows(): void {
    $plugin = $this->container->get('plugin.manager.block')->createInstance(SectionBlock::PLUGIN_ID);

    $this->expectException(\LogicException::class);
    $plugin->build();
  }

  /**
   * The container round-trips the defaults display under strict schema.
   *
   * KernelTestBase enforces strict config schema on save, so saving a
   * defaults display carrying a depth-2 container validates
   * block.settings.daedalus_layout_section, including the recursion through core's
   * layout_builder.section type.
   */
  public function testDefaultsDisplayRoundTripsUnderStrictSchema(): void {
    $depth_two = $this->createSection('layout_onecol', [
      $this->createNameFieldBlockComponent(),
    ]);
    $depth_one = $this->createSection('layout_onecol', [
      SectionBlock::createComponent($depth_two, 'content'),
    ]);
    $container = SectionBlock::createComponent($depth_one, 'content');
    $embedded_value = $container->get('configuration')[SectionBlock::SECTION_KEY];

    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $display->appendSection($this->createSection('layout_onecol', [$container]));
    $display->save();

    $reloaded = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $sections = $reloaded->getSections();
    $reloaded_component = end($sections)->getComponent($container->getUuid());
    // assertEquals, not assertSame: config casting reorders mapping keys to
    // schema-definition order on save; the values must round-trip untouched.
    $this->assertEquals($embedded_value, $reloaded_component->get('configuration')[SectionBlock::SECTION_KEY]);
  }

  /**
   * The plugin exists but never surfaces in plugin listings.
   */
  public function testPluginHiddenFromListings(): void {
    $block_manager = $this->container->get('plugin.manager.block');
    $this->assertTrue($block_manager->hasDefinition(SectionBlock::PLUGIN_ID));
    $this->assertArrayNotHasKey(SectionBlock::PLUGIN_ID, $block_manager->getFilteredDefinitions('block_ui'));
    $this->assertArrayNotHasKey(SectionBlock::PLUGIN_ID, $block_manager->getFilteredDefinitions('layout_builder', NULL, ['list' => 'inline_blocks']));
  }

  /**
   * Renders an entity's default view to HTML.
   */
  protected function renderEntity(EntityTest $entity): string {
    $build = $this->entityTypeManager->getViewBuilder('entity_test')->view($entity, 'default');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Asserts text renders at least $depth layout wrappers deep.
   *
   * A plain substring assertion would pass vacuously (the entity renders its
   * name outside the layout too), so the sentinel must be found under the
   * expected number of nested layout wrappers to prove the embedded block
   * resolved its context and rendered.
   */
  protected function assertRendersAtLayoutDepth(string $html, string $text, int $depth, string $message): void {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xpath = new \DOMXPath($dom);
    $query = str_repeat('//div[contains(@class,"layout")]', $depth) . sprintf('//text()[contains(., "%s")]', $text);
    $this->assertGreaterThan(0, $xpath->query($query)->length, $message);
  }

  /**
   * Creates a field block component rendering the root entity's name.
   *
   * @param string $entity_context_id
   *   The context id to map the entity slot to.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The field block component.
   */
  protected function createNameFieldBlockComponent(string $entity_context_id = 'layout_builder.entity'): SectionComponent {
    return new SectionComponent($this->uuid->generate(), 'content', [
      'id' => 'field_block:entity_test:entity_test:name',
      'label' => 'Name',
      'label_display' => '0',
      'context_mapping' => [
        'entity' => $entity_context_id,
        'view_mode' => 'view_mode',
      ],
    ]);
  }

}
