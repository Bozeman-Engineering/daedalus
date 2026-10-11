<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Capability;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\Capability\Policy\OwnershipPolicy;
use Drupal\daedalus_blueprint\Capability\Showing;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\PageStart;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A host can own a document and still be rendered inside a foreign one.
 *
 * A page of a locked bundle (its display Layout Builder enabled, not
 * overridable) carries the override field, which daedalus_layout adds to
 * every enabled bundle, and owns what that field holds: its own sections
 * inside the template. The ownership policy opens every capability on
 * those sections and refuses every document write on the template's
 * elements with FOREIGN_DOCUMENT (a field block presenting the page's
 * own field keeps content); the root's structure stays the template's;
 * the render still shows the template; such a page is never seeded and
 * its starts are fills of the template's slots, never a whole document.
 *
 * The template's slot blocks are the doors: insert on one is the page's
 * own (the one capability the door keeps), a placement anchored inside
 * it lands in the page's slot section (minted when the slot is empty,
 * the slot section itself when a section is placed into an empty door,
 * nested when the slot is filled), the last element leaving the section
 * empties the slot again, and the template's own canvas finds no door in
 * its own slot block (SLOT_OWN_DOCUMENT). The editor's road to all of it
 * is core's own permission name, defined by daedalus_layout for a locked
 * bundle (LockedBundlePermissions).
 *
 * What a door shows is the spine's one answer per join, per canvas, per
 * actor (VerdictStamper::showing(), a Showing): the route's output, a
 * fill, a default blueprint by reference (with the portal for an actor who
 * may update it, claimable when the door's insert verdict allows), or
 * nothing; a linked placement shows its blueprint; any other node is no
 * join and has none.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(OwnershipPolicy::class)]
#[CoversClass(VerdictStamper::class)]
#[CoversClass(Showing::class)]
#[Group('daedalus_blueprint')]
class OwnedSlotsPolicyTest extends BlueprintKernelTestBase {

  /**
   * The five capabilities, all allowed.
   */
  protected const ALL = [
    'content' => TRUE,
    'configure' => TRUE,
    'style' => TRUE,
    'arrange' => TRUE,
    'insert' => TRUE,
  ];

  /**
   * The five capabilities, all refused.
   */
  protected const NONE = [
    'content' => FALSE,
    'configure' => FALSE,
    'style' => FALSE,
    'arrange' => FALSE,
    'insert' => FALSE,
  ];

  /**
   * The template's owner, as a refusal names it.
   */
  protected const OWNER = [
    'entity_type' => 'entity_view_display',
    'entity_id' => 'entity_test_rev.entity_test_rev.default',
  ];

  /**
   * The template section's uuid.
   */
  protected string $templateSection;

  /**
   * The template's plugin block uuid.
   */
  protected string $templateBlock;

  /**
   * The template's field block uuid, presenting the bundle's name field.
   */
  protected string $fieldBlock;

  /**
   * The template's hero slot block uuid: the door of slot "hero".
   */
  protected string $heroDoor;

