<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Kernel\Migrate\PlusSuite;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\Exception\UnknownExtensionException;
use Drupal\daedalus\Migrate\PlusSuite\Audit;
use Drupal\daedalus\Migrate\PlusSuite\Mapping;
use Drupal\daedalus\Migrate\PlusSuite\Renamer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the rename phase over a site seeded in the old suite's vocabulary.
 *
 * The site is the audit's fixture; the modules beside it are what the old
 * suite's releases depended on, so the container the phase builds last, with
 * the renamed modules enabled, compiles as it does on a real site.
 *
 * @group daedalus
 */
#[Group('daedalus')]
#[RunTestsInSeparateProcesses]
final class RenamerTest extends KernelTestBase {

  use OldSuiteFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'block',
    'file',
    'image',
    'media',
    'navigation',
    'editor',
    'twig_events',
    'field_sample_value',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $this->registerOldSuiteKeyValue($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    $this->installSchema('node', ['node_access']);
    $this->seedOldSuite();
  }

  /**
   * Core's installer refuses a core.extension naming modules absent from disk.
   *
   * The reason the foundation step takes the old names out around the
   * install; should core come to tolerate them, this fails and the step can
   * shed the hiding.
   */
  public function testInstallerRefusesAbsentModules(): void {
    $this->expectException(UnknownExtensionException::class);
    $this->expectExceptionMessage('The module lb_plus does not exist.');
    $this->container->get('module_installer')->install(['daedalus']);
  }

