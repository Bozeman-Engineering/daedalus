<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\BlueprintSchemaBuilderInterface;
use Drupal\daedalus_blueprint\BlueprintSerializerInterface;
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\PluginBlockConfiguration;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin block configuration on the spine.
 *
 * A plugin block's property bag is its config schema (block.settings.{id}
 * through typed config): update_element and add_element take label,
 * label_display and the plugin's own settings keys, validation is typed
 * config validation of the resulting settings, read_element and
 * read_schema advertise the same keys, the full read carries them and the
 * tree of a read creates an equal entity. A plugin
 * without schema of its own is refused with NO_PLUGIN_SCHEMA naming the
 * missing schema. The field block is the representative: label,
 * label_display, formatter type and settings.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(PluginBlockConfiguration::class)]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class PluginBlockConfigurationTest extends BlueprintKernelTestBase {

  /**
   * The field block placement: entity_test's name, the string formatter.
   */
  protected const FIELD_UUID = '4b2c1a7e-0d3f-4e5a-9b6c-7d8e9f0a1b2c';

  /**
   * The schema-less placement: core's powered-by block.
   */
  protected const PLAIN_UUID = '5c3d2b8f-1e4a-4f6b-8c7d-8e9f0a1b2c3d';

  /**
   * The seed placement every add anchors beside.
   */
  protected const SEED_UUID = '6d4e3c9a-2f5b-4a7c-9d8e-9f0a1b2c3d4e';

  /**
   * The field block plugin under test.
   */
  protected const FIELD_PLUGIN = 'field_block:entity_test:entity_test:name';

  /**
   * The operations service.
   */
  protected BlueprintOperations $operations;

  /**
   * The serializer.
   */
  protected BlueprintSerializerInterface $serializer;

  /**
   * The schema builder.
   */
  protected BlueprintSchemaBuilderInterface $schemaBuilder;

  /**
   * The blueprint deserializer.
   */
  protected BlueprintDeserializerInterface $deserializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The whole-layout write anchors a container's children on its
    // identity, which is daedalus_layout's.
    $this->enableLayoutBuilder();
    $this->enableModules(['file', 'daedalus_ui', 'daedalus_tempstore', 'daedalus_blueprint_api', 'daedalus_layout']);
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
    $this->schemaBuilder = $this->container->get('daedalus_blueprint.schema_builder');
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
  }

  /**
   * An entity whose one section holds a field block and a schema-less block.
   */
  protected function createEntity(): object {
    $entity = $this->createTestEntity([
      'name' => 'Plugin block page',
      'layout_builder__layout' => [
        $this->createSection('layout_onecol', [
          [
            'uuid' => self::SEED_UUID,
            'region' => 'content',
            'weight' => 0,
            'configuration' => [
              'id' => 'system_branding_block',
              'provider' => 'system',
              'label' => 'Seed',
              'label_display' => '0',
            ],
          ],
          [
            'uuid' => self::FIELD_UUID,
            'region' => 'content',
            'weight' => 1,
            'configuration' => [
              'id' => self::FIELD_PLUGIN,
              'provider' => 'layout_builder',
              'label' => 'Name',
              'label_display' => '0',
              'context_mapping' => ['entity' => 'layout_builder.entity'],
              'formatter' => [
                'label' => 'above',
                'type' => 'string',
                'settings' => [],
                'third_party_settings' => [],
              ],
            ],
          ],
          [
            'uuid' => self::PLAIN_UUID,
            'region' => 'content',
            'weight' => 2,
            'configuration' => [
              'id' => 'system_powered_by_block',
              'provider' => 'system',
              'label' => 'Powered',
              'label_display' => 'visible',
            ],
          ],
        ]),
      ],
    ]);
    return $entity;
  }

  /**
   * The configuration of a component on the entity's first section.
   */
  protected function configurationOf(object $entity, string $uuid): array {
    return $entity->get('layout_builder__layout')->getSection(0)->getComponent($uuid)->get('configuration');
  }

  /**
   * A field block's formatter changes through update_element.
   */
  public function testFieldBlockFormatterUpdate(): void {
    $entity = $this->createEntity();
    $host = $this->host($entity);

    $result = $this->operations->updateElement($host, self::FIELD_UUID, [
      'formatter' => [
        'type' => 'string',
        'label' => 'hidden',
        'settings' => ['link_to_entity' => TRUE],
      ],
      'label_display' => 'visible',
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame([
      ['uuid' => self::FIELD_UUID, 'field_name' => 'formatter'],
      ['uuid' => self::FIELD_UUID, 'field_name' => 'label_display'],
    ], $result->getTargets());

    $configuration = $this->configurationOf($result->getEntity(), self::FIELD_UUID);
    $this->assertSame('hidden', $configuration['formatter']['label']);
    $this->assertSame('string', $configuration['formatter']['type']);
    $this->assertTrue($configuration['formatter']['settings']['link_to_entity']);
    $this->assertSame([], $configuration['formatter']['third_party_settings'], 'The plugin completes the partial map with its defaults.');
    $this->assertSame('visible', $configuration['label_display']);
    $this->assertSame('Name', $configuration['label'], 'An omitted property keeps its value.');
    $this->assertSame(['entity' => 'layout_builder.entity'], $configuration['context_mapping'], 'The spine\'s context mapping is untouched.');
    $this->assertSame(self::FIELD_PLUGIN, $configuration['id']);

    // The label is a property too, and an explicit null clears a key to the
    // plugin's default.
    $result = $this->operations->updateElement($host, self::FIELD_UUID, [
      'label' => 'Full name',
      'formatter' => NULL,
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $configuration = $this->configurationOf($result->getEntity(), self::FIELD_UUID);
    $this->assertSame('Full name', $configuration['label']);
    $this->assertSame('above', $configuration['formatter']['label'], 'Cleared: the plugin default rules.');
    $this->assertSame([], $configuration['formatter']['settings']);
  }

  /**
   * The resulting settings are validated as typed config; the bag is closed.
   */
  public function testFieldBlockRefusals(): void {
    $entity = $this->createEntity();
    $host = $this->host($entity);

    // A constraint violation is a semantic refusal at the value's path.
    $result = $this->operations->updateElement($host, self::FIELD_UUID, [
      'formatter' => ['type' => 'no_such_formatter'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $error = $result->toArray()['errors'][0];
    $this->assertSame('INVALID_CONFIGURATION', $error['code']);
    $this->assertSame('properties.formatter.type', $error['path']);
    $this->assertSame('block.settings.' . self::FIELD_PLUGIN, $error['context']['schema']);
    $this->assertSame('above', $this->configurationOf($host->entity(), self::FIELD_UUID)['formatter']['label'], 'Nothing was written.');

    $result = $this->operations->updateElement($host, self::FIELD_UUID, ['label_display' => 'maybe']);
    $this->assertFalse($result->isSuccess());
    $error = $result->toArray()['errors'][0];
    $this->assertSame('INVALID_CONFIGURATION', $error['code']);
    $this->assertSame('properties.label_display', $error['path']);

    // A key the schema does not declare is structural, with the roster.
    $result = $this->operations->updateElement($host, self::FIELD_UUID, ['field_heading' => 'Nope']);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $error = $result->toArray()['errors'][0];
    $this->assertSame('UNKNOWN_PROPERTY', $error['code']);
    $this->assertSame('properties.field_heading', $error['path']);
    $this->assertSame(['label', 'label_display', 'formatter'], $error['context']['available']);

    // The spine's own keys are not properties.
    $result = $this->operations->updateElement($host, self::FIELD_UUID, ['context_mapping' => []]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('UNKNOWN_PROPERTY', $result->toArray()['errors'][0]['code']);
  }

  /**
   * A plugin without config schema of its own is refused, naming the schema.
   */
  public function testSchemalessPluginRefused(): void {
    $entity = $this->createEntity();
    $host = $this->host($entity);

    $result = $this->operations->updateElement($host, self::PLAIN_UUID, ['label' => 'Renamed']);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $error = $result->toArray()['errors'][0];
    $this->assertSame('NO_PLUGIN_SCHEMA', $error['code']);
    $this->assertSame('properties', $error['path']);
    $this->assertSame('block.settings.system_powered_by_block', $error['context']['schema']);
    $this->assertStringContainsString('block.settings.system_powered_by_block', $error['hint']);
    $this->assertSame('Powered', $this->configurationOf($host->entity(), self::PLAIN_UUID)['label'], 'Nothing was written.');

    // add_element with properties is refused the same way; without
    // properties the placement is not a configuration and still lands.
    $location = ['anchor' => self::SEED_UUID, 'placement' => 'after'];
    $result = $this->operations->batchOperations($host, [
      [
        'op' => 'add_element',
        'plugin_id' => 'system_powered_by_block',
        'location' => $location,
        'properties' => ['label' => 'Nope'],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('NO_PLUGIN_SCHEMA', $result->toArray()['errors'][0]['code']);

    $result = $this->operations->addElement($host, $location, [
      'plugin_id' => 'system_powered_by_block',
      'uuid' => '7e5f4d0b-3a6c-4b8d-8e9f-0a1b2c3d4e5f',
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
  }

  /**
   * The add operation takes the bag as the plugin's configuration at placement.
   */
  public function testAddElementTakesConfiguration(): void {
    $entity = $this->createEntity();
    $host = $this->host($entity);
    $uuid = '8f6a5e1c-4b7d-4c9e-9f0a-1b2c3d4e5f6a';

    $result = $this->operations->batchOperations($host, [
      [
        'op' => 'add_element',
        'plugin_id' => self::FIELD_PLUGIN,
        'uuid' => $uuid,
        'location' => ['anchor' => self::SEED_UUID, 'placement' => 'after'],
        'properties' => [
          'label' => 'The name',
          'formatter' => [
            'type' => 'string',
            'label' => 'inline',
            'settings' => ['link_to_entity' => FALSE],
          ],
        ],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame([['uuid' => $uuid, 'field_name' => TargetType::ADDED]], $result->getTargets());

    $configuration = $this->configurationOf($result->getEntity(), $uuid);
    $this->assertSame(self::FIELD_PLUGIN, $configuration['id']);
    $this->assertSame('The name', $configuration['label']);
    $this->assertSame('0', $configuration['label_display'], 'The placement default holds when the bag leaves it out.');
    $this->assertSame('layout_builder.entity', $configuration['context_mapping']['entity'], 'The context mapping is defaulted by the spine.');
    $this->assertSame('inline', $configuration['formatter']['label']);
    $this->assertFalse($configuration['formatter']['settings']['link_to_entity']);
    $this->assertSame([], $configuration['formatter']['third_party_settings']);

    // An invalid bag places nothing.
    $result = $this->operations->batchOperations($host, [
      [
        'op' => 'add_element',
        'plugin_id' => self::FIELD_PLUGIN,
        'location' => ['anchor' => self::SEED_UUID, 'placement' => 'after'],
        'properties' => ['formatter' => ['type' => 'no_such_formatter']],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_CONFIGURATION', $result->toArray()['errors'][0]['code']);
  }

  /**
   * The element read is the node: the placed values, plain.
   *
   * The keys' types, descriptions and options are the schema's
   * (testReadSchemaAdvertisesPlugins); the read carries the bag as it
   * stands, the same node the full read's layout holds.
   */
  public function testReadElementCarriesTheBag(): void {
    $entity = $this->createEntity();
    $host = $this->host($entity);

    $element = $this->serializer->serializeElement($host, self::FIELD_UUID);
    $this->assertSame('block', $element['kind']);
    // A field block names its field; the per-bundle derivative never rides.
    $this->assertSame('name', $element['field']);
    $this->assertArrayNotHasKey('plugin_id', $element);
    $this->assertSame('Name', $element['label']);
    $this->assertArrayNotHasKey('label_rendered', $element, 'A hidden label is not rendered.');
    $this->assertArrayNotHasKey('label_display', $element);
    $this->assertArrayNotHasKey('configurable', $element);
    $this->assertSame(['formatter'], array_keys($element['configuration']));
    $this->assertSame([
      'label' => 'above',
      'type' => 'string',
      'settings' => [],
      'third_party_settings' => [],
    ], $element['configuration']['formatter']);

    // The branding block: none of its three own keys placed, so the plugin
    // defaults rule and the read spells no configuration (the schema
    // advertises the keys).
    $element = $this->serializer->serializeElement($host, self::SEED_UUID);
    $this->assertSame('Seed', $element['label']);
    $this->assertArrayNotHasKey('configuration', $element);

    // A schema-less plugin is its plugin id alone.
    $element = $this->serializer->serializeElement($host, self::PLAIN_UUID);
    $this->assertSame(['kind', 'uuid', 'plugin_id', '_verdict'], array_keys($element));
    $this->assertSame('system_powered_by_block', $element['plugin_id']);

    // The full read carries the same nodes.
    $blocks = $this->serializer->serialize($host)['layout'][0]['children'][0]['children'];
    $by_uuid = array_column($blocks, NULL, 'uuid');
    $this->assertSame($this->serializer->serializeElement($host, self::FIELD_UUID), $by_uuid[self::FIELD_UUID]);
    $this->assertSame($element, $by_uuid[self::PLAIN_UUID]);
  }

  /**
   * The schema read advertises the plugins with their configuration keys.
   */
  public function testReadSchemaAdvertisesPlugins(): void {
    $this->createEntity();
    $schema = $this->schemaBuilder->getSchema('entity_test', 'entity_test');
    $this->assertArrayHasKey('plugin_block_structure', $schema['layout']);
    $this->assertArrayHasKey('configuration', $schema['layout']['plugin_block_structure']);

    $plugins = $schema['layout']['available_block_plugins'];
    $this->assertArrayNotHasKey('inline_block:basic', $plugins, 'Inline blocks are block types, not plugins.');
    $this->assertArrayNotHasKey(self::FIELD_PLUGIN, $plugins, 'The bundle\'s field blocks are its available_fields, not plugins.');
    $this->assertArrayHasKey('field_block_structure', $schema['layout']);
    $field = $schema['layout']['available_fields']['name'];
    $this->assertSame('Name', $field['label']);
    $this->assertSame(['formatter'], array_keys($field['configuration']));
    $this->assertSame('field_formatter', $field['configuration']['formatter']['type']);
    $this->assertSame(['type', 'label', 'settings', 'third_party_settings'], array_keys($field['configuration']['formatter']['properties']));
    // Static description: the dynamic type stays a name to resolve on a
    // placed block.
    $this->assertSame('field.formatter.settings.[%parent.type]', $field['configuration']['formatter']['properties']['settings']['type']);

    $this->assertSame(['use_site_logo', 'use_site_name', 'use_site_slogan'], array_keys($plugins['system_branding_block']['configuration']));
    $this->assertFalse($plugins['system_powered_by_block']['configurable']);
    $this->assertArrayNotHasKey('configuration', $plugins['system_powered_by_block']);
  }

  /**
   * The full grammar takes the same bag; a read's tree creates an equal copy.
   */
  public function testDeserializerTakesTheBag(): void {
    $entity = $this->createEntity();
    $host = $this->host($entity);
    $create = fn (array $tree) => $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Copy'],
      'layout' => $tree,
    ]);

    // The read's tree, written as is: no refusal for the schema-less
    // block (the read spells it as its plugin id alone, so the copy
    // carries exactly that), every bag landed as read.
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($host);
    $result = $create($tree);
    $this->assertTrue($result->isSuccess(), print_r($result->getErrors(), TRUE));
    $copy = $result->getEntity();
    $this->assertSame('system_powered_by_block', $this->configurationOf($copy, self::PLAIN_UUID)['id']);
    $this->assertSame('above', $this->configurationOf($copy, self::FIELD_UUID)['formatter']['label']);
    $this->assertSame('0', $this->configurationOf($copy, self::FIELD_UUID)['label_display']);

    // A changed key in the tree is the write.
    foreach ($tree[0]['children'][0]['children'] as &$block) {
      if ($block['uuid'] === self::FIELD_UUID) {
        $block['configuration']['formatter']['label'] = 'inline';
        $block['label_rendered'] = TRUE;
      }
    }
    unset($block);
    $result = $create($tree);
    $this->assertTrue($result->isSuccess(), print_r($result->getErrors(), TRUE));
    $configuration = $this->configurationOf($result->getEntity(), self::FIELD_UUID);
    $this->assertSame('inline', $configuration['formatter']['label']);
    $this->assertSame('visible', $configuration['label_display']);
    // The context mapping is the op's (defaulted from the repository), not
    // the tree's.
    $this->assertSame('layout_builder.entity', $configuration['context_mapping']['entity']);

    // A bag on a schema-less node is the same refusal as the ops give.
    $tree[0]['children'][0]['children'][] = [
      'kind' => 'block',
      'plugin_id' => 'system_powered_by_block',
      'label' => 'Nope',
    ];
    $result = $create($tree);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('NO_PLUGIN_SCHEMA', $result->getErrors()[0]->getCode());
    $this->assertSame('layout[0].children[0].children[3].properties', $result->getErrors()[0]->getPath());
  }

}
