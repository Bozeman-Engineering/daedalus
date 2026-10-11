<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\NodeTree;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Lock and unlock are a merge, run per page on its next read.
 *
 * One entity_test_rev bundle whose template holds a plugin block and the
 * hero slot block, flipped between locked (overrides off) and overridable
 * at the display, with the pages read after each flip. Locking narrows a
 * page's own document to its slot sections on the next read, in memory,
 * the whole document it owned dropped with core's blank marker, so the
 * field is its fills or nothing; a reader that declined the seed answers
 * the same law without writing. Unlocking seeds the template around the
 * fills, each dropped into its slot block's place as a nested section
 * with its identity intact, and the merged page renders its fill where
 * the slot was. Emptying the last fill of a locked page leaves no marker
 * behind, so a later unlock still seeds.
 */
#[CoversClass(DocumentInstantiator::class)]
#[CoversClass(ContentHost::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class LockUnlockMergeTest extends BlueprintKernelTestBase {

  /**
   * The template section's uuid.
   */
  protected string $templateSection;

  /**
   * The template's plugin block uuid.
   */
  protected string $templateBlock;

  /**
   * The template's slot block uuid.
   */
  protected string $slotBlock;

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperationsInterface
   */
  protected $operations;

  /**
   * The daedalus_layout adapter.
   *
   * @var \Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter
   */
  protected $adapter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_ui',
      'daedalus_layout',
      'daedalus_blueprint_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->setUpCurrentUser([], [], TRUE);
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');

    // The site's slots: the roster save rebuilds the block definitions.
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
        'aside' => ['label' => 'Aside', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();

    // The bundle, locked: Layout Builder on, overrides off, one template
    // section holding a plugin block and the hero slot.
    $uuids = new PhpUuid();
    $this->templateSection = $uuids->generate();
    $this->templateBlock = $uuids->generate();
    $this->slotBlock = $uuids->generate();
    $template = $this->createSection('eb_test_container', [
      [
        'uuid' => $this->templateBlock,
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Template block', 'label_display' => '0'],
      ],
      [
        'uuid' => $this->slotBlock,
        'configuration' => [
          'id' => SlotBlock::PLUGIN_ID . ':hero',
          'label' => 'Hero',
          'label_display' => '0',
          'context_mapping' => SlotBlock::CONTEXT_MAPPING,
          SlotBlock::DEFAULT_KEY => NULL,
        ],
      ],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', $this->templateSection);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->removeAllSections()->appendSection($template)->save();
    // Field blocks derive per Layout Builder enabled bundle.
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();
  }

  /**
   * Locks or unlocks the bundle at its display.
   */
  protected function setLocked(bool $locked): void {
    $this->entityTypeManager->getStorage('entity_view_display')
      ->loadUnchanged('entity_test_rev.entity_test_rev.default')
      ->setOverridable(!$locked)
      ->save();
  }

  /**
   * A page loaded fresh from storage.
   */
  protected function reload(FieldableEntityInterface $page): FieldableEntityInterface {
    $storage = $this->entityTypeManager->getStorage('entity_test_rev');
    $storage->resetCache([$page->id()]);
    return $storage->load($page->id());
  }

  /**
   * The raw section list of a page's override field, as stored.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections, the blank marker included (the host's reads hide it).
   */
  protected static function rawSections(FieldableEntityInterface $page): array {
    return $page->get(ContentHost::LAYOUT_FIELD)->getSections();
  }

  /**
   * The daedalus_layout uuids of a section list, in order.
   */
  protected function uuidsOf(array $sections): array {
    return array_map(fn (Section $section): ?string => $this->adapter->getSectionUuid($section), array_values($sections));
  }

  /**
   * The component uuids of a host's document at every depth, sorted.
   */
  protected function componentUuids(HostInterface $host): array {
    $uuids = [];
    $walk = function (array $sections) use (&$walk, &$uuids): void {
      foreach ($sections as $section) {
        foreach ($section->getComponents() as $uuid => $component) {
          $uuids[] = $uuid;
          $nested = $this->adapter->embeddedSection($component);
          if ($nested !== NULL) {
            $walk([$nested]);
          }
        }
      }
    };
    $walk($host->ownSections());
    sort($uuids);
    return $uuids;
  }

  /**
   * A fill: a section tagged with a slot, holding the given components.
   */
  protected function fill(string $slot, string $uuid, array $components): Section {
    $section = $this->createSection('eb_test_container', $components);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    $section->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, $slot);
    return $section;
  }

  /**
   * The page's own name field block, label hidden.
   */
  protected function nameBlock(string $uuid): array {
    return [
      'uuid' => $uuid,
      'configuration' => [
        'id' => 'field_block:entity_test_rev:entity_test_rev:name',
        'label_display' => '0',
        'formatter' => [
          'label' => 'hidden',
          'type' => 'string',
          'settings' => ['link_to_entity' => FALSE],
          'third_party_settings' => [],
        ],
        'context_mapping' => ['entity' => 'layout_builder.entity'],
      ],
    ];
  }

  /**
   * Renders a page's full view to markup.
   */
  protected function renderPage(FieldableEntityInterface $page): string {
    $build = $this->entityTypeManager->getViewBuilder('entity_test_rev')->view($page, 'full');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * An XPath over markup.
   */
  protected function domXpath(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new \DOMXPath($dom);
  }

  /**
   * Locking narrows a page to its fills; unlocking seeds around them.
   *
   * On the overridable bundle a page owns the seeded template and what it
   * added: a hero fill and a plain section. Locked, its next read keeps
   * the fill alone, in memory, and a declined reader answers the same
   * without writing; saved, the field is the fill alone. Unlocked again,
   * the next read seeds the template around the fill, dropped into the
   * slot block's place: the whole document the page owned before the lock
   * is gone, the fill is not.
   */
  public function testLockNarrowsAndUnlockMerges(): void {
    $this->setLocked(FALSE);
    $uuids = new PhpUuid();
    $hero = $uuids->generate();
    $hero_block = $uuids->generate();
    $plain = $uuids->generate();

    $page = $this->createRevEntity(['name' => 'Flipped']);
    $host = $this->host($page);
    $seeded_section = DocumentInstantiator::derivedUuid($page->uuid(), $this->templateSection);
    $this->assertSame([$seeded_section], $this->uuidsOf($host->ownSections()), 'The overridable page seeded the template.');
    $result = $this->operations->batchOperations($host, [
      [
        'op' => 'add_section',
        'uuid' => $hero,
        'layout' => 'eb_test_container',
        'slot' => 'hero',
        'location' => ['anchor' => $host->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => $hero_block,
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => $hero, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_section',
        'uuid' => $plain,
        'layout' => 'eb_test_container',
        'location' => ['anchor' => $host->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);
    $this->assertSame([$seeded_section, $hero, $plain], $this->uuidsOf(self::rawSections($this->reload($page))), 'The page owns its whole document, the fill among it.');

    // The lock: the next read keeps the fill alone.
    $this->setLocked(TRUE);
    $page = $this->reload($page);
    $this->assertSame([$seeded_section, $hero, $plain], $this->uuidsOf(self::rawSections($page)), 'The display save touched no page.');
    $host = $this->host($page);
    $this->assertNotNull($this->container->get('daedalus_blueprint.host_resolver')->foreignOwner($host), 'The locked page renders inside the template.');
    $this->assertSame([$hero], $this->uuidsOf($host->ownSections()), 'The page owns its fill alone.');
    $this->assertSame([$hero], $this->uuidsOf(self::rawSections($page)), 'The field was narrowed in memory.');
    $this->assertSame('hero', LayoutSerializer::slotOf(self::rawSections($page)[0]), 'The fill keeps its tag.');
    $this->assertSame([$this->templateSection, $hero], $this->uuidsOf($host->sections()), 'A reader sees the template, then the fill.');

    // A declined reader of the stored page: the same law, no write.
    $declined = $this->host($this->reload($page));
    $declined->declineSeed();
    $this->assertSame([$hero], $this->uuidsOf($declined->ownSections()), 'A declined reader owns the fill alone.');
    $this->assertSame([$seeded_section, $hero, $plain], $this->uuidsOf(self::rawSections($declined->entity())), 'And wrote nothing.');

    // Saved, the narrowed document is what the field holds.
    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);
    $page = $this->reload($page);
    $this->assertSame([$hero], $this->uuidsOf(self::rawSections($page)), 'The save persisted the fill alone.');

    // The unlock: the next read lays the template around the fill.
    $this->setLocked(FALSE);
    $host = $this->host($this->reload($page));
    $this->assertNull($this->container->get('daedalus_blueprint.host_resolver')->foreignOwner($host), 'The unlocked page owns its document.');
    $sections = $host->ownSections();
    $this->assertSame([$seeded_section], $this->uuidsOf($sections), 'The template seeded around the fill: one top-level section.');
    $expected = [DocumentInstantiator::derivedUuid($page->uuid(), $this->templateBlock), $hero, $hero_block];
    sort($expected);
    $this->assertSame($expected, $this->componentUuids($host), 'The template\'s block, the fill and its block; the slot block gave way; the plain section is gone.');
    $container = $sections[0]->getComponent($hero);
    $this->assertSame('content', $container->getRegion(), 'The fill sits in the slot block\'s region.');
    $nested = $this->adapter->embeddedSection($container);
    $this->assertSame($hero, $this->adapter->getSectionUuid($nested), 'Embedded with its own identity.');
    $this->assertNull(LayoutSerializer::slotOf($nested), 'Its slot tag dropped.');
    $this->assertSame([$seeded_section], $this->uuidsOf($host->ownSections()), 'A second read seeds nothing more.');
  }

  /**
   * A locked page with no fill holds nothing, and seeds again when unlocked.
   *
   * Locking a page that owned the seeded template drops it all: the field
   * is empty, not core's blank marker (a marker would mean "started and
   * empty" and keep the template away for good), so the unlock finds a
   * never-started page and seeds the template, slot block included.
   */
  public function testLockOfAnUnfilledPageLeavesTheFieldEmpty(): void {
    $this->setLocked(FALSE);
    $page = $this->createRevEntity(['name' => 'Unfilled']);
    $host = $this->host($page);
    $seeded_section = DocumentInstantiator::derivedUuid($page->uuid(), $this->templateSection);
    $this->assertSame([$seeded_section], $this->uuidsOf($host->ownSections()));
    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);

    $this->setLocked(TRUE);
    $page = $this->reload($page);
    $host = $this->host($page);
    $this->assertSame([], $host->ownSections(), 'The locked page owns nothing.');
    $this->assertSame([], self::rawSections($page), 'The field is empty: no marker.');
    $this->assertSame([$this->templateSection], $this->uuidsOf($host->sections()), 'A reader sees the template alone.');
    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);
    $this->assertSame([], self::rawSections($this->reload($page)), 'Saved empty.');

    $this->setLocked(FALSE);
    $host = $this->host($this->reload($page));
    $this->assertSame([$seeded_section], $this->uuidsOf($host->ownSections()), 'The unlocked page seeded the template again.');
    $expected = [
      DocumentInstantiator::derivedUuid($page->uuid(), $this->templateBlock),
      DocumentInstantiator::derivedUuid($page->uuid(), $this->slotBlock),
    ];
    sort($expected);
    $this->assertSame($expected, $this->componentUuids($host), 'The template\'s block and its slot block, the slot unfilled.');
  }

  /**
   * An unlocked page renders its fill where the slot was.
   *
   * A locked page filling hero with its own name field block, unlocked:
   * the merged document renders the template's block and, in the slot
   * block's place, the fill as a nested section showing the page's name,
   * with no slot block left in the render.
   */
  public function testUnlockedPageRendersTheFillInItsSlot(): void {
    $uuids = new PhpUuid();
    $hero = $uuids->generate();
    $name_block = $uuids->generate();
    $page = $this->createRevEntity([
      'name' => 'Sentinel name',
      ContentHost::LAYOUT_FIELD => [$this->fill('hero', $hero, [$this->nameBlock($name_block)])],
    ]);
    $this->assertSame([$hero], $this->uuidsOf($this->host($page)->ownSections()), 'Locked, the page owns its fill and is not seeded.');

    $this->setLocked(FALSE);
    $page = $this->reload($page);
    $host = $this->host($page);
    $seeded_section = DocumentInstantiator::derivedUuid($page->uuid(), $this->templateSection);
    $this->assertSame([$seeded_section], $this->uuidsOf($host->ownSections()), 'Unlocked, the template seeded around the fill.');
    $this->assertSame($host, $this->container->get('daedalus_blueprint.host_resolver')->renderHost($host), 'The page renders its own document.');

    $html = $this->renderPage($page);
    $xpath = $this->domXpath($html);
    $this->assertStringContainsString('Powered by', $html, "The template's block renders: $html");
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid="' . DocumentInstantiator::derivedUuid($page->uuid(), $this->slotBlock) . '"]')->length, 'No slot block renders: the fill took its place.');
    $own = $xpath->query('//*[@data-selectable="container:' . $hero . '"]');
    $this->assertSame(1, $own->length, "The fill renders once, with its identity: $html");
    $this->assertStringContainsString('Sentinel name', $own->item(0)->textContent, 'Showing the page\'s own name inside it.');
    $this->assertSame(1, $xpath->query('//*[@data-selectable="container:' . $seeded_section . '"]//*[@data-selectable="container:' . $hero . '"]')->length, 'Nested inside the seeded template section.');
  }

  /**
   * Emptying the last fill of a locked page leaves no marker behind.
   *
   * Core writes its blank marker when the last root section goes; on a
   * locked page the op door clears it again, so the field is empty and a
   * later unlock seeds the template rather than reading a started-and-
   * emptied page.
   */
  public function testEmptyingTheLastFillLeavesNoMarker(): void {
    $uuids = new PhpUuid();
    $hero = $uuids->generate();
    $block = $uuids->generate();
    $page = $this->createRevEntity([
      'name' => 'Emptied',
      ContentHost::LAYOUT_FIELD => [
        $this->fill('hero', $hero, [
          ['uuid' => $block, 'configuration' => ['id' => 'system_powered_by_block']],
        ]),
      ],
    ]);
    $host = $this->host($page);
    $result = $this->operations->batchOperations($host, [
      ['op' => 'remove_element', 'element' => $block],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([], $host->ownSections(), 'The last element leaving took the fill with it.');
    $this->assertSame([], self::rawSections($page), 'And no marker was left: the field is empty.');
    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);
    $this->assertSame([], self::rawSections($this->reload($page)), 'Saved empty.');

    $this->setLocked(FALSE);
    $host = $this->host($this->reload($page));
    $this->assertSame([DocumentInstantiator::derivedUuid($page->uuid(), $this->templateSection)], $this->uuidsOf($host->ownSections()), 'The unlocked page seeded the template.');
  }

}
