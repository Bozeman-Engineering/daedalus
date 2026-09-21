<?php

declare(strict_types=1);

namespace Drupal\daedalus\Migrate\PlusSuite;

use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\daedalus\Migrate\PhaseInterface;
use Drupal\layout_builder\Section;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The rename phase: the site's persisted state translated into daedalus's.
 *
 * Runs once, after the composer swap and the opening audit, on a site whose
 * core.extension still names modules that left the disk. It installs the
 * foundation the renamed modules depend on (daedalus and the blueprint
 * family), then renames every store the audit reads: the extension and
 * update registries, every config object's name and data, every key_value
 * row, the user table's settings column, and every stored layout section,
 * revisions included. The stencil module's section-library migration is
 * un-marked so `drush updb` runs it, and the caches are flushed last, which
 * builds the first container with the renamed modules enabled. The site is
 * then ready for `drush updb`, which runs daedalus's own update hooks over
 * the translated data.
 *
 * Every step is idempotent (the mapping refuses to translate a daedalus name
 * twice), and the phase stops at the first failure with a report naming the
 * step, so the entry leaves it unrecorded and a rerun picks up where it
 * stopped. The phase rebuilds the container twice (the foundation install
 * and the flush), so services are taken from the current container at each
 * step rather than injected once and held stale.
 *
 * Core's module installer refuses to run while core.extension names a
 * module absent from disk (it resolves every enabled module's path), so the
 * foundation step takes the old names out of core.extension, installs, and
 * writes them back translated with their weights: the one write in which
 * the extension registry is renamed.
 *
 * An old module whose translated name is no module on disk (the old
 * section-library glue and the example block modules, which daedalus has no
 * twin of) leaves the registries instead of being renamed, the way a module
 * deleted from disk is removed from any site: its block types and config
 * stay. Core's recipe runner and config importer validate core.extension
 * against the modules on disk, so a name daedalus does not have cannot
 * stay in it.
 */
final class Renamer implements PhaseInterface {

  /**
   * The modules the renamed four depend on and the old site never had.
   *
   * The daedalus_layout module depends on daedalus_blueprint and
   * daedalus_blueprint_api; daedalus_ui and daedalus_edit on those and
   * daedalus_tempstore; the stencil module carries the section-library
   * migration.
   */
  public const FOUNDATION = [
    'daedalus',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_blueprint_stencil',
  ];

  /**
   * The post_update the foundation install marks run and `updb` must run.
   */
  public const STENCIL_MIGRATION = 'daedalus_blueprint_stencil_post_update_migrate_section_library';

  /**
   * The key columns a row of a dedicated field table is addressed by.
   */
  private const DEDICATED_KEYS = ['entity_id', 'revision_id', 'deleted', 'delta', 'langcode'];

  /**
   * The steps that ran so far, label to its line and items.
   *
   * @var array<string, array{line: string, items: list<string>}>
   */
  private array $steps = [];

  /**
   * The extension types whose list was reset this run.
   *
   * @var array<string, true>
   */
  private array $listsReset = [];

  public function __construct(
    private readonly Mapping $mapping,
    private ContainerInterface $container,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(Mapping::fromFile(), $container);
  }

  /**
   * {@inheritdoc}
   */
  public function run(): RenameReport {
    $this->steps = [];
    $sequence = [
      'foundation' => $this->installFoundation(...),
      'system.schema' => $this->renameSchema(...),
      'post_update' => $this->renamePostUpdates(...),
      'config' => $this->renameConfig(...),
      'columns' => $this->renameColumns(...),
      'key_value' => $this->renameKeyValue(...),
      'sections' => $this->renameSections(...),
      'stencil migration' => $this->unmarkStencilMigration(...),
      'caches' => $this->flush(...),
    ];
    foreach ($sequence as $label => $step) {
      try {
        $step();
      }
      catch (\Throwable $e) {
        return new RenameReport($this->steps, $label, sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
      }
    }
    return new RenameReport($this->steps);
  }

  /**
   * Installs the foundation and renames core.extension around the install.
   *
   * The old names leave core.extension before the install and come back
   * translated after it, weights kept, modules sorted as core writes them
   * (module_config_sort) and themes in place. Both writes go to the raw
   * config storage, past the config factory's save event: core's
   * UpdateRegistry listens to core.extension saves, prunes the recorded
   * post_updates of a module that leaves the list and marks every
   * post_update of a module that joins it as already run, which would
   * strand the renamed modules' migrations that `drush updb` must run. A
   * failing install puts the old names back untranslated and rethrows.
   */
  private function installFoundation(): void {
    $storage = $this->container->get('config.storage');
    $extension = $storage->read('core.extension') ?: [];
    $hidden = ['module' => [], 'theme' => []];
    foreach (array_keys($hidden) as $type) {
      $hidden[$type] = array_filter($extension[$type] ?? [], fn(string $name): bool => $this->namesOld($name), ARRAY_FILTER_USE_KEY);
      if ($hidden[$type]) {
        $extension[$type] = array_diff_key($extension[$type], $hidden[$type]);
      }
    }
    if ($hidden['module'] || $hidden['theme']) {
      $storage->write('core.extension', $extension);
      $this->container->get('config.factory')->reset('core.extension');
    }
    $before = array_keys($extension['module'] ?? []);
    try {
      $this->container->get('module_installer')->install(self::FOUNDATION);
    }
    catch (\Throwable $e) {
      $this->writeExtensions($hidden, FALSE);
      throw $e;
    }
    $this->container = $this->currentContainer();
    $installed = array_values(array_diff(array_keys($this->container->get('config.storage')->read('core.extension')['module'] ?? []), $before));
    $this->step('foundation', $installed ? sprintf('%d modules installed', count($installed)) : 'already installed', $installed);
    $renamed = $this->writeExtensions($hidden, TRUE);
    $this->step('core.extension', $this->registryLine('extensions', $renamed), $renamed);
  }

  /**
   * Writes extensions taken out of core.extension back, translated or not.
   *
   * Raw, past the save event (see installFoundation()), then the factory's
   * cache of core.extension is reset so the next reader sees the write.
   *
   * @param array{module: array<string, int>, theme: array<string, int>} $hidden
   *   The extensions by type, name to weight.
   * @param bool $translate
   *   Whether to write them under their daedalus names.
   *
   * @return list<string>
   *   What was written, `old to new` per extension.
   */
  private function writeExtensions(array $hidden, bool $translate): array {
    $container = $this->currentContainer();
    $storage = $container->get('config.storage');
    $extension = $storage->read('core.extension') ?: [];
    $written = [];
    foreach ($hidden as $type => $extensions) {
      if (!$extensions) {
        continue;
      }
      $current = $extension[$type] ?? [];
      foreach ($extensions as $name => $weight) {
        $new = $translate ? $this->mapping->translate($name) : $name;
        if ($new !== $name && !$this->onDisk($type, $new)) {
          $written[] = "$type $name removed ($new is not on disk: no daedalus twin)";
          continue;
        }
        $current[$new] = $weight;
        $written[] = "$type $name to $new (weight $weight)";
      }
      $extension[$type] = $type === 'module' ? module_config_sort($current) : $current;
    }
    if ($written) {
      $storage->write('core.extension', $extension);
      $container->get('config.factory')->reset('core.extension');
    }
    return $written;
  }

  /**
   * Renames the system.schema keys, versions kept.
   */
  private function renameSchema(): void {
    $store = $this->keyValue('system.schema');
    $renamed = [];
    foreach ($store->getAll() as $module => $version) {
      $new = $this->mapping->translate($module);
      if ($new === $module) {
        continue;
      }
      $store->delete($module);
      if (!$this->onDisk('module', $new)) {
        $renamed[] = "$module $version removed ($new is not on disk)";
        continue;
      }
      $store->set($new, $version);
      $renamed[] = "$module $version to $new";
    }
    $this->step('system.schema', $this->registryLine('keys', $renamed), $renamed);
  }

  /**
   * Renames the post_update names recorded as already run.
   */
  private function renamePostUpdates(): void {
    $store = $this->keyValue('post_update');
    $existing = $store->get('existing_updates', []);
    $renamed = [];
    $translated = [];
    foreach ($existing as $name) {
      $new = $this->mapping->translate($name);
      if ($new !== $name) {
        $module = strstr($new, '_post_update_', TRUE);
        if ($module !== FALSE && !$this->onDisk('module', $module)) {
          $renamed[] = "$name removed ($module is not on disk)";
          continue;
        }
        $renamed[] = "$name to $new";
      }
      $translated[] = $new;
    }
    $translated = array_values(array_unique($translated));
    if ($translated !== $existing) {
      $store->set('existing_updates', $translated);
    }
    $this->step('post_update', $this->registryLine('names', $renamed), $renamed);
  }

  /**
   * Renames every config object's name and data, in every collection.
   *
   * Read and written raw, so the objects of modules no longer on disk are
   * handled like any other; the config factory's static cache is reset
   * after. Dependency lists that changed are re-sorted the way a config
   * entity save writes them.
   */
  private function renameConfig(): void {
    $storage = $this->container->get('config.storage');
    $items = [];
    $collections = [StorageInterface::DEFAULT_COLLECTION, ...$storage->getAllCollectionNames()];
    foreach ($collections as $collection) {
      $collection_storage = $collection === StorageInterface::DEFAULT_COLLECTION ? $storage : $storage->createCollection($collection);
      $prefix = $collection === StorageInterface::DEFAULT_COLLECTION ? '' : "$collection:";
      foreach ($collection_storage->readMultiple($collection_storage->listAll()) as $name => $data) {
        $new_name = $this->mapping->translate($name);
        $new_data = $this->mapping->translateDeep($data);
        if ($new_name === $name && $new_data === $data) {
          continue;
        }
        if ($new_data !== $data) {
          $new_data = $this->sortDependencies($new_data);
        }
        $collection_storage->write($new_name, $new_data);
        if ($new_name !== $name) {
          $collection_storage->delete($name);
          $items[] = "$prefix$name to $new_name";
        }
        else {
          $items[] = "$prefix$name";
        }
      }
    }
    if ($items) {
      $this->container->get('config.factory')->reset();
    }
    $this->step('config', $items ? sprintf('%d objects rewritten', count($items)) : 'nothing to rewrite', $items);
  }

  /**
   * Sorts the dependency lists of a config object as a config entity does.
   */
  private function sortDependencies(array $data): array {
    if (!isset($data['dependencies']) || !is_array($data['dependencies'])) {
      return $data;
    }
    foreach ($data['dependencies'] as $type => $list) {
      if ($type === 'enforced' && is_array($list)) {
        $data['dependencies'][$type] = $this->sortDependencies(['dependencies' => $list])['dependencies'];
      }
      elseif (is_array($list) && array_is_list($list)) {
        sort($list);
        $data['dependencies'][$type] = $list;
      }
    }
    return $data;
  }

  /**
   * Renames the table columns of the old modules' base fields.
   *
   * Driven by the installed storage schema (entity.storage_schema.sql):
   * every `<entity type>.field_schema_data.<field>` entry whose column
   * names translate has the column changed through the schema API with the
   * stored spec. Runs before the key_value rows are translated, so the old
   * column names are still there to read.
   */
  private function renameColumns(): void {
    $schema = $this->container->get('database')->schema();
    $changed = [];
    foreach ($this->keyValue('entity.storage_schema.sql')->getAll() as $key => $entry) {
      if (!str_contains($key, '.field_schema_data.') || !is_array($entry)) {
        continue;
      }
      foreach ($entry as $table => $table_schema) {
        foreach ($table_schema['fields'] ?? [] as $column => $spec) {
          $new = $this->mapping->translate($column);
          if ($new !== $column && $schema->fieldExists($table, $column)) {
            $schema->changeField($table, $column, $new, $spec);
            $changed[] = "$table.$column to $new";
          }
        }
      }
    }
    $this->step('columns', $changed ? sprintf('%d columns renamed', count($changed)) : 'nothing to rename', $changed);
  }

  /**
   * Translates every key_value row whose collection, name or value is old.
   *
   * Listed as rows of the key_value table (the factory cannot list its
   * collections); the values are unserialized, walked and written back
   * through the factory, under the translated collection and name.
   */
  private function renameKeyValue(): void {
    $rows = $this->container->get('database')->select('key_value', 'kv')
      ->fields('kv', ['collection', 'name', 'value'])
      ->orderBy('collection')
      ->orderBy('name')
      ->execute()
      ->fetchAll();
    $items = [];
    foreach ($rows as $row) {
      $collection = $this->mapping->translate($row->collection);
      $name = $this->mapping->translate($row->name);
      $value_is_old = $this->namesOld($row->value);
      if ($collection === $row->collection && $name === $row->name && !$value_is_old) {
        continue;
      }
      $value = Mapping::unserializeStored($row->value, "key_value row $row->collection $row->name");
      if ($collection !== $row->collection || $name !== $row->name) {
        $this->keyValue($row->collection)->delete($row->name);
      }
      $this->keyValue($collection)->set($name, $value_is_old ? $this->mapping->translateDeep($value) : $value);
      $items[] = $collection === $row->collection && $name === $row->name ? "$collection $name" : "$row->collection $row->name to $collection $name";
    }
    $this->step('key_value', $items ? sprintf('%d rows rewritten', count($items)) : 'nothing to rewrite', $items);
  }

  /**
   * Translates every stored layout section, revisions included.
   *
   * The tables come from the field storage definitions of type
   * layout_section and their table mappings (a site's own layout fields and
   * section_library's included). Each row naming the old suite is
   * unserialized, walked and re-serialized, so string lengths are right and
   * nested sections (arrays inside a container component) come along.
   */
  private function renameSections(): void {
    $database = $this->container->get('database');
    $entity_type_manager = $this->container->get('entity_type.manager');
    $field_manager = $this->container->get('entity_field.manager');
    $counts = [];
    foreach ($field_manager->getFieldMapByFieldType('layout_section') as $entity_type_id => $fields) {
      $storage = $entity_type_manager->getStorage($entity_type_id);
      if (!$storage instanceof SqlContentEntityStorage) {
        continue;
      }
      $entity_type = $entity_type_manager->getDefinition($entity_type_id);
      $table_mapping = $storage->getTableMapping();
      $definitions = $field_manager->getFieldStorageDefinitions($entity_type_id);
      foreach (array_keys($fields) as $field_name) {
        $column = $table_mapping->getFieldColumnName($definitions[$field_name], 'section');
        foreach ($table_mapping->getAllFieldTableNames($field_name) as $table) {
          $keys = $this->rowKeys($entity_type, $table_mapping->getAllColumns($table));
          $rows = $database->select($table, 't')->fields('t', [...$keys, $column])->execute();
          foreach ($rows as $row) {
            if (!$this->namesOld($row->$column)) {
              continue;
            }
            $where = "$table row " . implode(' ', array_map(static fn(string $key): string => "$key {$row->$key}", $keys));
            $section = Mapping::unserializeStored($row->$column, $where);
            if (!$section instanceof Section) {
              throw new \RuntimeException("$where is not a layout section.");
            }
            $update = $database->update($table)->fields([$column => serialize($this->mapping->translateDeep($section))]);
            foreach ($keys as $key) {
              $update->condition($key, $row->$key);
            }
            $update->execute();
            $counts[$table] = ($counts[$table] ?? 0) + 1;
          }
        }
      }
    }
    $items = array_map(static fn(string $table, int $count): string => "$table $count", array_keys($counts), $counts);
    $this->step('sections', $counts ? sprintf('%d rows rewritten', array_sum($counts)) : 'nothing to rewrite', $items);
  }

  /**
   * The columns a row of a field table is addressed by.
   *
   * A dedicated field table carries the five dedicated keys; a shared table
   * (a single-value base field in the entity's own tables) is keyed by the
   * entity's id, revision and langcode columns, whichever it has.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The host entity type.
   * @param string[] $columns
   *   The table's columns.
   *
   * @return list<string>
   *   The key columns.
   */
  private function rowKeys(EntityTypeInterface $entity_type, array $columns): array {
    $keys = array_values(array_intersect(self::DEDICATED_KEYS, $columns));
    if (count($keys) === count(self::DEDICATED_KEYS)) {
      return $keys;
    }
    $entity_keys = array_filter([
      $entity_type->getKey('id'),
      $entity_type->getKey('revision'),
      $entity_type->getKey('langcode'),
    ]);
    return array_values(array_intersect($entity_keys, $columns));
  }

  /**
   * Un-marks the section-library migration the foundation install marked run.
   */
  private function unmarkStencilMigration(): void {
    $store = $this->keyValue('post_update');
    $existing = $store->get('existing_updates', []);
    $remaining = array_values(array_diff($existing, [self::STENCIL_MIGRATION]));
    if ($remaining !== $existing) {
      $store->set('existing_updates', $remaining);
      $this->step('stencil migration', 'un-marked, drush updb runs it', [self::STENCIL_MIGRATION]);
    }
    else {
      $this->step('stencil migration', 'not marked, nothing to do');
    }
  }

  /**
   * Flushes every cache and builds the first container with the new names.
   */
  private function flush(): void {
    drupal_flush_all_caches();
    $this->container = $this->currentContainer();
    $this->step('caches', 'flushed, container rebuilt with the renamed modules');
  }

  /**
   * Records a step in the report.
   *
   * @param string $label
   *   The step's label.
   * @param string $line
   *   The step's one-line count.
   * @param list<string> $items
   *   What the step touched, one line each.
   */
  private function step(string $label, string $line, array $items = []): void {
    $this->steps[$label] = ['line' => $line, 'items' => $items];
  }

  /**
   * The container as it stands after a rebuild.
   *
   * The kernel is one object across rebuilds and holds the container it
   * built last; the module installer and the cache flush both rebuild.
   */
  private function currentContainer(): ContainerInterface {
    return $this->container->get('kernel')->getContainer();
  }

  /**
   * A key-value store of the current container's factory.
   */
  private function keyValue(string $collection): KeyValueStoreInterface {
    return $this->container->get('keyvalue')->get($collection);
  }

  /**
   * A registry step's line: how many entries were renamed and removed.
   *
   * @param string $noun
   *   What the entries are (extensions, keys, names).
   * @param list<string> $items
   *   The step's items; a removed entry's item says "removed (".
   */
  private function registryLine(string $noun, array $items): string {
    if (!$items) {
      return 'nothing to rename';
    }
    $removed = count(array_filter($items, static fn(string $item): bool => str_contains($item, ' removed (')));
    $line = sprintf('%d %s renamed', count($items) - $removed, $noun);
    return $removed ? sprintf('%s, %d removed', $line, $removed) : $line;
  }

  /**
   * Whether an extension of the type is on disk, installed or not.
   *
   * The list is reset once per type so the code the composer swap put on
   * disk is seen whatever the cache held; the foundation install resets the
   * module list too.
   */
  private function onDisk(string $type, string $name): bool {
    $list = $this->currentContainer()->get("extension.list.$type");
    if (!isset($this->listsReset[$type])) {
      $list->reset();
      $this->listsReset[$type] = TRUE;
    }
    return $list->exists($name);
  }

  /**
   * Whether a stored string names the old suite.
   */
  private function namesOld(string $text): bool {
    return $this->mapping->translate($text) !== $text;
  }

}
