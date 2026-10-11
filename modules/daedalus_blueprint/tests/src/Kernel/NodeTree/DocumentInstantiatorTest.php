<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\NodeTree;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the node grammar spells everything a read emits.
 *
 * The instantiator's grammar is the lean tree's write grammar: a block
 * node carries its view_mode and, for an inline block, its managed
 * configuration, both reaching the op as properties; what a read carries
 * that no write can mean (delivery annotations, storage residue, blueprint
 * provenance below a root) is ignored rather than refused; label_rendered
 * is the one spelling for a shown label. Together those make a tree read
 * from one host instantiate onto another as it is.
 */
#[CoversClass(DocumentInstantiator::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DocumentInstantiatorTest extends BlueprintKernelTestBase {

  /**
   * The identities the batch mints on the source host.
   */
  protected const SECTION_UUID = '2b000000-0000-4000-8000-000000000001';
  protected const BLOCK_UUID = '2b000000-0000-4000-8000-000000000002';
  protected const PLUGIN_UUID = '2b000000-0000-4000-8000-000000000003';
  protected const NESTED_UUID = '2b000000-0000-4000-8000-000000000004';
  protected const INNER_UUID = '2b000000-0000-4000-8000-000000000005';

  /**
   * The template's slot block, and a page's fills with their blocks.
   */
  protected const SLOT_UUID = '2b000000-0000-4000-8000-000000000006';
  protected const HERO_UUID = '2b000000-0000-4000-8000-000000000007';
  protected const HERO_BLOCK_UUID = '2b000000-0000-4000-8000-000000000008';
  protected const ASIDE_UUID = '2b000000-0000-4000-8000-000000000009';
  protected const ASIDE_BLOCK_UUID = '2b000000-0000-4000-8000-00000000000a';

  /**
   * The instantiator under test.
   */
  protected DocumentInstantiator $instantiator;

  /**
   * The node tree builder.
   */
  protected NodeTreeBuilder $builder;

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperationsInterface
   */
  protected $operations;

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
      'file',
      'navigation',
      'twig_events',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_layout',
      'daedalus_stylish',
      // The unbound site: literal paddings land as written.
      'daedalus_stylish_unbound_test',
      // Declares yaml_setting as a managed key of yaml_test_block.
      'daedalus_blueprint_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['block_content', 'daedalus_stylish']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->enableLayoutBuilder();
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');

    $this->createBlockContentType('yaml_test_block', 'YAML block');
    $this->createBlockField('body', 'text_with_summary', 'yaml_test_block');

    $this->instantiator = $this->container->get('daedalus_blueprint.document_instantiator');
    $this->builder = $this->container->get('daedalus_blueprint.node_tree_builder');
    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * The component configurations of a host's document, keyed by uuid.
   *
   * Nested sections are walked: a container component's section is read
   * through the adapter, so every block at any depth answers.
   */
  protected function components(object $host): array {
    $configurations = [];
    $walk = function (array $sections) use (&$walk, &$configurations): void {
      $adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
      foreach ($sections as $section) {
        foreach ($section->getComponents() as $uuid => $component) {
          $configurations[$uuid] = $component->get('configuration');
          $nested = $adapter->embeddedSection($component);
          if ($nested !== NULL) {
            $walk([$nested]);
          }
        }
      }
    };
    $walk($host->sections());
    return $configurations;
  }

  /**
   * A tree with every uuid removed, for an identity-free comparison.
   */
  protected static function withoutIdentity(array $nodes): array {
    foreach ($nodes as $index => $node) {
      if (!is_array($node)) {
        continue;
      }
      unset($node['uuid']);
      if (isset($node['children'])) {
        $node['children'] = self::withoutIdentity($node['children']);
      }
      $nodes[$index] = $node;
    }
    return $nodes;
  }

  /**
   * The error codes of a result, keyed by path.
   */
  protected static function codesByPath(array $errors): array {
    $by_path = [];
    foreach ($errors as $error) {
      assert($error instanceof BlueprintError);
      $by_path[$error->getPath()] = $error->getCode();
    }
    return $by_path;
  }

  /**
   * The error messages of an expansion, keyed by path.
   */
  protected static function errorsByPath(array $errors): array {
    $by_path = [];
    foreach ($errors as $error) {
      assert($error instanceof BlueprintError);
      $by_path[$error->getPath()] = $error->getMessage();
    }
    return $by_path;
  }

  /**
   * A block node carries its view_mode and its managed configuration.
   */
  public function testBlockNodeCarriesViewModeAndManagedConfiguration(): void {
    $entity = $this->createTestEntity();
    $host = $this->host($entity);
    $result = $this->instantiator->instantiate($host, [
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'children' => [
          [
            'kind' => 'block',
            'uuid' => self::BLOCK_UUID,
            'block_type' => 'yaml_test_block',
            'label' => 'Ribbon',
            'label_rendered' => TRUE,
            'view_mode' => 'teaser',
            'block_fields' => ['body' => ['value' => '<p>Ribbon</p>', 'format' => 'plain_text']],
            'configuration' => ['yaml_setting' => 'ribbon'],
          ],
          [
            'kind' => 'block',
            'uuid' => self::INNER_UUID,
            'block_type' => 'yaml_test_block',
            // A read spells an unset managed key as null: it writes nothing.
            'configuration' => ['yaml_setting' => NULL],
          ],
        ],
      ],
    ], ['anchor' => $entity->uuid(), 'placement' => 'inside'], [
      'mint' => static fn (?string $source): string => $source ?? (new PhpUuid())->generate(),
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $components = $this->components($host);
    $ribbon = $components[self::BLOCK_UUID];
    $this->assertSame('teaser', $ribbon['view_mode'], 'The node\'s view_mode is the placement\'s.');
    $this->assertSame('ribbon', $ribbon['yaml_setting'], 'The managed key reached the op as a property.');
    $this->assertSame('Ribbon', $ribbon['label']);
    $this->assertSame('visible', $ribbon['label_display'], 'label_rendered is core\'s visible at the op.');
    $this->assertSame('<p>Ribbon</p>', $this->unserializeBlock($ribbon['block_serialized'])->get('body')->value);

    $bare = $components[self::INNER_UUID];
    $this->assertArrayNotHasKey('yaml_setting', $bare, 'A null managed value is the absent key.');
    $this->assertSame('full', $bare['view_mode'], 'Without a view_mode the op\'s default stands.');
    $this->assertSame('0', $bare['label_display'], 'Without label_rendered the label is hidden.');

    // The read answers what was written, in the tree's own spelling.
    $tree = $this->builder->build($host);
    $block = NodeTreeBuilder::find($tree, self::BLOCK_UUID);
    $this->assertSame(['yaml_setting' => 'ribbon'], $block['configuration']);
    $this->assertTrue($block['label_rendered']);
    $this->assertSame(['yaml_setting' => NULL], NodeTreeBuilder::find($tree, self::INNER_UUID)['configuration']);
  }

  /**
   * The grammar refuses what a write cannot mean, each with a corrective.
   */
  public function testBlockNodeGrammarCorrectives(): void {
    $host = $this->host($this->createTestEntity());
    $location = ['anchor' => 'c0000000-0000-4000-8000-000000000000', 'placement' => 'inside'];
    $container = static fn (array $block): array => [
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'children' => [$block + ['kind' => 'block']],
      ],
    ];

    // The label_display key left the grammar: the corrective names the
    // spelling.
    $expansion = $this->instantiator->expand($host, $container([
      'block_type' => 'yaml_test_block',
      'label' => 'A',
      'label_display' => 'visible',
    ]), $location);
    $this->assertFalse($expansion->isValid());
    $this->assertCount(1, $expansion->errors);
    $this->assertSame('nodes.children[0]', $expansion->errors[0]->getPath());
    $this->assertStringContainsString('"label_display"', $expansion->errors[0]->getMessage());
    $this->assertStringContainsString('label_rendered: true is the one spelling', $expansion->errors[0]->getHint());

    // A plugin block has no view_mode.
    $expansion = $this->instantiator->expand($host, $container([
      'plugin_id' => 'system_powered_by_block',
      'view_mode' => 'full',
    ]), $location);
    $this->assertSame(['nodes.children[0].view_mode' => 'A plugin block has no view_mode.'], self::errorsByPath($expansion->errors));

    // The view mode is an id.
    $expansion = $this->instantiator->expand($host, $container(['block_type' => 'yaml_test_block', 'view_mode' => '']), $location);
    $this->assertSame(['nodes.children[0].view_mode' => 'view_mode is a view mode id.'], self::errorsByPath($expansion->errors));

    // The configuration is a map, and a key is named once.
    $expansion = $this->instantiator->expand($host, $container(['block_type' => 'yaml_test_block', 'configuration' => ['a']]), $location);
    $this->assertSame([
      'nodes.children[0].configuration' => 'configuration is a map of the block type\'s managed keys to values.',
    ], self::errorsByPath($expansion->errors));
    $expansion = $this->instantiator->expand($host, $container([
      'block_type' => 'yaml_test_block',
      'block_fields' => ['yaml_setting' => 'as a field'],
      'configuration' => ['yaml_setting' => 'as configuration'],
    ]), $location);
    $this->assertSame([
      'nodes.children[0].configuration.yaml_setting' => '"yaml_setting" is named in both block_fields and configuration.',
    ], self::errorsByPath($expansion->errors));

    // A key that is neither a field nor a managed key is the op's refusal,
    // re-rooted onto the node: the grammar passes the bag through.
    $entity = $this->createTestEntity();
    $result = $this->instantiator->instantiate(
      $this->host($entity),
      $container(['block_type' => 'yaml_test_block', 'configuration' => ['not_managed' => 'x']]),
      ['anchor' => $entity->uuid(), 'placement' => 'inside'],
    );
    $this->assertFalse($result->isSuccess());
    $this->assertSame('UNKNOWN_PROPERTY', $result->getErrors()[0]->getCode());
    $this->assertSame('nodes.children[0].properties.not_managed', $result->getErrors()[0]->getPath());
    $this->assertContains('yaml_setting', $result->getErrors()[0]->getContext()['available']);
  }

  /**
   * Read-only keys a read carries are ignored on write, never stored.
   */
  public function testReadOnlyKeysAreIgnoredOnWrite(): void {
    $annotations = [
      '_element' => ['type' => 'block', 'name' => 'Ribbon', 'can' => ['content']],
      VerdictStamper::KEY => ['can' => ['content'], 'refused' => []],
    ];
    $nodes = [
      [
        'kind' => 'container',
        'uuid' => self::SECTION_UUID,
        'layout_id' => 'layout_twocol_section',
        'section_index' => 7,
        // A root's provenance is a birth fact of this placement: it travels.
        'blueprint' => 'root_stamp',
        'children' => [
          [
            'kind' => 'region',
            'region' => 'first',
            '_element' => ['type' => 'region'],
            'children' => [
              [
                'kind' => 'block',
                'uuid' => self::BLOCK_UUID,
                'block_type' => 'yaml_test_block',
                'block_revision_id' => 999,
                // Below the root, provenance names a placement this
                // expansion is not making: ignored.
                'blueprint' => 'nested_stamp',
              ] + $annotations,
              [
                'kind' => 'container',
                'uuid' => self::NESTED_UUID,
                'layout_id' => 'layout_onecol',
                'section_index' => 0,
                'blueprint' => 'nested_band',
                'children' => [
                  [
                    'kind' => 'block',
                    'uuid' => self::PLUGIN_UUID,
                    'plugin_id' => 'system_powered_by_block',
                    'blueprint' => 'nested_plugin',
                  ] + $annotations,
                ],
              ] + $annotations,
            ],
          ],
          ['kind' => 'region', 'region' => 'second', 'children' => []],
        ],
      ] + $annotations,
    ];
    $location = ['anchor' => 'c0000000-0000-4000-8000-000000000000', 'placement' => 'inside'];
    $host = $this->host($this->createTestEntity());

    $expansion = $this->instantiator->expand($host, $nodes, $location, ['mint' => static fn (?string $source): string => $source]);
    $this->assertSame([], self::errorsByPath($expansion->errors), 'Read-only keys are not grammar errors.');
    $ops = array_column($expansion->operations, NULL, 'uuid');
    $this->assertSame('root_stamp', $ops[self::SECTION_UUID]['blueprint']);
    $this->assertArrayNotHasKey('blueprint', $ops[self::NESTED_UUID]);
    $this->assertArrayNotHasKey('additional', $ops[self::BLOCK_UUID]);
    $this->assertArrayNotHasKey('additional', $ops[self::PLUGIN_UUID]);
    foreach ($expansion->operations as $op) {
      foreach (array_merge(array_keys($op), array_keys($op['properties'] ?? [])) as $key) {
        $this->assertStringStartsNotWith('_', (string) $key, 'No delivery annotation reaches an op.');
        $this->assertNotContains($key, ['block_revision_id', 'section_index'], 'No storage residue reaches an op.');
      }
    }

    // Instantiated, nothing of them is stored.
    $entity = $this->createTestEntity();
    $host = $this->host($entity);
    $result = $this->instantiator->instantiate($host, $nodes, ['anchor' => $entity->uuid(), 'placement' => 'inside'], ['mint' => static fn (?string $source): string => $source]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $root = $host->sections()[0];
    $this->assertSame('root_stamp', $root->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint'));
    $block = $root->getComponent(self::BLOCK_UUID);
    $this->assertInstanceOf(SectionComponent::class, $block);
    $this->assertNull($block->get(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY));
    $this->assertNotSame(999, (int) ($block->get('configuration')['block_revision_id'] ?? 0), 'The read\'s revision id is not the copy\'s.');
    $nested = $adapter->embeddedSection($root->getComponent(self::NESTED_UUID));
    $this->assertNull($nested->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint'));
    $this->assertNull($nested->getComponent(self::PLUGIN_UUID)->get(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY));
    foreach ($this->components($host) as $configuration) {
      foreach (array_keys($configuration) as $key) {
        $this->assertStringStartsNotWith('_', (string) $key);
      }
    }
  }

  /**
   * A tree read from one host instantiates onto another as an equal tree.
   *
   * The document is built through the ops (a styled container holding an
   * inline block with fields, managed configuration and a shown label, a
   * plugin block, and a nested container with a block), read as the lean
   * tree, instantiated onto a second host, and read again: the two trees
   * are equal apart from identity, which the second host minted afresh.
   */
  public function testTreeInstantiatesOntoAnotherHostUnchanged(): void {
    $source = $this->createTestEntity(['name' => 'Source']);
    $source_host = $this->host($source);
    $result = $this->operations->batchOperations($source_host, [
      [
        'op' => 'add_section',
        'uuid' => self::SECTION_UUID,
        'layout' => 'eb_test_container',
        'layout_settings' => ['label' => 'Band'],
        'location' => ['anchor' => $source->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => self::BLOCK_UUID,
        'block_type' => 'yaml_test_block',
        'properties' => [
          'body' => ['value' => '<p>Ribbon</p>', 'format' => 'plain_text'],
          'yaml_setting' => 'ribbon',
          'label' => 'Ribbon',
          'label_display' => 'visible',
        ],
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => self::PLUGIN_UUID,
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_section',
        'uuid' => self::NESTED_UUID,
        'layout' => 'eb_test_container',
        'layout_settings' => ['label' => 'Inner'],
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => self::INNER_UUID,
        'block_type' => 'yaml_test_block',
        'properties' => ['body' => ['value' => '<p>Inner</p>', 'format' => 'plain_text']],
        'location' => ['anchor' => self::NESTED_UUID, 'placement' => 'inside'],
      ],
      ['op' => 'update_style', 'key' => 'container:' . self::SECTION_UUID, 'properties' => ['padding' => '2rem']],
      ['op' => 'update_style', 'key' => 'container:' . self::SECTION_UUID . '::> *', 'properties' => ['gap' => '1rem']],
      ['op' => 'update_style', 'key' => 'block:' . self::BLOCK_UUID, 'properties' => ['text-align' => 'center']],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $source_tree = $this->builder->build($source_host);
    $this->assertSame(['yaml_setting' => 'ribbon'], NodeTreeBuilder::find($source_tree, self::BLOCK_UUID)['configuration'], 'The read emits the managed key the write must carry.');
    $this->assertArrayHasKey('styles', $source_tree[0]);

    $copy = $this->createTestEntity(['name' => 'Copy']);
    $copy_host = $this->host($copy);
    $result = $this->instantiator->instantiate($copy_host, $source_tree, [
      'anchor' => $copy->uuid(),
      'placement' => 'inside',
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $copy_tree = $this->builder->build($copy_host);

    $this->assertEquals(self::withoutIdentity($source_tree), self::withoutIdentity($copy_tree), 'The copy reads as the source apart from identity.');
    $copy_uuids = array_keys($this->components($copy_host));
    $this->assertCount(4, $copy_uuids);
    $source_uuids = [self::BLOCK_UUID, self::PLUGIN_UUID, self::NESTED_UUID, self::INNER_UUID];
    $this->assertSame([], array_intersect($copy_uuids, $source_uuids), 'The copy minted its own identity.');
    $copy_block = $copy_host->sections()[0]->getComponents();
    $copy_block = reset($copy_block)->get('configuration');
    $this->assertSame('ribbon', $copy_block['yaml_setting']);
    $this->assertSame('visible', $copy_block['label_display']);
    $this->assertSame(array_values($source_host->styleMap()), array_values($copy_host->styleMap()), 'The styles travelled, re-keyed.');
    $this->assertNotSame(array_keys($source_host->styleMap()), array_keys($copy_host->styleMap()));
  }

  /**
   * The seed carries the template's root entries onto the page's root.
   *
   * A template's page-level styles (its root's own entry and a rule under
   * it: the page background, the gap between its sections) are node-less,
   * so a copy of the nodes alone dropped them and a page lost them the
   * moment it owned its document. The whole-document copy lands them in
   * the page's own map under the page's uuid, first (the map's canonical
   * order), beside the node styles re-keyed onto the derived identity, and
   * nothing of the template's identity survives on the page.
   */
  public function testSeedCarriesTheTemplatesRootEntries(): void {
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $template = $this->styledTemplate();
    $this->assertArrayHasKey('entity:' . $template->uuid(), $template->styleMap(), 'The template holds its own root entry.');

    $entity = $this->createTestEntity(['name' => 'A page']);
    $page = $this->host($entity);
    // The first read of the map seeds both halves of the document.
    $map = $page->styleMap();
    $section = DocumentInstantiator::derivedUuid($entity->uuid(), self::SECTION_UUID);
    $this->assertSame([
      'entity:' . $entity->uuid() => ['default' => ['background-color' => 'aliceblue']],
      'entity:' . $entity->uuid() . '::> *' => ['default' => ['gap' => '3rem']],
      "container:$section" => ['default' => ['padding' => '2rem']],
    ], $map, 'The root entries under the page\'s uuid first, then the node styles under the derived identity.');
    foreach (array_keys($map) as $key) {
      $this->assertStringNotContainsString($display->uuid(), $key, 'No template identity survives on the page.');
    }
    $this->assertSame([DocumentInstantiator::derivedUuid($entity->uuid(), self::PLUGIN_UUID)], array_keys($this->components($page)), 'The sections seeded with the styles.');
    // Read the display as saved, not the instance loaded before the batch.
    $saved = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $this->assertArrayHasKey('entity:' . $display->uuid(), $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($saved)->styleMap(), 'The template keeps its own.');
  }

  /**
   * A shipped file's bare root keys copy as a saved document's keyed ones.
   *
   * A file has no host, so the shipper spells its page-level keys with no
   * uuid (`entity:`, `entity:::> *`, `field::name`).
   * RelativeSelector::splitKey() cannot read the rule forms (the first
   * "::" it meets is the bare key's own colon run into the suffix), so the
   * whole-document copy reads the bare shapes itself and lands them under
   * the destination's root exactly as it lands a keyed source's; a bare
   * key naming no field is a grammar error at its own coordinate.
   */
  public function testDocumentCopyReadsAFilesBareRootKeys(): void {
    $entity = $this->createTestEntity(['name' => 'A shipped start']);
    $page = $this->host($entity);
    $page->declineSeed();
    $result = $this->instantiator->instantiateDocument($page, [
      ['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []],
    ], [
      'entity:' => ['default' => ['background-color' => 'aliceblue']],
      'entity:::> *' => ['default' => ['gap' => '3rem']],
    ], [
      'path' => 'layout',
      'indexed' => TRUE,
      'batch' => ['admitted' => TRUE, 'system' => TRUE],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([
      'entity:' . $entity->uuid() => ['default' => ['background-color' => 'aliceblue']],
      'entity:' . $entity->uuid() . '::> *' => ['default' => ['gap' => '3rem']],
    ], $page->styleMap(), 'The bare keys re-address to the destination\'s root, the rule form included.');
    $this->assertCount(1, $page->ownSections());

    $other = $this->host($this->createTestEntity(['name' => 'A refused start']));
    $other->declineSeed();
    $result = $this->instantiator->instantiateDocument($other, [
      ['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []],
    ], [
      'field::' => ['default' => ['color' => 'tomato']],
    ], ['path' => 'layout', 'indexed' => TRUE, 'batch' => ['admitted' => TRUE, 'system' => TRUE]]);
    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('styles.field::', $errors[0]->getPath(), 'A bare field key naming no field is a grammar error at its coordinate.');
    $this->assertSame('INVALID_NODE', $errors[0]->getCode());
    $this->assertSame([], $other->styleMap(), 'Nothing landed.');
  }

  /**
   * The seed lays the template's root entries beneath the page's own.
   *
   * A never-edited page can already say something about its own root (a
   * page styled while its bundle shared the template's layout, a raw
   * write). The render layers such a page's own entry over the template's
   * (daedalus_stylish's RenderStyles::layer()), and the seed is the
   * persisted form of that render: per condition the page's own property
   * stands and the template supplies only what the page did not say, so a
   * page looks the same before and after it owns its document.
   */
  public function testSeedLaysTheTemplatesRootEntriesBeneathThePagesOwn(): void {
    $this->styledTemplate();

    $entity = $this->createTestEntity(['name' => 'A page with its own root styles']);
    $page = $this->host($entity);
    $root = 'entity:' . $entity->uuid();
    $page->setStyleMap([
      $root => ['default' => ['color' => 'navy', 'background-color' => 'white']],
      "$root::> *" => ['default' => ['gap' => '1rem']],
    ]);

    $map = $page->styleMap();
    $section = DocumentInstantiator::derivedUuid($entity->uuid(), self::SECTION_UUID);
    $this->assertEquals([
      $root => ['default' => ['color' => 'navy', 'background-color' => 'white']],
      "$root::> *" => ['default' => ['gap' => '1rem']],
      "container:$section" => ['default' => ['padding' => '2rem']],
    ], $map, 'The page\'s own background and gap stand; the template\'s node styles seed.');

    $other = $this->createTestEntity(['name' => 'A page that says less']);
    $page = $this->host($other);
    $root = 'entity:' . $other->uuid();
    $page->setStyleMap([$root => ['default' => ['color' => 'navy']]]);
    $map = $page->styleMap();
    $this->assertEquals(['color' => 'navy', 'background-color' => 'aliceblue'], $map[$root]['default'], 'The template supplies the property the page did not say.');
    $this->assertSame(['default' => ['gap' => '3rem']], $map["$root::> *"], 'A key only the template holds lands whole.');
  }

  /**
   * The entity_test template with one section, one block and styles.
   *
   * Built through the ops on the display host and committed: a container
   * (SECTION_UUID) holding a plugin block (PLUGIN_UUID), a style on the
   * container, and the template's two root entries (the page background
   * and the gap between sections), so a seed from it has both halves to
   * copy.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface
   *   The template host.
   */
  protected function styledTemplate(): HostInterface {
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $template = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display);
    $result = $this->operations->batchOperations($template, [
      [
        'op' => 'add_section',
        'uuid' => self::SECTION_UUID,
        'layout' => 'eb_test_container',
        'location' => ['anchor' => $template->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => self::PLUGIN_UUID,
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
      ['op' => 'update_style', 'key' => 'container:' . self::SECTION_UUID, 'properties' => ['padding' => '2rem']],
      [
        'op' => 'update_style',
        'key' => 'entity:' . $template->uuid(),
        'properties' => ['background-color' => 'aliceblue'],
      ],
      ['op' => 'update_style', 'key' => 'entity:' . $template->uuid() . '::> *', 'properties' => ['gap' => '3rem']],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->container->get('daedalus_blueprint.storage_handler')->commit($template);
    return $template;
  }

  /**
   * The raw section list of a page's override field, as stored.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections, the blank marker included (the host's reads hide it).
   */
  protected static function rawSections(FieldableEntityInterface $entity): array {
    return $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections();
  }

  /**
   * Asserts a page's override holds core's blank marker and nothing else.
   */
  protected function assertHoldsTheMarker(FieldableEntityInterface $entity, string $message): void {
    $sections = self::rawSections($entity);
    $this->assertCount(1, $sections, $message);
    $this->assertSame('layout_builder_blank', reset($sections)->getLayoutId(), $message);
  }

  /**
   * A page holding core's blank marker is started and empty: no seed.
   *
   * Core's layout_builder_blank section marks a layout that "has
   * purposefully had all sections removed" (SectionListTrait::
   * hasBlankSection()), never one that was never instantiated, so a page
   * holding it owns an empty document: its reads answer no sections and
   * an empty map (nothing of the template, whose document and root
   * entries a seed would have copied), the marker stays in the field
   * through every read, and core counts it as overridden (the field is
   * not empty), so the page renders its own empty document and never its
   * template's.
   */
  public function testMarkerPageIsNotSeeded(): void {
    $template = $this->styledTemplate();

    $entity = $this->createTestEntity([
      'name' => 'Started, and empty',
      OverridesSectionStorage::FIELD_NAME => [new Section('layout_builder_blank')],
    ]);
    $page = $this->host($entity);
    $this->assertSame([], $page->styleMap(), 'The map door seeds nothing: no root entry of the template lands.');
    $this->assertSame([], $page->ownSections(), 'The marker reads as no sections.');
    $this->assertSame([], $page->sections(), 'A reader sees no sections.');
    $this->assertSame([], $this->components($page), 'The section door seeds nothing: no block of the template lands.');
    $this->assertHoldsTheMarker($entity, 'The marker is left alone by every read.');
    $this->assertFalse($entity->get(OverridesSectionStorage::FIELD_NAME)->isEmpty(), 'Core counts the marker as an override.');
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    $this->assertSame($page, $resolver->renderHost($page), 'The page renders its own empty document, not its template.');
    $this->assertArrayHasKey('entity:' . $template->uuid(), $template->styleMap(), 'The template keeps its own.');
  }

  /**
   * A truly empty page still seeds from its template on its first read.
   *
   * The seed's one condition is the field holding no section at all: the
   * never-instantiated page, which takes the template's document (nodes
   * and root entries) on whichever door is read first.
   */
  public function testTrulyEmptyPageStillSeeds(): void {
    $this->styledTemplate();

    $entity = $this->createTestEntity(['name' => 'Never started']);
    $this->assertSame([], self::rawSections($entity), 'The field holds nothing before the first read.');
    $page = $this->host($entity);
    $this->assertSame([DocumentInstantiator::derivedUuid($entity->uuid(), self::PLUGIN_UUID)], array_keys($this->components($page)), 'The first read seeded the template\'s block.');
    $this->assertArrayHasKey('entity:' . $entity->uuid(), $page->styleMap(), 'The first read seeded the template\'s root entry under the page\'s uuid.');
    $sections = self::rawSections($entity);
    $this->assertCount(1, $sections);
    $this->assertSame('eb_test_container', reset($sections)->getLayoutId(), 'The seeded section is the template\'s, not the marker.');
  }

  /**
   * Removing a page's last top-level section leaves it empty, for good.
   *
   * Core's removeSection() writes the blank marker when the last root
   * section goes (Daedalus's op calls it on the root list), so an editor
   * emptying a page by hand lands on the same persisted fact a blank
   * start writes. Committed and reloaded, the page holds the marker, reads
   * as no sections on a fresh host, is not seeded again (its template
   * still has a document to offer) and renders its own empty document.
   */
  public function testRemovingTheLastSectionLeavesThePageEmpty(): void {
    $this->styledTemplate();

    $entity = $this->createTestEntity(['name' => 'Emptied by hand']);
    $page = $this->host($entity);
    $section = DocumentInstantiator::derivedUuid($entity->uuid(), self::SECTION_UUID);
    $this->assertCount(1, $page->ownSections(), 'The first read seeded the template\'s one section.');

    $result = $this->operations->batchOperations($page, [
      ['op' => 'remove_element', 'element' => $section],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertHoldsTheMarker($entity, 'Core wrote the marker when the last section went.');
    $this->assertSame([], $page->ownSections(), 'The emptied page reads as no sections on the same host.');
    $this->assertHoldsTheMarker($entity, 'The read after the removal seeded nothing.');

    $this->container->get('daedalus_blueprint.storage_handler')->commit($page);
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $storage->resetCache([$entity->id()]);
    $reloaded = $storage->load($entity->id());
    $this->assertHoldsTheMarker($reloaded, 'The marker is what was saved.');

    $fresh = $this->host($reloaded);
    $this->assertSame([], $fresh->ownSections(), 'The reloaded page reads as no sections.');
    $this->assertSame([], $fresh->sections(), 'A reader of the reloaded page sees no sections.');
    $this->assertSame([], $this->components($fresh), 'Nothing of the template came back.');
    $this->assertHoldsTheMarker($reloaded, 'The reloaded page was not seeded: it stays empty.');
    $this->assertSame($fresh, $this->container->get('daedalus_blueprint.host_resolver')->renderHost($fresh), 'The emptied page renders its own empty document, never its template\'s.');
  }

  /**
   * The seed lays the template around a locked page's fills.
   *
   * A page whose own document is slot sections alone was locked, and is
   * read on an overridable bundle (the unlock): the seed lifts the fills
   * out, lands the template, and drops each fill into its slot's place.
   * The copied slot block gives way to the fill, embedded as a nested
   * section in the slot block's region with its identity intact (its
   * section, its block, the page's style entry for it) and its slot tag
   * gone; a fill whose slot the template does not place lands after the
   * template's sections, untagged. The template's root entries seed as
   * on any page, and the seeded page, now owning a whole document, is
   * not seeded again.
   */
  public function testSeedDropsTheFillsIntoTheirSlots(): void {
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
        'aside' => ['label' => 'Aside', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();
    $template = $this->styledTemplate();
    $result = $this->operations->batchOperations($template, [
      [
        'op' => 'add_element',
        'uuid' => self::SLOT_UUID,
        'plugin_id' => SlotBlock::PLUGIN_ID . ':hero',
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->container->get('daedalus_blueprint.storage_handler')->commit($template);

    $entity = $this->createTestEntity([
      'name' => 'Unlocked',
      OverridesSectionStorage::FIELD_NAME => [
        $this->fill('hero', self::HERO_UUID, self::HERO_BLOCK_UUID),
        $this->fill('aside', self::ASIDE_UUID, self::ASIDE_BLOCK_UUID),
      ],
    ]);
    $page = $this->host($entity);
    $page->setStyleMap(['container:' . self::HERO_UUID => ['default' => ['padding' => '1rem']]]);

    // The first read of the section door seeds.
    $adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $sections = $page->ownSections();
    $section = DocumentInstantiator::derivedUuid($entity->uuid(), self::SECTION_UUID);
    $this->assertSame([$section, self::ASIDE_UUID], array_map(static fn (Section $section): ?string => $adapter->getSectionUuid($section), $sections), 'The template\'s section first, then the fill whose slot the template does not place.');
    $this->assertSame([NULL, NULL], array_map(static fn (Section $section): ?string => LayoutSerializer::slotOf($section), $sections), 'No section of the seeded page fills a slot.');

    $components = $this->components($page);
    $keys = array_keys($components);
    sort($keys);
    $expected = [
      DocumentInstantiator::derivedUuid($entity->uuid(), self::PLUGIN_UUID),
      self::HERO_UUID,
      self::HERO_BLOCK_UUID,
      self::ASIDE_BLOCK_UUID,
    ];
    sort($expected);
    $this->assertSame($expected, $keys, 'The template\'s block seeded; the hero fill and its block keep their identity; the slot block is gone.');
    $this->assertArrayNotHasKey(DocumentInstantiator::derivedUuid($entity->uuid(), self::SLOT_UUID), $components, 'The copied slot block gave way to the fill.');

    $hero = $sections[0]->getComponent(self::HERO_UUID);
    $this->assertSame('content', $hero->getRegion(), 'The fill sits in the slot block\'s region.');
    $nested = $adapter->embeddedSection($hero);
    $this->assertNotNull($nested, 'Embedded as a nested section.');
    $this->assertSame(self::HERO_UUID, $adapter->getSectionUuid($nested), 'With its own section identity.');
    $this->assertNull(LayoutSerializer::slotOf($nested), 'Its slot tag dropped: a section inside a document the page owns whole fills nothing.');
    $this->assertSame(['system_powered_by_block'], array_map(static fn ($component) => $component->getPluginId(), array_values($nested->getComponents())), 'Its block rides along.');

    $map = $page->styleMap();
    $this->assertSame(['default' => ['padding' => '1rem']], $map['container:' . self::HERO_UUID], 'The page\'s style entry for the fill stays addressed.');
    $this->assertArrayHasKey('entity:' . $entity->uuid(), $map, 'The template\'s root entry seeded under the page\'s uuid.');
    $this->assertArrayHasKey("container:$section", $map, 'The template\'s node style seeded under the derived identity.');

    $this->assertCount(2, self::rawSections($entity), 'The field holds the merged document.');
    $this->assertCount(2, $page->ownSections(), 'A second read seeds nothing: the page owns a whole document now.');
  }

  /**
   * A locked page's fill: a section tagged with a slot, holding one block.
   */
  protected function fill(string $slot, string $uuid, string $block_uuid): Section {
    $section = new Section('eb_test_container', [], [new SectionComponent($block_uuid, 'content', ['id' => 'system_powered_by_block'])]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    $section->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, $slot);
    return $section;
  }

  /**
   * A field node names the field; the destination's subject spells the block.
   *
   * The tree carries {kind: block, field: name}, never core's per-bundle
   * derivative, so the node read from one bundle lands on another bundle
   * that has the field (entity_test to entity_test_rev here, each getting
   * its own field_block:{type}:{bundle}:name), and a field the destination
   * cannot place is refused FIELD_NOT_ON_HOST with the fields it can. A
   * derivative spelled as plugin_id is refused toward the field spelling.
   */
  public function testFieldNodeExpandsAgainstTheHostSubject(): void {
    $this->enableLayoutBuilder('entity_test_rev', 'entity_test_rev');
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();

    $source = $this->createTestEntity(['name' => 'Source']);
    $source_host = $this->host($source);
    $result = $this->instantiator->instantiate($source_host, [
      [
        'kind' => 'container',
        'layout_id' => 'eb_test_container',
        'children' => [
          [
            'kind' => 'block',
            'uuid' => self::BLOCK_UUID,
            'field' => 'name',
            'label' => 'The name',
            'label_rendered' => TRUE,
            'configuration' => ['formatter' => ['type' => 'string', 'label' => 'inline', 'settings' => ['link_to_entity' => FALSE], 'third_party_settings' => []]],
          ],
        ],
      ],
    ], ['anchor' => $source->uuid(), 'placement' => 'inside'], [
      'mint' => static fn (?string $source): string => $source ?? (new PhpUuid())->generate(),
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $placed = $this->components($source_host)[self::BLOCK_UUID];
    $this->assertSame('field_block:entity_test:entity_test:name', $placed['id'], 'The subject\'s entity type and bundle spell the derivative.');
    $this->assertSame('inline', $placed['formatter']['label']);
    $this->assertSame('visible', $placed['label_display']);

    // The read answers the field, never the derivative.
    $tree = $this->builder->build($source_host);
    $node = NodeTreeBuilder::find($tree, self::BLOCK_UUID);
    $this->assertSame('name', $node['field']);
    $this->assertArrayNotHasKey('plugin_id', $node);
    $this->assertSame('inline', $node['configuration']['formatter']['label']);

    // The same tree lands on another bundle that has the field.
    $copy = $this->createRevEntity(['name' => 'Copy']);
    $copy_host = $this->host($copy);
    $result = $this->instantiator->instantiate($copy_host, $tree, ['anchor' => $copy->uuid(), 'placement' => 'inside']);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $copied = array_values($this->components($copy_host));
    $this->assertCount(1, $copied);
    $this->assertSame('field_block:entity_test_rev:entity_test_rev:name', $copied[0]['id'], 'The destination\'s own derivative, from the same node.');
    $this->assertEquals(self::withoutIdentity($tree), self::withoutIdentity($this->builder->build($copy_host)), 'The copy reads as the source apart from identity.');

    // A field the destination cannot place is refused with the fields it can.
    $location = ['anchor' => $copy->uuid(), 'placement' => 'inside'];
    $container = static fn (array $block): array => [
      ['kind' => 'container', 'layout_id' => 'eb_test_container', 'children' => [$block + ['kind' => 'block']]],
    ];
    $expansion = $this->instantiator->expand($copy_host, $container(['field' => 'field_subtitle']), $location);
    $this->assertFalse($expansion->isValid());
    $this->assertCount(1, $expansion->errors);
    $error = $expansion->errors[0];
    $this->assertSame('FIELD_NOT_ON_HOST', $error->getCode());
    $this->assertSame('nodes.children[0].field', $error->getPath());
    $this->assertSame('This entity_test_rev (entity_test_rev) has no placeable field "field_subtitle".', $error->getMessage());
    $this->assertContains('name', $error->getContext()['available']);
    $this->assertNotContains('field_subtitle', $error->getContext()['available']);
    $this->assertStringContainsString('name', $error->getHint());
    // The source bundle has the field: the same node is good there.
    $this->assertTrue($this->instantiator->expand($source_host, $container(['field' => 'field_subtitle']), $location)->isValid());

    // The derivative is never spelled; the corrective names the field.
    $expansion = $this->instantiator->expand($source_host, $container(['plugin_id' => 'field_block:entity_test:entity_test:name']), $location);
    $this->assertSame(['nodes.children[0].plugin_id' => 'A field block is placed by field, never by its plugin id "field_block:entity_test:entity_test:name".'], self::errorsByPath($expansion->errors));
    $this->assertStringContainsString('field: "name"', $expansion->errors[0]->getHint());

    // One identity per node.
    $expansion = $this->instantiator->expand($source_host, $container(['field' => 'name', 'plugin_id' => 'system_powered_by_block']), $location);
    $this->assertSame(['nodes.children[0]' => 'A block node names block_type (an inline block), plugin_id (a plugin block) or field (one of the page\'s fields), not plugin_id and field.'], self::errorsByPath($expansion->errors));
    $expansion = $this->instantiator->expand($source_host, $container(['field' => '']), $location);
    $this->assertSame(['nodes.children[0].field' => 'field is a field name of the page.'], self::errorsByPath($expansion->errors));
  }

  /**
   * A tree expands in an element's place: after it, in its flow, then it goes.
   *
   * The in-place expansion behind detach_blueprint: the roots land after
   * the element among its siblings with fresh identity and the caller's
   * provenance, the element is removed last, one dressed batch. A tree
   * that does not instantiate leaves the element where it was.
   */
  public function testTreeExpandsInAnElementsPlace(): void {
    $page = $this->createTestEntity(['name' => 'Page']);
    $host = $this->host($page);
    $first = '2b000000-0000-4000-8000-0000000000b1';
    $linked = '2b000000-0000-4000-8000-0000000000b2';
    $last = '2b000000-0000-4000-8000-0000000000b3';
    $result = $this->operations->batchOperations($host, [
      [
        'op' => 'add_section',
        'uuid' => self::SECTION_UUID,
        'layout' => 'eb_test_container',
        'location' => ['anchor' => $page->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => $first,
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => $linked,
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => $last,
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
      ['op' => 'update_style', 'key' => "block:$linked", 'properties' => ['margin' => '1rem']],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $tree = [
      [
        'kind' => 'container',
        'uuid' => self::NESTED_UUID,
        'layout_id' => 'eb_test_container',
        'label' => 'Copy',
        'styles' => ['' => ['default' => ['padding' => '2rem']]],
        'children' => [
          [
            'kind' => 'block',
            'uuid' => self::INNER_UUID,
            'block_type' => 'yaml_test_block',
            'block_fields' => ['body' => ['value' => '<p>Inner</p>', 'format' => 'plain_text']],
          ],
        ],
      ],
    ];
    $result = $this->instantiator->replace($host, $linked, $tree, ['provenance' => 'testimonial']);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $children = $this->builder->build($host)[0]['children'];
    $this->assertSame(['block', 'container', 'block'], array_column($children, 'kind'), 'The copy stands where the element stood.');
    $this->assertSame([$first, $last], [$children[0]['uuid'], $children[2]['uuid']]);
    $copy = $children[1];
    $this->assertNotSame(self::NESTED_UUID, $copy['uuid'], 'Fresh identity.');
    $this->assertSame('testimonial', $copy['blueprint'], 'The provenance stamp rides the root.');
    $this->assertSame('Copy', $copy['label']);
    $this->assertSame(['' => ['default' => ['padding' => '2rem']]], $copy['styles'], 'The copy\'s own styles, re-keyed.');
    $this->assertSame('<p>Inner</p>', $copy['children'][0]['block_fields']['body']['value']);
    $this->assertArrayNotHasKey($linked, $this->components($host), 'The element is gone.');
    $removed = array_filter($result->getTargets(), static fn (array $target): bool => ($target['uuid'] ?? NULL) === $linked);
    $this->assertSame([TargetType::REMOVED], array_column($removed, 'field_name'), 'The response reports the removal.');
    $added = array_filter($result->getTargets(), static fn (array $target): bool => ($target['uuid'] ?? NULL) === $copy['uuid']);
    $this->assertContains(TargetType::ADDED, array_column($added, 'field_name'), 'And the copy\'s birth.');
    $this->assertArrayHasKey("block:$linked", $host->styleMap(), 'The element\'s own entry stays as residue, by the serializer\'s law; nothing carries it onto the copy.');

    // A tree that does not instantiate leaves the element where it was.
    $before = array_keys($this->components($host));
    $result = $this->instantiator->replace($host, $first, [
      [
        'kind' => 'container',
        'layout_id' => 'eb_test_container',
        'children' => [['kind' => 'block', 'field' => 'field_missing']],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['nodes.children[0].field' => 'FIELD_NOT_ON_HOST'], self::codesByPath($result->getErrors()));
    $this->assertSame($before, array_keys($this->components($host)), 'Untouched.');

    // An element the document lacks: the removal's own refusal, at its
    // own parameter.
    $result = $this->instantiator->replace($host, '2b000000-0000-4000-8000-0000000000ff', $tree);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(
      ['nodes.location.anchor' => 'ANCHOR_NOT_FOUND', 'element' => 'ELEMENT_NOT_FOUND'],
      self::codesByPath($result->getErrors()),
      'The first root anchors on the element and the removal names it: both refusals, collected, at their own coordinates.',
    );
    $this->assertSame($before, array_keys($this->components($host)), 'Untouched.');
  }

  /**
   * A copy keeps a variant reference a reference: styles stay linked.
   *
   * The expansion emits apply_variant for every authored wearing (a bare
   * machine name or a {variant} map with no fragment and no origin) and
   * never spells the variant's properties into the copy; derived wearings
   * (a fragment a composition distributed, a wear with a via) are dropped
   * for the root's own apply to re-create. The premise the style axis
   * rests on while layout gains linked and detached placement.
   */
  public function testExpansionKeepsVariantReferencesAsReferences(): void {
    $page = $this->createTestEntity(['name' => 'Page']);
    $host = $this->host($page);
    $expansion = $this->instantiator->expand($host, [
      [
        'kind' => 'container',
        'layout_id' => 'eb_test_container',
        'styles' => [
          '' => [
            'variants' => [
              'card',
              ['variant' => 'band'],
              ['variant' => 'card', 'fragment' => 'title'],
              ['variant' => 'aurora', 'via' => 'card'],
            ],
            'default' => ['padding' => '2rem'],
          ],
        ],
        'children' => [
          ['kind' => 'block', 'plugin_id' => 'system_powered_by_block', 'styles' => ['' => ['variants' => ['chip']]]],
        ],
      ],
    ], ['anchor' => $page->uuid(), 'placement' => 'inside']);
    $this->assertTrue($expansion->isValid(), print_r(self::errorsByPath($expansion->errors), TRUE));
    $styles = array_slice($expansion->operations, $expansion->structuralCount);
    $this->assertSame(
      [['apply_variant', 'card'], ['apply_variant', 'band'], ['update_style', NULL], ['apply_variant', 'chip']],
      array_map(static fn (array $op): array => [$op['op'], $op['variant'] ?? NULL], $styles),
      'Authored wearings apply by name; derived ones are dropped; the one condition map is the one property write.',
    );
    $this->assertSame('default', $styles[2]['condition']);
    $this->assertSame(['padding' => '2rem'], $styles[2]['properties']);
    $root_key = 'container:' . $expansion->roots[0];
    $this->assertSame(['variants' => ['card', 'band'], 'default' => ['padding' => '2rem']], $expansion->styles[$root_key], 'The map carries the references, never the variants\' properties.');
  }

}
