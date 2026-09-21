<?php

declare(strict_types=1);

namespace Drupal\daedalus\Migrate\PlusSuite;

use Drupal\daedalus\Migrate\ReportInterface;

/**
 * What the audit found: counts per store, and a refusal for a site not served.
 */
final class AuditReport implements ReportInterface {

  /**
   * Constructs the report.
   *
   * @param array<string, int> $schema
   *   Old module name to its recorded system.schema version.
   * @param array<string, int> $floor
   *   Old module name to the oldest schema version the migration serves.
   * @param array{module: list<string>, theme: list<string>} $enabled
   *   The old extensions core.extension names, by type.
   * @param string|null $refusal
   *   Why the site cannot be served, or NULL.
   * @param array<string, list<string>> $formats
   *   Text format id to the identity tags it allows without the class
   *   attribute (daedalus_edit refuses saves through such a format).
   * @param list<string> $config
   *   The config names whose name or data names the old suite.
   * @param array<string, int> $keyValue
   *   Collection name to the number of its entries naming the old suite.
   * @param array<string, array<string, mixed>> $sections
   *   Per `<entity type>.<field>`: `table`, `rows`, `old`, `revision_rows`,
   *   `revision_old`.
   * @param array<string, int> $components
   *   Component plugin id to its count on the default revisions.
   * @param array<string, list<string>> $roles
   *   Role id to its old permission strings.
   * @param array<string, list<string>> $files
   *   Root-relative custom code path to the old literals it names.
   */
  public function __construct(
    public readonly array $schema,
    public readonly array $floor,
    public readonly array $enabled,
    public readonly ?string $refusal,
    public readonly array $formats,
    public readonly array $config,
    public readonly array $keyValue,
    public readonly array $sections,
    public readonly array $components,
    public readonly array $roles,
    public readonly array $files,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function ok(): bool {
    return $this->refusal === NULL;
  }

  /**
   * The rows of the data tables naming the old suite, over every host.
   */
  public function oldSections(): int {
    return array_sum(array_column($this->sections, 'old'));
  }

  /**
   * The rows of the revision tables naming the old suite, over every host.
   */
  public function oldRevisions(): int {
    return array_sum(array_column($this->sections, 'revision_old'));
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    if ($this->refusal !== NULL) {
      return 'audit: REFUSED. ' . str_replace("\n", ' ', $this->refusal);
    }
    $schema = $this->schema
      ? implode(', ', array_map(static fn(string $module, int $version): string => "$module $version", array_keys($this->schema), $this->schema))
      : 'no old module';
    $components = $this->components
      ? ' (' . implode(', ', array_map(static fn(string $id, int $count): string => "$count $id", array_keys($this->components), $this->components)) . ')'
      : '';
    return sprintf(
      'audit: schema %s; %d config objects, %d key_value entries, %d sections%s, %d revision rows, %d roles, %d custom-code files name the old suite.',
      $schema,
      count($this->config),
      array_sum($this->keyValue),
      $this->oldSections(),
      $components,
      $this->oldRevisions(),
      count($this->roles),
      count($this->files),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render(): string {
    $lines = ['Audit: the old suite in this site', ''];

    $lines[] = 'Schema versions (system.schema) of the old modules:';
    foreach ($this->schema as $module => $version) {
      $lines[] = isset($this->floor[$module])
        ? sprintf('  %s %d (the migration serves %d and later)', $module, $version, $this->floor[$module])
        : sprintf('  %s %d', $module, $version);
    }
    $lines[] = $this->schema ? '' : '  none';

    $lines[] = sprintf('Enabled (core.extension): modules %s; themes %s', $this->list($this->enabled['module']), $this->list($this->enabled['theme']));
    $lines[] = '';

    $lines[] = sprintf('Text formats allowing an identity tag without its class attribute: %d', count($this->formats));
    foreach ($this->formats as $format => $tags) {
      $lines[] = sprintf('  %s: %s', $format, implode(', ', $tags));
    }
    $lines[] = '';

    $lines[] = sprintf('Config objects naming the old suite: %d', count($this->config));
    foreach ($this->config as $name) {
      $lines[] = "  $name";
    }
    $lines[] = '';

    $lines[] = sprintf('key_value entries naming the old suite: %d', array_sum($this->keyValue));
    foreach ($this->keyValue as $collection => $count) {
      $lines[] = "  $collection $count";
    }
    $lines[] = '';

    $lines[] = sprintf('Layout sections naming the old suite: %d of the default revisions, %d revision rows', $this->oldSections(), $this->oldRevisions());
    foreach ($this->sections as $field => $entry) {
      $lines[] = sprintf('  %s (%s): %d of %d rows; revisions %d of %d', $field, $entry['table'], $entry['old'], $entry['rows'], $entry['revision_old'], $entry['revision_rows']);
    }
    $lines[] = '';

    $lines[] = 'Components the content phase rewrites (default revisions, nested sections included):';
    foreach ($this->components as $id => $count) {
      $lines[] = "  $id $count";
    }
    $lines[] = $this->components ? '' : '  none';

    $lines[] = sprintf('Roles carrying old permission strings: %d', count($this->roles));
    foreach ($this->roles as $role => $permissions) {
      $lines[] = sprintf('  %s: %s', $role, implode(', ', $permissions));
    }
    $lines[] = '';

    $lines[] = sprintf('Custom code naming the old suite (port with migrate/plus_suite/mapping.tsv): %d files', count($this->files));
    foreach ($this->files as $path => $hits) {
      $lines[] = sprintf('  %s: %s', $path, implode(', ', $hits));
    }
    $lines[] = '';

    if ($this->refusal !== NULL) {
      $lines[] = 'REFUSED: the site cannot be migrated as it is. Nothing was changed; fix what follows on the old code, then run again.';
      $lines[] = $this->refusal;
      $lines[] = '';
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * A comma list, or `none`.
   */
  private function list(array $items): string {
    return $items ? implode(', ', $items) : 'none';
  }

}
