<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\EntityTestHelper;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the verdicts a foreign document's delivery carries.
 *
 * A host with no document of its own (an entity of a bundle whose display
 * is not overridable) renders its display's document. The annotator copies
 * the spine's verdict onto every container and block node of that
 * document's tree, at every depth, as `_element.can` and `_element.refused` (everything
 * refused FOREIGN_DOCUMENT, except content on a field block presenting the
 * host's own field); the host-level verdict (hostVerdict()) names the
 * owner and carries the portal only for an actor who may open the
 * template. A host's own document and a template host's root are refused
 * nothing. A host can own a document and still be rendered inside a
 * foreign one: a page of the locked bundle carries the override field
 * (daedalus_layout adds it to every Layout Builder enabled bundle) and
 * what that field holds is the page's own, refused nothing, while the
 * template around it stays foreign and the page is never seeded.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class ForeignDocumentTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'entity_test',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_layout',
  ];

  /**
   * The non-overridable bundle.
   */
  protected const PLAIN_BUNDLE = 'plain';

  /**
   * The template's identities.
   */
  protected const SECTION_UUID = '3c000000-0000-4000-8000-000000000001';
  protected const NESTED_UUID = '3c000000-0000-4000-8000-000000000002';
  protected const BLOCK_UUID = '3c000000-0000-4000-8000-000000000003';
  protected const FIELD_UUID = '3c000000-0000-4000-8000-000000000004';

  /**
   * The identities of a locked page's own section inside the template.
   */
  protected const OWN_SECTION_UUID = '3c000000-0000-4000-8000-000000000011';
  protected const OWN_BLOCK_UUID = '3c000000-0000-4000-8000-000000000012';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['filter', 'block_content']);
    $this->setUpCurrentUser([], [], TRUE);

    BlockContentType::create(['id' => 'basic', 'label' => 'Basic', 'revision' => TRUE])->save();
    FieldStorageConfig::create(['field_name' => 'field_text', 'entity_type' => 'block_content', 'type' => 'string'])->save();
    FieldConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'block_content',
      'bundle' => 'basic',
      'label' => 'Text',
    ])->save();

    EntityTestHelper::createBundle(self::PLAIN_BUNDLE, 'Plain', 'entity_test');
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => self::PLAIN_BUNDLE,
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->appendSection($this->templateSection(self::PLAIN_BUNDLE))->save();

    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->appendSection($this->templateSection('entity_test'))->save();
  }

  /**
   * A template section.
   *
   * It holds a field block, a text block and a nested container holding a
   * second text block.
   */
  protected function templateSection(string $bundle): Section {
    $section = new Section('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', self::SECTION_UUID);
    $section->appendComponent(new SectionComponent(self::FIELD_UUID, 'content', [
      'id' => "field_block:entity_test:$bundle:name",
      'label' => 'Name',
      'label_display' => '0',
      'context_mapping' => ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
    ]));
    $section->appendComponent(new SectionComponent(self::BLOCK_UUID, 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Text',
      'label_display' => '0',
      'block_serialized' => serialize(BlockContent::create([
        'type' => 'basic',
        'info' => 'Text',
        'reusable' => FALSE,
        'field_text' => 'Hello',
      ])),
    ]));
    $nested = new Section('layout_onecol');
    $nested->setThirdPartySetting('daedalus_layout', 'uuid', self::NESTED_UUID);
    $nested->appendComponent(new SectionComponent('3c000000-0000-4000-8000-000000000005', 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Nested text',
      'label_display' => '0',
      'block_serialized' => serialize(BlockContent::create([
        'type' => 'basic',
        'info' => 'Nested',
        'reusable' => FALSE,
        'field_text' => 'Nested',
      ])),
    ]));
    $section->appendComponent(SectionBlock::createComponent($nested, 'content', 2));
    return $section;
  }

  /**
   * The annotated blueprint of an entity's host.
   */
  protected function annotated(EntityTest $entity): array {
    $host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($entity);
    $backend = $this->container->get('daedalus_blueprint.backend_resolver')->resolveFor($entity);
    return $this->container->get('daedalus_ui.element_annotator')->annotate($backend->serialize($entity), $host, 'default');
  }

  /**
   * Every uuid-bearing node of a tree, flattened.
   */
  protected function nodes(array $nodes): array {
    $flat = [];
    foreach ($nodes as $node) {
      if (isset($node['uuid'])) {
        $flat[$node['uuid']] = $node;
      }
      $flat += $this->nodes($node['children'] ?? []);
    }
    return $flat;
  }

  /**
   * The five capabilities, all one way.
   */
  protected static function all(bool $allowed): array {
    return array_fill_keys(['content', 'configure', 'style', 'arrange', 'insert'], $allowed);
  }

  /**
   * A plain host's every element carries the spine's verdict.
   */
  public function testForeignDocumentCarriesVerdicts(): void {
    $entity = EntityTest::create(['type' => self::PLAIN_BUNDLE, 'name' => 'Plain']);
    $entity->save();
    $blueprint = $this->annotated($entity);

    $this->assertSame('Plain', $blueprint['_element']['name'], 'The root is the host itself.');
    $this->assertSame(
      ['content' => TRUE, 'configure' => FALSE, 'style' => TRUE, 'arrange' => FALSE, 'insert' => FALSE],
      $blueprint['_element']['can'],
      'The root keeps content (its fields) and style (its own key); the top-level flow is the template\'s.',
    );
    $this->assertSame([['code' => 'FOREIGN_DOCUMENT', 'capabilities' => ['configure', 'arrange', 'insert']]], array_map(
      static fn (array $refusal): array => ['code' => $refusal['code'], 'capabilities' => $refusal['capabilities']],
      $blueprint['_element']['refused'],
    ));
    $this->assertNotEmpty($blueprint['layout'], 'The plain host reads the display\'s document.');

    $nodes = $this->nodes($blueprint['layout']);
    $this->assertSame([
      self::SECTION_UUID,
      self::FIELD_UUID,
      self::BLOCK_UUID,
      self::NESTED_UUID,
      '3c000000-0000-4000-8000-000000000005',
    ], array_keys($nodes), 'Every element at every depth is in the tree.');
    foreach ($nodes as $uuid => $node) {
      $can = $uuid === self::FIELD_UUID ? ['content' => TRUE] + self::all(FALSE) : self::all(FALSE);
      $this->assertSame($can, $node['_element']['can'], "$uuid carries its verdict: everything refused, but the field block presenting the host's own field keeps content.");
      $this->assertSame('FOREIGN_DOCUMENT', $node['_element']['refused'][0]['code'], "$uuid is refused as the template's.");
      $this->assertArrayNotHasKey('message', $node['_element']['refused'][0], 'An element carries the code alone; the root explains.');
    }
    // The spine's `_verdict` rides the same nodes the annotation does, and
    // the annotation's `can` is a copy of it.
    $this->assertSame(self::all(FALSE), $blueprint['layout'][0]['_verdict']['can']);
    foreach ($nodes as $uuid => $node) {
      $this->assertSame($node['_verdict']['can'], $node['_element']['can'], "$uuid's annotation copies the spine's stamp.");
    }
  }

  /**
   * A locked page owns what it holds inside the template, and nothing else.
   *
   * The locked bundle carries the override field (daedalus_layout added
   * it when the display was enabled, overridable or not). A page holding
   * one section of its own there owns that section: its nodes are refused
   * nothing, the template's nodes stay foreign, the root's structure stays
   * the template's, the render still shows the template, and the page is
   * never seeded (its own sections are exactly what its field holds). The
   * own section fills a slot: a locked page's own document is its slot
   * sections alone (the lock and unlock merge narrows the field to them
   * on read).
   */
  public function testLockedPageOwnsItsOwnSections(): void {
    $entity = EntityTest::create(['type' => self::PLAIN_BUNDLE, 'name' => 'Locked']);
    $this->assertTrue($entity->hasField(ContentHost::LAYOUT_FIELD), 'The locked bundle carries the override field.');
    $own = new Section('layout_onecol');
    $own->setThirdPartySetting('daedalus_layout', 'uuid', self::OWN_SECTION_UUID);
    $own->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, 'hero');
    $own->appendComponent(new SectionComponent(self::OWN_BLOCK_UUID, 'content', [
      'id' => 'system_powered_by_block',
      'label' => 'Own block',
      'label_display' => '0',
    ]));
    $entity->set(ContentHost::LAYOUT_FIELD, [$own]);
    $entity->save();

    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    $host = $resolver->fromEntity($entity);
    $this->assertTrue($host->hasDocument(), 'The locked page owns a document.');
    $owner = $resolver->foreignOwner($host);
    $this->assertInstanceOf(DisplayHost::class, $owner, 'And is still rendered inside the template.');
    $this->assertSame('entity_test.' . self::PLAIN_BUNDLE . '.default', $owner->id());
    $this->assertSame($owner->id(), $resolver->renderHost($host)->id(), 'The render shows the template, as before.');
    $uuids = static fn (array $sections): array => array_map(static fn (Section $section): string => $section->getThirdPartySetting('daedalus_layout', 'uuid'), $sections);
    $this->assertSame([self::OWN_SECTION_UUID], $uuids($host->ownSections()), 'The page owns exactly what its field holds: a locked page is never seeded.');
    $this->assertSame([self::SECTION_UUID, self::OWN_SECTION_UUID], $uuids($host->sections()), 'A reader sees the template first, then the page\'s own sections.');

    $blueprint = $this->annotated($entity);
    $this->assertSame(
      ['content' => TRUE, 'configure' => FALSE, 'style' => TRUE, 'arrange' => FALSE, 'insert' => FALSE],
      $blueprint['_element']['can'],
      'The root keeps its fields and its own key; its top-level flow is still the template\'s.',
    );
    $nodes = $this->nodes($blueprint['layout']);
    $this->assertSame([
      self::SECTION_UUID,
      self::FIELD_UUID,
      self::BLOCK_UUID,
      self::NESTED_UUID,
      '3c000000-0000-4000-8000-000000000005',
      self::OWN_SECTION_UUID,
      self::OWN_BLOCK_UUID,
    ], array_keys($nodes), 'One tree: the template\'s nodes, then the page\'s own.');
    foreach ([self::OWN_SECTION_UUID, self::OWN_BLOCK_UUID] as $uuid) {
      $this->assertSame(self::all(TRUE), $nodes[$uuid]['_element']['can'], "$uuid is the page's own: refused nothing.");
      $this->assertSame([], $nodes[$uuid]['_element']['refused']);
      $this->assertSame(self::all(TRUE), $nodes[$uuid]['_verdict']['can']);
    }
    foreach ([self::SECTION_UUID, self::BLOCK_UUID, self::NESTED_UUID] as $uuid) {
      $this->assertSame(self::all(FALSE), $nodes[$uuid]['_element']['can'], "$uuid is still the template's.");
      $this->assertSame('FOREIGN_DOCUMENT', $nodes[$uuid]['_element']['refused'][0]['code']);
    }
    $this->assertSame(['content' => TRUE] + self::all(FALSE), $nodes[self::FIELD_UUID]['_element']['can'], 'The field block presenting the page\'s own field keeps content.');
    // The annotator reads every document the tree shows: the template's
    // inline blocks carry their markup facts on the page canvas, as the
    // page's own blocks would.
    $this->assertArrayHasKey('markup', $nodes[self::BLOCK_UUID]['_element'], 'The template\'s inline block resolves its entity on the page canvas.');
    $this->assertArrayHasKey('markup', $nodes['3c000000-0000-4000-8000-000000000005']['_element'], 'At nested depth too.');
  }

  /**
   * The host-level verdict names the owner; the portal follows the actor.
   */
  public function testHostVerdictCarriesOwnerAndPortal(): void {
    $entity = EntityTest::create(['type' => self::PLAIN_BUNDLE, 'name' => 'Plain']);
    $entity->save();
    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')->load('entity_test.' . self::PLAIN_BUNDLE . '.default');
    $annotator = $this->container->get('daedalus_ui.element_annotator');

    // The admin may open the template: the portal rides, its url NULL
    // because the site has no manage-layout route (no field_ui).
    $verdict = $annotator->hostVerdict($this->annotated($entity));
    $this->assertSame(['content' => TRUE, 'configure' => FALSE, 'style' => TRUE, 'arrange' => FALSE, 'insert' => FALSE], $verdict['can']);
    $this->assertCount(1, $verdict['refused']);
    $refusal = $verdict['refused'][0];
    $this->assertSame('FOREIGN_DOCUMENT', $refusal['code']);
    $this->assertSame(['configure', 'arrange', 'insert'], $refusal['capabilities']);
    $this->assertNotEmpty($refusal['message']);
    $this->assertNotEmpty($refusal['hint']);
    $this->assertSame([
      'entity_type' => 'entity_view_display',
      'entity_id' => 'entity_test.' . self::PLAIN_BUNDLE . '.default',
      'label' => (string) $display->label(),
    ], $refusal['context']['owner'], 'The owner is named with its label, for the notice.');
    $this->assertSame([
      'entity_type' => 'entity_view_display',
      'entity_id' => 'entity_test.' . self::PLAIN_BUNDLE . '.default',
      'label' => (string) $display->label(),
      'url' => NULL,
    ], $refusal['context']['portal'], 'The portal names the owner and its editing url, NULL without the route.');

    // An editor who may update the page but not the display gets the same
    // refusal with no portal: no door to a room they cannot open.
    $this->setUpCurrentUser([], ['view test entity', 'administer entity_test content']);
    $verdict = $annotator->hostVerdict($this->annotated($entity));
    $refusal = $verdict['refused'][0];
    $this->assertSame('FOREIGN_DOCUMENT', $refusal['code']);
    $this->assertSame((string) $display->label(), $refusal['context']['owner']['label']);
    $this->assertArrayNotHasKey('portal', $refusal['context']);
    $this->assertTrue($verdict['can']['content'], 'The page\'s own fields stay editable.');
  }

  /**
   * A host's own document and a template host's root are refused nothing.
   */
  public function testOwnDocumentsAreRefusedNothing(): void {
    $entity = EntityTest::create(['name' => 'Own page']);
    $entity->save();
    $blueprint = $this->annotated($entity);
    $this->assertNotEmpty($blueprint['layout'], 'The overridable host is seeded from the template.');
    $this->assertSame(self::all(TRUE), $blueprint['_element']['can']);
    $this->assertSame([], $blueprint['_element']['refused']);
    $this->assertSame([], $this->container->get('daedalus_ui.element_annotator')->hostVerdict($blueprint)['refused']);
    foreach ($this->nodes($blueprint['layout']) as $uuid => $node) {
      $this->assertSame(self::all(TRUE), $node['_element']['can'], "$uuid is the host's own.");
      $this->assertSame([], $node['_element']['refused']);
    }

    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')->load('entity_test.entity_test.default');
    $host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display);
    $backend = $this->container->get('daedalus_blueprint.backend_resolver')->resolveFor($display);
    $blueprint = $this->container->get('daedalus_ui.element_annotator')->annotate($backend->serialize($display), $host, 'default');
    $this->assertSame('Template', $blueprint['_element']['type']);
    $this->assertSame($host->label(), $blueprint['_element']['name']);
    $daedalus_ui = $this->container->get('extension.list.module')->getPath('daedalus_ui');
    $this->assertSame('/' . $daedalus_ui . '/assets/outline/template.svg', $blueprint['_element']['icon'], 'A template root wears daedalus_ui\'s template glyph.');
    $this->assertSame('/' . $daedalus_ui . '/assets/outline/field-block.svg', $this->nodes($blueprint['layout'])[self::FIELD_UUID]['_element']['icon'], 'A field block wears the field-block glyph wherever it sits.');
    $this->assertSame(['mode' => 'open'], $blueprint['_element']['presentation']);
    $this->assertArrayHasKey('markup', $blueprint['_element']);
    $this->assertArrayNotHasKey('fields', $blueprint['_element']);
    $this->assertArrayNotHasKey('fields', $blueprint);
    $this->assertSame(self::all(TRUE), $blueprint['_element']['can'], 'The admin may do everything on the template.');
    foreach ($this->nodes($blueprint['layout']) as $uuid => $node) {
      $this->assertSame(self::all(TRUE), $node['_element']['can'], "$uuid is the template's own.");
    }
    $this->assertSame('Name', $this->nodes($blueprint['layout'])[self::FIELD_UUID]['_element']['type'], 'A field block on a template is worded by its plugin label, presenting no host field.');
    $this->assertArrayNotHasKey('presented_field', $this->nodes($blueprint['layout'])[self::FIELD_UUID]['_element']);
  }

}
