<?php

declare(strict_types=1);

namespace Drupal\daedalus\Migrate\PlusSuite;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\daedalus\Migrate\PhaseInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\filter\FilterFormatInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The audit phase: what a site still stores in the old suite's vocabulary.
 *
 * Read-only. It opens the migration (is the site one the pathway serves,
 * and what will the rename touch) and closes it (what is left, which should
 * be the custom code only). Every place the old names can live is read the
 * way the rename phase will write it: config through the raw active storage,
 * the registries and every other key_value row as stored, the layout tables
 * through the layout_section field storage definitions and their table
 * mappings, roles through config, and the site's own code under
 * modules/custom and themes/custom with the mapping's literal rows.
 *
 * Detection is on the stored text (a config name, a serialized value, a
 * file): a value the mapping would translate names the old suite. That is
 * total, an object of a class no longer on disk still counts, and it is the
 * stricter twin of the rename phase's walk, so a closing audit that reports
 * nothing left means nothing left.
 */
final class Audit implements PhaseInterface {

  public const REPEATABLE = TRUE;

  /**
   * The component plugins the content phase rewrites, counted per plugin.
   */
  public const CONTENT_PLUGINS = [HeadingTransform::HEADING];

  /**
   * Where a site's own code lives, relative to the Drupal root.
   */
  private const CODE_DIRECTORIES = ['modules/custom', 'themes/custom'];

  /**
   * Directory names the code scan never descends into (dot directories too).
   */
  private const SKIPPED_DIRECTORIES = ['node_modules', 'vendor'];

  /**
   * The declarations of daedalus_edit, relative to the package root.
   *
   * Its instances declaration ('.s-*') is the identity vocabulary: the tags
   * daedalus_edit mints identity classes onto at every save.
   */
  private const DECLARATIONS = '/modules/daedalus_edit/daedalus_edit.page_builder.yml';

  /**
   * The instances declaration key of the identity vocabulary.
   */
  private const IDENTITY_PATTERN = '.s-*';

