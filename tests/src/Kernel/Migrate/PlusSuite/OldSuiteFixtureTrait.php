<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Kernel\Migrate\PlusSuite;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\KeyValueStore\KeyValueDatabaseFactory;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Seeds a Kernel site in the old Plus Suite's vocabulary.
 *
 * The trial site in miniature (plus-suite-to-daedalus-migration,
 * 2026-09-19): core.extension and the registries naming the old modules
 * (absent from disk, as after the composer swap), the two settings objects,
 * a node type with navigation_plus third-party settings, a view display with
 * lb_plus ones, a role with an lb_plus permission, navigation_plus's map
 * base field on the user entity (its column, its installed definition and
 * its storage schema), a node whose layout section carries lb_plus
 * third-party settings and an inline_block:heading component (plus a nested
 * section with another), and custom code importing an edit_plus class. The
 * old modules themselves are never installed: the phases read stores, not
 * code.
 *
 * Kernel tests pin the keyvalue service to a memory factory and never create
 * the key_value table; a real site's registries are rows of that table,
 * which the phases list, so a using class swaps the container's factory for
 * a database one in register() (the first write creates the table, and the
 * factory persists across container rebuilds). The trait lives beside the
 * migration tests because that directory is the code gate's skip zone: its
 * old names are the fixture's point.
 */
trait OldSuiteFixtureTrait {

  /**
   * The database-backed key-value factory the site's registries live in.
   */
  protected KeyValueDatabaseFactory $registry;

  /**
   * The Drupal root the audit scans for custom code: a fixture tree.
   */
  protected string $codeRoot;