  /**
   * The site comes out speaking daedalus everywhere the audit looks.
   */
  public function testRename(): void {
    $report = (new Renamer(Mapping::fromFile(), $this->container))->run();
    $this->assertTrue($report->ok(), $report->summary());
    $this->assertNull($report->failure);
    $this->assertSame([
      'foundation',
      'core.extension',
      'system.schema',
      'post_update',
      'config',
      'columns',
      'key_value',
      'sections',
      'stencil migration',
      'caches',
    ], array_keys($report->steps));
    $this->assertSame(Renamer::FOUNDATION, $report->steps['foundation']['items']);
    $this->assertSame([
      'module lb_plus to daedalus_layout (weight 0)',
      'module lb_plus_section_library removed (daedalus_layout_section_library is not on disk: no daedalus twin)',
      'module navigation_plus to daedalus_ui (weight 0)',
      'module tempstore_plus to daedalus_tempstore (weight 0)',
      'module edit_plus to daedalus_edit (weight 5)',
    ], $report->steps['core.extension']['items']);
    $this->assertSame('1 columns renamed', $report->steps['columns']['line']);
    $this->assertSame(['users_field_data.navigation_plus_settings to daedalus_ui_settings'], $report->steps['columns']['items']);
    $this->assertSame('3 rows rewritten', $report->steps['sections']['line']);
    $this->assertSame(['node__layout_builder__layout 2', 'node_revision__layout_builder__layout 1'], $report->steps['sections']['items']);
    $this->assertSame([Renamer::STENCIL_MIGRATION], $report->steps['stencil migration']['items']);
    $this->assertStringStartsWith('rename: foundation: 4 modules installed; core.extension: 4 extensions renamed, 1 removed; system.schema: 4 keys renamed, 1 removed; post_update: 1 names renamed, 1 removed; config: ', $report->summary());
    $this->assertStringContainsString("  lb_plus.settings to daedalus_layout.settings\n", $report->render());

    // KernelTestBase re-points $this->container at every rebuilt container;
    // the global one is what the phase left behind.
    $container = \Drupal::getContainer();
    $this->assertArrayHasKey('daedalus_ui', $container->getParameter('container.modules'), 'The last container runs the renamed modules.');

    // core.extension: the foundation and the renamed four, weights kept,
    // sorted as core writes them, and no old name. The old section-library
    // glue has no daedalus twin on disk, so it leaves instead of renaming.
    $modules = $container->get('config.factory')->get('core.extension')->get('module');
    foreach ([...Renamer::FOUNDATION, 'daedalus_layout', 'daedalus_ui', 'daedalus_edit', 'daedalus_tempstore'] as $module) {
      $this->assertArrayHasKey($module, $modules);
    }
    foreach ([...array_keys(self::OLD_SUITE_EXTENSIONS), 'daedalus_layout_section_library'] as $module) {
      $this->assertArrayNotHasKey($module, $modules);
    }
    $this->assertStringContainsString('module lb_plus_section_library removed (daedalus_layout_section_library is not on disk: no daedalus twin)', $report->render());
    $this->assertSame(5, $modules['daedalus_edit']);
    $this->assertSame(module_config_sort($modules), $modules);
    $this->assertSame('daedalus_edit', array_key_last($modules), 'The heaviest module sorts last.');

    // system.schema: the old versions under the new names, the foundation
    // at its own.
    $schema = $this->registry->get('system.schema')->getAll();
    $this->assertSame(10003, $schema['daedalus_layout']);
    $this->assertArrayNotHasKey('daedalus_layout_section_library', $schema);
    $this->assertStringContainsString('lb_plus_section_library 8000 removed (daedalus_layout_section_library is not on disk)', $report->render());
    $this->assertSame(10400, $schema['daedalus_ui']);
    $this->assertSame(10001, $schema['daedalus_edit']);
    $this->assertSame(11001, $schema['daedalus_tempstore']);
    $this->assertArrayHasKey('daedalus_blueprint_stencil', $schema);
    foreach (['lb_plus', 'lb_plus_section_library', 'navigation_plus', 'edit_plus', 'tempstore_plus'] as $module) {
      $this->assertArrayNotHasKey($module, $schema);
    }

    // post_update: the old names translated, the install's marks kept but
    // for the section-library migration.
    $existing = $this->registry->get('post_update')->get('existing_updates');
    $this->assertContains('system_post_update_remove_path_key', $existing);
    $this->assertNotContains('lb_plus_section_library_post_update_gone', $existing);
    $this->assertNotContains('daedalus_layout_section_library_post_update_gone', $existing);
    $this->assertContains('daedalus_tempstore_post_update_prune_history', $existing);
    $this->assertNotContains('tempstore_plus_post_update_prune_history', $existing);
    $this->assertContains('daedalus_blueprint_stencil_post_update_migrate_layout_block_nodes', $existing);
    $this->assertNotContains(Renamer::STENCIL_MIGRATION, $existing);
    // The renamed modules' own migrations are pending for drush updb: the
    // rename never marks a post_update of theirs, and their hook_update_N
    // above the recorded versions are due.
    $this->assertSame(['daedalus_tempstore_post_update_prune_history'], array_values(preg_grep('/^daedalus_(layout|ui|edit|tempstore)_post_update_/', $existing)));
    $pending = $container->get('update.post_update_registry')->getPendingUpdateFunctions();
    $this->assertContains('daedalus_layout_post_update_0001_migrate_layout_blocks_hosts', $pending);
    $this->assertContains('daedalus_layout_post_update_0005_block_icons_to_block_types', $pending);
    $this->assertContains(Renamer::STENCIL_MIGRATION, $pending);
    $this->assertNotContains('daedalus_blueprint_stencil_post_update_migrate_layout_block_nodes', $pending);
    $container->get('module_handler')->loadInclude('daedalus_ui', 'install');
    $hook_registry = $container->get('update.update_hook_registry');
    $this->assertSame(10400, $hook_registry->getInstalledVersion('daedalus_ui'));
    $this->assertSame([10401, 10402, 10403], array_values(array_filter($hook_registry->getAvailableUpdates('daedalus_ui'), static fn(int $number): bool => $number > 10400)));

    // Config: names and data.
    $storage = $container->get('config.storage');
    $this->assertSame(['contextual_links' => TRUE], $storage->read('daedalus_layout.settings'));
    $this->assertFalse($storage->read('lb_plus.settings'));
    $this->assertSame(['colors' => ['primary' => '#000']], $storage->read('daedalus_ui.settings'));
    $this->assertFalse($storage->read('navigation_plus.settings'));
    $type = $storage->read('node.type.landing_page');
    $this->assertSame(['daedalus_ui'], $type['dependencies']['module']);
    $this->assertSame(['daedalus_ui' => ['initial_mode' => 'edit', 'status' => ['edit' => TRUE]]], $type['third_party_settings']);
    $display = $storage->read('core.entity_view_display.node.landing_page.default');
    $this->assertSame([
      'default_section' => 'layout_onecol',
      'block_config' => ['inline_block:heading' => ['icon' => '/modules/contrib/daedalus/modules/daedalus_layout/assets/heading.svg']],
    ], $display['third_party_settings']['daedalus_layout']);
    $this->assertArrayNotHasKey('lb_plus', $display['third_party_settings']);
    $this->assertSame(['access content', 'administer daedalus layout configuration'], $storage->read('user.role.content_editor')['permissions']);

    // The user column, its installed definition and its storage schema.
    $schema_api = $container->get('database')->schema();
    $this->assertTrue($schema_api->fieldExists('users_field_data', 'daedalus_ui_settings'));
    $this->assertFalse($schema_api->fieldExists('users_field_data', 'navigation_plus_settings'));
    $definitions = $this->registry->get('entity.definitions.installed')->get('user.field_storage_definitions');
    $this->assertArrayHasKey('daedalus_ui_settings', $definitions);
    $this->assertArrayNotHasKey('navigation_plus_settings', $definitions);
    $this->assertSame('daedalus_ui', $definitions['daedalus_ui_settings']->getProvider());
    $this->assertSame('daedalus_ui_settings', $definitions['daedalus_ui_settings']->getName());
    $this->assertSame('daedalus_ui_settings', $definitions['daedalus_ui_settings']->getItemDefinition()->getFieldDefinition()->getName(), 'The item definition points back at the one translated definition.');
    $this->assertSame('Daedalus UI Settings', (string) $definitions['daedalus_ui_settings']->getLabel());
    $storage_schema = $this->registry->get('entity.storage_schema.sql');
    $this->assertNull($storage_schema->get('user.field_schema_data.navigation_plus_settings'));
    $this->assertSame(['users_field_data'], array_keys($storage_schema->get('user.field_schema_data.daedalus_ui_settings')));
    $this->assertArrayHasKey('daedalus_ui_settings', $storage_schema->get('user.field_schema_data.daedalus_ui_settings')['users_field_data']['fields']);
    $this->assertArrayNotHasKey('user', $container->get('entity.definition_update_manager')->getChangeList(), 'daedalus_ui provides the field the registries now name; nothing is pending.');

    // Sections: the same uuids under daedalus_layout, the nested one and
    // the revision row too.
    $sections = array_column(Node::load(1)->get('layout_builder__layout')->getValue(), 'section');
    $this->assertCount(2, $sections);
    $this->assertSame(['uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'], $sections[0]->getThirdPartySettings('daedalus_layout'));
    $this->assertSame([], $sections[0]->getThirdPartySettings('lb_plus'));
    $this->assertSame('inline_block:heading', $sections[0]->getComponent('11111111-1111-1111-1111-111111111111')->getPluginId());
    $this->assertSame(['uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc'], $sections[1]->getThirdPartySettings('daedalus_layout'));
    $container_component = $sections[1]->getComponents();
    $inner = Section::fromArray(reset($container_component)->get('configuration')['section']);
    $this->assertSame(['uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'], $inner->getThirdPartySettings('daedalus_layout'));
    $this->assertSame([], $inner->getThirdPartySettings('lb_plus'));
    $nested = $inner->getComponent('33333333-3333-3333-3333-333333333333')->get('configuration');
    $this->assertSame('Nested', $nested['label']);
    $block = unserialize($nested['block_serialized'], ['allowed_classes' => [BlockContent::class]]);
    $this->assertInstanceOf(BlockContent::class, $block, 'The serialized block inside the component still unserializes.');
    $this->assertSame('daedalus_layout heading', $block->label());
    $revision = $container->get('database')->select('node_revision__layout_builder__layout', 'r')->fields('r', ['layout_builder__layout_section'])->execute()->fetchCol();
    $this->assertCount(1, $revision);
    $this->assertStringNotContainsString('lb_plus', $revision[0]);
    $revision_section = unserialize($revision[0], ['allowed_classes' => [Section::class, SectionComponent::class]]);
    $this->assertSame(['uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'], $revision_section->getThirdPartySettings('daedalus_layout'));

    // The closing audit: nothing old outside the custom code.
    $audit = $this->audit()->run();
    $this->assertTrue($audit->ok());
    $this->assertSame([], $audit->schema);
    $this->assertSame(['module' => [], 'theme' => []], $audit->enabled);
    $this->assertSame([], $audit->config);
    $this->assertSame([], $audit->keyValue);
    $this->assertSame(0, $audit->oldSections());
    $this->assertSame(0, $audit->oldRevisions());
    $this->assertSame(['inline_block:heading' => 2], $audit->components, 'The heading components are the content phase\'s.');
    $this->assertSame([], $audit->roles);
    $this->assertSame($this->oldSuiteCodeFiles(), $audit->files);
    $this->assertStringStartsWith('audit: schema no old module; 0 config objects, 0 key_value entries, 0 sections (2 inline_block:heading), 0 revision rows, 0 roles, 3 custom-code files', $audit->summary());

    // A second run is a no-op.
    $before = $this->snapshot();
    $again = (new Renamer(Mapping::fromFile(), \Drupal::getContainer()))->run();
    $this->assertTrue($again->ok(), $again->summary());
    $this->assertSame(
      'rename: foundation: already installed; core.extension: nothing to rename; system.schema: nothing to rename; post_update: nothing to rename; config: nothing to rewrite; columns: nothing to rename; key_value: nothing to rewrite; sections: nothing to rewrite; stencil migration: not marked, nothing to do; caches: flushed, container rebuilt with the renamed modules.',
      $again->summary(),
    );
    $this->assertSame($before, $this->snapshot());
  }