  /**
   * The template's aside slot block uuid: the door of slot "aside".
   */
  protected string $asideDoor;

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
      // The blueprint entity (a door's default, a linked placement's
      // blueprint, for the showing): its scope is a list_string, its icon
      // a media reference.
      'options',
      'image',
      'media',
      'daedalus_blueprint_library',
    ]);
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['block_content', 'image', 'file', 'media', 'daedalus_blueprint_library']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->createBlockContentType('basic', 'Basic');
    $this->setUpCurrentUser([], [], TRUE);
    // The overridable bundle beside the locked one: entity_test's pages
    // own the whole document they render.
    $this->enableLayoutBuilder();

    // The locked bundle: Layout Builder on, overrides off, one template
    // section holding a plugin block and a field block of the bundle's own
    // name field.
    // The site's slots, both the template tier's: the roster save rebuilds
    // the block definitions.
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
        'aside' => ['label' => 'Aside', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();
    $uuids = new PhpUuid();
    $this->templateSection = $uuids->generate();
    $this->templateBlock = $uuids->generate();
    $this->fieldBlock = $uuids->generate();
    $this->heroDoor = $uuids->generate();
    $this->asideDoor = $uuids->generate();
    $template = $this->createSection('layout_onecol', [
      [
        'uuid' => $this->templateBlock,
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Template block'],
      ],
      [
        'uuid' => $this->fieldBlock,
        'configuration' => ['id' => 'field_block:entity_test_rev:entity_test_rev:name', 'label' => 'Name'],
      ],
      [
        'uuid' => $this->heroDoor,
        'configuration' => $this->slotConfiguration('hero'),
      ],
      [
        'uuid' => $this->asideDoor,
        'configuration' => $this->slotConfiguration('aside'),
      ],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', $this->templateSection);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->removeAllSections()->appendSection($template)->save();
  }

  /**
   * A slot block's configuration, as the site builder places one.
   */
  protected function slotConfiguration(string $slot): array {
    return [
      'id' => SlotBlock::PLUGIN_ID . ':' . $slot,
      'label' => ucfirst($slot),
      'label_display' => '0',
      'context_mapping' => SlotBlock::CONTEXT_MAPPING,
      SlotBlock::DEFAULT_KEY => NULL,
    ];
  }

  /**
   * The template's host: the locked display's canvas.
   */
  protected function template(): HostInterface {
    return $this->container->get('daedalus_blueprint.host_resolver')->fromEntity(
      $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test_rev.entity_test_rev.default'),
    );
  }

  /**
   * The uuids of a host's own sections, each mapped to the slot it fills.
   *
   * @return array<string, string|null>
   *   Section uuid to slot id.
   */
  protected function ownSlots(HostInterface $host): array {
    $slots = [];
    foreach ($host->ownSections() as $section) {
      $slots[$section->getThirdPartySetting('daedalus_layout', 'uuid')] = LayoutSerializer::slotOf($section);
    }
    return $slots;
  }

  /**
   * An add_element of a plugin block at a location.
   */
  protected function addBlock(array $location, ?string $uuid = NULL): array {
    $op = [
      'op' => 'add_element',
      'plugin_id' => 'system_powered_by_block',
      'location' => $location,
    ];
    if ($uuid !== NULL) {
      $op['uuid'] = $uuid;
    }
    return $op;
  }

  /**
   * Whether a result's targets name a uuid under a target type.
   */
  protected function hasTarget(BlueprintResult $result, string $uuid, string $type): bool {
    return in_array(['uuid' => $uuid, 'field_name' => $type], $result->getTargets(), TRUE);
  }

  /**
   * A locked page holding one section of its own, with one plugin block.
   *
   * The section fills the hero slot: a locked page's own document is its
   * slot sections alone (the lock and unlock merge narrows the field to
   * them on read), so an own section of a locked page always fills one.
   *
   * @return array{0: \Drupal\daedalus_blueprint\Host\HostInterface, 1: string, 2: string}
   *   The host, its own section's uuid and its own block's uuid.
   */
  protected function lockedPageWithOwnSection(): array {
    $uuids = new PhpUuid();
    $section_uuid = $uuids->generate();
    $block_uuid = $uuids->generate();
    $own = $this->createSection('layout_onecol', [
      ['uuid' => $block_uuid, 'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Own block']],
    ]);
    $own->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);
    $own->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, 'hero');
    $entity = $this->createRevEntity(['name' => 'Locked', ContentHost::LAYOUT_FIELD => [$own]]);
    return [$this->host($entity), $section_uuid, $block_uuid];
  }

  /**
   * The verdict set of the current actor on one target.
   */
  protected function verdicts(HostInterface $host, string $target): array {
    return $this->container->get('daedalus_blueprint.verdict_stamper')->verdicts($host, $target);
  }

  /**
   * Runs one op as a batch on a host.
   */
  protected function apply(HostInterface $host, array $op): BlueprintResult {
    return $this->container->get('daedalus_blueprint.operations')->batchOperations($host, [$op]);
  }

  /**
   * The first error's code and path of a refused batch.
   *
   * @return array{0: string, 1: string}
   *   The code and the path.
   */
  protected function refusedWith(BlueprintResult $result): array {
    $this->assertFalse($result->isSuccess(), 'The batch was refused.');
    $error = $result->getErrors()[0];
    return [$error->getCode(), $error->getPath()];
  }

  /**
   * The locked page owns a document and is rendered inside the template.
   */
  public function testLockedPageOwnsDocumentInsideTemplate(): void {
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    [$host, $section_uuid] = $this->lockedPageWithOwnSection();

    $this->assertTrue($host->entity()->hasField(ContentHost::LAYOUT_FIELD), 'daedalus_layout added the override field to the locked bundle.');
    $this->assertTrue($host->hasDocument(), 'The page owns a document.');
    $owner = $resolver->foreignOwner($host);
    $this->assertInstanceOf(DisplayHost::class, $owner, 'And is rendered inside its template, whose host owns the rest.');
    $this->assertSame(self::OWNER['entity_id'], $owner->id());
    $this->assertSame(self::OWNER['entity_id'], $resolver->renderHost($host)->id(), 'renderHost() is unchanged: the render shows the template.');

    $uuid_of = static fn (Section $section): string => $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $this->assertSame([$section_uuid], array_map($uuid_of, $host->ownSections()), 'The page owns exactly what its field holds.');
    $this->assertSame([$this->templateSection, $section_uuid], array_map($uuid_of, $host->sections()), 'A reader sees the template, then the page\'s own sections.');

    // A page of the locked bundle with nothing of its own: never seeded,
    // however often its document doors are read.
    $empty = $this->host($this->createRevEntity(['name' => 'Empty']));
    $this->assertTrue($empty->hasDocument());
    $this->assertSame([], $empty->ownSections(), 'An empty locked page is not seeded from the template.');
    $this->assertSame([$this->templateSection], array_map($uuid_of, $empty->sections()));
    $empty->styleMap();
    $this->assertSame([], $empty->entity()->get(ContentHost::LAYOUT_FIELD)->getSections(), 'Neither document door seeded the field.');
    $this->assertNotNull($resolver->foreignOwner($empty));

    // An overridable bundle's page owns the whole document it renders.
    $this->assertNull($resolver->foreignOwner($this->host($this->createTestEntity(['name' => 'Own']))), 'An overridable bundle\'s page has no foreign owner.');
  }

  /**
   * The policy: the own section open, the template foreign, the root split.
   */
  public function testOwnSectionIsOpenAndTemplateIsForeign(): void {
    [$host, $section_uuid, $block_uuid] = $this->lockedPageWithOwnSection();

    foreach ([$section_uuid, $block_uuid] as $own) {
      $verdict = $this->verdicts($host, $own);
      $this->assertSame(self::ALL, $verdict['can'], "$own is the page's own: every capability open.");
      $this->assertSame([], $verdict['refused']);
    }

    foreach ([$this->templateSection, $this->templateBlock] as $foreign) {
      $verdict = $this->verdicts($host, $foreign);
      $this->assertSame(self::NONE, $verdict['can'], "$foreign is the template's: everything refused.");
      $this->assertCount(1, $verdict['refused']);
      $this->assertSame('FOREIGN_DOCUMENT', $verdict['refused'][0]['code']);
      $this->assertSame(self::OWNER, $verdict['refused'][0]['context']['owner']);
      $this->assertSame(self::OWNER['entity_id'], $verdict['refused'][0]['context']['portal']['entity_id'], 'The admin may open the template: the portal rides.');
    }

    $verdict = $this->verdicts($host, $this->fieldBlock);
    $this->assertSame(['content' => TRUE] + self::NONE, $verdict['can'], 'A field block presenting the page\'s own field keeps content and nothing else.');
    $this->assertSame(['configure', 'style', 'arrange', 'insert'], $verdict['refused'][0]['capabilities']);

    $verdict = $this->verdicts($host, $host->uuid());
    $this->assertSame(
      ['content' => TRUE, 'configure' => FALSE, 'style' => TRUE, 'arrange' => FALSE, 'insert' => FALSE],
      $verdict['can'],
      'The root keeps its fields and its own style key; its top-level flow is the template\'s.',
    );
    $this->assertSame('FOREIGN_DOCUMENT', $verdict['refused'][0]['code']);

    // An editor who may update the page and change layouts but not open
    // the template gets the same split with no portal. Core defines the
    // per-bundle override permissions only for overridable displays, so
    // on a locked bundle `configure any layout` is the one road the role
    // policy knows to the page's own structure.
    $this->setUpCurrentUser([], ['administer entity_test content', 'configure any layout']);
    $this->assertSame(self::ALL, $this->verdicts($host, $block_uuid)['can'], 'The editor may do everything to the page\'s own block.');
    $verdict = $this->verdicts($host, $this->templateBlock);
    $this->assertSame(self::NONE, $verdict['can']);
    $this->assertArrayNotHasKey('portal', $verdict['refused'][0]['context'], 'No door to a room the editor cannot open.');
  }

  /**
   * The op door: gestures land in the own section and bounce off the template.
   */
  public function testOpsLandInOwnSectionAndBounceOffTemplate(): void {
    [$host, $section_uuid, $block_uuid] = $this->lockedPageWithOwnSection();

    $result = $this->apply($host, [
      'op' => 'add_element',
      'plugin_id' => 'system_powered_by_block',
      'location' => ['anchor' => $block_uuid, 'placement' => 'after'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertCount(2, $host->ownSections()[0]->getComponents(), 'The element landed beside the page\'s own block.');

    $result = $this->apply($host, ['op' => 'remove_element', 'element' => $block_uuid]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertArrayNotHasKey($block_uuid, $host->ownSections()[0]->getComponents(), 'The page\'s own block is removable.');

    $result = $this->apply($host, [
      'op' => 'add_element',
      'plugin_id' => 'system_powered_by_block',
      'location' => ['anchor' => $section_uuid, 'placement' => 'inside'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $this->assertSame(['FOREIGN_DOCUMENT', 'location.anchor'], $this->refusedWith($this->apply($host, [
      'op' => 'add_element',
      'plugin_id' => 'system_powered_by_block',
      'location' => ['anchor' => $this->templateSection, 'placement' => 'inside'],
    ])), 'An insert into the template is foreign.');
    $this->assertSame(['FOREIGN_DOCUMENT', 'element'], $this->refusedWith($this->apply($host, [
      'op' => 'remove_element',
      'element' => $this->templateBlock,
    ])), 'A removal of the template\'s block is foreign.');
    $this->assertSame(['FOREIGN_DOCUMENT', 'location.anchor'], $this->refusedWith($this->apply($host, [
      'op' => 'add_section',
      'location' => ['anchor' => $host->uuid(), 'placement' => 'inside'],
    ])), 'A new top-level section is the template\'s structure to give.');
    [$code] = $this->refusedWith($this->apply($host, [
      'op' => 'remove_element',
      'element' => (new PhpUuid())->generate(),
    ]));
    $this->assertNotSame('FOREIGN_DOCUMENT', $code, 'A uuid nowhere on the page is the operation\'s own answer, not the template\'s.');
    $this->assertSame([$section_uuid], array_map(static fn (Section $section): string => $section->getThirdPartySetting('daedalus_layout', 'uuid'), $host->ownSections()), 'The refusals left the page\'s own document as it was.');
  }

  /**
   * The slot block is the page's door: insert is the page's, nothing else.
   */
  public function testSlotDoorVerdicts(): void {
    [$host] = $this->lockedPageWithOwnSection();

    foreach ([$this->heroDoor, $this->asideDoor] as $door) {
      $verdict = $this->verdicts($host, $door);
      $this->assertSame(array_merge(self::NONE, ['insert' => TRUE]), $verdict['can'], "The door $door keeps insert alone.");
      $this->assertSame('FOREIGN_DOCUMENT', $verdict['refused'][0]['code']);
      $this->assertSame(['content', 'configure', 'style', 'arrange'], $verdict['refused'][0]['capabilities']);
    }
    $this->assertSame(self::NONE, $this->verdicts($host, $this->templateBlock)['can'], 'An ordinary template block keeps nothing.');

    // The tree names the door: the slot block node prints the slot it
    // declares, the same key a slot section prints for the slot it fills.
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($host);
    $by_uuid = [];
    foreach ($tree[0]['children'][0]['children'] as $node) {
      $by_uuid[$node['uuid']] = $node;
    }
    $this->assertSame('hero', $by_uuid[$this->heroDoor]['slot']);
    $this->assertSame(SlotBlock::PLUGIN_ID . ':hero', $by_uuid[$this->heroDoor]['plugin_id']);
    $this->assertArrayNotHasKey('slot', $by_uuid[$this->templateBlock]);

    // The template's own canvas: a document never fills its own slot, so
    // its slot block is an ordinary block to place beside (insert on the
    // container around it) and no door (SLOT_OWN_DOCUMENT on itself).
    $template = $this->template();
    $verdict = $this->verdicts($template, $this->heroDoor);
    $this->assertSame(array_merge(self::ALL, ['insert' => FALSE]), $verdict['can'], 'The template owns its slot block in every way but as a door.');
    $this->assertCount(1, $verdict['refused']);
    $this->assertSame('SLOT_OWN_DOCUMENT', $verdict['refused'][0]['code']);
    $this->assertSame(['insert'], $verdict['refused'][0]['capabilities']);
    $this->assertSame(['slot' => 'hero'], $verdict['refused'][0]['context']);
    $this->assertSame(self::ALL, $this->verdicts($template, $this->templateSection)['can']);
    $inside_own_door = $this->apply($template, $this->addBlock(['anchor' => $this->heroDoor, 'placement' => 'inside']));
    $this->assertSame(['SLOT_OWN_DOCUMENT', 'location.anchor'], $this->refusedWith($inside_own_door), 'The op door refuses the template\'s own slot block as an anchor to place inside.');
    $result = $this->apply($template, $this->addBlock(['anchor' => $this->heroDoor, 'placement' => 'after']));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertCount(5, $template->ownSections()[0]->getComponents(), 'Beside the slot block is the template\'s own flow.');
  }

  /**
   * A slot block placed under the wrong tier is no door.
   *
   * A slot is declared by one tier of the stack, the roster says which
   * (SlotRoster), and the offer (PlacementRestrictions) places it there
   * alone; a document that holds a slot block of the other tier (written
   * into its sections directly, as older config holds one) still shows the
   * block, and the verdict agrees with the op door that it opens nothing.
   * On a template holding a frame-tier door: insert on it is the
   * template's (FOREIGN_DOCUMENT), a placement anchored inside it is
   * refused the same way (not ANCHOR_NOT_FOUND, the op door's answer when
   * the verdict let the location fall through to the page's own
   * document), and the hero door beside it fills as before. On the frame
   * holding a template-tier door: no page fills it (fillsFrameDoor()
   * FALSE, every capability refused from the page), while the frame-tier
   * door beside it is the page's to fill.
   */
  public function testWrongTierDoorIsNoDoor(): void {
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
        'aside' => ['label' => 'Aside', 'tier' => SlotRoster::TIER_TEMPLATE],
        'top' => ['label' => 'Top', 'tier' => SlotRoster::TIER_FRAME],
      ])
      ->save();
    $uuids = new PhpUuid();
    $top_door = $uuids->generate();
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test_rev.entity_test_rev.default');
    $display->getSection(0)->appendComponent(new SectionComponent($top_door, 'content', $this->slotConfiguration('top')));
    $display->save();

    $host = $this->host($this->createRevEntity(['name' => 'Framed']));
    $verdict = $this->verdicts($host, $top_door);
    $this->assertSame(self::NONE, $verdict['can'], 'The template\'s frame-tier door keeps nothing, insert included.');
    $this->assertSame('FOREIGN_DOCUMENT', $verdict['refused'][0]['code']);
    $this->assertSame(self::OWNER, $verdict['refused'][0]['context']['owner']);

    $inside = $this->apply($host, $this->addBlock(['anchor' => $top_door, 'placement' => 'inside']));
    $this->assertSame(['FOREIGN_DOCUMENT', 'location.anchor'], $this->refusedWith($inside), 'A placement inside it is the template\'s to refuse, not an anchor nowhere on the page.');
    $this->assertSame([], $host->ownSections(), 'Nothing landed.');

    $this->assertTrue($this->verdicts($host, $this->heroDoor)['can']['insert'], 'The hero door beside it is still a door.');
    $result = $this->apply($host, $this->addBlock(['anchor' => $this->heroDoor, 'placement' => 'inside']));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['hero'], array_values($this->ownSlots($host)), 'And fills.');

    // The mirror: the site frame holding a template-tier door beside a
    // frame-tier one.
    $this->container->get('theme_installer')->install(['stark']);
    $frame_hero_door = $uuids->generate();
    $frame_top_door = $uuids->generate();
    $frame_section = $this->createSection('layout_onecol', [
      ['uuid' => $frame_hero_door, 'configuration' => $this->slotConfiguration('hero')],
      ['uuid' => $frame_top_door, 'configuration' => $this->slotConfiguration('top')],
    ]);
    $frame_section->setThirdPartySetting('daedalus_layout', 'uuid', $uuids->generate());
    $frame = Frame::create(['id' => 'stark']);
    $frame->appendSection($frame_section);
    $frame->save();
    $frame_host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity(
      $this->entityTypeManager->getStorage(Frame::ENTITY_TYPE_ID)->loadUnchanged('stark'),
    );
    $this->assertInstanceOf(FrameHost::class, $frame_host);
    /** @var \Drupal\daedalus_blueprint\Capability\Policy\OwnershipPolicy $policy */
    $policy = $this->container->get('daedalus_blueprint.capability_policy.ownership');
    $stamper = $this->container->get('daedalus_blueprint.verdict_stamper');

    $this->assertFalse($policy->fillsFrameDoor($host, $frame_host, $frame_hero_door), 'A template-tier slot block in the frame is no door of the page.');
    $verdict = $stamper->verdictsOn($host, $frame_host, $frame_hero_door);
    $this->assertSame(self::NONE, $verdict['can'], 'The frame\'s template-tier door keeps nothing from the page, insert included.');
    $this->assertSame('FOREIGN_DOCUMENT', $verdict['refused'][0]['code']);

    $this->assertTrue($policy->fillsFrameDoor($host, $frame_host, $frame_top_door), 'The frame-tier door beside it is the page\'s to fill.');
    $verdict = $stamper->verdictsOn($host, $frame_host, $frame_top_door);
    $this->assertSame(array_merge(self::NONE, ['insert' => TRUE]), $verdict['can']);
    $this->assertSame(['content', 'configure', 'style', 'arrange'], $verdict['refused'][0]['capabilities']);
  }

  /**
   * The editor's road on a locked bundle: core's name, daedalus_layout's.
   *
   * Core defines the two override permissions for an overridable bundle
   * only; daedalus_layout continues the same names for a bundle whose
   * enabled displays are locked, so the role policy's one vocabulary
   * covers both and a role keeps its permission across a lock.
   */
  public function testEditorPermissionRoad(): void {
    $permissions = $this->container->get('user.permissions')->getPermissions();
    $editable = 'configure editable entity_test_rev entity_test_rev layout overrides';
    $this->assertArrayHasKey($editable, $permissions);
    $this->assertSame('daedalus_layout', $permissions[$editable]['provider']);
    $this->assertArrayHasKey('configure all entity_test_rev entity_test_rev layout overrides', $permissions);
    $this->assertSame('daedalus_layout', $permissions['configure all entity_test_rev entity_test_rev layout overrides']['provider']);
    $this->assertContains('core.entity_view_display.entity_test_rev.entity_test_rev.default', $permissions[$editable]['dependencies']['config']);
    $this->assertSame('layout_builder', $permissions['configure editable entity_test entity_test layout overrides']['provider'], 'An overridable bundle\'s permission stays core\'s.');

    [$host, $section_uuid, $block_uuid] = $this->lockedPageWithOwnSection();
    $this->setUpCurrentUser([], ['administer entity_test content', $editable]);
    $this->assertSame(self::ALL, $this->verdicts($host, $block_uuid)['can'], 'The editor may do everything to the page\'s own block.');
    $this->assertSame(self::ALL, $this->verdicts($host, $section_uuid)['can']);
    $this->assertTrue($this->verdicts($host, $this->heroDoor)['can']['insert'], 'And may fill the slot through its door.');
    $this->assertSame(self::NONE, $this->verdicts($host, $this->templateBlock)['can'], 'The template stays the template\'s.');
    $result = $this->apply($host, $this->addBlock(['anchor' => $this->heroDoor, 'placement' => 'inside']));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    // Without the road, the door is shut: the role policy refuses insert.
    $this->setUpCurrentUser([], ['administer entity_test content']);
    $verdict = $this->verdicts($host, $this->heroDoor);
    $this->assertFalse($verdict['can']['insert']);
    $this->assertContains('ACCESS_DENIED_PERMISSION', array_column($verdict['refused'], 'code'));
    $this->assertFalse($this->verdicts($host, $block_uuid)['can']['arrange']);
  }

  /**
   * Placements through the door land in the slot's section, minted if absent.
   */
  public function testOpsLandInTheSlotThroughTheDoor(): void {
    $host = $this->host($this->createRevEntity(['name' => 'Empty']));
    $this->assertSame([], $host->ownSections());

    // A block into the empty hero door: the slot section is minted around
    // it, and the response learns of the section and the restructured root
    // beside the block.
    $first = (new PhpUuid())->generate();
    $result = $this->apply($host, $this->addBlock(['anchor' => $this->heroDoor, 'placement' => 'inside'], $first));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $slots = $this->ownSlots($host);
    $this->assertSame(['hero'], array_values($slots), 'One section of the page\'s own, tagged with the slot.');
    $hero_section = array_key_first($slots);
    $this->assertSame([$first], array_keys($host->ownSections()[0]->getComponents()));
    $this->assertTrue($this->hasTarget($result, $first, TargetType::ADDED));
    $this->assertTrue($this->hasTarget($result, $hero_section, TargetType::ADDED), 'The minted section is a target of the placement.');
    $this->assertTrue($this->hasTarget($result, $host->uuid(), TargetType::RESTRUCTURED));

    // A second block through the same door lands in the same section.
    $second = (new PhpUuid())->generate();
    $result = $this->apply($host, $this->addBlock(['anchor' => $this->heroDoor, 'placement' => 'inside'], $second));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$hero_section => 'hero'], $this->ownSlots($host), 'The slot fills once.');
    $this->assertSame([$first, $second], array_keys($host->ownSections()[0]->getComponents()));
    $this->assertFalse($this->hasTarget($result, $hero_section, TargetType::ADDED), 'Nothing minted the second time.');

    // A section through a filled door nests inside the filling section.
    $nested = (new PhpUuid())->generate();
    $result = $this->apply($host, [
      'op' => 'add_section',
      'uuid' => $nested,
      'location' => ['anchor' => $this->heroDoor, 'placement' => 'inside'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$hero_section => 'hero'], $this->ownSlots($host));
    $this->assertTrue($this->container->get('daedalus_blueprint.element_locator')->isNestedSection($host, $nested), 'The section nests inside the slot section.');

    // A section through an EMPTY door becomes the slot section itself.
    $aside = (new PhpUuid())->generate();
    $result = $this->apply($host, [
      'op' => 'add_section',
      'uuid' => $aside,
      'location' => ['anchor' => $this->asideDoor, 'placement' => 'inside'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$hero_section => 'hero', $aside => 'aside'], $this->ownSlots($host), 'The placed section fills the slot at the top level.');
    $contradiction = $this->apply($host, [
      'op' => 'add_section',
      'slot' => 'hero',
      'location' => ['anchor' => $this->asideDoor, 'placement' => 'inside'],
    ]);
    $this->assertSame(['INVALID_SLOT', 'slot'], $this->refusedWith($contradiction), 'A slot named against the door is a contradiction.');

    // The door is a door for the page alone: a block beside it is the
    // template's flow, refused as before.
    $beside = $this->apply($host, $this->addBlock(['anchor' => $this->heroDoor, 'placement' => 'after']));
    $this->assertSame(['FOREIGN_DOCUMENT', 'location.anchor'], $this->refusedWith($beside));
  }

  /**
   * The last element leaving a slot section empties the slot.
   */
  public function testLastElementLeavingEmptiesTheSlot(): void {
    $host = $this->host($this->createRevEntity(['name' => 'Emptied']));
    $block = (new PhpUuid())->generate();
    $this->assertTrue($this->apply($host, $this->addBlock(['anchor' => $this->heroDoor, 'placement' => 'inside'], $block))->isSuccess());
    $hero_section = array_key_first($this->ownSlots($host));

    // Removed: the section goes with its last element.
    $result = $this->apply($host, ['op' => 'remove_element', 'element' => $block]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([], $host->ownSections(), 'The slot is empty again.');
    $this->assertTrue($this->hasTarget($result, $block, TargetType::REMOVED));
    $this->assertTrue($this->hasTarget($result, $hero_section, TargetType::REMOVED), 'The response learns the section left.');

    // Moved out: into the other door, whose section is minted, the source
    // section going with its last element.
    $this->assertTrue($this->apply($host, $this->addBlock(['anchor' => $this->heroDoor, 'placement' => 'inside'], $block))->isSuccess());
    $hero_section = array_key_first($this->ownSlots($host));
    $result = $this->apply($host, [
      'op' => 'move_element',
      'element' => $block,
      'location' => ['anchor' => $this->asideDoor, 'placement' => 'inside'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $slots = $this->ownSlots($host);
    $this->assertSame(['aside'], array_values($slots), 'The block now fills the aside slot alone.');
    $this->assertSame([$block], array_keys($host->ownSections()[0]->getComponents()));
    $this->assertTrue($this->hasTarget($result, $hero_section, TargetType::REMOVED));
    $this->assertTrue($this->hasTarget($result, array_key_first($slots), TargetType::ADDED));

    // A second element keeps the section when one leaves.
    $other = (new PhpUuid())->generate();
    $this->assertTrue($this->apply($host, $this->addBlock(['anchor' => $this->asideDoor, 'placement' => 'inside'], $other))->isSuccess());
    $this->assertTrue($this->apply($host, ['op' => 'remove_element', 'element' => $block])->isSuccess());
    $this->assertSame(['aside'], array_values($this->ownSlots($host)));
    $this->assertSame([$other], array_keys($host->ownSections()[0]->getComponents()));
  }

  /**
   * A locked page's reserved starts fill nothing; blank is not offered to it.
   *
   * The page takes a start (its template's slots are its to fill, so a
   * page blueprint of fills lands them: PageStartTest), but the two
   * reserved starts leave its field empty, blank included (never core's
   * marker, which a page that owns no whole document cannot carry), and
   * only template is offered of the two.
   */
  public function testLockedPageReservedStartsFillNothing(): void {
    $start = $this->container->get('daedalus_blueprint.page_start');
    $entity = $this->entityTypeManager->getStorage('entity_test_rev')->create(['name' => 'New']);
    $host = $this->host($entity);
    $this->assertTrue($start->takesStart($host), 'A page rendered inside its template carries a document: the slots it fills.');
    $this->assertFalse($start->blankOffered($host), 'An empty page is not its own to open.');
    $this->assertTrue($start->apply($host, PageStart::TEMPLATE)->isSuccess(), 'Template is the default start its pages get.');
    $this->assertSame([], $entity->get(ContentHost::LAYOUT_FIELD)->getSections());
    $this->assertTrue($start->apply($host, PageStart::BLANK)->isSuccess(), 'Blank is accepted and fills nothing.');
    $this->assertSame([], $entity->get(ContentHost::LAYOUT_FIELD)->getSections(), 'No marker: a locked page\'s field is its fills or nothing.');
    $own = $this->host($this->entityTypeManager->getStorage('entity_test')->create(['name' => 'Own']));
    $this->assertTrue($start->takesStart($own), 'An overridable bundle\'s page takes a start.');
    $this->assertTrue($start->blankOffered($own), 'And an empty page of its own.');
  }

  /**
   * A published blueprint of the section type, or an unpublished one.
   *
   * @return \Drupal\daedalus_blueprint_library\Entity\Blueprint
   *   The blueprint.
   */
  protected function blueprint(string $machine_name, string $label, bool $published = TRUE): Blueprint {
    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => (new PhpUuid())->generate(),
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Blueprint block'],
      ],
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', (new PhpUuid())->generate());
    $blueprint = Blueprint::create([
      'type' => 'section',
      'label' => $label,
      'machine_name' => $machine_name,
      'description' => 'A default for a slot.',
      'scope' => 'block',
      'subject_type' => 'entity_test_rev',
      'subject_bundle' => 'entity_test_rev',
      'status' => $published ? 1 : 0,
      ContentHost::LAYOUT_FIELD => [$section],
    ]);
    $blueprint->save();
    return $blueprint;
  }

  /**
   * Sets a template door's default, as the configure op writes it.
   */
  protected function setDoorDefault(string $uuid, ?string $default): void {
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test_rev.entity_test_rev.default');
    $component = $display->getSection(0)->getComponent($uuid);
    $component->setConfiguration([SlotBlock::DEFAULT_KEY => $default] + $component->get('configuration'));
    $display->save();
  }

  /**
   * The showing of a node on a canvas, for the current actor.
   */
  protected function showing(?HostInterface $canvas, HostInterface $holder, string $target): ?Showing {
    return $this->container->get('daedalus_blueprint.verdict_stamper')->showing($canvas, $holder, $target);
  }

  /**
   * A document's identity, as a showing names it.
   */
  protected function documentOf(HostInterface $host): array {
    return ['entity_type' => $host->entityTypeId(), 'id' => (string) $host->id(), 'label' => $host->label()];
  }

  /**
   * The showing: route, fill, default or nothing per join; NULL off a join.
   *
   * On a locked page the template's door with a default shows the default
   * (the blueprint host, its machine name, the admin's portal, claimable
   * through the door's insert verdict), a door nothing names shows
   * nothing, as does a default naming an unpublished blueprint; the page's
   * fill is what a filled door shows (the page, no portal, no claim); the
   * template's own canvas sees its door's default but may not claim it
   * (SLOT_OWN_DOCUMENT). On the frame: main hosts the route (the page, from
   * a page; the blueprint's canvas lands in the door defaulting to it, and
   * main then shows nothing); a linked placement shows its blueprint and is
   * never claimable; the frame's own canvas and a host-less route have no
   * route document and claim nothing. A section, an ordinary block, a
   * field block and the root are no join.
   */
  public function testShowing(): void {
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    $hero_default = $this->blueprint('hero_default', 'Hero default');
    $this->blueprint('hero_draft', 'Hero draft', FALSE);
    $this->setDoorDefault($this->heroDoor, 'hero_default');
    $template = $this->template();
    $empty = $this->host($this->createRevEntity(['name' => 'Empty']));

    foreach ([$this->templateSection, $this->templateBlock, $this->fieldBlock, $empty->uuid(), $template->uuid()] as $no_join) {
      $this->assertNull($this->showing($empty, $template, $no_join), "$no_join is no join: no showing.");
    }

    // DEFAULT: the door's default, a published blueprint, nothing filling.
    $showing = $this->showing($empty, $template, $this->heroDoor);
    $this->assertSame(Showing::DEFAULT, $showing->shows);
    $this->assertSame(['entity_type' => 'blueprint', 'id' => (string) $hero_default->id(), 'label' => 'Hero default'], $showing->document);
    $this->assertSame('hero_default', $showing->blueprint);
    $this->assertSame(
      ['entity_type' => 'blueprint', 'entity_id' => (string) $hero_default->id(), 'label' => 'Hero default'],
      $showing->portal->toArray(),
      'The admin may update the blueprint: the portal rides.',
    );
    $this->assertTrue($showing->claimable, 'The door is open to the page (insert) and shows its default: claimable.');
    $this->assertSame([
      'shows' => 'default',
      'document' => $showing->document,
      'blueprint' => 'hero_default',
      'portal' => $showing->portal->toArray(),
      'claimable' => TRUE,
    ], $showing->toArray());

    // NOTHING: a door without a default, and one naming a draft.
    $this->assertSame(Showing::nothing()->toArray(), $this->showing($empty, $template, $this->asideDoor)->toArray(), 'An empty door shows nothing.');
    $this->setDoorDefault($this->asideDoor, 'hero_draft');
    $showing = $this->showing($empty, $this->template(), $this->asideDoor);
    $this->assertSame(Showing::NOTHING, $showing->shows, 'A default naming no published blueprint shows nothing.');
    $this->assertNull($showing->blueprint);
    $this->assertFalse($showing->claimable);

    // FILL: the page's own section in the door.
    [$filled] = $this->lockedPageWithOwnSection();
    $showing = $this->showing($filled, $this->template(), $this->heroDoor);
    $this->assertSame(Showing::FILL, $showing->shows);
    $this->assertSame($this->documentOf($filled), $showing->document, 'The filler is the page.');
    $this->assertNull($showing->blueprint);
    $this->assertNull($showing->portal);
    $this->assertFalse($showing->claimable);
    $this->assertSame(Showing::DEFAULT, $this->showing($empty, $this->template(), $this->heroDoor)->shows, 'Another page, nothing filling: the default again.');

    // The template's own canvas: the default in view, no claim.
    $showing = $this->showing($template, $template, $this->heroDoor);
    $this->assertSame(Showing::DEFAULT, $showing->shows);
    $this->assertFalse($showing->claimable, 'A document never fills its own slot: SLOT_OWN_DOCUMENT closes the claim.');

    // The frame: main, a door defaulting to the header, a linked header.
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
        'aside' => ['label' => 'Aside', 'tier' => SlotRoster::TIER_TEMPLATE],
        'main' => ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME],
        'top' => ['label' => 'Top', 'tier' => SlotRoster::TIER_FRAME],
      ])
      ->save();
    $header = $this->blueprint('header_bp', 'Header');
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName('stark'));
    $uuids = new PhpUuid();
    $main_slot = $uuids->generate();
    $top_door = $uuids->generate();
    $linked = $uuids->generate();
    $frame_section = $this->createSection('layout_onecol', [
      [
        'uuid' => $top_door,
        'configuration' => [SlotBlock::DEFAULT_KEY => 'header_bp'] + $this->slotConfiguration('top'),
      ],
      ['uuid' => $main_slot, 'configuration' => $this->slotConfiguration(FrameHost::MAIN_SLOT)],
      [
        'uuid' => $linked,
        'configuration' => [
          'id' => LinkedBlueprintBlock::pluginId('header_bp'),
          'label' => 'Header',
          'label_display' => '0',
          'context_mapping' => LinkedBlueprintBlock::CONTEXT_MAPPING,
        ],
      ],
    ]);
    $frame_section->setThirdPartySetting('daedalus_layout', 'uuid', $uuids->generate());
    $frame = Frame::create(['id' => 'stark']);
    $frame->appendSection($frame_section);
    $frame->save();
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    $frame_host = $resolver->fromEntity($this->entityTypeManager->getStorage(Frame::ENTITY_TYPE_ID)->loadUnchanged('stark'));
    $empty = $resolver->fromEntity($empty->entity());
    $header_host = $resolver->fromEntity($header);

    // From a page: main hosts the page, the door shows the header.
    $showing = $this->showing($empty, $frame_host, $main_slot);
    $this->assertSame(Showing::ROUTE, $showing->shows, 'Main hosts the route.');
    $this->assertSame($this->documentOf($empty), $showing->document, 'The route is the page.');
    $this->assertNull($showing->portal, 'The slot hosting the route wears no portal.');
    $this->assertFalse($showing->claimable);
    $showing = $this->showing($empty, $frame_host, $top_door);
    $this->assertSame(Showing::DEFAULT, $showing->shows);
    $this->assertSame($this->documentOf($header_host), $showing->document);
    $this->assertSame('header_bp', $showing->blueprint);
    $this->assertSame('blueprint', $showing->portal->entityTypeId);
    $this->assertTrue($showing->claimable, "The frame's door is the page's to fill: claimable.");

    // A linked placement: its blueprint, never a claim.
    $showing = $this->showing($empty, $frame_host, $linked);
    $this->assertSame(Showing::DEFAULT, $showing->shows);
    $this->assertSame($this->documentOf($header_host), $showing->document);
    $this->assertSame('header_bp', $showing->blueprint);
    $this->assertSame('blueprint', $showing->portal->entityTypeId);
    $this->assertFalse($showing->claimable, 'Detach on a linked placement is its own verb, not a claim.');

    // From the header's canvas: the door hosts the route, main shows nothing.
    $showing = $this->showing($header_host, $frame_host, $top_door);
    $this->assertSame(Showing::ROUTE, $showing->shows, 'The door defaulting to the blueprint hosts its canvas.');
    $this->assertSame($this->documentOf($header_host), $showing->document);
    $this->assertNull($showing->blueprint);
    $this->assertNull($showing->portal);
    $this->assertFalse($showing->claimable);
    $this->assertSame(Showing::nothing()->toArray(), $this->showing($header_host, $frame_host, $main_slot)->toArray(), 'Main shows nothing of the page while a door hosts the route.');

    // The frame's own canvas, and a host-less route: no route document,
    // nothing claimed.
    foreach ([$frame_host, NULL] as $canvas) {
      $showing = $this->showing($canvas, $frame_host, $main_slot);
      $this->assertSame(Showing::ROUTE, $showing->shows);
      $this->assertNull($showing->document, 'No page is the route here.');
      $showing = $this->showing($canvas, $frame_host, $top_door);
      $this->assertSame(Showing::DEFAULT, $showing->shows);
      $this->assertSame('header_bp', $showing->blueprint);
      $this->assertFalse($showing->claimable, 'Nothing fills from here: no claim.');
    }
  }

  /**
   * The portal follows update access on the blueprint; the claim, insert.
   *
   * An editor who may fill the page's slots but not edit section blueprints
   * sees the default with no portal and may still claim it; one who may
   * edit the blueprint gets the portal; one without the road to the page's
   * own structure (the role policy refusing insert on the door) may not
   * claim, whatever the door shows.
   */
  public function testShowingPortalFollowsAccessAndClaimFollowsInsert(): void {
    $this->blueprint('hero_default', 'Hero default');
    $this->setDoorDefault($this->heroDoor, 'hero_default');
    $template = $this->template();
    $page = $this->host($this->createRevEntity(['name' => 'Editor page']));

    $this->setUpCurrentUser([], ['administer entity_test content', 'configure any layout']);
    $showing = $this->showing($page, $template, $this->heroDoor);
    $this->assertSame(Showing::DEFAULT, $showing->shows, 'The default is shown to every actor.');
    $this->assertNull($showing->portal, 'No door to a blueprint the editor may not edit.');
    $this->assertTrue($showing->claimable, 'The editor may fill the slot: the claim is open.');
    $this->assertTrue($this->verdicts($page, $this->heroDoor)['can']['insert']);

    $this->setUpCurrentUser([], ['administer entity_test content', 'configure any layout', 'edit any section blueprint']);
    $showing = $this->showing($page, $template, $this->heroDoor);
    $this->assertSame('blueprint', $showing->portal?->entityTypeId, 'The portal rides for an actor who may update the blueprint.');
    $this->assertTrue($showing->claimable);

    $this->setUpCurrentUser([], ['administer entity_test content', 'edit any section blueprint']);
    $showing = $this->showing($page, $template, $this->heroDoor);
    $this->assertSame(Showing::DEFAULT, $showing->shows);
    $this->assertSame('blueprint', $showing->portal?->entityTypeId);
    $this->assertFalse($showing->claimable, 'Insert refused on the door (no road to the page\'s structure): no claim.');
    $this->assertFalse($this->verdicts($page, $this->heroDoor)['can']['insert']);
  }

}
