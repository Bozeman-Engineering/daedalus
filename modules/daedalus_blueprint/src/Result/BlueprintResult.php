<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Result;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Result of a blueprint operation on a page host.
 *
 * Carries the host the operation ran against (the root of every content
 * operation: a content entity today, a display later) and the change
 * targets and minted aliases that only content operations produce.
 */
class BlueprintResult extends BlueprintResultBase {

  /**
   * Elements that were changed by the operation.
   *
   * Each entry is ['uuid' => string, 'field_name' => string]. The UUID
   * identifies either the host (when uuid === host->uuid()) or a
   * sub-element (a UUID resolved via ElementLocator).
   *
   * @var array<int, array{uuid: string, field_name: string}>
   */
  protected array $targets = [];

  /**
   * Alias => uuid pairings minted by the operation (the dialect ops).
   *
   * The `ids` echo of the DOM op grammar: an author-chosen id on an
   * inserted element resolves to the uuid the server minted for it.
   *
   * @var array<string, string>
   */
  protected array $aliases = [];

  /**
   * Constructs a new result.
   *
   * @param string $phase
   *   The phase reached ('structural', 'semantic', 'hydrated', 'validated').
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The errors collected during the operation.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   The warnings collected during the operation.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The host if the operation succeeded.
   */
  public function __construct(
    string $phase,
    array $errors = [],
    array $warnings = [],
    protected readonly ?HostInterface $host = NULL,
  ) {
    parent::__construct($phase, $errors, $warnings);
  }

  /**
   * Creates a successful result carrying the host the operation ran against.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host, its document and fields modified in place.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Any warnings collected.
   *
   * @return static
   *   A new successful result.
   */
  public static function success(HostInterface $host, array $warnings = []): static {
    return new static('hydrated', [], $warnings, $host);
  }

  /**
   * Returns the host if the operation succeeded.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The host, or NULL if the operation failed or was validate-only.
   */
  public function getHost(): ?HostInterface {
    return $this->host;
  }

  /**
   * {@inheritdoc}
   *
   * The host's entity, for the generic dispatch surface every backend
   * shares; content consumers read the host.
   */
  public function getEntity(): ?EntityInterface {
    return $this->host?->entity();
  }

  /**
   * {@inheritdoc}
   */
  public function getElementUuids(): array {
    return array_unique(array_column($this->targets, 'uuid'));
  }

  /**
   * Returns elements changed by the operation.
   *
   * @return array<int, array{uuid: string, field_name: string}|string>
   *   The change targets. A string entry is a selectable key naming the
   *   changed element directly (a style write's target).
   */
  public function getTargets(): array {
    return $this->targets;
  }

  /**
   * Returns a new instance with the given change targets.
   *
   * @param array<int, array{uuid: string, field_name: string}|string> $targets
   *   The change targets; a string entry is a selectable key.
   *
   * @return static
   *   A new result with targets set.
   */
  public function withTargets(array $targets): static {
    $clone = clone $this;
    $clone->targets = $targets;
    return $clone;
  }

  /**
   * Returns alias => uuid pairings minted by the operation.
   *
   * @return array<string, string>
   *   The minted pairings; empty for operations that mint no aliases.
   */
  public function getAliases(): array {
    return $this->aliases;
  }

  /**
   * Returns a new instance with minted alias pairings added.
   *
   * @param array<string, string> $aliases
   *   Alias => uuid pairings to merge onto the result.
   *
   * @return static
   *   A new result carrying the pairings.
   */
  public function withAliases(array $aliases): static {
    $clone = clone $this;
    $clone->aliases = array_merge($clone->aliases, $aliases);
    return $clone;
  }

  /**
   * Returns a failed result of the same phase carrying rewritten errors.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The errors to carry instead.
   *
   * @return static
   *   A new result with the same phase and warnings.
   */
  public function withErrors(array $errors): static {
    return new static($this->phase, $errors, $this->warnings, $this->host);
  }

  /**
   * Returns a new instance with targets built from a UUID and field names.
   *
   * @param string $uuid
   *   The element UUID (the host or a nested element).
   * @param string[] $field_names
   *   The field names that were changed.
   *
   * @return static
   *   A new result with targets set.
   */
  public function withTargetsFrom(string $uuid, array $field_names): static {
    $clone = clone $this;
    $clone->targets = array_map(
      fn(string $field_name) => ['uuid' => $uuid, 'field_name' => $field_name],
      $field_names,
    );
    return $clone;
  }

  /**
   * {@inheritdoc}
   */
  protected function subjectSummary(): array {
    if ($this->host === NULL) {
      return [];
    }
    $summary = [
      'entity_type' => $this->host->entityTypeId(),
      'bundle' => $this->host->bundle(),
    ];
    if ($this->host->id()) {
      $summary['entity_id'] = $this->host->id();
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    $result = parent::toArray();
    if (!empty($this->aliases)) {
      $result['ids'] = $this->aliases;
    }
    return $result;
  }

}