  /**
   * A stored layout row the walk cannot carry stops the phase, named.
   */
  public function testUnreadableSectionFails(): void {
    $table_mapping = $this->container->get('entity_type.manager')->getStorage('node')->getTableMapping();
    $this->container->get('database')->insert($table_mapping->getFieldTableName('layout_builder__layout'))->fields([
      'bundle' => 'landing_page',
      'deleted' => 0,
      'entity_id' => 1,
      'revision_id' => 1,
      'langcode' => 'en',
      'delta' => 2,
      'layout_builder__layout_section' => serialize('lb_plus left this behind'),
    ])->execute();

    $report = (new Renamer(Mapping::fromFile(), $this->container))->run();

    $this->assertFalse($report->ok());
    $this->assertSame('sections', $report->failedStep);
    $this->assertStringContainsString('node__layout_builder__layout row entity_id 1 revision_id 1 deleted 0 delta 2 langcode en is not a layout section.', $report->failure);
    $this->assertStringStartsWith('rename: FAILED at sections: RuntimeException: node__layout_builder__layout row', $report->summary());
    $this->assertStringContainsString("FAILED at sections. The steps above ran", $report->render());
    $this->assertArrayHasKey('key_value', $report->steps);
    $this->assertArrayNotHasKey('sections', $report->steps);
    $this->assertArrayNotHasKey('caches', $report->steps);
  }

