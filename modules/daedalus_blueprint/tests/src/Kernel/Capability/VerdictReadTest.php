<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Capability;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_api\Controller\BlueprintApiController;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Every read carries the verdict the op door enforces.
 *
 * On a plain host (a page of a non-overridable bundle, rendering its
 * template and owning only its fields) the full read, the summary, the
 * element read and the HTTP GET read stamp FOREIGN_DOCUMENT on every
 * template element (a field block presenting the page's own field keeps
 * content and nothing else), the root keeps its content and its own
 * style key while its structure is the template's, the explanation and
 * the portal ride the root's entry (the portal only for an actor who may
 * open the template), and the root's fields carry no stamp of their own.
 * An own document read by an actor who may do everything carries a stamp
 * that refuses nothing. A locked page filling a template slot reads one
 * tree of two hosts' documents, each node stamped with the verdict of the
 * host whose document holds it: the template's nodes (the slot block
 * among them) foreign, the page's slot section and what it holds open.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(VerdictStamper::class)]
#[Group('daedalus_blueprint')]
class VerdictReadTest extends BlueprintKernelTestBase {

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
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * A plain host: a page of a non-overridable bundle rendering its template.
   *
   * The template holds one section with a plugin block and a field block
   * presenting the bundle's own name field.
   *
   * @return array{0: \Drupal\daedalus_blueprint\Host\HostInterface, 1: string, 2: string, 3: string}
   *   The host, the section's uuid, the plugin block's uuid and the field
   *   block's uuid.
   */
  protected function plainHost(): array {
    $uuids = new PhpUuid();
    $section_uuid = $uuids->generate();
    $block_uuid = $uuids->generate();
    $field_block_uuid = $uuids->generate();
    $template = $this->createSection('layout_onecol', [
      [
        'uuid' => $block_uuid,
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Template block'],
      ],
      [
        'uuid' => $field_block_uuid,
        'configuration' => ['id' => 'field_block:entity_test_rev:entity_test_rev:name', 'label' => 'Name'],
      ],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);
    $display = LayoutBuilderEntityViewDisplay::load('entity_test_rev.entity_test_rev.default') ?? LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->removeAllSections()->appendSection($template)->save();

    $host = $this->host($this->createRevEntity(['name' => 'Plain']));
    $this->assertTrue($host->hasDocument(), 'The page owns a document (the override field is on every enabled bundle), empty here.');
    $this->assertNotNull($this->container->get('daedalus_blueprint.host_resolver')->foreignOwner($host), 'And it renders its template, the document it does not own.');
    return [$host, $section_uuid, $block_uuid, $field_block_uuid];
  }

  /**
   * A locked page filling a template slot with a section of its own.
   *
   * The template of plainHost() gains a slot block (daedalus_slot:hero);
   * the page's own document holds one top-level section tagged `hero`
   * with a plugin block.
   *
   * @return array{0: \Drupal\daedalus_blueprint\Host\HostInterface, 1: string, 2: string, 3: string}
   *   The host, the slot block's uuid, the page's slot section uuid and
   *   its block's uuid.
   */
  protected function lockedSlotHost(): array {
    [, $section_uuid] = $this->plainHost();
    $uuids = new PhpUuid();
    $slot_block_uuid = $uuids->generate();
    $own_section_uuid = $uuids->generate();
    $own_block_uuid = $uuids->generate();

    // The site's one slot; the roster save rebuilds the block definitions.
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();
    $display = LayoutBuilderEntityViewDisplay::load('entity_test_rev.entity_test_rev.default');
    $section = $display->getSection(0);
    $this->assertSame($section_uuid, $section->getThirdPartySetting('daedalus_layout', 'uuid'));
    $section->appendComponent(new SectionComponent($slot_block_uuid, 'content', [
      'id' => SlotBlock::PLUGIN_ID . ':hero',
      'label' => 'Hero',
      'label_display' => '0',
      'context_mapping' => SlotBlock::CONTEXT_MAPPING,
      SlotBlock::DEFAULT_KEY => NULL,
    ]));
    $display->save();

    $own = $this->createSection('layout_onecol', [
      ['uuid' => $own_block_uuid, 'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Own block']],
    ]);
    $own->setThirdPartySetting('daedalus_layout', 'uuid', $own_section_uuid);
    $own->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, 'hero');
    $host = $this->host($this->createRevEntity(['name' => 'Locked', ContentHost::LAYOUT_FIELD => [$own]]));
    return [$host, $slot_block_uuid, $own_section_uuid, $own_block_uuid];
  }

  /**
   * Asserts a node is the page's own: every capability open, nothing refused.
   */
  protected function assertOwn(array $node, string $message = ''): void {
    $this->assertSame(['can' => self::ALL, 'refused' => []], $node['_verdict'], $message);
  }

  /**
   * The block nodes of a layout_onecol container: its content region node's.
   *
   * @return array<string, array>
   *   The nodes keyed by uuid.
   */
  protected function componentsOf(array $section): array {
    $by_uuid = [];
    foreach ($section['children'][0]['children'] ?? [] as $component) {
      $by_uuid[$component['uuid']] = $component;
    }
    return $by_uuid;
  }

  /**
   * Asserts a template element's stamp: everything foreign but what is named.
   *
   * @param array $node
   *   The stamped node.
   * @param string[] $kept
   *   The capabilities the element keeps.
   * @param string $message
   *   Why.
   */
  protected function assertForeign(array $node, array $kept = [], string $message = ''): void {
    $can = self::NONE;
    foreach ($kept as $capability) {
      $can[$capability] = TRUE;
    }
    $this->assertSame($can, $node['_verdict']['can'], $message);
    $refused = array_keys(array_filter($can, static fn (bool $allowed): bool => !$allowed));
    $this->assertSame(
      [['code' => 'FOREIGN_DOCUMENT', 'capabilities' => $refused]],
      $node['_verdict']['refused'],
      'An element entry carries the code alone; the explanation is the root\'s.',
    );
  }

  /**
   * The full read, the summary and read_element of a plain host.
   */
  public function testPlainHostReadsCarryRefusals(): void {
    [$host, $section_uuid, $block_uuid, $field_block_uuid] = $this->plainHost();
    $serializer = $this->container->get('daedalus_blueprint.serializer');

    $blueprint = $serializer->serialize($host);
    $root = $blueprint['_verdict'];
    $this->assertSame(
      ['content' => TRUE, 'configure' => FALSE, 'style' => TRUE, 'arrange' => FALSE, 'insert' => FALSE],
      $root['can'],
      'The root keeps its fields and its own style key; its structure is the template\'s.',
    );
    $this->assertCount(1, $root['refused']);
    $refusal = $root['refused'][0];
    $this->assertSame('FOREIGN_DOCUMENT', $refusal['code']);
    $this->assertSame(['configure', 'arrange', 'insert'], $refusal['capabilities']);
    $this->assertSame('The layout of this entity_test_rev belongs to the template entity_view_display entity_test_rev.entity_test_rev.default, which it renders but does not own.', $refusal['message']);
    $this->assertStringContainsString('run the operation against entity_view_display entity_test_rev.entity_test_rev.default', $refusal['hint'], 'The admin may open the template: the corrective says where.');
    $this->assertSame(self::OWNER, $refusal['context']['owner']);
    $this->assertSame('entity_test_rev.entity_test_rev.default', $refusal['context']['portal']['entity_id'], 'The portal is offered to an actor who may open the template.');
    $this->assertSame('Plain', $blueprint['fields']['name'], 'The root\'s fields carry no stamp: the root\'s content verdict is theirs.');

    $section = $blueprint['layout'][0];
    $this->assertSame($section_uuid, $section['uuid']);
    $this->assertForeign($section);
    $components = $this->componentsOf($section);
    $this->assertForeign($components[$block_uuid]);
    $this->assertForeign($components[$field_block_uuid], ['content'], 'A field block presenting the page\'s own field keeps that field\'s content verdict and nothing else.');

    $summary = $serializer->serializeSummary($host);
    $this->assertSame($root, $summary['_verdict'], 'The summary carries the same root verdict.');
    $this->assertForeign($summary['layout'][0]);
    $this->assertForeign($this->componentsOf($summary['layout'][0])[$block_uuid]);

    $operations = $this->container->get('daedalus_blueprint.operations');
    $element = $operations->getElement($host, $block_uuid);
    $this->assertNotNull($element, 'A template element reads on the plain host that renders it.');
    $this->assertSame('system_powered_by_block', $element['plugin_id']);
    $this->assertForeign($element);
    $element = $operations->getElement($host, $section_uuid);
    $this->assertForeign($element);
    $this->assertForeign($this->componentsOf($element)[$field_block_uuid], ['content'], 'The container\'s subtree is stamped too.');

    // An editor who may update the page but not the template: the cause
    // rides the root's entry, the portal does not, and the corrective says
    // the template is not theirs.
    $this->setUpCurrentUser([], ['administer entity_test content']);
    $refusal = $serializer->serialize($host)['_verdict']['refused'][0];
    $this->assertSame('FOREIGN_DOCUMENT', $refusal['code']);
    $this->assertSame(self::OWNER, $refusal['context']['owner']);
    $this->assertArrayNotHasKey('portal', $refusal['context']);
    $this->assertStringContainsString('which you may not edit', $refusal['hint']);
  }

  /**
   * The HTTP GET read of a plain host, in its full and element modes.
   */
  public function testPlainHostGetReadCarriesRefusals(): void {
    [$host, $section_uuid, $block_uuid] = $this->plainHost();
    $controller = BlueprintApiController::create($this->container);

    $response = $controller->read(Request::create('/daedalus-blueprint/entity_test_rev/' . $host->id(), 'GET', ['mode' => 'full']), 'entity_test_rev', $host->entity());
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode($response->getContent(), TRUE);
    $blueprint = $payload['blueprint'];
    $this->assertSame(['configure', 'arrange', 'insert'], $blueprint['_verdict']['refused'][0]['capabilities']);
    $this->assertSame(self::OWNER, $blueprint['_verdict']['refused'][0]['context']['owner']);
    $this->assertForeign($blueprint['layout'][0]);
    $this->assertForeign($this->componentsOf($blueprint['layout'][0])[$block_uuid]);
    $this->assertSame('Plain', $blueprint['fields']['name']);

    $request = Request::create('/daedalus-blueprint/entity_test_rev/' . $host->id(), 'GET', [
      'mode' => 'element',
      'uuid' => $block_uuid,
    ]);
    $response = $controller->read($request, 'entity_test_rev', $host->entity());
    $this->assertSame(200, $response->getStatusCode());
    $element = json_decode($response->getContent(), TRUE)['blueprint'];
    $this->assertSame($block_uuid, $element['uuid']);
    $this->assertForeign($element);

    $response = $controller->read(Request::create('/daedalus-blueprint/entity_test_rev/' . $host->id(), 'GET', ['mode' => 'summary']), 'entity_test_rev', $host->entity());
    $summary = json_decode($response->getContent(), TRUE)['blueprint'];
    $this->assertSame($section_uuid, $summary['layout'][0]['uuid']);
    $this->assertForeign($summary['layout'][0]);
  }

  /**
   * A locked page with a slot: one tree, each node stamped by its holder.
   *
   * The full read, the summary, the element read and the HTTP GET read
   * all deliver the template's nodes (the slot block among them) as
   * foreign and the page's slot section with everything in it as the
   * page's own; the root keeps the plain host's split; the slot section
   * prints its slot id.
   */
  public function testLockedPageWithSlotReadsCarryBothHostsVerdicts(): void {
    [$host, $slot_block_uuid, $own_section_uuid, $own_block_uuid] = $this->lockedSlotHost();
    $serializer = $this->container->get('daedalus_blueprint.serializer');

    $blueprint = $serializer->serialize($host);
    $this->assertSame(
      ['content' => TRUE, 'configure' => FALSE, 'style' => TRUE, 'arrange' => FALSE, 'insert' => FALSE],
      $blueprint['_verdict']['can'],
      'The root keeps its fields and its own style key; its top-level flow is still the template\'s.',
    );
    $this->assertSame('FOREIGN_DOCUMENT', $blueprint['_verdict']['refused'][0]['code']);
    $this->assertSame(self::OWNER, $blueprint['_verdict']['refused'][0]['context']['owner']);

    $this->assertCount(2, $blueprint['layout'], 'One tree: the template\'s section, then the page\'s slot section.');
    [$template, $slot] = $blueprint['layout'];
    $this->assertForeign($template, [], 'The template section is the template\'s.');
    $template_components = $this->componentsOf($template);
    $this->assertArrayHasKey($slot_block_uuid, $template_components, 'The slot block is a node of the template.');
    $this->assertForeign($template_components[$slot_block_uuid], ['insert'], 'The slot block itself is the template\'s (only the site builder places or removes a slot), except that it is the door the page fills: insert is the page\'s own.');
    $this->assertSame('hero', $template_components[$slot_block_uuid]['slot'], 'The slot block prints the slot it declares.');
    $this->assertArrayNotHasKey('slot', $template);

    $this->assertSame($own_section_uuid, $slot['uuid']);
    $this->assertSame('hero', $slot['slot'], 'The page\'s section prints the slot it fills.');
    $this->assertOwn($slot, 'The slot section is the page\'s own: every capability open.');
    $this->assertOwn($this->componentsOf($slot)[$own_block_uuid], 'And so is the block it holds.');

    $summary = $serializer->serializeSummary($host);
    $this->assertSame($blueprint['_verdict'], $summary['_verdict']);
    $this->assertForeign($summary['layout'][0]);
    $this->assertSame('hero', $summary['layout'][1]['slot']);
    $this->assertOwn($summary['layout'][1]);

    $operations = $this->container->get('daedalus_blueprint.operations');
    $this->assertForeign($operations->getElement($host, $slot_block_uuid), ['insert'], 'The slot block reads foreign on its own, the door open.');
    $element = $operations->getElement($host, $own_section_uuid);
    $this->assertOwn($element, 'The slot section reads as the page\'s own on its own.');
    $this->assertOwn($this->componentsOf($element)[$own_block_uuid]);
    $this->assertOwn($operations->getElement($host, $own_block_uuid));

    $controller = BlueprintApiController::create($this->container);
    $response = $controller->read(Request::create('/daedalus-blueprint/entity_test_rev/' . $host->id(), 'GET', ['mode' => 'full']), 'entity_test_rev', $host->entity());
    $this->assertSame(200, $response->getStatusCode());
    $read = json_decode($response->getContent(), TRUE)['blueprint'];
    $this->assertSame(['configure', 'arrange', 'insert'], $read['_verdict']['refused'][0]['capabilities']);
    $this->assertForeign($read['layout'][0]);
    $this->assertForeign($this->componentsOf($read['layout'][0])[$slot_block_uuid], ['insert']);
    $this->assertSame('hero', $read['layout'][1]['slot']);
    $this->assertOwn($read['layout'][1]);
    $this->assertOwn($this->componentsOf($read['layout'][1])[$own_block_uuid]);

    $request = Request::create('/daedalus-blueprint/entity_test_rev/' . $host->id(), 'GET', [
      'mode' => 'element',
      'uuid' => $own_block_uuid,
    ]);
    $response = $controller->read($request, 'entity_test_rev', $host->entity());
    $this->assertOwn(json_decode($response->getContent(), TRUE)['blueprint']);

    // An editor who may update the page and change layouts but not open
    // the template: the slot section stays open, the template foreign.
    $this->setUpCurrentUser([], ['administer entity_test content', 'configure any layout']);
    $blueprint = $serializer->serialize($host);
    $this->assertOwn($blueprint['layout'][1], 'The editor may do everything to the page\'s own slot section.');
    $this->assertForeign($blueprint['layout'][0]);
    $this->assertArrayNotHasKey('portal', $blueprint['_verdict']['refused'][0]['context']);
  }

  /**
   * An own document read by an actor who may do everything refuses nothing.
   */
  public function testOwnDocumentCarriesNoRefusals(): void {
    $this->enableLayoutBuilder();
    $uuids = new PhpUuid();
    $section_uuid = $uuids->generate();
    $block_uuid = $uuids->generate();
    $section = $this->createSection('layout_onecol', [
      ['uuid' => $block_uuid, 'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Mine']],
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);
    $host = $this->host($this->createTestEntity(['name' => 'Own', 'layout_builder__layout' => [$section]]));
    $this->assertTrue($host->hasDocument());

    $serializer = $this->container->get('daedalus_blueprint.serializer');
    $blueprint = $serializer->serialize($host);
    $this->assertSame(['can' => self::ALL, 'refused' => []], $blueprint['_verdict']);
    $this->assertSame(['can' => self::ALL, 'refused' => []], $blueprint['layout'][0]['_verdict']);
    $this->assertSame(['can' => self::ALL, 'refused' => []], $this->componentsOf($blueprint['layout'][0])[$block_uuid]['_verdict']);
    $element = $this->container->get('daedalus_blueprint.operations')->getElement($host, $block_uuid);
    $this->assertSame(['can' => self::ALL, 'refused' => []], $element['_verdict']);

    // The stamp is delivery-only: a stamped read writes as written through
    // the one road that takes a whole layout, a create (an existing
    // entity's document is changed through operations), the root's
    // `_verdict` a known root key and every node's ignored by the grammar.
    unset($blueprint['_root_entity']);
    $result = $this->container->get('daedalus_blueprint.deserializer')->deserialize($blueprint);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $created = $this->host($result->getEntity());
    $this->assertSame($section_uuid, $this->container->get('daedalus_blueprint.node_tree_builder')->build($created)[0]['uuid'], 'The stamped tree instantiated with its identity kept and nothing of the stamp.');
  }

}
