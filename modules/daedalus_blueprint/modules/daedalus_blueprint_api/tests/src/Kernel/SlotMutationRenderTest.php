<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_api\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_api\MutationResponseBuilder;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_blueprint_api\Render\SubtreeRenderer;
use Drupal\daedalus_blueprint_api\Render\TargetKeyMapper;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * The mutation response re-renders a slot section's keys on a locked page.
 *
 * A locked entity_test_rev bundle whose template holds a plugin block and
 * a slot block (daedalus_slot:hero); a page fills the slot with a section
 * of its own. A mutation on the page lands in that section, and the
 * response is built for the page host: the target mapper resolves the
 * new element to its section's container key through the page's own
 * document, the subtree renderer renders the page's canvas (the template
 * around, the slot section inside it through the render primitive) and
 * cuts that container out of it, so the client swaps the slot alone and
 * the template's markup is never touched. The response's blueprint is
 * the one tree of both hosts, each node stamped by its holder.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(MutationResponseBuilder::class)]
#[CoversClass(SubtreeRenderer::class)]
#[CoversClass(TargetKeyMapper::class)]
#[Group('daedalus_blueprint')]
class SlotMutationRenderTest extends BlueprintKernelTestBase {

  /**
   * The template section's uuid.
   */
  protected string $templateSection;

  /**
   * The template's plugin block uuid (outside the slot).
   */
  protected string $templateBlock;

  /**
   * The template's slot block uuid.
   */
  protected string $slotBlock;

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
    $this->createBlockContentType('basic', 'Basic');
    $this->setUpCurrentUser([], [], TRUE);