  /**
   * The audit over the site as it stands, the phase's closing check.
   */
  private function audit(): Audit {
    $container = \Drupal::getContainer();
    return new Audit(
      Mapping::fromFile(),
      $container->get('config.storage'),
      $this->registry,
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $this->codeRoot,
    );
  }

  /**
   * Every store the phase writes, for a before-and-after comparison.
   */
  private function snapshot(): array {
    $container = \Drupal::getContainer();
    $database = $container->get('database');
    $storage = $container->get('config.storage');
    $config = $storage->readMultiple($storage->listAll());
    ksort($config);
    return [
      'config' => $config,
      // The state collection is left out: every flush regenerates the
      // router's state rows (routing.non_admin_routes) in the order of that
      // container's modules.
      'key_value' => $database->select('key_value', 'kv')->fields('kv')->condition('collection', 'state', '<>')->orderBy('collection')->orderBy('name')->execute()->fetchAll(FetchAs::Associative),
      'sections' => $database->select('node__layout_builder__layout', 's')->fields('s')->orderBy('delta')->execute()->fetchAll(FetchAs::Associative),
      'revisions' => $database->select('node_revision__layout_builder__layout', 's')->fields('s')->orderBy('delta')->execute()->fetchAll(FetchAs::Associative),
      'columns' => array_keys($database->select('users_field_data', 'u')->fields('u')->range(0, 1)->execute()->fetchAssoc() ?: []),
    ];
  }

}