  /**
   * The modules the fixture's stores need; a using class lists them.
   */
  protected const OLD_SUITE_MODULES = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'block_content',
    'layout_discovery',
    'layout_builder',
  ];

  /**
   * The old modules core.extension names, with the weights the rename keeps.
   */
  protected const OLD_SUITE_EXTENSIONS = [
    'lb_plus' => 0,
    'lb_plus_section_library' => 0,
    'navigation_plus' => 0,
    'edit_plus' => 5,
    'tempstore_plus' => 0,
  ];

  /**
   * Makes the container's keyvalue factory the database one; from register().
   */
  protected function registerOldSuiteKeyValue(ContainerBuilder $container): void {
    $this->registry ??= new KeyValueDatabaseFactory(new PhpSerialize(), Database::getConnection());
    $container->set('keyvalue', $this->registry);
  }

  /**
   * Seeds the site; after the entity schemas are installed.
   */
  protected function seedOldSuite(): void {
    $this->registry->get('system.schema')->setMultiple([
      'lb_plus' => 10003,
      'lb_plus_section_library' => 8000,
      'navigation_plus' => 10400,
      'edit_plus' => 10001,
      'tempstore_plus' => 11001,
    ]);
    $this->registry->get('post_update')->set('existing_updates', [
      'system_post_update_remove_path_key',
      'tempstore_plus_post_update_prune_history',
      'lb_plus_section_library_post_update_gone',
    ]);

    $extension = $this->config('core.extension');
    $extension->set('module', module_config_sort(($extension->get('module') ?: []) + self::OLD_SUITE_EXTENSIONS))->save(TRUE);

    // navigation_plus's map base field on the user entity, installed the way
    // the old module's install did: the column, the installed definition and
    // the storage schema entry.
    $definition = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Navigation Plus Settings'))
      ->setDescription(new TranslatableMarkup('Settings for Navigation Plus hotkeys.'))
      ->setDefaultValue([]);
    \Drupal::entityDefinitionUpdateManager()->installFieldStorageDefinition('navigation_plus_settings', 'user', 'navigation_plus', $definition);

    $storage = $this->container->get('config.storage');
    $storage->write('lb_plus.settings', ['contextual_links' => TRUE]);
    $storage->write('navigation_plus.settings', ['colors' => ['primary' => '#000']]);
    $storage->write('user.role.content_editor', [
      'id' => 'content_editor',
      'label' => 'Content editor',
      'weight' => 2,
      'is_admin' => FALSE,
      'permissions' => ['access content', 'administer layout builder + configuration'],
    ]);

    NodeType::create(['type' => 'landing_page', 'name' => 'Landing page'])->save();
    $type = $storage->read('node.type.landing_page');
    $type['dependencies']['module'][] = 'navigation_plus';
    $type['third_party_settings']['navigation_plus'] = ['initial_mode' => 'edit', 'status' => ['edit' => TRUE]];
    $storage->write('node.type.landing_page', $type);

    BlockContentType::create(['id' => 'heading', 'label' => 'Heading'])->save();

    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'landing_page',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    $display = $storage->read('core.entity_view_display.node.landing_page.default');
    $display['third_party_settings']['lb_plus'] = [
      'default_section' => 'layout_onecol',
      'block_config' => ['inline_block:heading' => ['icon' => '/modules/contrib/lb_plus/assets/heading.svg']],
    ];
    $storage->write('core.entity_view_display.node.landing_page.default', $display);

    $section = new Section('layout_onecol', [], [
      $this->heading('11111111-1111-1111-1111-111111111111', 'Welcome'),
      new SectionComponent('22222222-2222-2222-2222-222222222222', 'content', ['id' => 'system_powered_by_block']),
    ], ['lb_plus' => ['uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']]);
    $node = Node::create([
      'type' => 'landing_page',
      'title' => 'Home',
      'layout_builder__layout' => [['section' => $section]],
    ]);
    $node->save();

    // A second delta on the same node, written as the table row daedalus's
    // own hooks would leave behind: a nested-section container embedding a
    // heading component, in the old vocabulary throughout (built by hand:
    // SectionBlock::createComponent() stamps a daedalus_layout uuid on the
    // inner section, a mixed state no site has). The plugin does not exist
    // in this site (its module is not installed), so the entity API cannot
    // save it.
    // The nested heading carries its unsaved block the way Layout Builder
    // stores one (block_serialized), a serialized string inside the section.
    $inner = new Section('layout_onecol', [], [
      $this->heading('33333333-3333-3333-3333-333333333333', 'Nested', [
        'block_serialized' => serialize(BlockContent::create(['type' => 'heading', 'info' => 'lb_plus heading'])),
      ]),
    ], [
      'lb_plus' => ['uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'],
    ]);
    $container = new SectionComponent('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'content', [
      'id' => SectionBlock::PLUGIN_ID,
      'label_display' => '0',
      SectionBlock::SECTION_KEY => $inner->toArray(),
      'context_mapping' => SectionBlock::CONTEXT_MAPPING,
    ]);
    $outer = new Section('layout_onecol', [], [$container], [
      'lb_plus' => ['uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc'],
    ]);
    $table_mapping = $this->container->get('entity_type.manager')->getStorage('node')->getTableMapping();
    $definition = $this->container->get('entity_field.manager')->getFieldStorageDefinitions('node')['layout_builder__layout'];
    $this->container->get('database')->insert($table_mapping->getFieldTableName('layout_builder__layout'))->fields([
      'bundle' => 'landing_page',
      'deleted' => 0,
      'entity_id' => $node->id(),
      'revision_id' => $node->getRevisionId(),
      'langcode' => 'en',
      'delta' => 1,
      $table_mapping->getFieldColumnName($definition, 'section') => serialize($outer),
    ])->execute();

    $this->codeRoot = $this->root . '/' . $this->siteDirectory . '/code';
    $this->writeCode('modules/custom/engineering_animation/engineering_animation.info.yml', "name: Engineering animation\ntype: module\ndependencies:\n  - edit_plus:edit_plus\n");
    $this->writeCode('modules/custom/engineering_animation/src/EventSubscriber/BlockAnimationProperties.php', "<?php\n\nnamespace Drupal\\engineering_animation\\EventSubscriber;\n\nuse Drupal\\edit_plus\\Event\\BlockPropertiesEvent;\n\n// Rides edit_plus's event.\n");
    $this->writeCode('modules/custom/engineering_animation/node_modules/edit_plus/index.js', "module.exports = 'edit_plus';\n");
    $this->writeCode('modules/custom/sdc_block/sdc_block.info.yml', "name: SDC block\ntype: module\ndependencies:\n  - field_sample_value:field_sample_value\n");
    $this->writeCode('modules/custom/sdc_block/assets/logo.png', "\x89PNG\r\n\x1a\n\0lb_plus");
    $this->writeCode('themes/custom/engineering/templates/page.html.twig', "{# navigation_plus draws the toolbar #}\n<main>{{ page.content }}</main>\n");
  }

  /**
   * The custom code files the fixture writes, as the audit reports them.
   *
   * @return array<string, list<string>>
   *   Root-relative path to the old literals in the file.
   */
  protected function oldSuiteCodeFiles(): array {
    $module = 'modules/custom/engineering_animation';
    return [
      "$module/engineering_animation.info.yml" => ['edit_plus'],
      "$module/src/EventSubscriber/BlockAnimationProperties.php" => ['Drupal\edit_plus', 'edit_plus'],
      'themes/custom/engineering/templates/page.html.twig' => ['navigation_plus'],
    ];
  }

  /**
   * An old heading component: text in the label, the rest in configuration.
   *
   * The configuration is the latest old release's shape (the trial site's):
   * the tag in label_tag, a size class in title_size, the alignment and the
   * link beside them; the given keys override it.
   */
  protected function heading(string $uuid, string $label, array $configuration = []): SectionComponent {
    return new SectionComponent($uuid, 'content', $configuration + [
      'id' => 'inline_block:heading',
      'label' => $label,
      'label_display' => 'visible',
      'label_tag' => 'h2',
      'title_size' => 'text-large1',
      'horizontal_alignment' => 'text-center',
      'link_enabled' => FALSE,
      'url' => '',
    ]);
  }

  /**
   * Writes one file of the custom code fixture.
   */
  private function writeCode(string $path, string $contents): void {
    $file = $this->codeRoot . '/' . $path;
    if (!is_dir(dirname($file))) {
      mkdir(dirname($file), 0777, TRUE);
    }
    file_put_contents($file, $contents);
  }

}
