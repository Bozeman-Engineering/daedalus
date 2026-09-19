<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that stale pre-migration tempstore sessions converge at LOAD time.
 *
 * A session staged before the container migration ran holds sections that
 * reference the four RETIRED layout plugins (daedalus_stylish_twocol etc., deleted
 * in the cleanup pass). The save-time safety net cannot help such a
 * session: rendering it throws PluginNotFoundException, and a session that
 * cannot render can never reach save. The tempstore load chokepoint
 * (LayoutTempstoreStrategy::get()) must therefore converge every staged
 * envelope it deserializes, through the load-time half of daedalus_layout's
 * deprecation-window safety net.
 *
 * Deliberately does NOT enable daedalus_stylish_layouts_retired_test: the retired
 * plugins must be truly absent, exactly as on a real updated site, to pin
 * that neither the transforms nor the render path resolve them.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StaleSessionConvergenceTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    'daedalus_stylish_layouts',
  ];

  /**
   * The daedalus_layout uuid of the stale twocol section.
   */
  protected const TWOCOL_UUID = 'af23ae09-0a81-4f55-b1bf-344852458aaa';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * A section as a pre-migration editor session staged it.
   *
   * Section::fromArray() stores the layout id and settings raw, so building
   * the object needs no plugin - just like unserializing a tempstore
   * envelope written before the update.
   */
  protected function staleTwocolSection(): Section {
    return Section::fromArray([
      'layout_id' => 'daedalus_stylish_twocol',
      'layout_settings' => ['label' => 'Stale hero'],
      'components' => [
        '77d8924a-fe5d-4333-8b97-ce53cd82e800' => [
          'uuid' => '77d8924a-fe5d-4333-8b97-ce53cd82e800',
          'region' => 'first',
          'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Left', 'label_display' => '0'],
          'weight' => 0,
          'additional' => [],
        ],
        '5a1df62c-caf2-4db0-913f-da3bd84b9200' => [
          'uuid' => '5a1df62c-caf2-4db0-913f-da3bd84b9200',
          'region' => 'second',
          'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Right', 'label_display' => '0'],
          'weight' => 0,
          'additional' => [],
        ],
      ],
      'third_party_settings' => [
        'daedalus_layout' => ['uuid' => self::TWOCOL_UUID],
      ],
    ]);
  }

  /**
   * The staged host's style map, in the pre-migration grammar.
   */
  protected function staleMap(): array {
    return [
      'section:' . self::TWOCOL_UUID . '::.daedalus-stylish-layout-container' => [
        'default' => ['display' => 'grid', 'grid-template-columns' => 'minmax(0, 1fr) minmax(0, 1fr)'],
        'mobile' => ['grid-template-columns' => 'none'],
      ],
      'block:77d8924a-fe5d-4333-8b97-ce53cd82e800' => [
        'default' => ['text-align' => 'left'],
      ],
    ];
  }

  /**
   * A retired-layout session converges on load and renders.
   */
  public function testStaleSessionConvergesAtLoad(): void {
    // Persisted data is fine (the walkers converged it; here it is simply
    // post-migration): only the tempstore envelope is stale.
    $entity = $this->createTestEntity([$this->createSection('daedalus_stylish_container')]);

    // Stage the pre-migration session: the editable storage's host entity
    // carries the retired-layout section and the old style grammar, and the
    // envelope is written straight into shared tempstore, exactly what a
    // pre-update session left behind.
    $helper = $this->container->get('daedalus_tempstore.section_storage_helper');
    $storage = $helper->getEditableSectionStorage($entity);
    if ($storage instanceof NestedSectionStorageInterface) {
      $storage = $storage->getRootStorage();
    }
    $staged_entity = $storage->getContextValue('entity');
    $staged_entity->set(OverridesSectionStorage::FIELD_NAME, [$this->staleTwocolSection()]);
    $staged_entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($this->staleMap(), JSON_UNESCAPED_SLASHES));
    $strategy = $this->container->get('daedalus_tempstore.strategy.layout');
    $key = $strategy->getKey($storage);
    $this->container->get('tempstore.shared')
      ->get('layout_builder.section_storage.overrides')
      ->set($key, ['section_storage' => $storage]);

    // The edit-mode render path: LoadEditablePage swaps in the tempstore
    // entity through the entity repository, which loads through the layout
    // strategy. This get() is where load-time convergence must happen.
    $fresh = $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
    $staged = $this->container->get('daedalus_tempstore.entity_tempstore_repository')->get($fresh);
    $this->assertNotSame($fresh, $staged, 'The staged session is served.');

    $sections = $staged->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $this->assertCount(1, $sections);
    $section = $sections[0];
    $this->assertSame('daedalus_stylish_container', $section->getLayoutId());
    $this->assertSame(self::TWOCOL_UUID, $section->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertSame('Stale hero', $section->getLayoutSettings()['label'], 'The stored layout settings survive without resolving the retired plugin.');
    $children = array_values($section->getComponents());
    usort($children, static fn ($a, $b) => $a->getWeight() <=> $b->getWeight());
    $this->assertCount(2, $children);
    $this->assertSame(
      [['77d8924a-fe5d-4333-8b97-ce53cd82e800'], ['5a1df62c-caf2-4db0-913f-da3bd84b9200']],
      array_map(static fn ($child) => array_keys(SectionBlock::embeddedSection($child)->getComponents()), $children),
      'Each populated region became a child container, in region order.'
    );

    // Host state converged in the same load: the style map speaks the
    // container grammar, all the way to mode + share - the stepper-shaped
    // explicit tracks became the one row arrangement reference (equal
    // ratio, so no child grows), and the emptied part entry is gone.
    $map = $staged->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $this->assertSame([
      'variants' => [['variant' => 'row', 'fragment' => '']],
    ], $map['container:' . self::TWOCOL_UUID]);
    $this->assertArrayNotHasKey('container:' . self::TWOCOL_UUID . '::.daedalus-stylish-layout-container', $map);
    $this->assertArrayNotHasKey('section:' . self::TWOCOL_UUID . '::.daedalus-stylish-layout-container', $map);

    // The line that used to throw PluginNotFoundException (daedalus_stylish_twocol):
    // the staged session renders, through the composed one-box render (a
    // root-depth container is a real <section>; the old inner part div is
    // gone).
    $html = $this->renderEntity($staged);
    $this->assertStringContainsString('<section', $html);
    $this->assertStringNotContainsString('daedalus-stylish-layout-container', $html);

    // The envelope was upgraded in place, so the session is converged once,
    // not on every load.
    $envelope = $this->container->get('tempstore.shared')
      ->get('layout_builder.section_storage.overrides')
      ->get($key);
    $this->assertSame('daedalus_stylish_container', $envelope['section_storage']->getSections()[0]->getLayoutId());

    // The section storage door (layout endpoints, ops) serves the same
    // converged session.
    $repo_storage = $this->container->get('layout_builder.tempstore_repository')
      ->get($helper->getEditableSectionStorage($fresh));
    $this->assertSame('daedalus_stylish_container', $repo_storage->getSections()[0]->getLayoutId());
  }

  /**
   * Renders an entity the way the edit-mode canvas does.
   */
  protected function renderEntity(EntityTest $entity): string {
    $build = $this->entityTypeManager->getViewBuilder('entity_test')->view($entity, 'default');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

}