    // The locked bundle: Layout Builder on, overrides off, one template
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
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', $this->templateSection);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->removeAllSections()->appendSection($template)->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();

    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test_rev.entity_test_rev.default');
    $display->getSection(0)->appendComponent(new SectionComponent($this->slotBlock, 'content', [
      'id' => SlotBlock::PLUGIN_ID . ':hero',
      'label' => 'Hero',
      'label_display' => '0',
      'context_mapping' => SlotBlock::CONTEXT_MAPPING,
      SlotBlock::DEFAULT_KEY => NULL,
    ]));
    $display->save();
  }

  /**
   * A locked page filling the hero slot with a section holding one block.
   *
   * @return array{0: \Drupal\daedalus_blueprint\Host\HostInterface, 1: string, 2: string}
   *   The host, its slot section's uuid and its block's uuid.
   */
  protected function lockedPage(): array {
    $uuids = new PhpUuid();
    $section_uuid = $uuids->generate();
    $block_uuid = $uuids->generate();
    $section = $this->createSection('eb_test_container', [
      [
        'uuid' => $block_uuid,
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Own block', 'label_display' => '0'],
      ],
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);
    $section->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, 'hero');
    $page = $this->createRevEntity(['name' => 'Locked', ContentHost::LAYOUT_FIELD => [$section]]);
    return [$this->host($page), $section_uuid, $block_uuid];
  }

  /**
   * Runs one op on a host and builds the mutation response for it.
   *
   * @return array{0: \Drupal\daedalus_blueprint\Result\BlueprintResult, 1: array}
   *   The result and the decoded payload.
   */
  protected function mutate(HostInterface $host, array $op): array {
    $result = $this->container->get('daedalus_blueprint.operations')->batchOperations($host, [$op]);
    $request = Request::create('/daedalus-blueprint/entity_test_rev/' . $host->id(), 'POST', [], [], [], [], Json::encode(['operations' => [$op]]));
    $response = $this->container->get('daedalus_blueprint_api.mutation_response_builder')->build($request, $host->entity(), $result, NULL, [], 'full', $host);
    return [$result, Json::decode($response->getContent())];
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
   * An insert into the slot re-renders the slot section alone.
   */
  public function testInsertIntoSlotReRendersTheSlotSection(): void {
    [$host, $section_uuid, $block_uuid] = $this->lockedPage();
    $added = (new PhpUuid())->generate();
    [$result, $payload] = $this->mutate($host, [
      'op' => 'add_element',
      'uuid' => $added,
      'plugin_id' => 'system_powered_by_block',
      'location' => ['anchor' => $block_uuid, 'placement' => 'after'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertTrue($payload['success']);
    $this->assertSame([], $payload['warnings'], 'The render of the page did not fail.');

    // The mapper: the added element's smallest subtree is its section's
    // container, resolved through the page's own document.
    $this->assertSame(
      [SelectableKey::container($section_uuid)],
      $this->container->get('daedalus_blueprint_api.target_key_mapper')->map($host, $result->getTargets()),
      'The slot section is the key the mutation re-renders.',
    );

    // The markup: the slot section's subtree alone, cut out of the page's
    // render, the new block inside it and nothing of the template.
    $container_key = SelectableKey::container($section_uuid);
    $this->assertSame([$container_key], array_keys($payload['markup']), 'The response swaps the slot section, not the whole page: ' . implode(', ', array_keys($payload['markup'])));
    $markup = $payload['markup'][$container_key];
    $xpath = $this->domXpath($markup);
    $this->assertSame(1, $xpath->query('//*[@data-selectable="' . $container_key . '"]')->length, "The fragment is the slot section itself: $markup");
    $this->assertSame(1, $xpath->query('//*[@data-selectable="' . SelectableKey::block($block_uuid) . '"]')->length, 'The page\'s block is in it.');
    $this->assertSame(1, $xpath->query('//*[@data-selectable="' . SelectableKey::block($added) . '"]')->length, "And the block the mutation added: $markup");
    $this->assertSame(0, $xpath->query('//*[@data-selectable="' . SelectableKey::block($this->templateBlock) . '"]')->length, 'The template\'s block is outside the swap.');
    $this->assertSame(0, $xpath->query('//*[@data-selectable="' . SelectableKey::container($this->templateSection) . '"]')->length, 'So is the template\'s section.');
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid="' . $this->slotBlock . '"]')->length, 'The fragment is the section inside the slot block, not the slot block.');

    // The blueprint half: one tree of both hosts, each node stamped by
    // its holder, the template foreign and the slot section the page's.
    $blueprint = $payload['blueprint'];
    $this->assertSame($this->templateSection, $blueprint['layout'][0]['uuid']);
    $this->assertFalse($blueprint['layout'][0]['_verdict']['can']['insert'], 'The template section is foreign on the page.');
    $this->assertSame('FOREIGN_DOCUMENT', $blueprint['layout'][0]['_verdict']['refused'][0]['code']);
    $this->assertSame($section_uuid, $blueprint['layout'][1]['uuid']);
    $this->assertSame('hero', $blueprint['layout'][1]['slot']);
    $open = ['can' => array_fill_keys(['content', 'configure', 'style', 'arrange', 'insert'], TRUE), 'refused' => []];
    $this->assertSame($open, $blueprint['layout'][1]['_verdict'], 'The slot section is the page\'s own.');
    $children = array_column($blueprint['layout'][1]['children'], NULL, 'uuid');
    $this->assertSame([$block_uuid, $added], array_keys($children), 'The tree shows the insert in the slot section.');
    $this->assertTrue($children[$added]['_verdict']['can']['arrange']);
    // The annotation (daedalus_ui) copies the stamp and rides the response.
    $this->assertSame($blueprint['layout'][1]['_verdict']['can'], $blueprint['layout'][1]['_element']['can']);
    $this->assertSame('FOREIGN_DOCUMENT', $payload['verdict']['refused'][0]['code'], 'The host-level verdict rides beside the blueprint.');
  }

  /**
   * A removal of the slot's last block falls back to the whole page.
   *
   * The last element leaving a slot section empties the slot (the section
   * goes with it, the op door's rule), and a removed node has no
   * surviving subtree, so the response re-renders the page under its
   * entity key: the template around the empty slot, rendered through the
   * same stack (the stand-in it wears on the canvas is SlotRenderTest's).
   */
  public function testRemovalFromSlotFallsBackToThePage(): void {
    [$host, $section_uuid, $block_uuid] = $this->lockedPage();
    [$result, $payload] = $this->mutate($host, ['op' => 'remove_element', 'element' => $block_uuid]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([], $payload['warnings']);

    $entity_key = SelectableKey::entity($host->uuid());
    $this->assertSame([$entity_key], array_keys($payload['markup']), 'A removal re-renders the whole page.');
    $xpath = $this->domXpath($payload['markup'][$entity_key]);
    $this->assertSame(1, $xpath->query('//*[@data-selectable="' . SelectableKey::container($this->templateSection) . '"]')->length, 'The template renders around.');
    $this->assertSame(0, $xpath->query('//*[@data-selectable="' . SelectableKey::container($section_uuid) . '"]')->length, 'The slot section left with its last element: the slot is empty again.');
    $this->assertSame([], $host->ownSections(), 'The page owns no section any more.');
    $this->assertSame(0, $xpath->query('//*[@data-selectable="' . SelectableKey::block($block_uuid) . '"]')->length, 'The removed block is gone.');
    $this->assertSame(1, $xpath->query('//*[@data-selectable="' . SelectableKey::block($this->templateBlock) . '"]')->length, 'The template\'s block is where it was.');
  }

  /**
   * A mutation aimed at the template is refused and renders nothing.
   */
  public function testTemplateTargetIsRefusedOnThePage(): void {
    [$host] = $this->lockedPage();
    [$result, $payload] = $this->mutate($host, ['op' => 'remove_element', 'element' => $this->templateBlock]);
    $this->assertFalse($result->isSuccess());
    $this->assertFalse($payload['success']);
    $this->assertSame('FOREIGN_DOCUMENT', $payload['errors'][0]['code']);
    $this->assertSame([], (array) $payload['markup'], 'A refused mutation swaps nothing.');
    $this->assertInstanceOf(BlueprintResult::class, $result);
  }

}