  public function __construct(
    private readonly Mapping $mapping,
    private readonly StorageInterface $configStorage,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly string $root,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      Mapping::fromFile(),
      $container->get('config.storage'),
      $container->get('keyvalue'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->getParameter('app.root'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(): AuditReport {
    $schema = $this->schemaVersions();
    $formats = $this->formats();
    [$sections, $components] = $this->layoutSections();
    return new AuditReport(
      schema: $schema,
      floor: $this->mapping->floor(),
      enabled: $this->enabledExtensions(),
      refusal: $this->refusal($schema, $formats),
      formats: $formats,
      config: $this->configObjects(),
      keyValue: $this->keyValueEntries(),
      sections: $sections,
      components: $components,
      roles: $this->rolePermissions(),
      files: $this->codeFiles(),
    );
  }

  /**
   * Whether a stored string names the old suite.
   */
  private function namesOld(string $text): bool {
    return $this->mapping->translate($text) !== $text;
  }

  /**
   * The old modules' system.schema versions, from the registry.
   *
   * @return array<string, int>
   *   Old module name to its recorded schema version.
   */
  private function schemaVersions(): array {
    $versions = [];
    foreach ($this->keyValue->get('system.schema')->getAll() as $module => $version) {
      if ($this->namesOld($module)) {
        $versions[$module] = (int) $version;
      }
    }
    ksort($versions);
    return $versions;
  }

  /**
   * The old extensions core.extension still names, by type.
   *
   * @return array{module: list<string>, theme: list<string>}
   *   The old module and theme names.
   */
  private function enabledExtensions(): array {
    $extension = $this->configStorage->read('core.extension') ?: [];
    $enabled = [];
    foreach (['module', 'theme'] as $type) {
      $enabled[$type] = array_values(array_filter(array_keys($extension[$type] ?? []), fn(string $name): bool => $this->namesOld($name)));
    }
    return $enabled;
  }

  /**
   * Why the site cannot be served, or NULL when it can.
   *
   * An old module whose recorded schema version is below the table's floor
   * ran an older release than the pathway serves; the site updates the old
   * modules first. A text format that allows an identity tag but strips its
   * class attribute would fail every save through it once daedalus_edit
   * mints (its IdentityMinter throws rather than lose the ids at render),
   * the update run included; the site allows the attribute first.
   *
   * @param array<string, int> $schema
   *   The old modules' schema versions.
   * @param array<string, list<string>> $formats
   *   Text format id to the identity tags it allows without class.
   */
  private function refusal(array $schema, array $formats): ?string {
    $reasons = [];
    foreach ($this->mapping->rows('floor') as $row) {
      $module = $row['old'];
      $floor = (int) $row['new'];
      if (isset($schema[$module]) && $schema[$module] < $floor) {
        $reasons[] = sprintf('%s is at schema version %d; the migration serves %d and later (%s).', $module, $schema[$module], $floor, $row['note']);
      }
    }
    if ($formats) {
      $vocabulary = implode(', ', $this->vocabulary());
      foreach ($formats as $format => $tags) {
        $reasons[] = sprintf(
          'Text format %s allows <%s> without the class attribute; daedalus_edit mints identity classes onto every vocabulary tag a format allows (%s) and refuses a save through a format that would strip them. Allow class on those tags in the format\'s allowed HTML first.',
          $format,
          implode('>, <', $tags),
          $vocabulary,
        );
      }
    }
    return $reasons ? implode("\n", $reasons) : NULL;
  }

  /**
   * The identity tags each enabled text format allows without class.
   *
   * Read through the format's own HTML restrictions, the door
   * daedalus_edit's IdentityMinter reads at every save: a tag the format
   * strips entirely is moot, one it allows must allow class unrestricted.
   *
   * @return array<string, list<string>>
   *   Text format id to the tags, formats with none left out.
   */
  private function formats(): array {
    if (!$this->entityTypeManager->hasDefinition('filter_format')) {
      return [];
    }
    $vocabulary = $this->vocabulary();
    $formats = [];
    foreach ($this->entityTypeManager->getStorage('filter_format')->loadMultiple() as $id => $format) {
      if (!$format instanceof FilterFormatInterface || !$format->status()) {
        continue;
      }
      $restrictions = $format->getHtmlRestrictions();
      if ($restrictions === FALSE) {
        continue;
      }
      $allowed = $restrictions['allowed'];
      $stripping = [];
      foreach ($vocabulary as $tag) {
        if (isset($allowed[$tag]) && ($allowed[$tag]['class'] ?? $allowed['*']['class'] ?? FALSE) !== TRUE) {
          $stripping[] = $tag;
        }
      }
      if ($stripping) {
        $formats[$id] = $stripping;
      }
    }
    ksort($formats);
    return $formats;
  }

  /**
   * The identity vocabulary: the tags of daedalus_edit's '.s-*' declaration.
   *
   * Read from the package on disk, since the module is not installed when
   * the opening audit runs.
   *
   * @return list<string>
   *   The tag names.
   */
  private function vocabulary(): array {
    $declarations = Yaml::decode((string) file_get_contents(dirname(__DIR__, 3) . self::DECLARATIONS));
    return array_map('strval', array_keys($declarations['instances'][self::IDENTITY_PATTERN]['tags'] ?? []));
  }

  /**
   * The config objects whose name or data names the old suite.
   *
   * Every collection of the active storage, read raw so the objects of
   * modules no longer on disk read like any other.
   *
   * @return list<string>
   *   Config names, prefixed with `<collection>:` outside the default one.
   */
  private function configObjects(): array {
    $names = [];
    $collections = [StorageInterface::DEFAULT_COLLECTION, ...$this->configStorage->getAllCollectionNames()];
    foreach ($collections as $collection) {
      $storage = $collection === StorageInterface::DEFAULT_COLLECTION ? $this->configStorage : $this->configStorage->createCollection($collection);
      foreach ($storage->readMultiple($storage->listAll()) as $name => $data) {
        if ($this->namesOld($name) || $this->mapping->translateDeep($data) !== $data) {
          $names[] = $collection === StorageInterface::DEFAULT_COLLECTION ? $name : "$collection:$name";
        }
      }
    }
    sort($names);
    return $names;
  }

  /**
   * The key_value entries whose name or stored value names the old suite.
   *
   * Read as rows of the key_value table rather than through the key-value
   * factory: the factory cannot list its collections, and it unserializes
   * every value, while a stored value of a class that left the disk with the
   * old modules must still count. The rename phase walks the unserialized
   * values; this is its stricter twin.
   *
   * @return array<string, int>
   *   Collection name to the number of entries naming the old suite.
   */
  private function keyValueEntries(): array {
    $counts = [];
    $rows = $this->database->select('key_value', 'kv')
      ->fields('kv', ['collection', 'name', 'value'])
      ->orderBy('collection')
      ->execute();
    foreach ($rows as $row) {
      if ($this->namesOld($row->name) || $this->namesOld($row->value)) {
        $counts[$row->collection] = ($counts[$row->collection] ?? 0) + 1;
      }
    }
    return $counts;
  }

  /**
   * Every layout_section table's old rows and the components to rewrite.
   *
   * The tables come from the field storage definitions of type
   * layout_section and their SQL table mappings, so a site's own layout
   * fields and every host entity type are found the same way. Rows are
   * counted on the field's data table (the default revisions) and on its
   * revision tables; components are counted on the default revisions only,
   * nested sections included.
   *
   * @return array{0: array<string, array<string, mixed>>, 1: array<string, int>}
   *   Per `<entity type>.<field>`: the data table, its row and old-row
   *   counts and the same for the revision tables; then plugin id to count.
   */
  private function layoutSections(): array {
    $sections = [];
    $components = [];
    foreach ($this->entityFieldManager->getFieldMapByFieldType('layout_section') as $entity_type_id => $fields) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      if (!$storage instanceof SqlContentEntityStorage) {
        continue;
      }
      $table_mapping = $storage->getTableMapping();
      $definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
      foreach (array_keys($fields) as $field_name) {
        $column = $table_mapping->getFieldColumnName($definitions[$field_name], 'section');
        $table = $table_mapping->getFieldTableName($field_name);
        $entry = ['table' => $table, 'rows' => 0, 'old' => 0, 'revision_rows' => 0, 'revision_old' => 0];
        foreach ($this->sectionRows($table, $column) as $value) {
          $entry['rows']++;
          $entry['old'] += (int) $this->namesOld($value);
          $section = unserialize($value, ['allowed_classes' => [Section::class, SectionComponent::class]]);
          if ($section instanceof Section) {
            $this->countComponents($section, $components);
          }
        }
        foreach (array_diff($table_mapping->getAllFieldTableNames($field_name), [$table]) as $revision_table) {
          foreach ($this->sectionRows($revision_table, $column) as $value) {
            $entry['revision_rows']++;
            $entry['revision_old'] += (int) $this->namesOld($value);
          }
        }
        $sections["$entity_type_id.$field_name"] = $entry;
      }
    }
    ksort($sections);
    ksort($components);
    return [$sections, $components];
  }

  /**
   * The serialized sections stored in one table.
   *
   * @return list<string>
   *   The column's values.
   */
  private function sectionRows(string $table, string $column): array {
    return $this->database->select($table, 't')->fields('t', [$column])->execute()->fetchCol();
  }

  /**
   * Counts the components the content phase rewrites, nested sections too.
   *
   * A component whose plugin id the mapping would rename counts as well: it
   * is a block plugin of an old module, and the rename phase carries it.
   */
  private function countComponents(Section $section, array &$components): void {
    foreach ($section->getComponents() as $component) {
      $plugin_id = $component->getPluginId();
      if (in_array($plugin_id, self::CONTENT_PLUGINS, TRUE) || $this->namesOld($plugin_id)) {
        $components[$plugin_id] = ($components[$plugin_id] ?? 0) + 1;
      }
      $embedded = SectionBlock::embeddedSection($component);
      if ($embedded) {
        $this->countComponents($embedded, $components);
      }
    }
  }

  /**
   * The roles carrying a permission string the mapping renames.
   *
   * @return array<string, list<string>>
   *   Role id to its old permission strings.
   */
  private function rolePermissions(): array {
    $roles = [];
    foreach ($this->configStorage->readMultiple($this->configStorage->listAll('user.role.')) as $name => $data) {
      $old = array_values(array_filter($data['permissions'] ?? [], fn(string $permission): bool => $this->namesOld($permission)));
      if ($old) {
        $roles[$data['id'] ?? substr($name, strlen('user.role.'))] = $old;
      }
    }
    ksort($roles);
    return $roles;
  }

  /**
   * The site's own code files naming the old suite, with what they name.
   *
   * @return array<string, list<string>>
   *   Root-relative path to the old literals found in the file.
   */
  private function codeFiles(): array {
    $files = [];
    foreach (self::CODE_DIRECTORIES as $directory) {
      $path = $this->root . '/' . $directory;
      if (!is_dir($path)) {
        continue;
      }
      $tree = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
      $filter = new \RecursiveCallbackFilterIterator($tree, static fn(\SplFileInfo $file): bool => !$file->isDir() || (!str_starts_with($file->getFilename(), '.') && !in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, TRUE)));
      foreach (new \RecursiveIteratorIterator($filter) as $file) {
        if (!$file->isFile()) {
          continue;
        }
        $contents = file_get_contents($file->getPathname());
        if ($contents === FALSE || str_contains(substr($contents, 0, 8192), "\0")) {
          continue;
        }
        $hits = $this->mapping->hits($contents);
        if ($hits) {
          $files[substr($file->getPathname(), strlen($this->root) + 1)] = $hits;
        }
      }
    }
    ksort($files);
    return $files;
  }

}
